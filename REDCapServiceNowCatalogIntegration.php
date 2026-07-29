<?php

namespace YaleREDCap\REDCapServiceNowCatalogIntegration;

use ExternalModules\AbstractExternalModule;
use ExternalModules\Framework;

require_once __DIR__ . '/classes/ServiceNowApiClient.php';

/**
 * @property Framework $framework
 * @see Framework
 */
class REDCapServiceNowCatalogIntegration extends AbstractExternalModule
{

    /** Set to true to write verbose debug entries to the EM log. */
    private const DEBUG = true;

    /**
     * This function is called via a cron job. It is meant to check for changes in the ServiceNow catalog items that
     * have been configured in REDCap. If changes are detected, it should alert the appropriate users.
     */
    public function checkCatalogChanges()
    {

    }

    public function redcap_save_record( $project_id, $record, $instrument, $event_id, $group_id = NULL, $survey_hash = NULL, $response_id = NULL, $repeat_instance = 1 ) {
        $this->debug('redcap_save_record fired', [
            'project_id'      => $project_id,
            'record'          => $record,
            'instrument'      => $instrument,
            'event_id'        => $event_id,
            'repeat_instance' => $repeat_instance,
        ]);

        $items = $this->getCatalogItems();
        if (empty($items)) {
            $this->debug('No catalog items configured — exiting');
            return;
        }

        $this->debug('Catalog items loaded', ['count' => count($items)]);

        $rawDataFetched = false;
        $recordData     = [];

        foreach ($items as $idx => $item) {
            $itemLabel = $item['label'] ?? "(item $idx)";

            // Skip inactive items
            if (!($item['active'] ?? false)) {
                $this->debug("[$itemLabel] Skipping — item is inactive");
                continue;
            }

            // Only fire when the configured trigger form is saved
            $triggerForm = (string) ($item['triggerForm'] ?? '');
            if ($triggerForm === '') {
                $this->debug("[$itemLabel] Skipping — no trigger form configured");
                continue;
            }
            if ($triggerForm !== $instrument) {
                $this->debug("[$itemLabel] Skipping — trigger form '$triggerForm' does not match saved instrument '$instrument'");
                continue;
            }

            // Require a configured catalog item and field mappings
            $catalogSysId  = (string) ($item['sysId'] ?? '');
            $fieldMappings = $item['fieldMappings'] ?? [];
            if ($catalogSysId === '') {
                $this->debug("[$itemLabel] Skipping — no catalog sys_id configured");
                continue;
            }
            if (empty($fieldMappings)) {
                $this->debug("[$itemLabel] Skipping — no field mappings configured");
                continue;
            }

            $this->debug("[$itemLabel] Trigger form matches — loading record data");

            // Lazy-load record data on first match
            if (!$rawDataFetched) {
                $rawData = \REDCap::getData([
                    'project_id'    => $project_id,
                    'return_format' => 'array',
                    'records'       => [$record],
                    'events'        => [$event_id],
                ]);

                $recordData = $rawData[$record][$event_id] ?? [];
                if (isset($rawData[$record]['repeat_instances'][$event_id][$instrument][$repeat_instance])) {
                    $recordData = array_merge(
                        $recordData,
                        $rawData[$record]['repeat_instances'][$event_id][$instrument][$repeat_instance]
                    );
                }
                $rawDataFetched = true;
                $this->debug('Record data fetched', [
                    'field_count' => count($recordData), 
                    'record_data' => json_encode($recordData, JSON_PRETTY_PRINT)
                ]);
            }

            // Evaluate trigger criteria — all conditions must pass (AND logic)
            $criteria = $item['criteria'] ?? [];
            $this->debug("[$itemLabel] Evaluating criteria", ['criteria_count' => count($criteria)]);
            if (!$this->meetsCriteria((array) $criteria, $recordData)) {
                $this->debug("[$itemLabel] Criteria not met — skipping");
                continue;
            }
            $this->debug("[$itemLabel] Criteria met — building RITM payload");

            // Build the RITM variable map from saved field mappings
            $valueMappings = $item['valueMappings'] ?? [];
            $ritmVariables = [];
            foreach ((array) $fieldMappings as $snVariable => $rcField) {
                if ($rcField === '' || !array_key_exists($rcField, $recordData)) {
                    continue;
                }

                $rawValue    = $recordData[$rcField];
                $variableMap = (isset($valueMappings[$snVariable]) && is_array($valueMappings[$snVariable]))
                    ? $valueMappings[$snVariable]
                    : [];
                // When a value mapping is configured for this variable it is
                // authoritative: only explicitly matched REDCap values are sent.
                // Anything mapped to "Do not send" (or left unmatched) is omitted.
                $hasValueMap = !empty($variableMap);

                if (is_array($rawValue)) {
                    // Checkbox fields arrive as an associative array of
                    // choiceValue => '0'|'1'. Keep only the checked choices and,
                    // when a value mapping exists, translate/filter them.
                    $checkedValues = [];
                    foreach ($rawValue as $choiceValue => $isChecked) {
                        if ((string) $isChecked !== '1') {
                            continue;
                        }
                        $choiceValue = (string) $choiceValue;
                        if ($hasValueMap) {
                            if (array_key_exists($choiceValue, $variableMap) && $variableMap[$choiceValue] !== '') {
                                $checkedValues[] = (string) $variableMap[$choiceValue];
                            }
                            // Unmatched / "Do not send" checked choices are skipped.
                        } else {
                            $checkedValues[] = $choiceValue;
                        }
                    }
                    // In value-map mode, omit the variable entirely when nothing
                    // is left to send (every checked choice was "Do not send").
                    if (!$hasValueMap || !empty($checkedValues)) {
                        $ritmVariables[$snVariable] = implode(',', $checkedValues);
                    }
                } else {
                    $rcValue = (string) $rawValue;
                    if ($hasValueMap) {
                        // Only send when this value is explicitly matched;
                        // otherwise treat it as "Do not send".
                        if (array_key_exists($rcValue, $variableMap) && $variableMap[$rcValue] !== '') {
                            $ritmVariables[$snVariable] = (string) $variableMap[$rcValue];
                        }
                    } else {
                        $ritmVariables[$snVariable] = $rcValue;
                    }
                }
            }
            $this->debug("[$itemLabel] RITM payload built", [
                'sys_id'          => $catalogSysId,
                'variable_count'  => count($ritmVariables),
                'variables'       => $ritmVariables,
            ]);

            // Submit the RITM and log the outcome
            $result = $this->getApiClient()->submitRitm($catalogSysId, $ritmVariables);
            $this->debug("[$itemLabel] RITM submission response", [
                'success'     => $result['success'],
                'http_code'   => $result['httpCode']               ?? null,
                'request_number' => $result['data']['request_number'] ?? null,
                'error'       => $result['error']                  ?? null,
            ]);

            $this->framework->log(
                'ServiceNow RITM submission ' . ($result['success'] ? 'succeeded' : 'failed'),
                [
                    'record'        => $record,
                    'event_id'      => $event_id,
                    'instrument'    => $instrument,
                    'instance'      => $repeat_instance,
                    'catalog_label' => $item['label'] ?? '',
                    'http_code'     => $result['httpCode']               ?? null,
                    'request_number'   => $result['data']['request_number'] ?? null,
                    'request_id'    => $result['data']['request_id'] ?? null,
                    'error'         => $result['error']                  ?? null,
                ]
            );

            // Upload any mapped file-upload fields as RITM attachments. Files
            // cannot be sent through order_now, so this runs as a follow-up step
            // only when the RITM was created successfully.
            $attachmentFields = array_values(array_filter((array) ($item['attachmentFields'] ?? [])));
            if ($result['success'] && !empty($attachmentFields)) {
                $requestId = (string) ($result['data']['request_id'] ?? '');
                $this->processAttachments($attachmentFields, $recordData, $requestId, $itemLabel, [
                    'record'     => $record,
                    'event_id'   => $event_id,
                    'instrument' => $instrument,
                    'instance'   => $repeat_instance,
                ]);
            }
        }

        $this->debug('redcap_save_record complete');
    }

    public function redcap_module_ajax($action, $payload, $project_id, $record, $instrument, $event_id, $repeat_instance, $survey_hash, $response_id, $survey_queue_hash, $page, $page_full, $user_id, $group_id)  {
        switch ($action) {
            case 'testConnection':
                return $this->testConnection($payload);
            case 'saveConnectionSettings':
                return $this->saveConnectionSettings($payload);
            case 'getServiceNowFields':
                $catalogItemId = $payload['sysId'] ?? '';
                if (empty($catalogItemId)) {
                    return ['success' => false, 'error' => 'Missing required parameter: sysId.'];
                }
                return $this->getServiceNowFields($catalogItemId);
            case 'saveCatalogItem':
                return $this->saveCatalogItem($payload);
            case 'deleteCatalogItem':
                return $this->deleteCatalogItem($payload);
            case 'getSubmissionLog':
                return $this->getSubmissionLog($payload);
            default:
                return ['success' => false, 'error' => 'Unknown action.'];
        }
    }

    /** Log message written for a successful RITM submission. */
    private const RITM_SUCCESS_MESSAGE = 'ServiceNow RITM submission succeeded';

    /**
     * Server-side DataTables endpoint for the RITM submission log.
     *
     * Reads the standard DataTables request parameters (draw, start, length,
     * search, order) and returns only the requested page of rows so the table
     * stays performant no matter how large the log grows. All filtering,
     * ordering and pagination are pushed down to the database via queryLogs().
     *
     * @param array $payload DataTables server-side request parameters.
     * @return array DataTables server-side response (draw/recordsTotal/recordsFiltered/data).
     */
    private function getSubmissionLog(array $payload): array
    {
        $draw   = isset($payload['draw'])  ? (int) $payload['draw']  : 1;
        $start  = isset($payload['start']) ? max(0, (int) $payload['start']) : 0;
        $length = isset($payload['length']) ? (int) $payload['length'] : 10;

        // Clamp page size to a sane upper bound (covers DataTables' "All" = -1).
        if ($length < 1 || $length > 100) {
            $length = 100;
        }

        // Columns exposed to DataTables, in display order. Index → sortable log field.
        $sortFields = ['timestamp', 'record', 'catalog_label', 'instrument', 'request_number'];

        $orderColIdx = isset($payload['order'][0]['column']) ? (int) $payload['order'][0]['column'] : 0;
        $orderDir    = (strtolower((string) ($payload['order'][0]['dir'] ?? 'desc')) === 'asc') ? 'asc' : 'desc';
        $orderField  = $sortFields[$orderColIdx] ?? 'timestamp';

        $searchValue = trim((string) ($payload['search']['value'] ?? ''));

        // ── Unfiltered total ──────────────────────────────────────────────
        $recordsTotal = (int) $this->framework->countLogs('message = ?', [self::RITM_SUCCESS_MESSAGE]);

        // No submissions logged yet — the custom log parameters referenced below
        // do not exist, so queryLogs() would throw. Return an empty page instead.
        if ($recordsTotal === 0) {
            return [
                'draw'            => $draw,
                'recordsTotal'    => 0,
                'recordsFiltered' => 0,
                'data'            => [],
            ];
        }

        // ── Build the filtered WHERE clause ───────────────────────────────
        $where  = 'message = ?';
        $params = [self::RITM_SUCCESS_MESSAGE];

        if ($searchValue !== '') {
            $like = '%' . $searchValue . '%';
            $where .= ' and (record like ? or catalog_label like ? or instrument like ? or request_number like ?)';
            array_push($params, $like, $like, $like, $like);
        }

        // ── Filtered total ────────────────────────────────────────────────
        $recordsFiltered = ($searchValue === '')
            ? $recordsTotal
            : (int) $this->framework->countLogs($where, $params);

        // ── Page of rows ──────────────────────────────────────────────────
        // LIMIT values must be integer literals for queryLogs; the casts above
        // guarantee they are safe to inline.
        $sql = "select timestamp, record, instrument, catalog_label, request_number, request_id"
             . " where $where"
             . " order by $orderField $orderDir, log_id $orderDir"
             . " limit " . (int) $start . ", " . (int) $length;

        $instanceUrl = rtrim((string) ($this->framework->getProjectSetting('servicenow-instance-url') ?? ''), '/');

        $data   = [];
        $result = $this->framework->queryLogs($sql, $params);
        while ($row = $result->fetch_assoc()) {
            $requestId = $row['request_id'] ?? null;
            $data[] = [
                // 'log_id'        => $row['log_id'],
                'timestamp'     => $row['timestamp'],
                // 'username'      => $row['username'],
                'record'        => $row['record'],
                // 'event_id'      => $row['event_id'],
                'instrument'    => $row['instrument'],
                // 'instance'      => $row['instance'],
                'catalog_label' => $row['catalog_label'],
                'request_number'   => $row['request_number'] ?? null,
                'request_url'   => ($instanceUrl !== '' && !empty($requestId))
                    ? $instanceUrl . '/now/nav/ui/classic/params/target/' . rawurlencode('sc_request.do?sys_id=' . $requestId)
                    : null,
                // 'http_code'     => $row['http_code'],
            ];
        }

        return [
            'draw'            => $draw,
            'recordsTotal'    => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data'            => $data,
        ];
    }

    /**
     * Returns all configured catalog items as an array of associative arrays.
     * Each element has: label, active, sysId, fieldMappings, valueMappings, snapshot, triggerForm, criteria.
     */
    public function getCatalogItems(): array
    {
        $labels    = (array) ($this->framework->getProjectSetting('catalog-item-label')            ?? []);
        $actives   = (array) ($this->framework->getProjectSetting('catalog-item-active')           ?? []);
        $sysIds    = (array) ($this->framework->getProjectSetting('catalog-item-sys-id')           ?? []);
        $mappings  = (array) ($this->framework->getProjectSetting('catalog-item-field-mappings')   ?? []);
        $valueMaps = (array) ($this->framework->getProjectSetting('catalog-item-value-mappings')   ?? []);
        $attachFds = (array) ($this->framework->getProjectSetting('catalog-item-attachment-fields') ?? []);
        $snapshots = (array) ($this->framework->getProjectSetting('catalog-item-snapshot')         ?? []);
        $forms     = (array) ($this->framework->getProjectSetting('catalog-item-trigger-form')     ?? []);
        $criteria  = (array) ($this->framework->getProjectSetting('catalog-item-trigger-criteria') ?? []);

        $count = count($labels);
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $items[] = [
                'label'            => (string) ($labels[$i] ?? ''),
                'active'           => ($actives[$i] ?? '') === '1',
                'sysId'            => (string) ($sysIds[$i] ?? ''),
                'fieldMappings'    => $this->decodeProjectSettingJson($mappings[$i] ?? [], []),
                'valueMappings'    => $this->decodeProjectSettingJson($valueMaps[$i] ?? [], []),
                'attachmentFields' => $this->decodeProjectSettingJson($attachFds[$i] ?? [], []),
                'snapshot'         => $this->decodeProjectSettingJson($snapshots[$i] ?? null, null),
                'triggerForm'      => (string) ($forms[$i] ?? ''),
                'criteria'         => $this->decodeProjectSettingJson($criteria[$i] ?? [], []),
            ];
        }
        return $items;
    }

    private function decodeProjectSettingJson($value, $default)
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value === null || $value === '') {
            return $default;
        }

        if (!is_string($value)) {
            return $default;
        }

        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
    }

    private function saveCatalogItem(array $payload): array
    {
        $index         = (isset($payload['index']) && $payload['index'] !== null && $payload['index'] !== '')
                         ? (int) $payload['index'] : null;
        $label         = trim($payload['label']       ?? '');
        $active        = !empty($payload['active']);
        $sysId         = trim($payload['sysId']       ?? '');
        $fieldMappings = $payload['fieldMappings']    ?? [];
        $valueMappings = $payload['valueMappings']    ?? [];
        $attachFields  = $payload['attachmentFields'] ?? [];
        $triggerForm   = $payload['triggerForm']      ?? '';
        $criteria      = $payload['criteria']         ?? [];
        $snapshot      = $payload['snapshot']         ?? null;

        if ($label === '') {
            return ['success' => false, 'error' => 'Label is required.'];
        }
        if ($sysId === '') {
            return ['success' => false, 'error' => 'Catalog Item sys_id is required.'];
        }

        // Read existing parallel arrays
        $labels    = (array) ($this->framework->getProjectSetting('catalog-item-label')            ?? []);
        $actives   = (array) ($this->framework->getProjectSetting('catalog-item-active')           ?? []);
        $sysIds    = (array) ($this->framework->getProjectSetting('catalog-item-sys-id')           ?? []);
        $mapsArr   = (array) ($this->framework->getProjectSetting('catalog-item-field-mappings')   ?? []);
        $valMapsArr = (array) ($this->framework->getProjectSetting('catalog-item-value-mappings')  ?? []);
        $attachArr = (array) ($this->framework->getProjectSetting('catalog-item-attachment-fields') ?? []);
        $snapsArr  = (array) ($this->framework->getProjectSetting('catalog-item-snapshot')         ?? []);
        $formsArr  = (array) ($this->framework->getProjectSetting('catalog-item-trigger-form')     ?? []);
        $critArr   = (array) ($this->framework->getProjectSetting('catalog-item-trigger-criteria') ?? []);

        // Append for new items
        if ($index === null) {
            $index = count($labels);
        }

        $labels[$index]   = $label;
        $actives[$index]  = $active ? '1' : '0';
        $sysIds[$index]   = $sysId;
        $mapsArr[$index]  = json_encode(is_array($fieldMappings) ? $fieldMappings : []);
        $valMapsArr[$index] = json_encode(is_array($valueMappings) ? $valueMappings : []);
        $attachArr[$index] = json_encode(is_array($attachFields) ? array_values($attachFields) : []);
        $snapsArr[$index] = $snapshot !== null ? json_encode($snapshot) : null;
        $formsArr[$index] = $triggerForm;
        $critArr[$index]  = json_encode(is_array($criteria) ? $criteria : []);

        $this->framework->setProjectSetting('catalog-item-label',              $labels);
        $this->framework->setProjectSetting('catalog-item-active',             $actives);
        $this->framework->setProjectSetting('catalog-item-sys-id',             $sysIds);
        $this->framework->setProjectSetting('catalog-item-field-mappings',     $mapsArr);
        $this->framework->setProjectSetting('catalog-item-value-mappings',     $valMapsArr);
        $this->framework->setProjectSetting('catalog-item-attachment-fields',  $attachArr);
        $this->framework->setProjectSetting('catalog-item-snapshot',           $snapsArr);
        $this->framework->setProjectSetting('catalog-item-trigger-form',       $formsArr);
        $this->framework->setProjectSetting('catalog-item-trigger-criteria',   $critArr);

        return ['success' => true, 'index' => $index];
    }

    private function deleteCatalogItem(array $payload): array
    {
        $index = isset($payload['index']) ? (int) $payload['index'] : -1;
        if ($index < 0) {
            return ['success' => false, 'error' => 'Invalid index.'];
        }

        $keys = [
            'catalog-item-label',
            'catalog-item-active',
            'catalog-item-sys-id',
            'catalog-item-field-mappings',
            'catalog-item-value-mappings',
            'catalog-item-attachment-fields',
            'catalog-item-snapshot',
            'catalog-item-trigger-form',
            'catalog-item-trigger-criteria',
        ];

        foreach ($keys as $key) {
            $values = (array) ($this->framework->getProjectSetting($key) ?? []);
            array_splice($values, $index, 1);
            $this->framework->setProjectSetting($key, array_values($values));
        }

        return ['success' => true];
    }

    private function saveConnectionSettings(array $payload)
    {
        $instanceUrl = $payload['instanceUrl'] ?? '';
        $username    = $payload['username']    ?? '';
        $password    = $payload['password']    ?? '';
        if (empty($instanceUrl) || empty($username) || empty($password)) {
            return ['success' => false, 'message' => 'Missing required parameters.'];
        }
        $this->framework->setProjectSetting('servicenow-instance-url', $instanceUrl);
        $this->framework->setProjectSetting('servicenow-username',      $username);
        $this->framework->setProjectSetting('servicenow-password',      $password);
        return ['success' => true, 'message' => 'Connection settings saved successfully.'];
    }

    private function testConnection(array $payload): array
    {
        $instanceUrl = trim($payload['instanceUrl'] ?? '');
        $username    = trim($payload['username']    ?? '');
        $password    =      $payload['password']    ?? '';

        if ($instanceUrl === '' || $username === '' || $password === '') {
            return ['success' => false, 'error' => 'Missing required parameters.'];
        }

        if ($password === '__SAVED__') {
            $password = (string) ($this->framework->getProjectSetting('servicenow-password') ?? '');
        }

        return (new ServiceNowApiClient($instanceUrl, $username, $password))->testConnection();
    }

    private function getServiceNowFields(string $catalogItemId): array
    {
        return $this->getApiClient()->getCatalogItemVariables($catalogItemId);
    }

    /**
     * Uploads REDCap file-upload field contents as attachments on a newly
     * created RITM.
     *
     * Because the order_now endpoint cannot carry files, this runs as a
     * follow-up: resolve the RITM sys_id from the parent request, then POST each
     * file's raw bytes to the ServiceNow Attachment API. File contents are read
     * via the public REDCap::getFile() developer method.
     *
     * @param string[] $attachmentFields REDCap file-upload field names to send.
     * @param array    $recordData       Flat field→value map for the saved record.
     * @param string   $requestId        order_now `request_id` (sc_request sys_id).
     * @param string   $itemLabel        Catalog item label, for debug output.
     * @param array    $logContext       Extra context merged into the audit log.
     */
    private function processAttachments(
        array $attachmentFields,
        array $recordData,
        string $requestId,
        string $itemLabel,
        array $logContext = []
    ): void {
        if ($requestId === '') {
            $this->debug("[$itemLabel] Cannot upload attachments — missing request_id");
            return;
        }

        // Collect fields that actually hold an uploaded file (an edoc id).
        $pendingFields = [];
        foreach ($attachmentFields as $field) {
            $edocId = $recordData[$field] ?? '';
            if (is_numeric($edocId) && (int) $edocId > 0) {
                $pendingFields[$field] = (int) $edocId;
            }
        }
        if (empty($pendingFields)) {
            $this->debug("[$itemLabel] No uploaded files found for configured attachment fields — skipping");
            return;
        }

        // Resolve the RITM sys_id once (all files attach to the same RITM).
        $client = $this->getApiClient();
        $ritm   = $client->getRequestItemSysId($requestId);
        if (!($ritm['success'] ?? false)) {
            $this->debug("[$itemLabel] Could not resolve RITM sys_id for attachments", [
                'request_id' => $requestId,
                'error'      => $ritm['error'] ?? null,
            ]);
            $this->framework->log('ServiceNow attachment upload failed', array_merge($logContext, [
                'catalog_label' => $itemLabel,
                'error'         => 'Could not resolve request item: ' . ($ritm['error'] ?? 'unknown error'),
            ]));
            return;
        }
        $ritmSysId = (string) $ritm['sysId'];

        foreach ($pendingFields as $field => $edocId) {
            $contents = \REDCap::getFile($edocId);
            if (!is_array($contents) || count($contents) < 3) {
                $this->debug("[$itemLabel] Could not read file contents for field '$field'", [
                    'edoc_id' => $edocId,
                ]);
                $this->framework->log('ServiceNow attachment upload failed', array_merge($logContext, [
                    'catalog_label' => $itemLabel,
                    'field'         => $field,
                    'error'         => 'Could not read uploaded file (edoc ' . $edocId . ').',
                ]));
                continue;
            }

            [$mimeType, $fileName, $binary] = $contents;
            $upload = $client->uploadAttachment(
                $ritmSysId,
                (string) $fileName,
                (string) $mimeType,
                (string) $binary
            );

            $this->debug("[$itemLabel] Attachment upload response", [
                'field'     => $field,
                'file_name' => $fileName,
                'success'   => $upload['success'] ?? false,
                'http_code' => $upload['httpCode'] ?? null,
                'error'     => $upload['error'] ?? null,
            ]);

            $this->framework->log(
                'ServiceNow attachment upload ' . (($upload['success'] ?? false) ? 'succeeded' : 'failed'),
                array_merge($logContext, [
                    'catalog_label' => $itemLabel,
                    'field'         => $field,
                    'file_name'     => (string) $fileName,
                    'ritm_sys_id'   => $ritmSysId,
                    'http_code'     => $upload['httpCode'] ?? null,
                    'error'         => $upload['error'] ?? null,
                ])
            );
        }
    }

    /**
     * Creates a ServiceNowApiClient using the project's saved connection settings.
     */
    private function getApiClient(): ServiceNowApiClient
    {
        return new ServiceNowApiClient(
            (string) ($this->framework->getProjectSetting('servicenow-instance-url') ?? ''),
            (string) ($this->framework->getProjectSetting('servicenow-username')     ?? ''),
            (string) ($this->framework->getProjectSetting('servicenow-password')     ?? '')
        );
    }

    /**
     * Returns true when every criterion in $criteria is satisfied by $recordData.
     * An empty criteria array is always satisfied (no conditions = always push).
     *
     * Supported operators: eq, neq, contains, not_empty, empty.
     *
     * @param array $criteria   Array of {field, operator, value} objects.
     * @param array $recordData Flat field→value map for the current record/event.
     */
    /**
     * Writes a log entry only when DEBUG is true.
     *
     * @param string $message
     * @param array  $params  Optional key→value context pairs.
     */
    private function debug(string $message, array $params = []): void
    {
        if (!self::DEBUG) {
            return;
        }
        // framework->log() only accepts scalar param values; encode arrays/objects.
        $safe = [];
        foreach ($params as $k => $v) {
            $safe[$k] = is_array($v) || is_object($v) ? json_encode($v) : $v;
        }
        $this->framework->log('[DEBUG] ' . $message, $safe);
    }

    private function meetsCriteria(array $criteria, array $recordData): bool
    {
        foreach ($criteria as $criterion) {
            $field    =          $criterion['field']    ?? '';
            $operator = (string) ($criterion['operator'] ?? 'eq');
            $expected = (string) ($criterion['value']    ?? '');

            if ($field === '') {
                continue;
            }

            $actual = (string) ($recordData[$field] ?? '');

            switch ($operator) {
                case 'eq':
                    $passes = $actual === $expected;
                    break;
                case 'neq':
                    $passes = $actual !== $expected;
                    break;
                case 'contains':
                    $passes = $expected === '' || str_contains($actual, $expected);
                    break;
                case 'not_empty':
                    $passes = $actual !== '';
                    break;
                case 'empty':
                    $passes = $actual === '';
                    break;
                default:
                    $passes = true;
            }

            if (!$passes) {
                return false;
            }
        }

        return true;
    }


}

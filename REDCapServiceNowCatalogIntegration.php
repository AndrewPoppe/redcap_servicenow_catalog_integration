<?php

namespace YaleREDCap\REDCapServiceNowCatalogIntegration;

use ExternalModules\AbstractExternalModule;
use ExternalModules\Framework;

require_once __DIR__ . '/classes/ServiceNowCredentials.php';
require_once __DIR__ . '/classes/ServiceNowApiClient.php';

/**
 * @property Framework $framework
 * @see Framework
 */
class REDCapServiceNowCatalogIntegration extends AbstractExternalModule
{

    /** Set to true to write verbose debug entries to the EM log. */
    private const DEBUG = false;

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

            // Evaluate trigger criteria — groups are OR'd, conditions within a group are AND'd
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

            $logContext = [
                'record'        => $record,
                'event_id'      => $event_id,
                'instrument'    => $instrument,
                'instance'      => $repeat_instance,
                'catalog_label' => $item['label'] ?? '',
            ];
            $requestNumber = isset($result['data']['request_number']) ? (string) $result['data']['request_number'] : null;
            $requestId     = isset($result['data']['request_id'])     ? (string) $result['data']['request_id']     : null;

            // order_now only identifies the parent request, so look up its RITM
            // (number + sys_id) for the Submission Log. The result is cached and
            // reused by the result-field write-back and the attachment uploads.
            $ritm       = $result['success'] ? $this->resolveRequestItem((string) $requestId) : ['success' => false];
            $ritmNumber = ($ritm['success'] ?? false) ? (string) ($ritm['number'] ?? '') : null;
            $ritmSysId  = ($ritm['success'] ?? false) ? (string) ($ritm['sysId'] ?? '') : null;

            $this->framework->log(
                $result['success'] ? self::RITM_SUCCESS_MESSAGE : self::RITM_FAILURE_MESSAGE,
                array_merge($logContext, [
                    'http_code'      => $result['httpCode'] ?? null,
                    'request_number' => $requestNumber,
                    'request_id'     => $requestId,
                    'ritm_number'    => $ritmNumber,
                    'ritm_sys_id'    => $ritmSysId,
                    'error'          => $result['success'] ? null : (string) ($result['error'] ?? 'Unknown error.'),
                ])
            );

            if (!$result['success']) {
                $reasons = [$this->formatFailureReason($result['error'] ?? null, $result['httpCode'] ?? null)];

                // Record the failure on the record (status "Failed", stale
                // request numbers cleared) so receipts never show an old request.
                $saveError = $this->saveResultsToRecord($item, $project_id, $record, $event_id, $instrument, $repeat_instance, false, null, null, $logContext);
                if ($saveError !== null) {
                    $reasons[] = $saveError;
                }

                $this->sendFailureNotification($item, $logContext, false, $reasons);
                continue;
            }

            $followUpLogContext = array_merge($logContext, [
                'request_number' => $requestNumber,
                'request_id'     => $requestId,
                'ritm_number'    => $ritmNumber,
                'ritm_sys_id'    => $ritmSysId,
            ]);
            $followUpFailures = [];

            // Save the request/RITM numbers back to the record first, so they
            // are in place before REDCap pipes the survey completion text and
            // sends the confirmation email (both happen after this hook).
            if ($this->hasResultFields($item)) {
                if (($item['resultFields']['ritmNumber'] ?? '') !== '' && $ritmNumber === null) {
                    $lookupError = 'Could not look up the request item (RITM) number: ' . $this->formatFailureReason($ritm['error'] ?? null, $ritm['httpCode'] ?? null);
                    $this->framework->log(self::RESULT_SAVE_FAILURE_MESSAGE, array_merge($followUpLogContext, [
                        'error' => $lookupError,
                    ]));
                    $followUpFailures[] = $lookupError;
                }

                $saveError = $this->saveResultsToRecord($item, $project_id, $record, $event_id, $instrument, $repeat_instance, true, $requestNumber, $ritmNumber, $followUpLogContext);
                if ($saveError !== null) {
                    $followUpFailures[] = $saveError;
                }
            }

            // Upload any mapped file-upload fields as RITM attachments. Files
            // cannot be sent through order_now, so this runs as a follow-up step
            // only when the RITM was created successfully.
            $attachmentFields = array_values(array_filter((array) ($item['attachmentFields'] ?? [])));
            if (!empty($attachmentFields)) {
                $followUpFailures = array_merge($followUpFailures, $this->processAttachments(
                    $attachmentFields,
                    $recordData,
                    (string) $requestId,
                    $itemLabel,
                    $followUpLogContext
                ));
            }

            if (!empty($followUpFailures)) {
                $this->sendFailureNotification($item, $logContext, true, $followUpFailures, $requestNumber, $ritmNumber);
            }
        }

        $this->debug('redcap_save_record complete');
    }

    public function redcap_module_ajax($action, $payload, $project_id, $record, $instrument, $event_id, $repeat_instance, $survey_hash, $response_id, $survey_queue_hash, $page, $page_full, $user_id, $group_id)  {
        if (!$this->framework->isSuperUser()) {
            return ['success' => false, 'error' => 'Unauthorized: only super users can perform this action.'];
        }
    
        switch ($action) {
            case 'testConnection':
                return $this->testConnection($payload);
            case 'saveConnectionSettings':
                return $this->saveConnectionSettings($payload);
            case 'deleteConnectionSettings':
                return $this->deleteConnectionSettings();
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

    /** Log message written when ServiceNow rejects (or never receives) a RITM submission. */
    private const RITM_FAILURE_MESSAGE = 'ServiceNow RITM submission failed';

    /** Log message written when a RITM was created but a file could not be attached to it. */
    private const ATTACHMENT_FAILURE_MESSAGE = 'ServiceNow attachment upload failed';

    /** Log message written when submission results could not be saved back to the REDCap record. */
    private const RESULT_SAVE_FAILURE_MESSAGE = 'ServiceNow results could not be saved to REDCap';

    /** Log message written when a failure notification email could not be sent. */
    private const NOTIFICATION_FAILURE_MESSAGE = 'ServiceNow failure notification could not be sent';

    /** Log messages shown in the Submission Log, keyed by the status reported to the UI. */
    private const SUBMISSION_LOG_STATUSES = [
        'success'           => self::RITM_SUCCESS_MESSAGE,
        'failed'            => self::RITM_FAILURE_MESSAGE,
        'attachment_failed' => self::ATTACHMENT_FAILURE_MESSAGE,
        'save_failed'       => self::RESULT_SAVE_FAILURE_MESSAGE,
    ];

    /** Value written to the Submission Status result field after a successful submission. */
    private const RESULT_STATUS_SUBMITTED = 'Submitted';

    /** Value written to the Submission Status result field after a failed submission. */
    private const RESULT_STATUS_FAILED = 'Failed';

    /** Result field roles that can be mapped to REDCap fields, in display order. */
    private const RESULT_FIELD_KEYS = ['requestNumber', 'ritmNumber', 'status'];

    /**
     * Sent by the configuration page in place of a password or client secret
     * that was left blank, meaning "keep the saved value".
     */
    private const SAVED_SECRET_PLACEHOLDER = '__SAVED__';

    /** Project setting caching the current OAuth access/refresh token (encrypted JSON). */
    private const OAUTH_TOKEN_SETTING = 'servicenow-oauth-token';

    /**
     * Marks a secret setting (password, client secret, or token cache) that
     * is stored encrypted. Passwords saved by earlier versions of the module
     * are plain text and have no prefix.
     */
    private const ENCRYPTED_SECRET_PREFIX = 'encrypted:v1:';

    /**
     * Project settings that make up the ServiceNow connection, including the
     * token cache. All are marked super-users-only in config.json, so only
     * REDCap administrators can change or export them through the External
     * Modules framework.
     */
    private const CONNECTION_SETTING_KEYS = [
        'servicenow-instance-url',
        'servicenow-auth-type',
        'servicenow-username',
        'servicenow-password',
        'servicenow-oauth-grant-type',
        'servicenow-oauth-client-id',
        'servicenow-oauth-client-secret',
        self::OAUTH_TOKEN_SETTING,
    ];

    /** Request item lookups made during this request, keyed by request sys_id. */
    private array $requestItemCache = [];

    /** API clients created during this request, keyed by project ID (see getApiClient()). */
    private array $apiClients = [];

    /**
     * Server-side DataTables endpoint for the RITM submission log.
     *
     * Reads the standard DataTables request parameters (draw, start, length,
     * search, order) plus an optional `status` filter (all|success|failed) and
     * returns only the requested page of rows so the table stays performant no
     * matter how large the log grows. All filtering, ordering and pagination
     * are pushed down to the database via queryLogs().
     *
     * Successful submissions, failed submissions, and follow-up failures
     * after a request was created (a file could not be attached, or results
     * could not be saved back to the record) are all included.
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

        // Columns exposed to DataTables, in display order. Index → sortable log
        // field (null = not sortable; falls back to timestamp).
        $sortFields = ['timestamp', 'message', 'record', 'catalog_label', 'instrument', 'request_number', 'ritm_number', null];

        $orderColIdx = isset($payload['order'][0]['column']) ? (int) $payload['order'][0]['column'] : 0;
        $orderDir    = (strtolower((string) ($payload['order'][0]['dir'] ?? 'desc')) === 'asc') ? 'asc' : 'desc';
        $orderField  = $sortFields[$orderColIdx] ?? 'timestamp';

        $searchValue = trim((string) ($payload['search']['value'] ?? ''));

        $allMessages = array_values(self::SUBMISSION_LOG_STATUSES);
        switch ((string) ($payload['status'] ?? 'all')) {
            case 'success':
                $statusMessages = [self::RITM_SUCCESS_MESSAGE];
                break;
            case 'failed':
                $statusMessages = array_values(array_diff($allMessages, [self::RITM_SUCCESS_MESSAGE]));
                break;
            default:
                $statusMessages = $allMessages;
        }
        $isFiltered = ($searchValue !== '' || count($statusMessages) !== count($allMessages));

        // ── Unfiltered total ──────────────────────────────────────────────
        $recordsTotal = (int) $this->framework->countLogs($this->buildInClause('message', $allMessages), $allMessages);

        if ($recordsTotal === 0) {
            return [
                'draw'            => $draw,
                'recordsTotal'    => 0,
                'recordsFiltered' => 0,
                'data'            => [],
            ];
        }

        // ── Build the filtered WHERE clause ───────────────────────────────
        $where  = $this->buildInClause('message', $statusMessages);
        $params = $statusMessages;

        if ($searchValue !== '') {
            $like = '%' . $searchValue . '%';
            $where .= ' and (record like ? or catalog_label like ? or instrument like ? or request_number like ?'
                    . ' or ritm_number like ? or error like ? or file_name like ?)';
            array_push($params, $like, $like, $like, $like, $like, $like, $like);
        }

        // ── Filtered total ────────────────────────────────────────────────
        $recordsFiltered = $isFiltered
            ? (int) $this->framework->countLogs($where, $params)
            : $recordsTotal;

        // ── Page of rows ──────────────────────────────────────────────────
        // LIMIT values must be integer literals for queryLogs; the casts above
        // guarantee they are safe to inline.
        $sql = "select timestamp, message, record, instrument, catalog_label, request_number, request_id,"
             . " ritm_number, ritm_sys_id, http_code, error, field, file_name"
             . " where $where"
             . " order by $orderField $orderDir, log_id $orderDir"
             . " limit " . (int) $start . ", " . (int) $length;

        // Links use the same base URL as requests, so a path in a URL saved by
        // an earlier version (or anything that is not an https URL) is dropped.
        $instanceUrl = ServiceNowApiClient::normalizeInstanceUrl($this->getSavedInstanceUrl()) ?? '';
        $statusByMessage = array_flip(self::SUBMISSION_LOG_STATUSES);

        $data   = [];
        $result = $this->framework->queryLogs($sql, $params);
        while ($row = $result->fetch_assoc()) {
            $requestId = $row['request_id'] ?? null;
            $ritmSysId = $row['ritm_sys_id'] ?? null;
            $status    = $statusByMessage[$row['message']] ?? 'failed';

            $reason = null;
            if ($status === 'failed' || $status === 'save_failed') {
                $reason = $this->formatFailureReason($row['error'] ?? null, $row['http_code'] ?? null);
            } elseif ($status === 'attachment_failed') {
                $reason = $this->formatAttachmentFailureReason(
                    $row['file_name'] ?? null,
                    $row['field'] ?? null,
                    $row['error'] ?? null,
                    $row['http_code'] ?? null
                );
            }

            $data[] = [
                'timestamp'      => $row['timestamp'],
                'status'         => $status,
                'record'         => $row['record'],
                'instrument'     => $row['instrument'],
                'catalog_label'  => $row['catalog_label'],
                'request_number' => $row['request_number'] ?? null,
                'request_url'    => $this->buildServiceNowRecordUrl($instanceUrl, 'sc_request', $requestId),
                'ritm_number'    => $row['ritm_number'] ?? null,
                'ritm_url'       => $this->buildServiceNowRecordUrl($instanceUrl, 'sc_req_item', $ritmSysId),
                'reason'         => $reason,
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
     * Project setting keys that make up one catalog item. Each key holds a
     * parallel array indexed by catalog item position.
     */
    private const CATALOG_ITEM_SETTING_KEYS = [
        'catalog-item-label',
        'catalog-item-active',
        'catalog-item-sys-id',
        'catalog-item-field-mappings',
        'catalog-item-value-mappings',
        'catalog-item-attachment-fields',
        'catalog-item-snapshot',
        'catalog-item-trigger-form',
        'catalog-item-trigger-criteria',
        'catalog-item-result-fields',
        'catalog-item-failure-emails',
    ];

    /**
     * Returns all configured catalog items as an array of associative arrays.
     * Each element has: label, active, sysId, fieldMappings, valueMappings,
     * attachmentFields, snapshot, triggerForm, criteria, resultFields,
     * failureEmails.
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
        $results   = (array) ($this->framework->getProjectSetting('catalog-item-result-fields')    ?? []);
        $emails    = (array) ($this->framework->getProjectSetting('catalog-item-failure-emails')   ?? []);

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
                'resultFields'     => $this->normalizeResultFields($this->decodeProjectSettingJson($results[$i] ?? [], [])),
                'failureEmails'    => array_values(array_filter(
                    (array) $this->decodeProjectSettingJson($emails[$i] ?? [], []),
                    'is_string'
                )),
            ];
        }
        return $items;
    }

    /**
     * Returns a catalog-item settings array re-indexed as a 0..$count-1 list.
     *
     * Settings added in later module versions have no entries for catalog
     * items created before them, so writing a single index can leave a sparse
     * array (stored as a JSON object). Padding keeps every parallel array
     * aligned with the label array so positional operations such as
     * array_splice() hit the intended item.
     */
    private function normalizeSettingArray(array $values, int $count, $default = null): array
    {
        $list = [];
        for ($i = 0; $i < $count; $i++) {
            $list[] = array_key_exists($i, $values) ? $values[$i] : $default;
        }
        return $list;
    }

    /**
     * Parses a list of email addresses supplied as an array or as a string
     * separated by commas, semicolons, or whitespace.
     *
     * @return array{valid: string[], invalid: string[]} De-duplicated (case-insensitively) valid addresses, plus any rejected entries.
     */
    private function parseEmailList($input): array
    {
        if (is_array($input)) {
            $input = implode(',', array_filter($input, 'is_scalar'));
        }
        $parts = is_scalar($input)
            ? preg_split('/[\s,;]+/', (string) $input, -1, PREG_SPLIT_NO_EMPTY)
            : [];

        $valid   = [];
        $invalid = [];
        $seen    = [];
        foreach ((array) $parts as $email) {
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $invalid[] = $email;
                continue;
            }
            $key = strtolower($email);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $valid[]    = $email;
            }
        }

        return ['valid' => $valid, 'invalid' => $invalid];
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
        $emailList     = $this->parseEmailList($payload['failureEmails'] ?? []);
        $resultFields  = $this->normalizeResultFields($payload['resultFields'] ?? []);

        if ($label === '') {
            return ['success' => false, 'error' => 'Label is required.'];
        }
        if ($sysId === '') {
            return ['success' => false, 'error' => 'Catalog Item sys_id is required.'];
        }
        if (!empty($emailList['invalid'])) {
            return [
                'success' => false,
                'error'   => 'Invalid failure notification email address(es): ' . implode(', ', $emailList['invalid']),
            ];
        }
        $resultFieldError = $this->validateResultFields($resultFields);
        if ($resultFieldError !== null) {
            return ['success' => false, 'error' => $resultFieldError];
        }

        // Read existing parallel arrays
        $labels = (array) ($this->framework->getProjectSetting('catalog-item-label') ?? []);
        $count  = count($labels);

        // Append for new items
        if ($index === null || $index < 0 || $index > $count) {
            $index = $count;
        }
        $count = max($count, $index + 1);

        $values = [
            'catalog-item-label'             => $label,
            'catalog-item-active'            => $active ? '1' : '0',
            'catalog-item-sys-id'            => $sysId,
            'catalog-item-field-mappings'    => json_encode(is_array($fieldMappings) ? $fieldMappings : []),
            'catalog-item-value-mappings'    => json_encode(is_array($valueMappings) ? $valueMappings : []),
            'catalog-item-attachment-fields' => json_encode(is_array($attachFields) ? array_values($attachFields) : []),
            'catalog-item-snapshot'          => $snapshot !== null ? json_encode($snapshot) : null,
            'catalog-item-trigger-form'      => $triggerForm,
            'catalog-item-trigger-criteria'  => json_encode(is_array($criteria) ? $criteria : []),
            'catalog-item-result-fields'     => json_encode($resultFields),
            'catalog-item-failure-emails'    => json_encode($emailList['valid']),
        ];

        foreach ($values as $key => $value) {
            $existing = (array) ($this->framework->getProjectSetting($key) ?? []);
            $list     = $this->normalizeSettingArray($existing, $count);
            $list[$index] = $value;
            $this->framework->setProjectSetting($key, $list);
        }

        return [
            'success'       => true,
            'index'         => $index,
            'failureEmails' => $emailList['valid'],
            'resultFields'  => $resultFields,
        ];
    }

    private function deleteCatalogItem(array $payload): array
    {
        $index = isset($payload['index']) ? (int) $payload['index'] : -1;
        $count = count((array) ($this->framework->getProjectSetting('catalog-item-label') ?? []));
        if ($index < 0 || $index >= $count) {
            return ['success' => false, 'error' => 'Invalid index.'];
        }

        foreach (self::CATALOG_ITEM_SETTING_KEYS as $key) {
            $values = $this->normalizeSettingArray((array) ($this->framework->getProjectSetting($key) ?? []), $count);
            array_splice($values, $index, 1);
            $this->framework->setProjectSetting($key, $values);
        }

        return ['success' => true];
    }

    /**
     * Saves the connection settings from the configuration page.
     *
     * Only the credentials used by the selected authentication method are
     * kept; any others (e.g. a password after switching to the OAuth client
     * credentials grant) are removed. The instance URL is saved in its
     * normalized form (see ServiceNowApiClient::normalizeInstanceUrl()), and
     * the password and client secret are stored encrypted, bound to that
     * instance. Any cached OAuth token is discarded so the next request
     * authenticates with the new settings.
     */
    private function saveConnectionSettings(array $payload): array
    {
        $connection = $this->resolveConnectionPayload($payload);
        if (isset($connection['error'])) {
            return ['success' => false, 'error' => $connection['error']];
        }

        /** @var ServiceNowCredentials $credentials */
        $credentials = $connection['credentials'];
        $instanceUrl = $connection['instanceUrl'];
        $oauth       = $credentials->isOAuth();
        $userCreds   = $credentials->usesUserCredentials();

        // Encrypt the secrets before changing anything, so a failure leaves
        // the saved settings as they were.
        try {
            $password     = $userCreds ? $this->encryptSecret($credentials->getPassword(), $instanceUrl) : null;
            $clientSecret = $oauth ? $this->encryptSecret($credentials->getClientSecret(), $instanceUrl) : null;
        } catch (\RuntimeException $e) {
            return ['success' => false, 'error' => 'The connection settings could not be saved because the credentials could not be encrypted.'];
        }

        // The instance URL is written last: a request that reads the settings
        // while they are being saved and already sees the new URL then sees
        // all the other new values too (secrets bound to an instance cover
        // the other cases, see getSecretSetting()).
        $values = [
            'servicenow-auth-type'           => $credentials->getAuthType(),
            'servicenow-username'            => $userCreds ? $credentials->getUsername() : null,
            'servicenow-password'            => $password,
            'servicenow-oauth-grant-type'    => $oauth ? $credentials->getGrantType() : null,
            'servicenow-oauth-client-id'     => $oauth ? $credentials->getClientId() : null,
            'servicenow-oauth-client-secret' => $clientSecret,
            self::OAUTH_TOKEN_SETTING        => null,
            'servicenow-instance-url'        => $instanceUrl,
        ];
        foreach ($values as $key => $value) {
            if ($value === null) {
                $this->framework->removeProjectSetting($key);
            } else {
                $this->framework->setProjectSetting($key, $value);
            }
        }
        $this->apiClients = [];

        return ['success' => true, 'message' => 'Connection settings saved successfully.', 'instanceUrl' => $instanceUrl];
    }

    private function deleteConnectionSettings(): array
    {
        foreach (self::CONNECTION_SETTING_KEYS as $key) {
            $this->framework->removeProjectSetting($key);
        }
        $this->apiClients = [];
        return ['success' => true, 'message' => 'Connection settings deleted.'];
    }

    /**
     * Tests the connection settings currently entered on the configuration
     * page (which may not be saved yet). Any OAuth token obtained is kept in
     * memory only, so testing never replaces the token cached for the saved
     * settings.
     */
    private function testConnection(array $payload): array
    {
        $connection = $this->resolveConnectionPayload($payload);
        if (isset($connection['error'])) {
            return ['success' => false, 'error' => $connection['error']];
        }

        $result = (new ServiceNowApiClient($connection['instanceUrl'], $connection['credentials']))->testConnection();
        if (!$result['success']) {
            $result['error'] = $this->formatFailureReason($result['error'] ?? null, $result['httpCode'] ?? null);
        }
        return $result;
    }

    /**
     * Validates connection settings sent by the configuration page and builds
     * the credentials they describe. A password or client secret sent as
     * SAVED_SECRET_PLACEHOLDER is replaced with the saved value, which is only
     * allowed for the saved instance, so a saved secret is never sent to a
     * different one. The returned instance URL is normalized.
     *
     * @return array{instanceUrl?: string, credentials?: ServiceNowCredentials, error?: string}
     */
    private function resolveConnectionPayload(array $payload): array
    {
        $instanceUrl = is_string($payload['instanceUrl'] ?? null) ? trim($payload['instanceUrl']) : '';
        $authType    = is_string($payload['authType'] ?? null) ? $payload['authType'] : ServiceNowCredentials::AUTH_BASIC;
        $grantType   = is_string($payload['grantType'] ?? null) ? $payload['grantType'] : '';

        if (!in_array($authType, ServiceNowCredentials::AUTH_TYPES, true)) {
            return ['error' => 'Unsupported authentication method.'];
        }
        if ($authType === ServiceNowCredentials::AUTH_OAUTH && !in_array($grantType, ServiceNowCredentials::GRANT_TYPES, true)) {
            return ['error' => 'Unsupported OAuth grant type.'];
        }

        $savedUrl = $this->getSavedInstanceUrl();
        $username = is_string($payload['username'] ?? null) ? trim($payload['username']) : '';
        $password = $this->resolveSavedSecret($payload['password'] ?? null, 'servicenow-password', $savedUrl);

        $credentials = $authType === ServiceNowCredentials::AUTH_OAUTH
            ? ServiceNowCredentials::oauth(
                $grantType,
                is_string($payload['clientId'] ?? null) ? trim($payload['clientId']) : '',
                $this->resolveSavedSecret($payload['clientSecret'] ?? null, 'servicenow-oauth-client-secret', $savedUrl),
                $username,
                $password
            )
            : ServiceNowCredentials::basic($username, $password);

        $missing = $credentials->getMissingFields();
        if ($instanceUrl === '') {
            array_unshift($missing, 'Instance URL');
        }
        if (!empty($missing)) {
            return ['error' => 'Missing required connection settings: ' . implode(', ', $missing) . '.'];
        }

        $normalizedUrl = ServiceNowApiClient::normalizeInstanceUrl($instanceUrl);
        if ($normalizedUrl === null) {
            return ['error' => 'Instance URL must be a valid https:// URL.'];
        }

        $savedSecrets = [];
        if ($credentials->isOAuth() && ($payload['clientSecret'] ?? null) === self::SAVED_SECRET_PLACEHOLDER) {
            $savedSecrets[] = 'client secret';
        }
        if ($credentials->usesUserCredentials() && ($payload['password'] ?? null) === self::SAVED_SECRET_PLACEHOLDER) {
            $savedSecrets[] = 'password';
        }
        if (!empty($savedSecrets) && $normalizedUrl !== ServiceNowApiClient::normalizeInstanceUrl($savedUrl)) {
            return [
                'error' => 'The saved ' . implode(' and ', $savedSecrets) . ' can only be used with the saved instance URL. '
                    . 'Enter ' . (count($savedSecrets) > 1 ? 'them' : 'it') . ' again to connect to a different instance.',
            ];
        }

        return ['instanceUrl' => $normalizedUrl, 'credentials' => $credentials];
    }

    /**
     * Returns a password or client secret from the configuration page, or the
     * value of $settingKey saved for $savedUrl when the page sent
     * SAVED_SECRET_PLACEHOLDER.
     *
     * @param mixed $value
     */
    private function resolveSavedSecret($value, string $settingKey, string $savedUrl): string
    {
        if ($value === self::SAVED_SECRET_PLACEHOLDER) {
            return $this->getSecretSetting($settingKey, $savedUrl);
        }
        return is_string($value) ? $value : '';
    }

    /** Returns the saved instance URL ('' when none is saved). */
    private function getSavedInstanceUrl(): string
    {
        return (string) ($this->framework->getProjectSetting('servicenow-instance-url') ?? '');
    }

    /**
     * Builds the credentials described by the project's saved connection
     * settings, for the instance URL the caller read: a password or client
     * secret saved for a different instance is treated as missing.
     */
    private function getSavedCredentials(string $instanceUrl): ServiceNowCredentials
    {
        $username = (string) ($this->framework->getProjectSetting('servicenow-username') ?? '');
        $password = $this->getSecretSetting('servicenow-password', $instanceUrl);

        if ((string) ($this->framework->getProjectSetting('servicenow-auth-type') ?? '') === ServiceNowCredentials::AUTH_OAUTH) {
            return ServiceNowCredentials::oauth(
                (string) ($this->framework->getProjectSetting('servicenow-oauth-grant-type') ?? ''),
                (string) ($this->framework->getProjectSetting('servicenow-oauth-client-id')  ?? ''),
                $this->getSecretSetting('servicenow-oauth-client-secret', $instanceUrl),
                $username,
                $password
            );
        }

        return ServiceNowCredentials::basic($username, $password);
    }

    /** Whether complete connection settings are saved for the project. */
    private function hasSavedConnection(): bool
    {
        $instanceUrl = $this->getSavedInstanceUrl();
        return $instanceUrl !== '' && $this->getSavedCredentials($instanceUrl)->getValidationError() === null;
    }

    /**
     * Describes the saved connection settings for the configuration page,
     * without revealing the password, client secret, or token.
     * `instanceBaseUrl` is the normalized instance URL (null when the saved URL
     * is not valid), which saved secrets are bound to.
     *
     * @return array{
     *   instanceUrl: string, instanceBaseUrl: ?string, authType: string, grantType: string, clientId: string,
     *   username: string, hasPassword: bool, hasClientSecret: bool, hasAnySettings: bool, isComplete: bool
     * }
     */
    public function getConnectionSummary(): array
    {
        $instanceUrl = $this->getSavedInstanceUrl();
        $credentials = $this->getSavedCredentials($instanceUrl);

        $hasAnySettings = false;
        foreach (self::CONNECTION_SETTING_KEYS as $key) {
            $value = $this->framework->getProjectSetting($key);
            if ($value !== null && $value !== '') {
                $hasAnySettings = true;
                break;
            }
        }

        return [
            'instanceUrl'     => $instanceUrl,
            'instanceBaseUrl' => ServiceNowApiClient::normalizeInstanceUrl($instanceUrl),
            // Projects configured before OAuth support was added have no
            // saved method and use basic authentication.
            'authType'        => $credentials->getAuthType(),
            // The client credentials grant is the default for new OAuth setups.
            'grantType'       => $credentials->getGrantType() === ServiceNowCredentials::GRANT_PASSWORD
                ? ServiceNowCredentials::GRANT_PASSWORD
                : ServiceNowCredentials::GRANT_CLIENT_CREDENTIALS,
            'clientId'        => $credentials->getClientId(),
            'username'        => $credentials->getUsername(),
            'hasPassword'     => $credentials->getPassword() !== '',
            'hasClientSecret' => $credentials->getClientSecret() !== '',
            // Includes a token cache left without other settings, so it can
            // still be deleted.
            'hasAnySettings'  => $hasAnySettings,
            'isComplete'      => $instanceUrl !== '' && $credentials->getValidationError() === null,
        ];
    }

    /**
     * Returns a secret project setting (password, client secret, or token
     * cache) decrypted, or '' when it is not set, cannot be decrypted, or was
     * saved for a different instance than $instanceUrl.
     *
     * Secrets are stored encrypted with REDCap's key for configuration
     * secrets, so anyone who can read the project's module settings without
     * that key (for example through the External Modules framework's settings
     * requests) gets only ciphertext. Each secret is encrypted together with
     * the instance it was saved for. The connection settings are separate
     * settings, so a request that reads them while an administrator is saving
     * new ones could otherwise pair one instance's URL with the other's
     * secret; with the binding, it finds no secret instead.
     *
     * A password or client secret stored in plain text by an earlier version
     * of the module still works, and is encrypted (for $instanceUrl, which the
     * caller read just before) the first time it is read.
     */
    private function getSecretSetting(string $key, string $instanceUrl): string
    {
        $value    = $this->framework->getProjectSetting($key);
        $instance = ServiceNowApiClient::normalizeInstanceUrl($instanceUrl);
        if (!is_string($value) || $value === '' || $instance === null) {
            return '';
        }

        if (strpos($value, self::ENCRYPTED_SECRET_PREFIX) !== 0) {
            if ($key === self::OAUTH_TOKEN_SETTING) {
                // The token cache is only ever written encrypted.
                return '';
            }
            try {
                // Skip the write if the settings were saved in the meantime.
                if ($this->framework->getProjectSetting($key) === $value) {
                    $this->framework->setProjectSetting($key, $this->encryptSecret($value, $instance));
                }
            } catch (\Throwable $e) {
                // Keep using it; it is encrypted when the settings are next saved.
            }
            return $value;
        }

        $decrypted = \System::decryptConfigSetting(substr($value, strlen(self::ENCRYPTED_SECRET_PREFIX)));
        $secret    = is_string($decrypted) ? json_decode($decrypted, true) : null;
        if (!is_array($secret) || ($secret['instance'] ?? null) !== $instance || !is_string($secret['value'] ?? null)) {
            return '';
        }
        return $secret['value'];
    }

    /**
     * Encrypts a secret, bound to the instance it belongs to, for storage in a
     * project setting (see getSecretSetting()).
     *
     * @throws \RuntimeException When the value cannot be encrypted.
     */
    private function encryptSecret(string $value, string $instanceUrl): string
    {
        $instance  = ServiceNowApiClient::normalizeInstanceUrl($instanceUrl);
        $json      = json_encode(['instance' => $instance, 'value' => $value]);
        $encrypted = ($instance !== null && is_string($json)) ? \System::encryptConfigSetting($json) : false;
        if (!is_string($encrypted) || $encrypted === '') {
            throw new \RuntimeException('The value could not be encrypted.');
        }
        return self::ENCRYPTED_SECRET_PREFIX . $encrypted;
    }

    private function getServiceNowFields(string $catalogItemId): array
    {
        if (!$this->hasSavedConnection()) {
            return ['success' => false, 'error' => 'Save the connection settings before loading fields.'];
        }
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
     * @return string[] Human-readable description of each attachment failure (empty when all succeeded).
     */
    private function processAttachments(
        array $attachmentFields,
        array $recordData,
        string $requestId,
        string $itemLabel,
        array $logContext = []
    ): array {
        $failures = [];

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
            return $failures;
        }

        if ($requestId === '') {
            $error = 'ServiceNow did not return a request ID, so files could not be attached.';
            $this->debug("[$itemLabel] Cannot upload attachments — missing request_id");
            $this->framework->log(self::ATTACHMENT_FAILURE_MESSAGE, array_merge($logContext, [
                'catalog_label' => $itemLabel,
                'error'         => $error,
            ]));
            $failures[] = $this->formatAttachmentFailureReason(null, null, $error, null);
            return $failures;
        }

        // Resolve the RITM sys_id once (all files attach to the same RITM).
        $client = $this->getApiClient();
        $ritm   = $this->resolveRequestItem($requestId);
        if (!($ritm['success'] ?? false)) {
            $error = 'Could not resolve request item: ' . ($ritm['error'] ?? 'unknown error');
            $this->debug("[$itemLabel] Could not resolve RITM sys_id for attachments", [
                'request_id' => $requestId,
                'error'      => $ritm['error'] ?? null,
            ]);
            $this->framework->log(self::ATTACHMENT_FAILURE_MESSAGE, array_merge($logContext, [
                'catalog_label' => $itemLabel,
                'http_code'     => $ritm['httpCode'] ?? null,
                'error'         => $error,
            ]));
            $failures[] = $this->formatAttachmentFailureReason(null, null, $error, $ritm['httpCode'] ?? null);
            return $failures;
        }
        $ritmSysId = (string) $ritm['sysId'];

        foreach ($pendingFields as $field => $edocId) {
            $contents = \REDCap::getFile($edocId);
            if (!is_array($contents) || count($contents) < 3) {
                $error = 'Could not read uploaded file (edoc ' . $edocId . ').';
                $this->debug("[$itemLabel] Could not read file contents for field '$field'", [
                    'edoc_id' => $edocId,
                ]);
                $this->framework->log(self::ATTACHMENT_FAILURE_MESSAGE, array_merge($logContext, [
                    'catalog_label' => $itemLabel,
                    'field'         => $field,
                    'error'         => $error,
                ]));
                $failures[] = $this->formatAttachmentFailureReason(null, $field, $error, null);
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

            $uploaded = (bool) ($upload['success'] ?? false);
            $this->framework->log(
                $uploaded ? 'ServiceNow attachment upload succeeded' : self::ATTACHMENT_FAILURE_MESSAGE,
                array_merge($logContext, [
                    'catalog_label' => $itemLabel,
                    'field'         => $field,
                    'file_name'     => (string) $fileName,
                    'ritm_sys_id'   => $ritmSysId,
                    'http_code'     => $upload['httpCode'] ?? null,
                    'error'         => $uploaded ? null : (string) ($upload['error'] ?? 'Unknown error.'),
                ])
            );

            if (!$uploaded) {
                $failures[] = $this->formatAttachmentFailureReason(
                    (string) $fileName,
                    $field,
                    $upload['error'] ?? null,
                    $upload['httpCode'] ?? null
                );
            }
        }

        return $failures;
    }

    /**
     * Returns the RITM (sys_id and number) for a submitted request, looking it
     * up at most once per request so the result-field write-back and the
     * attachment uploads share a single ServiceNow call.
     *
     * @return array{success: bool, sysId?: string, number?: string, error?: string, httpCode?: int}
     */
    private function resolveRequestItem(string $requestId): array
    {
        if ($requestId === '') {
            return ['success' => false, 'error' => 'ServiceNow did not return a request ID.'];
        }
        if (!isset($this->requestItemCache[$requestId])) {
            $this->requestItemCache[$requestId] = $this->getApiClient()->getRequestItem($requestId);
        }
        return $this->requestItemCache[$requestId];
    }

    /**
     * Returns a result-field mapping with exactly the keys in RESULT_FIELD_KEYS,
     * each a trimmed field name ('' = not mapped).
     *
     * @param mixed $value Decoded setting or AJAX payload.
     */
    private function normalizeResultFields($value): array
    {
        $value  = is_array($value) ? $value : [];
        $fields = [];
        foreach (self::RESULT_FIELD_KEYS as $key) {
            $fields[$key] = is_string($value[$key] ?? null) ? trim($value[$key]) : '';
        }
        return $fields;
    }

    /** True when at least one result field is mapped for the catalog item. */
    private function hasResultFields(array $item): bool
    {
        return count(array_filter((array) ($item['resultFields'] ?? []), static fn($f) => $f !== '')) > 0;
    }

    /**
     * Validates a result-field mapping before it is saved.
     *
     * @return string|null An error message, or null when the mapping is usable.
     */
    private function validateResultFields(array $resultFields): ?string
    {
        $labels = [
            'requestNumber' => 'Request Number',
            'ritmNumber'    => 'Request Item Number',
            'status'        => 'Submission Status',
        ];

        $project = $this->framework->getProject()->getREDCapProjectObject();
        $used    = [];
        foreach ($resultFields as $key => $field) {
            if ($field === '') {
                continue;
            }
            if (isset($used[$field])) {
                return 'The field "' . $field . '" is selected for more than one result. Choose a different field for each.';
            }
            $used[$field] = true;

            $problem = $this->getResultFieldProblem($field, $project);
            if ($problem !== null) {
                return ($labels[$key] ?? $key) . ' field: ' . $problem;
            }
        }
        return null;
    }

    /**
     * Explains why a field cannot hold submission results, or returns null.
     *
     * Result fields must be existing text fields without validation (or notes
     * fields), and never the record ID field.
     *
     * @param \Project $project REDCap project object.
     */
    private function getResultFieldProblem(string $field, $project): ?string
    {
        $attr = $project->metadata[$field] ?? null;
        if ($attr === null) {
            return '"' . $field . '" does not exist in this project.';
        }
        if ($field === $project->table_pk) {
            return '"' . $field . '" is the record ID field and cannot be used.';
        }
        $type       = (string) ($attr['element_type'] ?? '');
        $validation = trim((string) ($attr['element_validation_type'] ?? ''));
        if (!($type === 'textarea' || ($type === 'text' && $validation === ''))) {
            return '"' . $field . '" must be a text field with no validation, or a notes field.';
        }
        return null;
    }

    /**
     * Saves submission results (request number, RITM number, status) to the
     * catalog item's mapped result fields on the record that was saved.
     *
     * After a failure the status is set to "Failed" and the number fields are
     * cleared, so a receipt never shows a request from an earlier submission.
     * Problems are logged and returned rather than thrown, so the record save
     * is never interrupted.
     *
     * @return string|null A failure reason (already logged), or null on success / nothing to save.
     */
    private function saveResultsToRecord(
        array $item,
        $projectId,
        $record,
        $eventId,
        $instrument,
        $instance,
        bool $submitted,
        ?string $requestNumber,
        ?string $ritmNumber,
        array $logContext
    ): ?string {
        $fields = (array) ($item['resultFields'] ?? []);
        $values = [];
        if (($fields['requestNumber'] ?? '') !== '') {
            $values[$fields['requestNumber']] = $submitted ? (string) $requestNumber : '';
        }
        if (($fields['ritmNumber'] ?? '') !== '') {
            $values[$fields['ritmNumber']] = $submitted ? (string) $ritmNumber : '';
        }
        if (($fields['status'] ?? '') !== '') {
            $values[$fields['status']] = $submitted ? self::RESULT_STATUS_SUBMITTED : self::RESULT_STATUS_FAILED;
        }
        if (empty($values)) {
            return null;
        }

        $errors = [];
        try {
            $project = $this->framework->getProject((int) $projectId)->getREDCapProjectObject();
            [$data, $errors] = $this->buildResultData($project, (string) $record, (int) $eventId, (string) $instrument, (int) $instance, $values);

            if (!empty($data)) {
                $response = \REDCap::saveData([
                    'project_id'        => (int) $projectId,
                    'dataFormat'        => 'array',
                    'data'              => $data,
                    'overwriteBehavior' => 'overwrite', // allows clearing stale numbers after a failure
                ]);
                $saveErrors = $this->flattenMessages($response['errors'] ?? []);
                if (!empty($saveErrors)) {
                    $errors[] = 'REDCap rejected the update: ' . implode('; ', $saveErrors);
                }
            }
        } catch (\Throwable $e) {
            $errors[] = 'Unexpected error: ' . $e->getMessage();
        }

        if (empty($errors)) {
            return null;
        }

        $error = 'Could not save results to REDCap: ' . implode(' ', $errors);
        $this->framework->log(self::RESULT_SAVE_FAILURE_MESSAGE, array_merge($logContext, ['error' => $error]));
        return $error;
    }

    /**
     * Builds the REDCap::saveData() array for the result values, placing each
     * field in the right event/instance.
     *
     * Fields on the trigger form (or in a repeating event) are written to the
     * instance that was just saved. Fields on a different repeating
     * instrument are rejected because the instance to update is ambiguous.
     *
     * @param \Project $project REDCap project object.
     * @param array    $values  field name => value.
     * @return array{0: array, 1: string[]} [saveData array, error messages]
     */
    private function buildResultData($project, string $record, int $eventId, string $instrument, int $instance, array $values): array
    {
        $data     = [];
        $errors   = [];
        $instance = max(1, $instance);

        foreach ($values as $field => $value) {
            $problem = $this->getResultFieldProblem($field, $project);
            if ($problem !== null) {
                $errors[] = 'Field ' . $problem;
                continue;
            }

            $form = (string) $project->metadata[$field]['form_name'];
            if (!in_array($form, (array) ($project->eventsForms[$eventId] ?? []), true)) {
                $errors[] = 'Field "' . $field . '" is on the "' . $form . '" instrument, which is not used in this event.';
                continue;
            }

            if ($project->isRepeatingEvent($eventId)) {
                $data[$record]['repeat_instances'][$eventId][''][$instance][$field] = $value;
            } elseif ($project->isRepeatingForm($eventId, $form)) {
                if ($form !== $instrument) {
                    $errors[] = 'Field "' . $field . '" is on a repeating instrument ("' . $form . '") other than the trigger form, so the instance to update is ambiguous.';
                    continue;
                }
                $data[$record]['repeat_instances'][$eventId][$form][$instance][$field] = $value;
            } else {
                $data[$record][$eventId][$field] = $value;
            }
        }

        return [$data, $errors];
    }

    /**
     * Flattens a saveData() errors value (string, list, or nested list) into a
     * list of non-empty strings.
     *
     * @param mixed $messages
     * @return string[]
     */
    private function flattenMessages($messages): array
    {
        if (is_scalar($messages)) {
            $text = trim((string) $messages);
            return $text === '' ? [] : [$text];
        }
        $flat = [];
        foreach ((array) $messages as $message) {
            $flat = array_merge($flat, $this->flattenMessages($message));
        }
        return $flat;
    }

    /**
     * Formats a submission failure reason for display, e.g.
     * "Mandatory variables are missing (HTTP 400)".
     *
     * @param mixed $error    Error message (string or null).
     * @param mixed $httpCode HTTP status code, when the request reached ServiceNow.
     */
    private function formatFailureReason($error, $httpCode): string
    {
        $reason = trim((string) $error);
        if ($reason === '') {
            $reason = 'Unknown error.';
        }

        $code = (int) $httpCode;
        if ($code > 0 && strpos($reason, 'HTTP ' . $code) === false) {
            $reason .= ' (HTTP ' . $code . ')';
        }

        return $reason;
    }

    /**
     * Formats an attachment upload failure for display, identifying the file
     * (or field) that could not be attached when known.
     */
    private function formatAttachmentFailureReason(?string $fileName, ?string $field, $error, $httpCode): string
    {
        $reason   = $this->formatFailureReason($error, $httpCode);
        $fileName = trim((string) $fileName);
        $field    = trim((string) $field);

        if ($fileName !== '') {
            return 'Attachment "' . $fileName . '"' . ($field !== '' ? ' (' . $field . ')' : '') . ': ' . $reason;
        }
        if ($field !== '') {
            return 'Attachment field ' . $field . ': ' . $reason;
        }
        return 'Attachments: ' . $reason;
    }

    /**
     * Builds a link to a ServiceNow record in the classic UI, or null when the
     * instance URL or sys_id is missing.
     */
    private function buildServiceNowRecordUrl(string $instanceUrl, string $table, ?string $sysId): ?string
    {
        if ($instanceUrl === '' || $sysId === null || $sysId === '') {
            return null;
        }
        return $instanceUrl . '/now/nav/ui/classic/params/target/' . rawurlencode($table . '.do?sys_id=' . $sysId);
    }

    /**
     * Builds a "<column> in (?, ?, ...)" clause for queryLogs()/countLogs().
     */
    private function buildInClause(string $column, array $values): string
    {
        return $column . ' in (' . implode(', ', array_fill(0, max(1, count($values)), '?')) . ')';
    }

    /**
     * Emails the catalog item's failure notification recipients about a
     * failed submission (or, when $requestCreated is true, a request that was
     * created but whose follow-up steps — attachment uploads or saving results
     * back to the record — did not all succeed).
     *
     * Notifications never interrupt the save: any problem sending them is
     * caught and written to the module log instead.
     *
     * @param array       $item            Catalog item configuration (from getCatalogItems()).
     * @param array       $context         record, event_id, instrument, instance, catalog_label.
     * @param bool        $requestCreated  True when the RITM was created but a follow-up step failed.
     * @param string[]    $reasons         One human-readable failure reason per problem.
     * @param string|null $requestNumber   ServiceNow request number, when one was created.
     * @param string|null $ritmNumber      ServiceNow request item (RITM) number, when known.
     */
    private function sendFailureNotification(
        array $item,
        array $context,
        bool $requestCreated,
        array $reasons,
        ?string $requestNumber = null,
        ?string $ritmNumber = null
    ): void {
        $recipients = array_values(array_filter((array) ($item['failureEmails'] ?? []), 'is_string'));
        if (empty($recipients)) {
            return;
        }

        $logContext = [
            'record'        => $context['record'] ?? null,
            'event_id'      => $context['event_id'] ?? null,
            'instrument'    => $context['instrument'] ?? null,
            'instance'      => $context['instance'] ?? null,
            'catalog_label' => $context['catalog_label'] ?? null,
        ];

        try {
            $projectId = (int) $this->framework->getProjectId();
            [$subject, $body] = $this->buildFailureNotification($item, $context, $requestCreated, $reasons, $requestNumber, $ritmNumber);

            $contactEmail = (string) ($GLOBALS['project_contact_email'] ?? '');
            $from = method_exists('\Message', 'useDoNotReply') ? \Message::useDoNotReply($contactEmail) : $contactEmail;

            $failedRecipients = [];
            foreach ($recipients as $to) {
                if (!\REDCap::email($to, $from, $subject, $body, '', '', '', [], $projectId)) {
                    $failedRecipients[] = $to;
                }
            }

            if (!empty($failedRecipients)) {
                $this->framework->log(self::NOTIFICATION_FAILURE_MESSAGE, array_merge($logContext, [
                    'recipients' => implode(', ', $failedRecipients),
                    'error'      => 'REDCap could not send the email.',
                ]));
            }
        } catch (\Throwable $e) {
            try {
                $this->framework->log(self::NOTIFICATION_FAILURE_MESSAGE, array_merge($logContext, [
                    'recipients' => implode(', ', $recipients),
                    'error'      => $e->getMessage(),
                ]));
            } catch (\Throwable $ignored) {
                // Never let notification problems break the record save.
            }
        }
    }

    /**
     * Builds the subject and HTML body of a failure notification email.
     *
     * Only identifying context (project, catalog item, record ID, event,
     * form, instance) and the failure reason are included — never mapped
     * record values. Every interpolated value is HTML-escaped.
     *
     * @return array{0: string, 1: string} [subject, html body]
     */
    private function buildFailureNotification(
        array $item,
        array $context,
        bool $requestCreated,
        array $reasons,
        ?string $requestNumber,
        ?string $ritmNumber = null
    ): array {
        $projectId = (int) $this->framework->getProjectId();
        $record    = (string) ($context['record'] ?? '');
        $label     = (string) ($item['label'] ?? '');
        $esc       = static function ($value): string {
            return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        };

        $projectTitle = '';
        try {
            $projectTitle = (string) $this->framework->getProject($projectId)->getTitle();
        } catch (\Throwable $e) {
            // Fall back to the PID alone.
        }

        $instrument      = (string) ($context['instrument'] ?? '');
        $instrumentLabel = $instrument !== '' ? (string) (\REDCap::getInstrumentNames($instrument) ?: $instrument) : '';
        $eventLabel      = '';
        if (\REDCap::isLongitudinal() && !empty($context['event_id'])) {
            $eventLabel = (string) (\REDCap::getEventNames(false, true, (int) $context['event_id']) ?: '');
        }
        $instance = (int) ($context['instance'] ?? 1);

        $recordUrl = APP_PATH_WEBROOT_FULL . 'redcap_v' . REDCAP_VERSION . '/DataEntry/record_home.php?'
                   . http_build_query(['pid' => $projectId, 'id' => $record]);
        $logUrl    = $this->framework->getUrl('pages/config.php') . '#submission-log';

        $subject = ($requestCreated ? 'ServiceNow request created with errors: ' : 'ServiceNow submission failed: ')
                 . $label . ' (PID ' . $projectId . ')';

        $rows = [
            'Project'      => $esc($projectTitle !== '' ? "$projectTitle (PID $projectId)" : "PID $projectId"),
            'Catalog item' => $esc($label),
            'Record'       => '<a href="' . $esc($recordUrl) . '">' . $esc($record) . '</a>',
        ];
        if ($eventLabel !== '') {
            $rows['Event'] = $esc($eventLabel);
        }
        if ($instrumentLabel !== '') {
            $rows['Trigger form'] = $esc($instrumentLabel);
        }
        if ($instance > 1) {
            $rows['Instance'] = $esc($instance);
        }
        if ($requestCreated && $requestNumber !== null && $requestNumber !== '') {
            $rows['Request number'] = $esc($requestNumber);
        }
        if ($requestCreated && $ritmNumber !== null && $ritmNumber !== '') {
            $rows['Request item'] = $esc($ritmNumber);
        }
        $rows['Time'] = $esc(date('Y-m-d H:i:s'));

        $body  = '<p>' . ($requestCreated
            ? 'A ServiceNow request was created from a REDCap record, but one or more follow-up steps failed.'
            : 'A REDCap record could not be submitted to ServiceNow.') . '</p>';
        $body .= '<table cellpadding="4" cellspacing="0" border="0">';
        foreach ($rows as $name => $value) {
            $body .= '<tr><td style="vertical-align:top"><strong>' . $esc($name) . '</strong></td><td>' . $value . '</td></tr>';
        }
        $body .= '</table>';
        $body .= '<p><strong>' . (count($reasons) === 1 ? 'Failure reason' : 'Failure reasons') . ':</strong></p><ul>';
        foreach ($reasons as $reason) {
            $body .= '<li>' . $esc($reason) . '</li>';
        }
        $body .= '</ul>';
        $body .= '<p>' . ($requestCreated
            ? 'Saving the record again would create a new request. Instead, fix the problems above by hand &mdash; for example, '
            . 'attach the file(s) to the existing request in ServiceNow, or enter the request number in the REDCap record.'
            : 'Once the problem is resolved, you can retry by saving the trigger form again (if the push criteria still pass) '
            . 'or create the request in ServiceNow manually. If the failure was a timeout or connection error, check '
            . 'ServiceNow first &mdash; the request may have been created even though no confirmation was received.') . '</p>';
        $body .= '<p><a href="' . $esc($logUrl) . '">View the submission log</a></p>';

        return [$subject, $body];
    }

    /**
     * Returns a ServiceNowApiClient for the project's saved connection settings.
     *
     * One client is used per project for the rest of the request, so all the
     * catalog items, RITM lookups, and attachment uploads of a record save
     * share its OAuth token, and a token request that fails is not repeated
     * for each of them (see ServiceNowApiClient::TOKEN_FAILURE_BACKOFF).
     * Saving or deleting the connection settings starts over with a new client.
     */
    private function getApiClient(): ServiceNowApiClient
    {
        $projectId = (string) $this->framework->getProjectId();
        if (!isset($this->apiClients[$projectId])) {
            $this->apiClients[$projectId] = $this->createApiClient();
        }
        return $this->apiClients[$projectId];
    }

    /**
     * Creates a ServiceNowApiClient using the project's saved connection settings.
     *
     * With OAuth, the client caches its token (encrypted) in a project setting
     * so later requests (e.g. the next record save) reuse it until it expires
     * instead of requesting a new token every time.
     */
    private function createApiClient(): ServiceNowApiClient
    {
        $instanceUrl = $this->getSavedInstanceUrl();
        $credentials = $this->getSavedCredentials($instanceUrl);
        $fingerprint = (new ServiceNowApiClient($instanceUrl, $credentials))->getTokenFingerprint();

        return new ServiceNowApiClient(
            $instanceUrl,
            $credentials,
            function () use ($instanceUrl): ?array {
                $token = $this->decodeProjectSettingJson($this->getSecretSetting(self::OAUTH_TOKEN_SETTING, $instanceUrl), null);
                return is_array($token) ? $token : null;
            },
            function (?array $token) use ($fingerprint): void {
                // An administrator may have saved or deleted the connection
                // settings while this request was running. Only change the
                // cached token if the saved settings are still the ones this
                // client uses (and the token was issued for them).
                $savedUrl = $this->getSavedInstanceUrl();
                $saved    = new ServiceNowApiClient($savedUrl, $this->getSavedCredentials($savedUrl));
                if ($fingerprint !== $saved->getTokenFingerprint()
                    || ($token !== null && ($token['fingerprint'] ?? null) !== $fingerprint)
                ) {
                    return;
                }
                if ($token === null) {
                    $this->framework->removeProjectSetting(self::OAUTH_TOKEN_SETTING);
                } else {
                    $this->framework->setProjectSetting(self::OAUTH_TOKEN_SETTING, $this->encryptSecret(json_encode($token), $savedUrl));
                }
            }
        );
    }

    /**
     * Returns true when the criteria are satisfied by $recordData.
     *
     * $criteria is a list of groups; each group is a list of {field, operator,
     * value} objects. All criteria within a group must pass (AND), and at
     * least one group must fully pass (OR) — i.e. disjunction of conjunctions.
     * An empty criteria array is always satisfied (no conditions = always push).
     *
     * For backward compatibility, a flat array of criterion objects (the
     * pre-OR-group format) is treated as a single AND group.
     *
     * Supported operators: eq, neq, contains, not_empty, empty.
     *
     * @param array $criteria   Array of criterion groups (or, for legacy data, a flat array of criterion objects).
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
        if (empty($criteria)) {
            return true;
        }

        // Legacy format: a flat array of criterion objects (has 'field' directly
        // on the first element) rather than an array of groups.
        if (isset($criteria[0]) && is_array($criteria[0]) && array_key_exists('field', $criteria[0])) {
            $criteria = [$criteria];
        }

        foreach ($criteria as $group) {
            if (is_array($group) && !empty($group) && $this->groupMeetsCriteria($group, $recordData)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns true when every criterion in $criteria (a single AND group) is satisfied.
     */
    private function groupMeetsCriteria(array $criteria, array $recordData): bool
    {
        foreach ($criteria as $criterion) {
            $field    =          $criterion['field']    ?? '';
            $operator = (string) ($criterion['operator'] ?? 'eq');
            $expected = (string) ($criterion['value']    ?? '');

            if ($field === '') {
                continue;
            }

            $actual = $this->resolveCriterionFieldValue($field, $recordData);

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

    /**
     * Resolves the comparison value for a single trigger criterion field.
     *
     * Checkbox options are addressed as "<field>___<code>" (mirroring REDCap's
     * own export naming convention). Since REDCap::getData() with
     * return_format=array returns checkbox fields as an associative array of
     * choiceCode => '0'|'1', this looks up that specific choice's checked state.
     * Regular fields are returned as-is.
     *
     * @param string $field      Criterion field name, possibly a checkbox option.
     * @param array  $recordData Flat field→value map for the current record/event.
     */
    private function resolveCriterionFieldValue(string $field, array $recordData): string
    {
        $sep = strpos($field, '___');
        if ($sep !== false) {
            $baseField    = substr($field, 0, $sep);
            $choiceCode   = substr($field, $sep + 3);
            $checkboxData = $recordData[$baseField] ?? null;
            if (is_array($checkboxData)) {
                return (string) ($checkboxData[$choiceCode] ?? '0');
            }
            return '0';
        }

        $value = $recordData[$field] ?? '';
        return is_array($value) ? '' : (string) $value;
    }

}

<?php

namespace YaleREDCap\REDCapServiceNowCatalogIntegration;

/**
 * Client for the ServiceNow Service Catalog REST API.
 *
 * Handles authentication, request construction, and response parsing for the
 * three operations the module needs: testing connectivity, fetching catalog item
 * variables, and ordering a catalog item to create a Request Item (RITM).
 */
class ServiceNowApiClient
{
    private string $instanceUrl;
    private string $username;
    private string $password;
    private bool   $verifySsl;

    /**
     * @param string $instanceUrl  Base URL of the ServiceNow instance (e.g. https://XXXX.service-now.com).
     * @param string $username     API username.
     * @param string $password     API password.
     * @param bool   $verifySsl    Whether to verify the server's SSL certificate (default: true).
     */
    public function __construct(
        string $instanceUrl,
        string $username,
        string $password,
        bool   $verifySsl = true
    ) {
        $this->instanceUrl = rtrim(trim($instanceUrl), '/');
        $this->username    = $username;
        $this->password    = $password;
        $this->verifySsl   = $verifySsl;
    }

    // ── Public API ─────────────────────────────────────────────────────────────

    /**
     * Verifies that the credentials and instance URL are valid by fetching the
     * catalog list (a lightweight, always-available endpoint).
     *
     * @return array{success: bool, message?: string, error?: string, httpCode?: int}
     */
    public function testConnection(): array
    {
        $result = $this->get('/api/sn_sc/servicecatalog/catalogs?sysparm_limit=1');

        if ($result['success']) {
            return ['success' => true, 'message' => 'Connection successful.'];
        }

        return $result;
    }

    /**
     * Returns the variables (fields) defined on a catalog item.
     *
     * @param  string $catalogItemSysId  32-character hexadecimal sys_id of the catalog item.
     * @return array{success: bool, data?: list<array>, error?: string, httpCode?: int}
     *         On success, `data` is the flat array of variable objects returned by ServiceNow.
     */
    public function getCatalogItemVariables(string $catalogItemSysId): array
    {
        $sysId = $this->sanitizeSysId($catalogItemSysId);
        if ($sysId === '') {
            return ['success' => false, 'error' => 'Invalid catalog item sys_id.'];
        }

        return $this->get('/api/sn_sc/servicecatalog/items/' . $sysId . '/variables');
    }

    /**
     * Creates a new Request Item (RITM) by ordering the specified catalog item.
     *
     * Uses the ServiceNow `order_now` endpoint:
     *   POST /api/sn_sc/servicecatalog/items/{sys_id}/order_now
     *
     * @param  string $catalogItemSysId  32-character hexadecimal sys_id of the catalog item.
     * @param  array  $variables         Map of variable name → value to populate on the RITM.
     * @param  int    $quantity          Number of items to request (default: 1).
     * @return array{
     *   success: bool,
     *   data?: array{
     *     request_number?: string,
     *     request_id?: array{value: string, link: string},
     *     request_item_id?: array{value: string, link: string}
     *   },
     *   error?: string,
     *   httpCode?: int
     * }
     */
    public function submitRitm(string $catalogItemSysId, array $variables, int $quantity = 1): array
    {
        $sysId = $this->sanitizeSysId($catalogItemSysId);
        if ($sysId === '') {
            return ['success' => false, 'error' => 'Invalid catalog item sys_id.'];
        }

        return $this->post(
            '/api/sn_sc/servicecatalog/items/' . $sysId . '/order_now',
            [
                'sysparm_quantity' => (string) $quantity,
                'variables'        => $variables,
            ]
        );
    }

    /**
     * Resolves the Request Item (RITM) sys_id belonging to a submitted request.
     *
     * The `order_now` response returns the sys_id of the parent request
     * (sc_request). Attachments must be posted against the request *item*
     * (sc_req_item), so this looks up the RITM linked to that request via the
     * Table API.
     *
     * @param  string $requestSysId  sys_id of the sc_request record (order_now `request_id`).
     * @return array{success: bool, sysId?: string, error?: string, httpCode?: int}
     */
    public function getRequestItemSysId(string $requestSysId): array
    {
        $id = $this->sanitizeSysId($requestSysId);
        if ($id === '') {
            return ['success' => false, 'error' => 'Invalid request sys_id.'];
        }

        $result = $this->get(
            '/api/now/table/sc_req_item'
            . '?sysparm_query=' . rawurlencode('request=' . $id)
            . '&sysparm_fields=sys_id'
            . '&sysparm_limit=1'
        );

        if (!$result['success']) {
            return $result;
        }

        $rows  = is_array($result['data'] ?? null) ? $result['data'] : [];
        $sysId = isset($rows[0]['sys_id']) ? (string) $rows[0]['sys_id'] : '';
        if ($sysId === '') {
            return ['success' => false, 'error' => 'No request item found for request ' . $id . '.'];
        }

        return ['success' => true, 'sysId' => $sysId];
    }

    /**
     * Uploads a binary file as an attachment on a target record.
     *
     * Uses the ServiceNow Attachment API:
     *   POST /api/now/attachment/file?table_name=...&table_sys_id=...&file_name=...
     * with the raw file bytes as the request body.
     *
     * @param  string $tableSysId  sys_id of the record to attach the file to.
     * @param  string $fileName    Name to store the attachment under.
     * @param  string $mimeType    MIME type of the file (used as Content-Type).
     * @param  string $binaryData  Raw binary contents of the file.
     * @param  string $tableName   Target table (default: sc_req_item).
     * @return array{success: bool, data?: mixed, error?: string, httpCode?: int}
     */
    public function uploadAttachment(
        string $tableSysId,
        string $fileName,
        string $mimeType,
        string $binaryData,
        string $tableName = 'sc_req_item'
    ): array {
        $sysId = $this->sanitizeSysId($tableSysId);
        if ($sysId === '') {
            return ['success' => false, 'error' => 'Invalid table sys_id.'];
        }
        if ($fileName === '') {
            return ['success' => false, 'error' => 'Attachment file name is required.'];
        }

        $query = http_build_query([
            'table_name'   => $tableName,
            'table_sys_id' => $sysId,
            'file_name'    => $fileName,
        ]);

        return $this->requestBinary(
            'POST',
            '/api/now/attachment/file?' . $query,
            $binaryData,
            $mimeType !== '' ? $mimeType : 'application/octet-stream'
        );
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    private function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    private function post(string $path, array $payload): array
    {
        return $this->request('POST', $path, $payload);
    }

    /**
     * Executes an HTTP request against the ServiceNow instance.
     *
     * @param  string     $method   HTTP verb (GET, POST, PUT, PATCH, DELETE).
     * @param  string     $path     Path relative to the instance base URL; must start with /.
     * @param  array|null $payload  Request body; encoded as JSON when provided.
     * @return array{success: bool, data?: mixed, error?: string, httpCode?: int}
     */
    private function request(string $method, string $path, ?array $payload = null): array
    {
        if (!function_exists('curl_init')) {
            return ['success' => false, 'error' => 'cURL is not available on this server.'];
        }

        if (!preg_match('#^https://#i', $this->instanceUrl)) {
            return ['success' => false, 'error' => 'Instance URL must use HTTPS (https://).'];
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $this->instanceUrl . $path,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Basic ' . base64_encode($this->username . ':' . $this->password),
                'Accept: application/json',
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr !== '') {
            return ['success' => false, 'error' => 'Connection failed: ' . $curlErr];
        }

        $decoded = json_decode($response, true);

        if ($httpCode >= 200 && $httpCode < 300) {
            return [
                'success'  => true,
                'data'     => $decoded['result'] ?? $decoded,
                'httpCode' => $httpCode,
            ];
        }

        $errMsg = $decoded['error']['message']
               ?? $decoded['error']['detail']
               ?? ('Unexpected HTTP ' . $httpCode);

        return ['success' => false, 'error' => $errMsg, 'httpCode' => $httpCode];
    }

    /**
     * Executes an HTTP request that sends a raw (non-JSON) body, such as a
     * binary file upload. Mirrors request() but sets a caller-supplied
     * Content-Type and passes the body through untouched.
     *
     * @param  string $method       HTTP verb.
     * @param  string $path         Path relative to the instance base URL; must start with /.
     * @param  string $body         Raw request body (e.g. binary file contents).
     * @param  string $contentType  Value for the Content-Type header.
     * @return array{success: bool, data?: mixed, error?: string, httpCode?: int}
     */
    private function requestBinary(string $method, string $path, string $body, string $contentType): array
    {
        if (!function_exists('curl_init')) {
            return ['success' => false, 'error' => 'cURL is not available on this server.'];
        }

        if (!preg_match('#^https://#i', $this->instanceUrl)) {
            return ['success' => false, 'error' => 'Instance URL must use HTTPS (https://).'];
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $this->instanceUrl . $path,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Basic ' . base64_encode($this->username . ':' . $this->password),
                'Accept: application/json',
                'Content-Type: ' . $contentType,
            ],
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr !== '') {
            return ['success' => false, 'error' => 'Connection failed: ' . $curlErr];
        }

        $decoded = json_decode($response, true);

        if ($httpCode >= 200 && $httpCode < 300) {
            return [
                'success'  => true,
                'data'     => $decoded['result'] ?? $decoded,
                'httpCode' => $httpCode,
            ];
        }

        $errMsg = $decoded['error']['message']
               ?? $decoded['error']['detail']
               ?? ('Unexpected HTTP ' . $httpCode);

        return ['success' => false, 'error' => $errMsg, 'httpCode' => $httpCode];
    }

    /** Strips any character that is not a lowercase/uppercase hex digit. */
    private function sanitizeSysId(string $sysId): string
    {
        return preg_replace('/[^a-f0-9]/i', '', $sysId) ?? '';
    }
}

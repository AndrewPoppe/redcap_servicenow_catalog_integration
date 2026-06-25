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

    /** Strips any character that is not a lowercase/uppercase hex digit. */
    private function sanitizeSysId(string $sysId): string
    {
        return preg_replace('/[^a-f0-9]/i', '', $sysId) ?? '';
    }
}

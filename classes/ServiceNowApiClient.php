<?php

namespace YaleREDCap\REDCapServiceNowCatalogIntegration;

/**
 * Client for the ServiceNow Service Catalog REST API.
 *
 * Handles authentication, request construction, and response parsing for the
 * operations the module needs: testing connectivity, fetching catalog item
 * variables, ordering a catalog item to create a Request Item (RITM), looking
 * up the RITM, and attaching files to it.
 *
 * Requests authenticate with HTTP Basic or with an OAuth 2.0 bearer token, as
 * described by the ServiceNowCredentials passed in. OAuth tokens are obtained
 * from the instance's token endpoint, reused until shortly before they expire
 * (optionally across requests, via the token store callbacks), and renewed
 * with the refresh token when ServiceNow issued one.
 *
 * Requests are only ever sent to the instance URL's host: redirects are not
 * followed.
 */
class ServiceNowApiClient
{
    /** ServiceNow's OAuth 2.0 token endpoint. */
    private const TOKEN_PATH = '/oauth_token.do';

    /**
     * Seconds before expiry at which a token stops being used, so a token
     * never lapses while a request is in flight. Tokens with a lifetime of
     * less than twice this are used for half their lifetime.
     */
    private const TOKEN_EXPIRY_MARGIN = 60;

    /** Token lifetime (seconds) assumed when ServiceNow does not report one. */
    private const DEFAULT_TOKEN_LIFETIME = 300;

    /** Request timeout (seconds) for JSON API calls and token requests. */
    private const REQUEST_TIMEOUT = 15;

    /** Request timeout (seconds) for file uploads. */
    private const UPLOAD_TIMEOUT = 60;

    /**
     * Seconds for which a failure to obtain an OAuth token is reported again
     * without another token request. Requests made through the same client
     * soon after a failure would almost certainly fail the same way, and with
     * the password grant each attempt is a failed sign-in for the account.
     */
    private const TOKEN_FAILURE_BACKOFF = 60;

    private string $instanceUrl;
    private ServiceNowCredentials $credentials;
    private ?\Closure $loadToken;
    private ?\Closure $saveToken;
    private bool $verifySsl;

    /**
     * The OAuth token currently in use, or null when none is known:
     * {access_token: string, expires_at: int, renew_at: int, refresh_token: string, fingerprint: string}.
     * The token is used until renew_at, shortly before it expires at expires_at.
     * After a token is rejected, access_token is '' while refresh_token is kept.
     */
    private ?array $token = null;

    /** Whether the token store has been consulted yet. */
    private bool $tokenLoaded = false;

    /** The result of the last failed token request, reused until $tokenFailureUntil. */
    private ?array $tokenFailure = null;
    private int $tokenFailureUntil = 0;

    /**
     * Whether a request retried with a newly issued token was refused with
     * HTTP 401 again. ServiceNow then refuses it for another reason (such as
     * a REST API access policy), so later 401s are not retried.
     */
    private bool $retryRefused = false;

    /**
     * @param string                $instanceUrl  Base URL of the ServiceNow instance (e.g. https://XXXX.service-now.com).
     * @param ServiceNowCredentials $credentials  How to authenticate (Basic or OAuth 2.0).
     * @param callable|null         $loadToken    OAuth only: returns the token saved by $saveToken (or null).
     *                                            Omit (with $saveToken) to keep tokens in memory for this client only.
     * @param callable|null         $saveToken    OAuth only: persists a token array, or forgets it when passed null.
     * @param bool                  $verifySsl    Whether to verify the server's SSL certificate (default: true).
     */
    public function __construct(
        string $instanceUrl,
        ServiceNowCredentials $credentials,
        ?callable $loadToken = null,
        ?callable $saveToken = null,
        bool $verifySsl = true
    ) {
        $this->instanceUrl = rtrim(trim($instanceUrl), '/');
        $this->credentials = $credentials;
        $this->loadToken   = $loadToken !== null ? \Closure::fromCallable($loadToken) : null;
        $this->saveToken   = $saveToken !== null ? \Closure::fromCallable($saveToken) : null;
        $this->verifySsl   = $verifySsl;
    }

    // ── Public API ─────────────────────────────────────────────────────────────

    /**
     * Verifies that the credentials and instance URL are valid by fetching the
     * catalog list (a lightweight, always-available endpoint).
     *
     * Submissions also look up the request item (RITM) through the Table API.
     * ServiceNow can let a connection use some APIs but not others (for
     * example, an OAuth application limited to its own scope), so a request
     * item lookup is tried as well. When only that lookup fails, the test
     * still succeeds, with a warning.
     *
     * @return array{success: bool, message?: string, warning?: string, error?: string, httpCode?: int}
     */
    public function testConnection(): array
    {
        $result = $this->get('/api/sn_sc/servicecatalog/catalogs?sysparm_limit=1');
        if (!$result['success']) {
            return $result;
        }

        $response = ['success' => true, 'message' => 'Connection successful.'];

        $lookup = $this->get('/api/now/table/sc_req_item?sysparm_fields=sys_id&sysparm_limit=1');
        if (!$lookup['success']) {
            $code   = (int) ($lookup['httpCode'] ?? 0);
            $reason = rtrim(trim((string) ($lookup['error'] ?? '')), '.');
            if ($reason === '') {
                $reason = 'Unknown error';
            }
            if ($code > 0 && strpos($reason, 'HTTP ' . $code) === false) {
                $reason .= ' (HTTP ' . $code . ')';
            }

            // A 4xx answer is a refusal that will repeat for submissions; no
            // answer, a 5xx answer, or rate limiting may be temporary.
            $response['warning'] = ($code >= 400 && $code < 500 && $code !== 429)
                ? 'Connected, but ServiceNow refused a request item lookup through the Table API: ' . $reason
                    . '. Requests can still be submitted, but their RITM numbers cannot be logged or saved'
                    . ' to REDCap, and files cannot be attached to them.'
                : 'Connected, but a request item lookup through the Table API did not complete: ' . $reason
                    . '. This may be temporary; test the connection again.';
        }

        return $response;
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
                'variables'        => $this->sanitizeVariables($variables),
            ]
        );
    }

    /**
     * Looks up the Request Item (RITM) belonging to a submitted request.
     *
     * The `order_now` response returns only the parent request (sc_request).
     * Attachments must be posted against the request *item* (sc_req_item),
     * and the RITM number is useful as a receipt, so this looks up the RITM
     * linked to that request via the Table API.
     *
     * @param  string $requestSysId  sys_id of the sc_request record (order_now `request_id`).
     * @return array{success: bool, sysId?: string, number?: string, error?: string, httpCode?: int}
     *         On success, `sysId` is the RITM sys_id and `number` its RITM number (e.g. RITM0012345).
     */
    public function getRequestItem(string $requestSysId): array
    {
        $id = $this->sanitizeSysId($requestSysId);
        if ($id === '') {
            return ['success' => false, 'error' => 'Invalid request sys_id.'];
        }

        $result = $this->get(
            '/api/now/table/sc_req_item'
            . '?sysparm_query=' . rawurlencode('request=' . $id)
            . '&sysparm_fields=sys_id,number'
            . '&sysparm_limit=1'
        );

        if (!$result['success']) {
            return $result;
        }

        $rows  = is_array($result['data'] ?? null) ? $result['data'] : [];
        $sysId = isset($rows[0]['sys_id']) && is_scalar($rows[0]['sys_id']) ? (string) $rows[0]['sys_id'] : '';
        if ($sysId === '') {
            return ['success' => false, 'error' => 'No request item found for request ' . $id . '.'];
        }

        return [
            'success' => true,
            'sysId'   => $sysId,
            'number'  => isset($rows[0]['number']) && is_scalar($rows[0]['number']) ? (string) $rows[0]['number'] : '',
        ];
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

        return $this->authorizedRequest(
            'POST',
            '/api/now/attachment/file?' . $query,
            $binaryData,
            $mimeType !== '' ? $mimeType : 'application/octet-stream',
            self::UPLOAD_TIMEOUT
        );
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    /**
     * Returns a validated copy of a RITM variable map that is safe to place in
     * a request body.
     *
     * Variable names must match ServiceNow's variable-name format and values
     * must be scalar (control characters are stripped); anything else is
     * dropped. The request destination itself is never derived from this data:
     * URLs are built by buildUrl() from the validated instance host, fixed API
     * paths, and hex-sanitized sys_ids only, so the body cannot redirect the
     * request. That makes this the sanctioned taint boundary for record values
     * intentionally sent to ServiceNow.
     *
     */
    private function sanitizeVariables(array $variables): array
    {
        $clean = [];
        foreach ($variables as $name => $value) {
            if (!is_string($name) || !preg_match('/^[a-zA-Z0-9_.\-]{1,250}$/', $name)) {
                continue;
            }
            if (!is_scalar($value)) {
                continue;
            }
            $clean[$name] = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $value);
        }
        return $clean;
    }

    /**
     * Returns the base URL that requests for an instance URL are sent to
     * (https://host, plus the port when it is not 443), or null when the
     * instance URL is not a valid https URL.
     *
     * Only the host (and optional port) of the instance URL is used, so
     * paths, queries, or fragments cannot redirect requests to an unintended
     * destination; a URL with embedded credentials is rejected. The host is
     * lowercased, so two instance URLs that reach the same instance return
     * the same base URL.
     *
     * The rules are deliberately strict (a host name, and a port of digits
     * only) and are mirrored by normalizeInstanceUrl() in pages/config.php,
     * so the configuration page knows exactly which URLs the server treats as
     * the same instance.
     */
    public static function normalizeInstanceUrl(string $instanceUrl): ?string
    {
        $url = rtrim(trim($instanceUrl), '/');

        // The scheme must be https; the authority runs up to the first '/', '?' or '#'.
        if (!preg_match('~^https://([^/?#]*)~i', $url, $match)
            || preg_match('/\s/', $match[1])
            || !preg_match('/^([^:@]*)(?::([^:@]*))?$/', $match[1], $authority)
            || !preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]*[a-zA-Z0-9])?)+$/', $authority[1])
        ) {
            return null;
        }

        $port = 443;
        if (($authority[2] ?? '') !== '') {
            if (!preg_match('/^[0-9]{1,5}$/', $authority[2]) || (int) $authority[2] < 1 || (int) $authority[2] > 65535) {
                return null;
            }
            $port = (int) $authority[2];
        }

        return 'https://' . strtolower($authority[1]) . ($port !== 443 ? ':' . $port : '');
    }

    /**
     * Builds the absolute request URL from the validated instance host and a
     * module-supplied path.
     *
     * @return string|null Null when the instance URL is not a valid https URL.
     */
    private function buildUrl(string $path): ?string
    {
        $baseUrl = self::normalizeInstanceUrl($this->instanceUrl);
        return $baseUrl === null ? null : $baseUrl . $path;
    }

    /** The error reported when buildUrl() cannot build a URL. */
    private function getInstanceUrlError(): string
    {
        return $this->instanceUrl === ''
            ? 'The ServiceNow instance URL is not set.'
            : 'Instance URL must be a valid https:// URL.';
    }

    private function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    private function post(string $path, array $payload): array
    {
        return $this->request('POST', $path, $payload);
    }

    /**
     * Executes a JSON API request against the ServiceNow instance.
     *
     * @param  string     $method   HTTP verb (GET, POST, PUT, PATCH, DELETE).
     * @param  string     $path     Path relative to the instance base URL; must start with /.
     * @param  array|null $payload  Request body; encoded as JSON when provided.
     * @return array{success: bool, data?: mixed, error?: string, httpCode?: int}
     */
    private function request(string $method, string $path, ?array $payload = null): array
    {
        return $this->authorizedRequest(
            $method,
            $path,
            $payload !== null ? json_encode($payload) : null,
            'application/json',
            self::REQUEST_TIMEOUT
        );
    }

    /**
     * Executes an authenticated API request.
     *
     * With OAuth, the token may have been revoked or may have expired on the
     * ServiceNow side sooner than the module expected. ServiceNow rejects such
     * a request with HTTP 401 before processing it, so after a 401 the token
     * is discarded and a token is requested again. If ServiceNow issues a
     * different token, the request is retried once with it. If it returns the
     * same token, it still considers that token valid, so the request was
     * refused for another reason and is not retried; the same applies to later
     * requests once a retry with a new token has also been refused. Retrying
     * is safe even for order_now and attachment uploads because a rejected
     * request has no effect.
     *
     * @param  string      $method       HTTP verb.
     * @param  string      $path         Path relative to the instance base URL; must start with /.
     * @param  string|null $body         Raw request body, or null for none.
     * @param  string      $contentType  Value for the Content-Type header.
     * @param  int         $timeout      Request timeout in seconds.
     * @return array{success: bool, data?: mixed, error?: string, httpCode?: int}
     */
    private function authorizedRequest(string $method, string $path, ?string $body, string $contentType, int $timeout): array
    {
        $url = $this->buildUrl($path);
        if ($url === null) {
            return ['success' => false, 'error' => $this->getInstanceUrlError()];
        }

        $credentialError = $this->credentials->getValidationError();
        if ($credentialError !== null) {
            return ['success' => false, 'error' => $credentialError];
        }

        $auth = $this->getAuthorization();
        if (!$auth['success']) {
            return $auth['failure'];
        }

        $result = $this->send($method, $url, $body, $contentType, $auth['header'], $timeout);

        if (($result['httpCode'] ?? null) === 401 && $this->credentials->isOAuth() && !$this->retryRefused) {
            $rejectedToken = $auth['accessToken'];
            $this->discardAccessToken();

            $auth = $this->getAuthorization();
            if (!$auth['success']) {
                return $auth['failure'];
            }
            if ($auth['accessToken'] !== $rejectedToken) {
                $result             = $this->send($method, $url, $body, $contentType, $auth['header'], $timeout);
                $this->retryRefused = ($result['httpCode'] ?? null) === 401;
            }
        }

        return $result;
    }

    /**
     * Returns the Authorization header to send with an API request.
     *
     * With OAuth, `accessToken` is the token the header carries; `failure`
     * holds the error result when no token could be obtained. After a failed
     * token request, the same failure is returned for TOKEN_FAILURE_BACKOFF
     * seconds without asking ServiceNow again.
     *
     * @return array{success: bool, header?: string, accessToken?: string, failure?: array}
     */
    private function getAuthorization(): array
    {
        if (!$this->credentials->isOAuth()) {
            return ['success' => true, 'header' => $this->credentials->getBasicAuthorizationHeader(), 'accessToken' => ''];
        }

        $token = $this->getUsableToken();
        if ($token === null) {
            if ($this->tokenFailure !== null && time() < $this->tokenFailureUntil) {
                return ['success' => false, 'failure' => $this->tokenFailure];
            }
            $issued = $this->obtainToken();
            if (!$issued['success']) {
                $this->tokenFailure      = $issued['failure'];
                $this->tokenFailureUntil = time() + self::TOKEN_FAILURE_BACKOFF;
                return ['success' => false, 'failure' => $issued['failure']];
            }
            $this->tokenFailure = null;
            $token              = $issued['token'];
        }

        return [
            'success'     => true,
            'header'      => 'Authorization: Bearer ' . $token['access_token'],
            'accessToken' => $token['access_token'],
        ];
    }

    // ── OAuth token management ─────────────────────────────────────────────

    /**
     * Returns the current OAuth token if it can still be used, loading it from
     * the token store the first time.
     */
    private function getUsableToken(): ?array
    {
        if (!$this->tokenLoaded) {
            $this->tokenLoaded = true;
            $this->token       = $this->loadStoredToken();
        }

        $token = $this->token;
        if ($token === null || $token['access_token'] === '' || $token['renew_at'] <= time()) {
            return null;
        }
        return $token;
    }

    /**
     * Obtains a new access token, preferring the refresh token (so the service
     * account password is not sent again) and falling back to the configured
     * grant when there is no refresh token or ServiceNow refuses it.
     *
     * When the refresh request gets no response at all (the instance cannot
     * be reached or does not answer in time), the failure is returned
     * without trying the configured grant, which would only wait for the
     * same timeout again. A refresh token that ServiceNow refuses (HTTP 400
     * or 401) is forgotten, so it is not tried again; after other errors,
     * such as an outage or rate limiting, it is kept for later.
     *
     * @return array{success: bool, token?: array, failure?: array}
     */
    private function obtainToken(): array
    {
        $refreshToken = $this->token['refresh_token'] ?? '';
        if ($refreshToken !== '') {
            $refreshed = $this->requestToken(
                $this->credentials->getRefreshRequestParameters($refreshToken),
                $refreshToken
            );
            if ($refreshed['success'] || !$refreshed['responded']) {
                return $refreshed;
            }
            if (in_array($refreshed['failure']['httpCode'] ?? null, [400, 401], true)) {
                $this->setToken(null);
            }
        }

        return $this->requestToken($this->credentials->getTokenRequestParameters(), '');
    }

    /**
     * Requests a token from ServiceNow's token endpoint and, on success, makes
     * it the current token and saves it to the token store.
     *
     * On failure, `responded` tells whether ServiceNow sent an HTTP response.
     *
     * @param  array<string, string> $params               Form parameters (grant_type, client_id, ...).
     * @param  string                $currentRefreshToken  Refresh token to keep if the response does not include a new one.
     * @return array{success: bool, token?: array, failure?: array, responded?: bool}
     */
    private function requestToken(array $params, string $currentRefreshToken): array
    {
        $url = $this->buildUrl(self::TOKEN_PATH);
        if ($url === null) {
            return [
                'success'   => false,
                'failure'   => ['success' => false, 'error' => $this->getInstanceUrlError()],
                'responded' => false,
            ];
        }

        $result = $this->send(
            'POST',
            $url,
            http_build_query($params, '', '&'),
            'application/x-www-form-urlencoded',
            null,
            self::REQUEST_TIMEOUT
        );

        $data        = ($result['success'] && is_array($result['data'] ?? null)) ? $result['data'] : [];
        $accessToken = (isset($data['access_token']) && is_string($data['access_token'])) ? $data['access_token'] : '';

        if ($accessToken === '' || !self::isValidAccessToken($accessToken)) {
            if ($accessToken !== '') {
                $reason = 'ServiceNow returned an access token in an unexpected format.';
            } elseif ($result['success']) {
                $reason = 'ServiceNow did not return an access token.';
            } else {
                $reason = (string) ($result['error'] ?? 'Unknown error.');
            }
            $code = $result['success'] ? null : ($result['httpCode'] ?? null);

            $message = 'Could not obtain an OAuth access token from ServiceNow: ' . rtrim($reason, '.') . '.';
            if ($code === 400 || $code === 401) {
                // ServiceNow usually reports a generic "access_denied", so
                // point at the settings that most often cause it.
                $message .= $this->credentials->usesUserCredentials()
                    ? ' Check the client ID, client secret, username, and password, and that the password grant is allowed on the ServiceNow instance.'
                    : ' Check the client ID and client secret, that the client credentials grant is enabled on the ServiceNow instance, and that the OAuth application has an OAuth Application User.';
            }

            $failure = ['success' => false, 'error' => $message];
            if ($code !== null) {
                $failure['httpCode'] = $code;
            }
            return ['success' => false, 'failure' => $failure, 'responded' => isset($result['httpCode'])];
        }

        $expiresIn = (isset($data['expires_in']) && is_numeric($data['expires_in'])) ? (int) $data['expires_in'] : 0;
        if ($expiresIn <= 0) {
            $expiresIn = self::DEFAULT_TOKEN_LIFETIME;
        }

        $refreshToken = (isset($data['refresh_token']) && is_string($data['refresh_token']) && $data['refresh_token'] !== '')
            ? $data['refresh_token']
            : $currentRefreshToken;

        $now = time();
        $this->setToken([
            'access_token'  => $accessToken,
            'expires_at'    => $now + $expiresIn,
            'renew_at'      => $now + $expiresIn - min(self::TOKEN_EXPIRY_MARGIN, intdiv($expiresIn, 2)),
            'refresh_token' => $refreshToken,
            'fingerprint'   => $this->getTokenFingerprint(),
        ]);

        return ['success' => true, 'token' => $this->token];
    }

    /**
     * Whether $token is made up only of the bearer token characters allowed
     * by RFC 6750 (which also covers JWTs). Access tokens are placed in an
     * Authorization header, so anything else, such as a line break, is
     * rejected.
     */
    private static function isValidAccessToken(string $token): bool
    {
        return preg_match('/\A[A-Za-z0-9\-._~+\/]+=*\z/', $token) === 1;
    }

    /**
     * Forgets the current access token (after ServiceNow rejected it) while
     * keeping its refresh token, which may still be valid.
     */
    private function discardAccessToken(): void
    {
        $refreshToken = $this->token['refresh_token'] ?? '';
        $this->setToken($refreshToken === '' ? null : [
            'access_token'  => '',
            'expires_at'    => 0,
            'renew_at'      => 0,
            'refresh_token' => $refreshToken,
            'fingerprint'   => $this->getTokenFingerprint(),
        ]);
    }

    /** Makes $token the current token and saves it to the token store. */
    private function setToken(?array $token): void
    {
        $this->token       = $token;
        $this->tokenLoaded = true;

        if ($this->saveToken === null) {
            return;
        }
        try {
            ($this->saveToken)($token);
        } catch (\Throwable $e) {
            // The token is still used for the rest of this request; it just
            // will not be reused by later ones.
        }
    }

    /**
     * Reads the token saved in the token store, ignoring anything malformed
     * or issued for different connection settings.
     */
    private function loadStoredToken(): ?array
    {
        if ($this->loadToken === null) {
            return null;
        }
        try {
            $stored = ($this->loadToken)();
        } catch (\Throwable $e) {
            return null;
        }

        if (!is_array($stored)
            || ($stored['fingerprint'] ?? null) !== $this->getTokenFingerprint()
            || !is_string($stored['access_token'] ?? null)
            || !is_string($stored['refresh_token'] ?? null)
            || !is_int($stored['expires_at'] ?? null)
            || !is_int($stored['renew_at'] ?? null)
            // An empty access token means only the refresh token is left.
            || ($stored['access_token'] !== '' && !self::isValidAccessToken($stored['access_token']))
        ) {
            return null;
        }

        return [
            'access_token'  => $stored['access_token'],
            'expires_at'    => $stored['expires_at'],
            'renew_at'      => $stored['renew_at'],
            'refresh_token' => $stored['refresh_token'],
            'fingerprint'   => $stored['fingerprint'],
        ];
    }

    /**
     * Identifies the connection settings that tokens issued to this client
     * belong to: the instance and the non-secret parts of the credentials.
     */
    public function getTokenFingerprint(): string
    {
        return $this->credentials->getTokenFingerprint((string) $this->buildUrl(''));
    }

    // ── HTTP ──────────────────────────────────────────────────────────────

    /**
     * Sends one HTTP request to an absolute URL produced by buildUrl().
     * Redirects are never followed, so credentials are only ever sent to the
     * configured instance.
     *
     * @param  string      $method       HTTP verb.
     * @param  string      $url          Absolute URL from buildUrl().
     * @param  string|null $body         Raw request body, or null for none.
     * @param  string      $contentType  Value for the Content-Type header.
     * @param  string|null $authHeader   Complete Authorization header line, or null for none.
     * @param  int         $timeout      Request timeout in seconds.
     * @return array{success: bool, data?: mixed, error?: string, httpCode?: int}
     */
    private function send(string $method, string $url, ?string $body, string $contentType, ?string $authHeader, int $timeout): array
    {
        if (!function_exists('curl_init')) {
            return ['success' => false, 'error' => 'cURL is not available on this server.'];
        }

        $headers = [
            'Accept: application/json',
            'Content-Type: ' . $contentType,
        ];
        if ($authHeader !== null) {
            array_unshift($headers, $authHeader);
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        unset($ch);

        if ($curlErr !== '') {
            return ['success' => false, 'error' => 'Connection failed: ' . $curlErr];
        }

        return $this->parseResponse($response, $httpCode);
    }

    /**
     * Converts a raw ServiceNow response into the client's result shape.
     *
     * @param  string|bool $response  Raw response body from curl_exec().
     * @param  int         $httpCode  HTTP status code of the response.
     * @return array{success: bool, data?: mixed, error?: string, httpCode?: int}
     */
    private function parseResponse($response, int $httpCode): array
    {
        $decoded = is_string($response) ? json_decode($response, true) : null;

        if ($httpCode >= 200 && $httpCode < 300) {
            return [
                'success'  => true,
                'data'     => $decoded['result'] ?? $decoded,
                'httpCode' => $httpCode,
            ];
        }

        return [
            'success'  => false,
            'error'    => $this->extractErrorMessage($decoded, $httpCode),
            'httpCode' => $httpCode,
        ];
    }

    /**
     * Builds a human-readable failure reason from a ServiceNow error body.
     *
     * ServiceNow API errors usually look like
     *   {"error": {"message": "...", "detail": "..."}, "status": "failure"}
     * where `message` is a short summary and `detail` carries the specifics, so
     * both are kept when present. The OAuth token endpoint instead uses the
     * OAuth 2.0 format
     *   {"error": "server_error", "error_description": "access_denied"}
     * where the description is the more informative part.
     *
     * @param mixed $decoded  JSON-decoded response body (null when not JSON).
     */
    private function extractErrorMessage($decoded, int $httpCode): string
    {
        $error = is_array($decoded) ? ($decoded['error'] ?? null) : null;

        if (is_string($error) && trim($error) !== '') {
            $error       = trim($error);
            $description = is_scalar($decoded['error_description'] ?? null) ? trim((string) $decoded['error_description']) : '';
            if ($description !== '' && strcasecmp($description, $error) !== 0) {
                return $description . ' (' . $error . ')';
            }
            return $error;
        }

        $message = (is_array($error) && is_scalar($error['message'] ?? null)) ? trim((string) $error['message']) : '';
        $detail  = (is_array($error) && is_scalar($error['detail']  ?? null)) ? trim((string) $error['detail'])  : '';

        if ($message !== '' && $detail !== '' && strcasecmp($message, $detail) !== 0) {
            return $message . ': ' . $detail;
        }
        if ($message !== '' || $detail !== '') {
            return $message !== '' ? $message : $detail;
        }

        return 'Unexpected HTTP ' . $httpCode;
    }

    /** Strips any character that is not a lowercase/uppercase hex digit. */
    private function sanitizeSysId(string $sysId): string
    {
        return preg_replace('/[^a-f0-9]/i', '', $sysId) ?? '';
    }
}

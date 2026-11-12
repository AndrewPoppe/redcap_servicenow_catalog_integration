<?php

namespace YaleREDCap\REDCapServiceNowCatalogIntegration;

/**
 * Describes how the module authenticates to ServiceNow.
 *
 * Two authentication methods are supported:
 *  - HTTP Basic authentication with a service account username and password.
 *  - OAuth 2.0, using one of ServiceNow's non-interactive inbound grants:
 *      - "password" (resource owner password credentials): the OAuth client ID
 *        and secret plus a service account username and password.
 *      - "client_credentials": the OAuth client ID and secret only; tokens act
 *        as the OAuth Application User configured in ServiceNow.
 *
 * Interactive grants (such as authorization code) are not supported because
 * requests are made server-side, often while a survey is being submitted,
 * where nobody can be redirected to ServiceNow to sign in.
 */
final class ServiceNowCredentials
{
    public const AUTH_BASIC = 'basic';
    public const AUTH_OAUTH = 'oauth';

    public const GRANT_PASSWORD           = 'password';
    public const GRANT_CLIENT_CREDENTIALS = 'client_credentials';

    /** Supported authentication methods. */
    public const AUTH_TYPES = [self::AUTH_BASIC, self::AUTH_OAUTH];

    /** Supported OAuth grant types. */
    public const GRANT_TYPES = [self::GRANT_PASSWORD, self::GRANT_CLIENT_CREDENTIALS];

    private string $authType;
    private string $grantType;
    private string $clientId;
    private string $clientSecret;
    private string $username;
    private string $password;

    private function __construct(
        string $authType,
        string $grantType,
        string $clientId,
        string $clientSecret,
        string $username,
        string $password
    ) {
        $this->authType     = $authType;
        $this->grantType    = $grantType;
        $this->clientId     = $clientId;
        $this->clientSecret = $clientSecret;
        $this->username     = $username;
        $this->password     = $password;
    }

    /** Credentials for HTTP Basic authentication. */
    public static function basic(string $username, string $password): self
    {
        return new self(self::AUTH_BASIC, '', '', '', $username, $password);
    }

    /**
     * Credentials for OAuth 2.0. The username and password are only used by
     * the password grant and are discarded for the client credentials grant.
     */
    public static function oauth(
        string $grantType,
        string $clientId,
        string $clientSecret,
        string $username = '',
        string $password = ''
    ): self {
        if ($grantType !== self::GRANT_PASSWORD) {
            $username = '';
            $password = '';
        }
        return new self(self::AUTH_OAUTH, $grantType, $clientId, $clientSecret, $username, $password);
    }

    public function getAuthType(): string
    {
        return $this->authType;
    }

    public function getGrantType(): string
    {
        return $this->grantType;
    }

    public function getClientId(): string
    {
        return $this->clientId;
    }

    public function getClientSecret(): string
    {
        return $this->clientSecret;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function isOAuth(): bool
    {
        return $this->authType === self::AUTH_OAUTH;
    }

    /** True when a service account username and password are part of these credentials. */
    public function usesUserCredentials(): bool
    {
        return !$this->isOAuth() || $this->grantType === self::GRANT_PASSWORD;
    }

    /**
     * Returns the labels of required settings that are missing, in the order
     * they appear on the configuration page.
     *
     * @return string[]
     */
    public function getMissingFields(): array
    {
        $missing = [];
        if ($this->isOAuth()) {
            if ($this->clientId === '') {
                $missing[] = 'Client ID';
            }
            if ($this->clientSecret === '') {
                $missing[] = 'Client Secret';
            }
        }
        if ($this->usesUserCredentials()) {
            if ($this->username === '') {
                $missing[] = 'Username';
            }
            if ($this->password === '') {
                $missing[] = 'Password';
            }
        }
        return $missing;
    }

    /**
     * Explains why these credentials cannot be used, or returns null when
     * they are complete.
     */
    public function getValidationError(): ?string
    {
        if (!in_array($this->authType, self::AUTH_TYPES, true)) {
            return 'Unsupported authentication method.';
        }
        if ($this->isOAuth() && !in_array($this->grantType, self::GRANT_TYPES, true)) {
            return 'Unsupported OAuth grant type.';
        }
        $missing = $this->getMissingFields();
        if (!empty($missing)) {
            return 'Missing required connection settings: ' . implode(', ', $missing) . '.';
        }
        return null;
    }

    /** The Authorization header for HTTP Basic authentication. */
    public function getBasicAuthorizationHeader(): string
    {
        return 'Authorization: Basic ' . base64_encode($this->username . ':' . $this->password);
    }

    /**
     * Form parameters for requesting a new access token with the configured
     * grant. The client authenticates with its ID and secret in the request
     * body, as ServiceNow's token endpoint expects.
     *
     * @return array<string, string>
     */
    public function getTokenRequestParameters(): array
    {
        $params = [
            'grant_type'    => $this->grantType,
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
        ];
        if ($this->grantType === self::GRANT_PASSWORD) {
            $params['username'] = $this->username;
            $params['password'] = $this->password;
        }
        return $params;
    }

    /**
     * Form parameters for exchanging a refresh token for a new access token.
     *
     * @return array<string, string>
     */
    public function getRefreshRequestParameters(string $refreshToken): array
    {
        return [
            'grant_type'    => 'refresh_token',
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $refreshToken,
        ];
    }

    /**
     * Identifies the ServiceNow instance, client, and account a cached token
     * was issued for, so a token is never reused after the connection
     * settings point somewhere else. Only non-secret values are included.
     */
    public function getTokenFingerprint(string $instanceBaseUrl): string
    {
        return hash('sha256', (string) json_encode([
            strtolower($instanceBaseUrl),
            $this->authType,
            $this->grantType,
            $this->clientId,
            $this->username,
        ]));
    }
}

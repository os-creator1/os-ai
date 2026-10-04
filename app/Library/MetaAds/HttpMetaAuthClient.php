<?php

namespace App\Library\MetaAds;

use App\DTO\MetaAds\MetaTokenGrant;
use App\DTO\MetaAds\MetaUserProfile;
use App\Exceptions\MetaAds\MetaConfigurationException;
use App\Exceptions\MetaAds\MetaProviderException;
use App\Library\MetaAds\Contracts\MetaAuthClient;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Meta Ads Module V1 contract §2/§3 — the real OAuth client.
 *
 * The code exchange is SERVER-SIDE ONLY and is two requests: the code for a
 * short-lived token, then `grant_type=fb_exchange_token` for the long-lived
 * (~60 day) token. Only the long-lived token is returned; the short-lived one
 * never leaves this method. Both requests are reserved against the call budget
 * like any other. Nothing is persisted here and no token is logged.
 */
final class HttpMetaAuthClient implements MetaAuthClient
{
    public function __construct(
        private readonly MetaGraphTransport $transport,
        private readonly MetaAdsConfig $config,
    ) {
    }

    public function authorizationUrl(string $signedState): string
    {
        $query = [
            'client_id' => $this->appId(),
            'redirect_uri' => $this->redirect(),
            'state' => $signedState,
            // Meta separates scopes with commas.
            'scope' => implode(',', MetaAdsConfig::SCOPES),
            'response_type' => 'code',
        ];

        return $this->config->oauthDialogBaseUrl() . '/' . $this->config->apiVersion() . '/dialog/oauth?' . http_build_query($query);
    }

    public function exchangeCode(string $code): MetaTokenGrant
    {
        $appId = $this->appId();
        $redirect = $this->redirect();
        $secret = $this->config->appSecret() ?? throw MetaConfigurationException::missingAppSecret();

        $short = $this->transport->getOAuth('oauth/access_token', [
            'client_id' => $appId,
            'redirect_uri' => $redirect,
            'client_secret' => $secret,
            'code' => $code,
        ], 'oauth_code_exchange');

        $shortToken = $this->token($short->body);

        $long = $this->transport->getOAuth('oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $appId,
            'client_secret' => $secret,
            'fb_exchange_token' => $shortToken,
        ], 'oauth_long_lived_exchange');

        $expiresIn = MetaAdsJson::unsignedInt($long->body['expires_in'] ?? null);

        try {
            return new MetaTokenGrant(
                $this->token($long->body),
                $expiresIn !== null && $expiresIn > 0 ? CarbonImmutable::now()->addSeconds($expiresIn) : null,
            );
        } catch (InvalidArgumentException) {
            throw MetaProviderException::unexpectedResponse();
        }
    }

    public function profile(string $accessToken): MetaUserProfile
    {
        $result = $this->transport->get('me', ['fields' => 'id,name'], $accessToken, 'profile');

        $id = MetaAdsJson::id($result->body['id'] ?? null) ?? throw MetaProviderException::unexpectedResponse();

        return new MetaUserProfile($id, MetaAdsJson::string($result->body['name'] ?? null, 255));
    }

    public function grantedPermissions(string $accessToken): array
    {
        $result = $this->transport->get('me/permissions', [], $accessToken, 'permissions');

        $data = $result->body['data'] ?? null;

        if (! is_array($data)) {
            throw MetaProviderException::unexpectedResponse();
        }

        $granted = [];

        foreach ($data as $entry) {
            if (is_array($entry)
                && ($entry['status'] ?? null) === 'granted'
                && is_string($entry['permission'] ?? null)
                && preg_match('/\A[a-z_]{1,64}\z/', $entry['permission']) === 1) {
                $granted[] = $entry['permission'];
            }
        }

        return array_values(array_unique($granted));
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function token(array $body): string
    {
        $token = $body['access_token'] ?? null;

        if (! is_string($token) || trim($token) === '') {
            throw MetaProviderException::unexpectedResponse();
        }

        return $token;
    }

    private function appId(): string
    {
        return $this->config->appId() ?? throw MetaConfigurationException::missingAppId();
    }

    private function redirect(): string
    {
        $redirect = $this->config->redirectUri() ?? throw MetaConfigurationException::missingRedirect();

        if (! str_starts_with(strtolower($redirect), 'https://')) {
            throw MetaConfigurationException::redirectNotHttps();
        }

        return $redirect;
    }
}

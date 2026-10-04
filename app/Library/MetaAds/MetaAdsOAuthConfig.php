<?php

namespace App\Library\MetaAds;

use App\Exceptions\MetaAds\MetaConfigurationException;

/**
 * Meta Ads Module V1 contract 24 §3 — validates the dedicated Meta OAuth
 * configuration BEFORE anything irreversible happens. assertUsable() runs
 * first in MetaAdsConnectionManager::beginConnect() so a misconfigured
 * deployment leaves no row, nonce or ledger entry behind and sends Meta no
 * redirect_uri it would reject.
 *
 * The redirect must EXACTLY equal the one fixed, tenant-free callback
 * (`/ads/meta/oauth/callback`): Meta matches the registered URI, so it can
 * carry no Workspace or Business segment. The Business is resolved from the
 * signed, single-use state.
 *
 * The expected URL is computed from the path (url()), not route(): the UI lane
 * registers the named route later and this class must work before and after.
 */
final class MetaAdsOAuthConfig
{
    /** The route name the UI lane registers for the callback. */
    public const CALLBACK_ROUTE = 'customer.ads.meta.oauth.callback';

    public const CALLBACK_PATH = '/ads/meta/oauth/callback';

    public function __construct(private readonly MetaAdsConfig $config)
    {
    }

    /**
     * A redirect that is not https (or permitted local http) or that is not
     * the fixed callback is reported as REDIRECT_NOT_HTTPS: the existing
     * MetaConfigurationException has no separate "mismatch" reason.
     *
     * @throws MetaConfigurationException
     */
    public function assertUsable(): void
    {
        if ($this->appId() === null) {
            throw MetaConfigurationException::missingAppId();
        }

        if ($this->appSecret() === null) {
            throw MetaConfigurationException::missingAppSecret();
        }

        $redirect = $this->redirect();

        if ($redirect === null) {
            throw MetaConfigurationException::missingRedirect();
        }

        if (! $this->isHttpsOrPermittedLocal($redirect) || ! $this->matchesFixedCallback($redirect)) {
            throw MetaConfigurationException::redirectNotHttps();
        }
    }

    public function isUsable(): bool
    {
        try {
            $this->assertUsable();
        } catch (MetaConfigurationException) {
            return false;
        }

        return true;
    }

    public function appId(): ?string
    {
        return $this->config->appId();
    }

    public function appSecret(): ?string
    {
        return $this->config->appSecret();
    }

    public function redirect(): ?string
    {
        return $this->config->redirectUri();
    }

    /** The callback URL this application serves. */
    public function expectedCallbackUrl(): string
    {
        return url(self::CALLBACK_PATH);
    }

    private function isHttpsOrPermittedLocal(string $redirect): bool
    {
        $parts = parse_url($redirect);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);

        if ($scheme === 'https') {
            return true;
        }

        return $scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true);
    }

    /** Scheme, host, port and path must match exactly; no query or fragment. */
    private function matchesFixedCallback(string $redirect): bool
    {
        $configured = parse_url($redirect);
        $expected = parse_url($this->expectedCallbackUrl());

        if ($configured === false || $expected === false) {
            return false;
        }

        if (isset($configured['query']) || isset($configured['fragment'])) {
            return false;
        }

        $normalize = static fn (array $parts): array => [
            'scheme' => strtolower((string) ($parts['scheme'] ?? '')),
            'host' => strtolower((string) ($parts['host'] ?? '')),
            'port' => $parts['port'] ?? null,
            'path' => rtrim((string) ($parts['path'] ?? ''), '/'),
        ];

        return $normalize($configured) === $normalize($expected);
    }
}

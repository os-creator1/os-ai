<?php

namespace App\Library\GoogleAds;

use App\Exceptions\GoogleAds\GoogleAdsConfigurationException;

/**
 * Google Ads Module V1 contract §3 — validates the dedicated OAuth
 * configuration BEFORE anything irreversible happens (mirrors
 * GoogleBusinessProfileOAuthConfig). assertUsable() runs first in
 * GoogleAdsConnectionManager::beginConnect() so a misconfigured deployment
 * leaves no pending row, nonce or ledger entry behind and sends Google no
 * redirect_uri it would reject.
 *
 * The redirect must EXACTLY equal the one fixed, tenant-free callback
 * (`ads/oauth/callback`): Google matches the registered URI exactly, so it
 * can carry no Workspace or Business segment. The Business is resolved from
 * the signed, single-use state.
 */
final class GoogleAdsOAuthConfig
{
    /**
     * The ONE fixed, tenant-free callback route name. The route itself is
     * registered by the routing phase; only the constant lives here.
     */
    public const CALLBACK_ROUTE = 'customer.ads.oauth.callback';

    /**
     * @throws GoogleAdsConfigurationException
     */
    public function assertUsable(): void
    {
        if ($this->clientId() === null) {
            throw GoogleAdsConfigurationException::missingClientId();
        }

        if ($this->clientSecret() === null) {
            throw GoogleAdsConfigurationException::missingClientSecret();
        }

        $redirect = $this->redirect();

        if ($redirect === null) {
            throw GoogleAdsConfigurationException::missingRedirect();
        }

        if (! $this->isHttpsOrPermittedLocal($redirect)) {
            throw GoogleAdsConfigurationException::redirectNotHttps();
        }

        if (! $this->matchesFixedCallback($redirect)) {
            throw GoogleAdsConfigurationException::redirectMismatch();
        }
    }

    public function isUsable(): bool
    {
        try {
            $this->assertUsable();
        } catch (GoogleAdsConfigurationException) {
            return false;
        }

        return true;
    }

    public function clientId(): ?string
    {
        return $this->nonEmptyString(config('services.google_ads.client_id'));
    }

    public function clientSecret(): ?string
    {
        return $this->nonEmptyString(config('services.google_ads.client_secret'));
    }

    public function redirect(): ?string
    {
        return $this->nonEmptyString(config('services.google_ads.redirect'));
    }

    /** The callback URL this application actually serves. */
    public function expectedCallbackUrl(): string
    {
        return route(self::CALLBACK_ROUTE);
    }

    /** Production redirects must be https; Google itself exempts localhost. */
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

        return $scheme === 'http'
            && in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true);
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

    private function nonEmptyString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}

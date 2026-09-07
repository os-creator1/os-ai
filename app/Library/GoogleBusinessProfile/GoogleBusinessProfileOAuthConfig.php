<?php

namespace App\Library\GoogleBusinessProfile;

use App\Exceptions\GoogleBusinessProfile\GoogleBusinessProfileConfigurationException;

/**
 * GBP Slice A contract §28 — validates the dedicated OAuth configuration
 * BEFORE anything irreversible happens.
 *
 * Correction pass item 7. Google rejects an authorization request whose
 * `redirect_uri` is not one of the client's registered authorized redirect
 * URIs, so a mismatch here is not a cosmetic problem: it produces a dead
 * flow AFTER we have already written a pending connection, issued a nonce
 * and written a ledger row. assertUsable() therefore runs first and throws,
 * leaving no database state change, no provider call, and no credential
 * value anywhere in the response.
 *
 * The redirect must EXACTLY equal the one fixed callback this application
 * serves (contract §17.2, correction item 1): a single registered URI is
 * the only shape Google can accept, which is precisely why the callback
 * carries no Workspace or Business path segment.
 */
final class GoogleBusinessProfileOAuthConfig
{
    /**
     * Contract §17.1 — the ONE fixed, tenant-free callback route name.
     */
    public const CALLBACK_ROUTE = 'customer.gbp.oauth.callback';

    /**
     * Throws unless every credential is present and the configured
     * redirect exactly matches this application's fixed callback.
     *
     * @throws GoogleBusinessProfileConfigurationException
     */
    public function assertUsable(): void
    {
        if ($this->clientId() === null) {
            throw GoogleBusinessProfileConfigurationException::missingClientId();
        }

        if ($this->clientSecret() === null) {
            throw GoogleBusinessProfileConfigurationException::missingClientSecret();
        }

        $redirect = $this->redirect();

        if ($redirect === null) {
            throw GoogleBusinessProfileConfigurationException::missingRedirect();
        }

        if (! $this->isHttpsOrPermittedLocal($redirect)) {
            throw GoogleBusinessProfileConfigurationException::redirectNotHttps();
        }

        if (! $this->matchesFixedCallback($redirect)) {
            throw GoogleBusinessProfileConfigurationException::redirectMismatch();
        }
    }

    public function isUsable(): bool
    {
        try {
            $this->assertUsable();
        } catch (GoogleBusinessProfileConfigurationException) {
            return false;
        }

        return true;
    }

    public function clientId(): ?string
    {
        return $this->nonEmptyString(config('services.google_business_profile.client_id'));
    }

    public function clientSecret(): ?string
    {
        return $this->nonEmptyString(config('services.google_business_profile.client_secret'));
    }

    public function redirect(): ?string
    {
        return $this->nonEmptyString(config('services.google_business_profile.redirect'));
    }

    /**
     * The callback URL this application actually serves. The configured
     * redirect must equal it exactly.
     */
    public function expectedCallbackUrl(): string
    {
        return route(self::CALLBACK_ROUTE);
    }

    /**
     * Contract §28.3 — production redirects must use HTTPS. Google itself
     * exempts localhost, and so do we, so a developer can exercise the
     * flow locally without weakening the production rule.
     */
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

    /**
     * Compares scheme, host, port and path exactly. Query strings and
     * fragments are not permitted on the redirect at all — Google matches
     * the registered URI exactly, and a stray query would break it.
     */
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

        $normalize = static fn (?array $parts): array => [
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

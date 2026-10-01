<?php

namespace App\Library\BusinessEmail;

use App\Enums\BusinessEmail\BusinessEmailProviderType;
use App\Exceptions\BusinessEmail\BusinessEmailConfigurationException as Config;

/**
 * Validates the per-provider OAuth configuration BEFORE anything irreversible
 * happens (before any row, nonce or provider call), mirroring
 * ExternalCalendarOAuthConfig / GoogleBusinessProfileOAuthConfig.
 *
 * The redirect must equal the ONE fixed, tenant-free callback route
 * (`email/oauth/{provider}/callback`): providers match redirect_uri exactly,
 * so it can never carry a Workspace or Business segment.
 */
final class BusinessEmailOAuthConfig
{
    public const CALLBACK_ROUTE = 'customer.email.oauth.callback';

    /** @throws Config */
    public function assertUsable(BusinessEmailProviderType $provider): void
    {
        $key = $provider->value;

        if ($this->clientId($provider) === null) {
            throw Config::for($key, Config::MISSING_CLIENT_ID);
        }

        if ($this->clientSecret($provider) === null) {
            throw Config::for($key, Config::MISSING_CLIENT_SECRET);
        }

        $redirect = $this->redirect($provider);

        if ($redirect === null) {
            throw Config::for($key, Config::MISSING_REDIRECT);
        }

        if (! $this->isHttpsOrPermittedLocal($redirect)) {
            throw Config::for($key, Config::REDIRECT_NOT_HTTPS);
        }

        if (! $this->matchesFixedCallback($redirect, $provider)) {
            throw Config::for($key, Config::REDIRECT_MISMATCH);
        }
    }

    public function isUsable(BusinessEmailProviderType $provider): bool
    {
        try {
            $this->assertUsable($provider);
        } catch (Config) {
            return false;
        }

        return true;
    }

    public function clientId(BusinessEmailProviderType $provider): ?string
    {
        return $this->nonEmptyString(config("business_email.{$provider->value}.client_id"));
    }

    public function clientSecret(BusinessEmailProviderType $provider): ?string
    {
        return $this->nonEmptyString(config("business_email.{$provider->value}.client_secret"));
    }

    public function redirect(BusinessEmailProviderType $provider): ?string
    {
        return $this->nonEmptyString(config("business_email.{$provider->value}.redirect"));
    }

    public function expectedCallbackUrl(BusinessEmailProviderType $provider): string
    {
        return route(self::CALLBACK_ROUTE, ['provider' => $provider->value]);
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

    private function matchesFixedCallback(string $redirect, BusinessEmailProviderType $provider): bool
    {
        $configured = parse_url($redirect);
        $expected = parse_url($this->expectedCallbackUrl($provider));

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

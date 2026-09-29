<?php

namespace App\Library\Calendar\ExternalCalendar;

use App\Enums\Calendar\ExternalCalendarProvider;
use App\Exceptions\Calendar\ExternalCalendarConfigurationException;

/**
 * Implementation Contract 15 §5.5/§28 (mirroring GoogleBusinessProfileOAuthConfig)
 * — validates the per-provider OAuth configuration BEFORE anything
 * irreversible happens (before any row, nonce, or provider call).
 *
 * Provider-parameterized because this slice authorizes exactly two
 * providers on one shared code path (§12.F), unlike GBP's single-provider
 * config.
 */
final class ExternalCalendarOAuthConfig
{
    /**
     * ONE route pattern handling both providers' callbacks
     * (`calendar-connection/oauth/{provider}/callback`): each provider's
     * OWN registered redirect_uri is still a distinct, fixed, literal URL
     * (`.../oauth/google/callback` vs `.../oauth/outlook/callback`) — the
     * provider never sees or cares that this application routes both
     * through one controller action.
     */
    public const CALLBACK_ROUTE = 'customer.calendar-connection.oauth.callback';

    /** @throws ExternalCalendarConfigurationException */
    public function assertUsable(ExternalCalendarProvider $provider): void
    {
        $key = $provider->value;

        if ($this->clientId($provider) === null) {
            throw ExternalCalendarConfigurationException::missingClientId($key);
        }

        if ($this->clientSecret($provider) === null) {
            throw ExternalCalendarConfigurationException::missingClientSecret($key);
        }

        $redirect = $this->redirect($provider);

        if ($redirect === null) {
            throw ExternalCalendarConfigurationException::missingRedirect($key);
        }

        if (! $this->isHttpsOrPermittedLocal($redirect)) {
            throw ExternalCalendarConfigurationException::redirectNotHttps($key);
        }

        if (! $this->matchesFixedCallback($redirect, $provider)) {
            throw ExternalCalendarConfigurationException::redirectMismatch($key);
        }
    }

    public function isUsable(ExternalCalendarProvider $provider): bool
    {
        try {
            $this->assertUsable($provider);
        } catch (ExternalCalendarConfigurationException) {
            return false;
        }

        return true;
    }

    public function clientId(ExternalCalendarProvider $provider): ?string
    {
        return $this->nonEmptyString(config("calendar_external.{$provider->value}.client_id"));
    }

    public function clientSecret(ExternalCalendarProvider $provider): ?string
    {
        return $this->nonEmptyString(config("calendar_external.{$provider->value}.client_secret"));
    }

    public function redirect(ExternalCalendarProvider $provider): ?string
    {
        return $this->nonEmptyString(config("calendar_external.{$provider->value}.redirect"));
    }

    public function expectedCallbackUrl(ExternalCalendarProvider $provider): string
    {
        return route(self::CALLBACK_ROUTE, ['provider' => $provider->value]);
    }

    /** Contract §28.3 idiom — HTTPS in production, localhost permitted for development. */
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

    private function matchesFixedCallback(string $redirect, ExternalCalendarProvider $provider): bool
    {
        $configured = parse_url($redirect);
        $expected = parse_url($this->expectedCallbackUrl($provider));

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

<?php

namespace App\Library\NicheBlueprint\Workspace;

use InvalidArgumentException;

/**
 * Blueprint V2 — the CRITICAL DATA BOUNDARY, enforced in code.
 *
 * A Blueprint carries CONFIGURATION. It must never carry customer or
 * operational data, credentials or provider state. Two layers keep that true:
 *
 *   1. STRUCTURAL: a Blueprint can only hold component types that have a
 *      registered adapter, and every adapter parses a fixed descriptor shape
 *      (no free-form blobs, no row ids). There is no adapter for contacts,
 *      opportunities, conversations, appointments, submissions, invoices,
 *      payments, signed contracts, OAuth tokens, provider credentials, Ads
 *      data, rank observations, reviews, users, sessions or wallet state, and
 *      none can be added without a deliberate new adapter.
 *   2. DEFENSIVE (this class): at publish and at every Workspace save, any
 *      payload is scanned for forbidden KEYS (credential-shaped names and the
 *      names of operational tables) and forbidden VALUES (e-mail addresses,
 *      phone numbers, provider-token prefixes, card-like numbers). It refuses
 *      rather than strips: a Blueprint author who pasted real data should
 *      learn it, not have it silently altered.
 *
 * Merge tokens such as `{{contact.first_name}}` are configuration, not data,
 * and pass.
 */
final class BlueprintDataBoundary
{
    /** Key fragments that mark credentials/secrets wherever they appear in a key. */
    private const SECRET_KEY_FRAGMENTS = ['token', 'secret', 'password', 'passwd', 'credential', 'api_key', 'apikey', 'private_key', 'client_secret', 'authorization', 'bearer'];

    /** Exact key names that denote customer/operational data or provider state. */
    private const FORBIDDEN_KEYS = [
        'contact', 'contacts', 'contact_id', 'contact_ids', 'opportunity', 'opportunities', 'opportunity_id',
        'conversation', 'conversations', 'message_thread', 'appointment', 'appointments', 'submission', 'submissions',
        'form_submissions', 'invoice', 'invoices', 'payment', 'payments', 'payment_id', 'signed_contract', 'signed_contracts',
        'oauth', 'google_ads', 'meta_ads', 'ads_data', 'rank_observations', 'reviews', 'review', 'users', 'user', 'user_id',
        'session', 'sessions', 'wallet', 'wallet_balance', 'business_id', 'workspace_id', 'customer_id', 'stripe_account_id',
        'card_number', 'iban', 'account_number', 'ssn',
    ];

    /** @param array<string, mixed> $payload */
    public static function assertClean(array $payload): void
    {
        self::walk($payload, '');
    }

    private static function walk(mixed $value, string $path): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $childPath = $path === '' ? (string) $key : $path.'.'.$key;

                if (is_string($key)) {
                    self::assertKeyAllowed($key, $childPath);
                }

                self::walk($child, $childPath);
            }

            return;
        }

        if (is_string($value)) {
            self::assertValueAllowed($value, $path);
        }
    }

    private static function assertKeyAllowed(string $key, string $path): void
    {
        $lower = strtolower($key);

        if (in_array($lower, self::FORBIDDEN_KEYS, true)) {
            throw new InvalidArgumentException("Blueprint data boundary: \"{$path}\" names customer/operational data. A Blueprint holds configuration only.");
        }

        foreach (self::SECRET_KEY_FRAGMENTS as $fragment) {
            if (str_contains($lower, $fragment)) {
                throw new InvalidArgumentException("Blueprint data boundary: \"{$path}\" looks like a credential. A Blueprint never holds tokens, secrets or provider credentials.");
            }
        }
    }

    private static function assertValueAllowed(string $value, string $path): void
    {
        // Strip merge tokens first: "{{contact.email}}" is configuration.
        $plain = preg_replace('/\{\{[^}]*\}\}/', '', $value) ?? $value;

        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $plain) === 1) {
            throw new InvalidArgumentException("Blueprint data boundary: \"{$path}\" contains an email address. A Blueprint never holds real contact data.");
        }

        // UUIDs (template references) are identifiers, not phone numbers.
        $plain = preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', '', $plain) ?? $plain;

        if (preg_match('/(?<![\w.])(\+\d[\d\s().\-]{7,}\d|\(?\d{3}\)?[\s.\-]\d{3}[\s.\-]\d{4})(?![\w])/', $plain) === 1) {
            throw new InvalidArgumentException("Blueprint data boundary: \"{$path}\" contains a phone-like number. A Blueprint never holds real contact data.");
        }

        if (preg_match('/\b(sk_live_|sk_test_|rk_live_|pk_live_|whsec_|ya29\.|EAA[A-Za-z0-9]{20,}|xox[abp]-|ghp_|AKIA[0-9A-Z]{12,})/', $plain) === 1) {
            throw new InvalidArgumentException("Blueprint data boundary: \"{$path}\" contains a provider token or key. A Blueprint never holds credentials.");
        }
    }
}

<?php

namespace App\Library\Messaging;

use App\Enums\Messaging\MessagingProvider;

/**
 * Slice 3 §4.2 — the normalized identifier persisted in every
 * transport-provider column.
 *
 * WHY THIS IS NOT `MessagingProvider`.
 *
 * `MessagingProvider` is the MANAGED-ADAPTER enum. It has exactly one case
 * because Slice 3 ships exactly one managed adapter, and that is correct: it
 * answers "which adapter implementation handles this?".
 *
 * The persisted `provider` columns answer a different and much wider
 * question — "which provider did this traffic actually come from or go to?"
 * — and their domain includes Twilio and the ~58 other legacy gateways
 * `DLRController` serves, none of which will ever have a managed adapter.
 *
 * An earlier revision of this lane collapsed the two, and recorded a Twilio
 * signature rejection with `provider = 'telnyx'` on the grounds that
 * `MessagingProvider` had no other case. That is a false record: it names
 * the wrong company in a security-audit row, and it makes the rejection
 * table useless for the exact question it exists to answer ("which provider
 * is sending us traffic we cannot verify?"). Widening the managed-adapter
 * enum to hold ~60 gateways that have no adapter would have been the same
 * mistake from the other direction.
 *
 * So the two stay separate, and this class is the normalizer for the
 * persisted side. A managed provider still round-trips through it, so the
 * managed path is not a special case in the storage layer.
 *
 * NORMALIZATION. Legacy provider names arrive as `SendingServer::$settings`
 * values, which are human-facing labels ("Twilio", "TwilioCopilot",
 * "800com", "D7Networks"). They are lowercased and reduced to
 * `[a-z0-9_]` so that one provider cannot occupy several spellings in the
 * same column. `Twilio` and `twilio` are the same provider; `TwilioCopilot`
 * is deliberately NOT the same as `twilio`, because they are different
 * gateways with different credentials.
 */
final class TransportProviderIdentifier
{
    /** What to record when the caller genuinely cannot name a provider. */
    public const UNKNOWN = 'unknown';

    /** Matches the `provider` columns in §4.2's schema. */
    private const MAX_LENGTH = 32;

    /**
     * @param MessagingProvider|string|null $provider a managed-adapter case,
     *        a legacy `SendingServer::$settings` label, or null
     */
    public static function normalize(MessagingProvider|string|null $provider): string
    {
        if ($provider instanceof MessagingProvider) {
            return $provider->value;
        }

        if ($provider === null) {
            return self::UNKNOWN;
        }

        $slug = strtolower(trim($provider));
        $slug = (string) preg_replace('/[^a-z0-9]+/', '_', $slug);
        $slug = trim($slug, '_');

        if ($slug === '') {
            return self::UNKNOWN;
        }

        // Truncation is the last resort, not the normal path: every provider
        // label in this repository is far shorter than the column. Doing it
        // here rather than letting MySQL do it means the value in the row is
        // always the value this class returned.
        return substr($slug, 0, self::MAX_LENGTH);
    }

    /**
     * True when the identifier names the one provider Slice 3 actually
     * operates a managed adapter for. Used by tests and by triage; never as
     * an authorization check.
     */
    public static function isManagedProvider(string $identifier): bool
    {
        return $identifier === MessagingProvider::Telnyx->value;
    }
}

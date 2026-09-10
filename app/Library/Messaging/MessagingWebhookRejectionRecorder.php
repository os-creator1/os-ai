<?php

namespace App\Library\Messaging;

use App\Enums\Messaging\MessagingProvider;
use App\Enums\Messaging\WebhookRejectionReason;
use App\Models\MessagingWebhookRejection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Slice 3 §4.2/§4.6 — the sole writer of messaging_webhook_rejections.
 *
 * Payload minimization is enforced here, not merely documented: this class
 * accepts a raw body only to hash it, and never stores it. There is no
 * parameter, and no column, capable of carrying a message body or a
 * credential.
 *
 * Idempotent by (reason, provider, payload_hash): a repeat of the identical
 * rejection increments occurrence_count and moves last_seen_at rather than
 * inserting a new row, which is what bounds storage growth under provider
 * replay and retry.
 *
 * No business_id is ever recorded — a row here is by definition a case where
 * authoritative attribution could not be established. The two candidate
 * identity ids are admin-only debugging hints for the conflicting case.
 */
class MessagingWebhookRejectionRecorder
{
    /**
     * @param MessagingProvider|string|null $provider the provider the
     *        traffic ACTUALLY came from — a managed-adapter case, or a
     *        legacy `SendingServer::$settings` label such as 'Twilio'.
     *        Never coerced to the managed provider: see
     *        TransportProviderIdentifier for why the two are separate.
     */
    public function record(
        WebhookRejectionReason $reason,
        MessagingProvider|string|null $provider,
        string $rawBody,
        ?string $messagingProfileId = null,
        ?string $destinationNumber = null,
        ?int $profileResolvedIdentityId = null,
        ?int $numberResolvedIdentityId = null,
    ): MessagingWebhookRejection {
        $payloadHash = hash('sha256', $rawBody);
        $providerIdentifier = TransportProviderIdentifier::normalize($provider);
        $now = Carbon::now();

        $existing = MessagingWebhookRejection::query()
            ->where('reason', $reason->value)
            ->where('provider', $providerIdentifier)
            ->where('payload_hash', $payloadHash)
            ->first();

        if ($existing instanceof MessagingWebhookRejection) {
            return $this->touch($existing, $now);
        }

        try {
            $rejection = new MessagingWebhookRejection([
                'reason' => $reason->value,
                'provider' => $providerIdentifier,
                'payload_hash' => $payloadHash,
                'messaging_profile_id' => self::trimmedOrNull($messagingProfileId),
                // Stored E.164-normalized when normalizable, so support
                // triage can correlate; still never authoritative.
                'destination_number' => E164Normalizer::normalize($destinationNumber),
                'profile_resolved_identity_id' => $profileResolvedIdentityId,
                'number_resolved_identity_id' => $numberResolvedIdentityId,
                'occurrence_count' => 1,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
            ]);
            $rejection->created_at = $now;
            $rejection->save();

            return $rejection;
        } catch (UniqueConstraintViolationException) {
            // A concurrent identical rejection won the unique key; converge
            // on its row and count this occurrence against it.
            $winner = MessagingWebhookRejection::query()
                ->where('reason', $reason->value)
                ->where('provider', $providerIdentifier)
                ->where('payload_hash', $payloadHash)
                ->firstOrFail();

            return $this->touch($winner, $now);
        }
    }

    /**
     * ATOMIC, deliberately.
     *
     * This used to read `occurrence_count`, add one in PHP, and write the
     * result back. Two concurrent copies of the same replayed webhook — the
     * exact situation this counter exists to measure — both read the same
     * value and both wrote the same increment, so N simultaneous deliveries
     * counted as one. The counter under-reported precisely when it mattered.
     *
     * The increment now happens in the database, in one statement, so
     * concurrent callers serialize on the row rather than racing in
     * userland.
     */
    private function touch(MessagingWebhookRejection $rejection, Carbon $seenAt): MessagingWebhookRejection
    {
        MessagingWebhookRejection::query()
            ->whereKey($rejection->getKey())
            ->update([
                'occurrence_count' => DB::raw('occurrence_count + 1'),
                'last_seen_at' => $seenAt,
            ]);

        return $rejection->refresh();
    }

    private static function trimmedOrNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}

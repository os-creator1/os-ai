<?php

namespace App\Library\Calendar\ExternalCalendar;

use App\DTO\Calendar\ExternalCalendarNotificationRegistration;
use App\Enums\Calendar\ExternalCalendarConnectionState;
use App\Enums\Calendar\ExternalCalendarProvider;
use App\Exceptions\Calendar\ExternalCalendarProviderException;
use App\Models\ExternalCalendarConnection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Implementation Contract 15 §11/§12.F, review correction — establishes and
 * renews the actual provider push mechanism (Google `events.watch`,
 * Microsoft Graph `/subscriptions`) so the provider genuinely calls
 * ExternalCalendarWebhookController, and best-effort tears it down on
 * disconnect/revoke.
 *
 * Deliberately separate from ExternalCalendarSyncService — the existing
 * sync engine's shape (idempotent upsert, tombstones, reconciliation,
 * fail-safe-stale, the §7.5 lock discipline) is unchanged by this class and
 * is never touched here. This class only ever performs bookkeeping writes
 * to the connection's own row (notification_channel_id/
 * notification_registration_id/notification_expires_at, plus the existing
 * failure-tracking columns on failure) — never a busy-block write, so it
 * never needs the staff_booking_locks row §7.5 governs. Every provider HTTP
 * call happens outside any lock and outside any transaction, matching the
 * same discipline the rest of this slice already follows.
 *
 * Every method here is non-fatal by design: a registration or renewal
 * failure never throws past this class and never blocks the caller
 * (connect completion, the scheduled sweep, or disconnect) — the existing
 * poll-based sync sweep remains the fallback regardless of whether the
 * push channel is currently live.
 */
final class ExternalCalendarNotificationRegistrar
{
    public function __construct(private readonly ExternalCalendarConnectionManager $connections)
    {
    }

    /**
     * Renews or registers a channel/subscription when none exists, or when
     * the existing one is missing/expiring within the configured lead time.
     * $force skips that check (used right after a connection activates, so
     * push notifications start as close to immediately as possible rather
     * than waiting for the next sweep).
     *
     * Review correction — provider-specific renewal semantics. When a live
     * registration already exists, an IN-PLACE renewal is attempted FIRST
     * (Microsoft Graph `PATCH /subscriptions/{id}`; Google has none, so its
     * client throws registrationNotFound() immediately, before any HTTP
     * call). Only when that renewal reports registrationNotFound() — the
     * one classification meaning "this exact registration cannot be
     * extended, never any other failure" — does this fall back to a fresh
     * registerNotifications() call, exactly once, followed by a best-effort
     * teardown of the now-superseded old registration. Any OTHER renewal
     * failure is recorded as an ordinary sync failure and leaves the
     * existing (still provider-valid) registration in place — it is never
     * blindly replaced.
     */
    public function ensureRegistered(ExternalCalendarConnection $connection, bool $force = false): void
    {
        if ($connection->state !== ExternalCalendarConnectionState::Active) {
            return;
        }

        if (! $force && ! $this->needsRegistration($connection)) {
            return;
        }

        $provider = $connection->provider instanceof ExternalCalendarProvider
            ? $connection->provider
            : ExternalCalendarProvider::from((string) $connection->provider);

        try {
            $accessToken = $this->connections->accessTokenFor($connection);
        } catch (ExternalCalendarProviderException) {
            // Already recorded by accessTokenFor() (and revoked, if that's
            // what it was) — nothing left to do here.
            return;
        }

        $client = $this->connections->clientFor($provider);
        $requestedExpiry = now()->addMinutes((int) config("calendar_external.notifications.{$this->configKey($provider)}_expiration_minutes"));

        $existingRegistrationId = $connection->notification_registration_id;

        if (! empty($existingRegistrationId)) {
            try {
                $renewed = $client->renewNotifications($accessToken, $existingRegistrationId, $requestedExpiry);
                $this->persist($connection, $renewed);

                return;
            } catch (ExternalCalendarProviderException $exception) {
                if ($exception->classification !== ExternalCalendarProviderException::FAILURE_REGISTRATION_NOT_FOUND) {
                    // A real provider failure (rate limited, unavailable,
                    // timeout, ...) — the EXISTING registration is still
                    // provider-valid as far as we know, so it is left
                    // completely untouched. Polling remains the fallback.
                    $this->connections->markFailure($connection, $exception);

                    return;
                }

                // Falls through — this specific registration cannot be
                // renewed (Google: mechanically, always; Microsoft: this
                // one id is gone). Registered fresh below, exactly once.
            }
        }

        $proofToken = ExternalCalendarWebhookToken::forConnection((string) $connection->uid, $provider->value);
        $notificationUrl = $this->notificationUrlFor($provider, (string) $connection->uid, $proofToken);

        try {
            $registration = $client->registerNotifications($accessToken, $notificationUrl, $proofToken, $requestedExpiry);
        } catch (ExternalCalendarProviderException $exception) {
            $this->connections->markFailure($connection, $exception);

            return;
        }

        $oldChannelId = $connection->notification_channel_id;
        $this->persist($connection, $registration);

        // Best-effort only, and only AFTER the new registration is already
        // the persisted, authoritative one — see class docblock. A stale
        // old channel/subscription that somehow still delivers a
        // notification after this point fails ExternalCalendarWebhookController's
        // proof check regardless, because that check compares against the
        // (now new) persisted identity, not the provider's own bookkeeping.
        if (! empty($existingRegistrationId) && $existingRegistrationId !== $registration->registrationId) {
            try {
                $client->unregisterNotifications($accessToken, $existingRegistrationId, $oldChannelId);
            } catch (Throwable) {
                // Genuinely best-effort — see class docblock.
            }
        }
    }

    private function persist(ExternalCalendarConnection $connection, ExternalCalendarNotificationRegistration $registration): void
    {
        DB::table('external_calendar_connections')
            ->where('id', $connection->id)
            ->update([
                'notification_channel_id' => $registration->channelId,
                'notification_registration_id' => $registration->registrationId,
                'notification_expires_at' => $registration->expiresAt,
                'updated_at' => now(),
            ]);

        $connection->refresh();
    }

    /**
     * Best-effort provider-side teardown, called BEFORE the local
     * disconnect/revoke transaction. Never throws: if the provider call
     * fails, local credential/state destruction proceeds exactly as it
     * already does — a stale registration left behind on the provider's
     * side cannot cause a false pull, because
     * ExternalCalendarWebhookController refuses any connection whose state
     * is not Active before ever consulting the registration fields.
     */
    public function unregister(ExternalCalendarConnection $connection): void
    {
        if (empty($connection->notification_registration_id)) {
            return;
        }

        $provider = $connection->provider instanceof ExternalCalendarProvider
            ? $connection->provider
            : ExternalCalendarProvider::from((string) $connection->provider);

        try {
            $accessToken = $this->connections->accessTokenFor($connection);
            $this->connections->clientFor($provider)->unregisterNotifications(
                $accessToken,
                (string) $connection->notification_registration_id,
                $connection->notification_channel_id,
            );
        } catch (Throwable) {
            // Genuinely best-effort — see class docblock.
        }
    }

    private function needsRegistration(ExternalCalendarConnection $connection): bool
    {
        if (empty($connection->notification_registration_id) || $connection->notification_expires_at === null) {
            return true;
        }

        $leadHours = (int) config('calendar_external.notifications.renewal_lead_hours');

        return $connection->notification_expires_at->isBefore(now()->addHours($leadHours));
    }

    private function notificationUrlFor(ExternalCalendarProvider $provider, string $connectionUid, string $token): string
    {
        $routeName = $provider === ExternalCalendarProvider::Google
            ? 'public.calendar.webhooks.google'
            : 'public.calendar.webhooks.outlook';

        return route($routeName, ['connectionUid' => $connectionUid, 'token' => $token]);
    }

    private function configKey(ExternalCalendarProvider $provider): string
    {
        return $provider === ExternalCalendarProvider::Google ? 'google' : 'outlook';
    }
}

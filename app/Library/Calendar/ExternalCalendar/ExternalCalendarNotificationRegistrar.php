<?php

namespace App\Library\Calendar\ExternalCalendar;

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
     * Registers a fresh channel/subscription when none exists, or when the
     * existing one is missing/expiring within the configured lead time.
     * $force skips that check (used right after a connection activates, so
     * push notifications start as close to immediately as possible rather
     * than waiting for the next sweep).
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

        $proofToken = ExternalCalendarWebhookToken::forConnection((string) $connection->uid, $provider->value);
        $notificationUrl = $this->notificationUrlFor($provider, (string) $connection->uid, $proofToken);
        $requestedExpiry = now()->addMinutes((int) config("calendar_external.notifications.{$this->configKey($provider)}_expiration_minutes"));

        try {
            $registration = $this->connections->clientFor($provider)
                ->registerNotifications($accessToken, $notificationUrl, $proofToken, $requestedExpiry);
        } catch (ExternalCalendarProviderException $exception) {
            $this->connections->markFailure($connection, $exception);

            return;
        }

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

<?php

namespace App\Library\Calendar\ExternalCalendar;

use App\DTO\Calendar\ExternalCalendarSyncPage;
use App\Enums\Calendar\ExternalCalendarConnectionState;
use App\Enums\Calendar\ExternalCalendarProvider;
use App\Exceptions\Calendar\ExternalCalendarProviderException;
use App\Library\Calendar\StaffBookingLockManager;
use App\Models\ExternalCalendarConnection;
use Illuminate\Support\Facades\DB;

/**
 * Implementation Contract 15 §5.6/§7.5/§11/§12.F — the full+incremental sync
 * engine. Called by both the scheduled console command and the webhook
 * controller (a verified webhook is a TRIGGER, never data — it causes
 * exactly this same authenticated pull, never supplies event data itself).
 *
 * §7.5's two-phase shape, always in this order:
 *   1. every provider HTTP call happens OUTSIDE any transaction and OUTSIDE
 *      any lock (a provider timeout must never hold a staff member's
 *      booking timeline hostage);
 *   2. once fetched into memory, the connection's User's staff_booking_locks
 *      row is ensured-then-locked (the SAME tier-2 lock the booking check
     *  takes), and the busy-block create/update/delete set plus the
 *      sync_cursor/last_synced_at advance are applied in ONE transaction.
 *
 * §11's fail-safe-stale rule: ANY provider failure — token exchange,
 * fetch, or an invalid/expired cursor — records the failure bookkeeping and
 * leaves every existing busy block exactly as it was. It never blocks or
 * fails an internal booking, and never wipes the prior good cache.
 */
class ExternalCalendarSyncService
{
    public function __construct(
        private readonly ExternalCalendarConnectionManager $connections,
        private readonly StaffBookingLockManager $locks,
    ) {
    }

    /**
     * §5.5/§11 — disconnected/revoked/pending connections are simply not
     * synced (disconnect already purged their busy blocks); this is the
     * mechanism that makes "disconnect prevents further sync" true.
     */
    public function syncConnection(ExternalCalendarConnection $connection, bool $forceFull = false): void
    {
        if ($connection->state !== ExternalCalendarConnectionState::Active) {
            return;
        }

        $provider = $connection->provider instanceof ExternalCalendarProvider
            ? $connection->provider
            : ExternalCalendarProvider::from((string) $connection->provider);

        try {
            $accessToken = $this->connections->accessTokenFor($connection);
        } catch (ExternalCalendarProviderException) {
            // accessTokenFor() already recorded the failure (and revoked
            // the connection on a revocation classification). Nothing else
            // to do — the existing cache is left untouched.
            return;
        }

        $client = $this->connections->clientFor($provider);
        $useIncremental = ! $forceFull && ! $this->needsFullResync($connection);

        try {
            $page = $useIncremental
                ? $client->fetchIncrementalBusy($accessToken, (string) $connection->sync_cursor)
                : $client->fetchFullBusy($accessToken, now(), now()->addDays((int) config('calendar_external.sync.full_sync_window_days')));
        } catch (ExternalCalendarProviderException $exception) {
            if ($exception->classification === ExternalCalendarProviderException::FAILURE_CURSOR_INVALID && ! $forceFull) {
                // Fall back to a full read exactly once — never a blind
                // retry against the same disowned cursor.
                $this->syncConnection($connection, forceFull: true);

                return;
            }

            $this->connections->markFailure($connection, $exception);

            return;
        }

        $this->applyPage($connection, $page, isFullSync: ! $useIncremental);
    }

    /**
     * Review correction, §5.6/§11 — the rolling-window decision. A cursor
     * existing is NOT by itself proof the local cache still covers the
     * product's configured future horizon: neither provider's incremental
     * mechanism widens the bounded window its OWN originating full read
     * established (see config/calendar_external.php's own note). An
     * otherwise-healthy incremental cursor is therefore still routed to a
     * full read once the last full read is missing or old enough that the
     * cached window has meaningfully drifted behind "now".
     */
    private function needsFullResync(ExternalCalendarConnection $connection): bool
    {
        if (empty($connection->sync_cursor) || $connection->last_full_synced_at === null) {
            return true;
        }

        $intervalHours = (int) config('calendar_external.sync.full_resync_interval_hours');

        return $connection->last_full_synced_at->addHours($intervalHours)->isPast();
    }

    /**
     * §7.5 step 2/3 + §5.6 — the ONLY place a busy-block row is written.
     * Ensure-then-lock the connection's User's tier-2 row, apply the whole
     * write set (upserts, tombstone deletes, full-sync reconciliation,
     * cursor advance) in one transaction, then commit.
     */
    private function applyPage(ExternalCalendarConnection $connection, ExternalCalendarSyncPage $page, bool $isFullSync): void
    {
        $userId = (int) $connection->user_id;
        $windowStart = now();
        $windowEnd = now()->addDays((int) config('calendar_external.sync.full_sync_window_days'));

        $this->locks->ensure([$userId]);

        DB::transaction(function () use ($connection, $page, $isFullSync, $userId, $windowStart, $windowEnd): void {
            $this->locks->lockAscending([$userId]);

            // The connection can end (disconnect / revoke) while the provider
            // read above was in flight. Ending takes this same tier-2 lock,
            // so re-checking the state NOW, under it, is race-free: a page
            // fetched for a connection that has since ended is discarded
            // rather than resurrecting busy blocks and a cursor that
            // endConnection() just destroyed.
            $state = DB::table('external_calendar_connections')->where('id', $connection->id)->value('state');

            if ($state !== ExternalCalendarConnectionState::Active->value) {
                return;
            }

            $keptProviderEventIds = [];

            foreach ($page->events as $event) {
                if ($event->deleted) {
                    // §5.6 rule 1 — a tombstone for an id already absent is
                    // a successful no-op, so replaying it any number of
                    // times is idempotent.
                    DB::table('external_calendar_busy_blocks')
                        ->where('external_calendar_connection_id', $connection->id)
                        ->where('provider_event_id', $event->providerEventId)
                        ->delete();

                    continue;
                }

                $keptProviderEventIds[] = $event->providerEventId;

                // §5.6/§7 — the (connection_id, provider_event_id) unique
                // key is the idempotency mechanism: an upsert of the same
                // event twice is a no-op update, never a duplicate row.
                DB::table('external_calendar_busy_blocks')->updateOrInsert(
                    [
                        'external_calendar_connection_id' => $connection->id,
                        'provider_event_id' => $event->providerEventId,
                    ],
                    [
                        'start_at' => $event->startAt,
                        'end_at' => $event->endAt,
                        'busy_type' => $event->busyType,
                        'synced_at' => now(),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }

            // §5.6 rule 2 — reconciliation ONLY for a full sync whose ENTIRE
            // paginated read succeeded (page->complete is only ever true
            // under that condition — see ExternalCalendarSyncPage), and only
            // within the window that sync actually covered. An incremental
            // page never reconciles by absence; it only ever applies
            // explicit tombstones (rule 1).
            if ($isFullSync && $page->complete) {
                $query = DB::table('external_calendar_busy_blocks')
                    ->where('external_calendar_connection_id', $connection->id)
                    ->where('start_at', '<', $windowEnd)
                    ->where('end_at', '>', $windowStart);

                if ($keptProviderEventIds !== []) {
                    $query->whereNotIn('provider_event_id', $keptProviderEventIds);
                }

                $query->delete();
            }

            // §5.6 rule 4 — the cursor advances WITH the data, in the same
            // transaction, never ahead of it.
            $update = [
                'sync_cursor' => $page->nextCursor,
                'last_synced_at' => now(),
                'last_sync_failure_at' => null,
                'sync_failure_count' => 0,
                'failure_classification' => null,
                'updated_at' => now(),
            ];

            // Review correction — last_full_synced_at moves ONLY here,
            // ONLY for a full sync whose entire paginated read completed
            // (the same condition rule 2's reconciliation above already
            // requires), and in the SAME transaction as the cursor and the
            // reconciliation it describes. An incremental sync's success
            // never touches it — see needsFullResync()'s own docblock for
            // why overloading last_synced_at for this purpose would be
            // wrong.
            if ($isFullSync && $page->complete) {
                $update['last_full_synced_at'] = now();
            }

            DB::table('external_calendar_connections')
                ->where('id', $connection->id)
                ->update($update);
        });

        $connection->refresh();
    }
}

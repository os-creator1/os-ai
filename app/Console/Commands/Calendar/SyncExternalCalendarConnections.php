<?php

namespace App\Console\Commands\Calendar;

use App\Enums\Calendar\ExternalCalendarConnectionState;
use App\Library\Calendar\ExternalCalendar\ExternalCalendarNotificationRegistrar;
use App\Library\Calendar\ExternalCalendar\ExternalCalendarSyncService;
use App\Models\ExternalCalendarConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Implementation Contract 15 §12.F — the scheduled sweep for every active
 * external calendar connection: full/incremental sync, AND (review
 * correction) renewing the provider push registration before it expires.
 *
 * Reuses this one existing sweep for both jobs rather than inventing a
 * second scheduler, per the review's explicit instruction. Registration
 * renewal runs first so a freshly-(re)established channel's sync still
 * happens in the same pass; either step's failure is isolated per
 * connection and never blocks the other, the same division
 * AdvanceWorkspaceAccountLifecycle uses for its own sweep — one
 * connection's provider failure or unexpected exception is logged and
 * counted, never allowed to abort the run for every other connection.
 * ExternalCalendarSyncService and ExternalCalendarNotificationRegistrar
 * both already fail safe-stale/non-fatal for an ordinary provider failure;
 * the try/catch here exists only for a genuinely unexpected exception
 * escaping those boundaries.
 */
class SyncExternalCalendarConnections extends Command
{
    protected $signature = 'calendar:sync-external-connections';

    protected $description = 'Renew provider push registrations and full/incremental-sync every active external (Google/Outlook) calendar connection';

    public function handle(ExternalCalendarSyncService $syncService, ExternalCalendarNotificationRegistrar $registrar): int
    {
        $synced = 0;
        $failed = 0;
        $registrationsRenewed = 0;

        ExternalCalendarConnection::query()
            ->where('state', ExternalCalendarConnectionState::Active->value)
            ->orderBy('id')
            ->chunkById(50, function ($connections) use ($syncService, $registrar, &$synced, &$failed, &$registrationsRenewed): void {
                foreach ($connections as $connection) {
                    try {
                        $wasRegistered = $connection->notification_registration_id;
                        $registrar->ensureRegistered($connection);

                        if ($connection->fresh()->notification_registration_id !== $wasRegistered) {
                            $registrationsRenewed++;
                        }

                        $syncService->syncConnection($connection->fresh());
                        $synced++;
                    } catch (Throwable $exception) {
                        $failed++;
                        Log::warning('External calendar sync sweep could not process a connection.', [
                            'connection_id' => $connection->id,
                            'exception' => $exception::class,
                            'message' => $exception->getMessage(),
                        ]);
                    }
                }
            });

        $this->info("External calendar connections synced: {$synced} (failed: {$failed}, registrations renewed: {$registrationsRenewed}).");

        return self::SUCCESS;
    }
}

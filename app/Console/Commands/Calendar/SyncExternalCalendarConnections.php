<?php

namespace App\Console\Commands\Calendar;

use App\Enums\Calendar\ExternalCalendarConnectionState;
use App\Library\Calendar\ExternalCalendar\ExternalCalendarSyncService;
use App\Models\ExternalCalendarConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Implementation Contract 15 §12.F — the scheduled full+incremental sync
 * job for every active external calendar connection.
 *
 * Per-connection isolation, the same division AdvanceWorkspaceAccountLifecycle
 * uses for its own sweep: one connection's provider failure or unexpected
 * exception is logged and counted, never allowed to abort the run for every
 * other connection. ExternalCalendarSyncService itself already fails
 * safe-stale for an ordinary provider failure (§11); the try/catch here
 * exists only for a genuinely unexpected exception escaping that boundary.
 */
class SyncExternalCalendarConnections extends Command
{
    protected $signature = 'calendar:sync-external-connections';

    protected $description = 'Full/incremental-sync every active external (Google/Outlook) calendar connection';

    public function handle(ExternalCalendarSyncService $syncService): int
    {
        $synced = 0;
        $failed = 0;

        ExternalCalendarConnection::query()
            ->where('state', ExternalCalendarConnectionState::Active->value)
            ->orderBy('id')
            ->chunkById(50, function ($connections) use ($syncService, &$synced, &$failed): void {
                foreach ($connections as $connection) {
                    try {
                        $syncService->syncConnection($connection);
                        $synced++;
                    } catch (Throwable $exception) {
                        $failed++;
                        Log::warning('External calendar sync sweep could not sync a connection.', [
                            'connection_id' => $connection->id,
                            'exception' => $exception::class,
                            'message' => $exception->getMessage(),
                        ]);
                    }
                }
            });

        $this->info("External calendar connections synced: {$synced} (failed: {$failed}).");

        return self::SUCCESS;
    }
}

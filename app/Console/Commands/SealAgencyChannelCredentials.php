<?php

namespace App\Console\Commands;

use App\Library\Security\SealedSecret;
use App\Models\AgencyProspectingChannel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time, idempotent upgrade of agency-owned provider credentials saved before
 * sealing existed. Touches ONLY sending servers an agency channel points at —
 * never Growth/admin servers — and only api_key / auth_token. A value already
 * sealed is skipped; a plaintext value is sealed in place. Reads keep working
 * for both formats at every point, so running it late (or never) is safe.
 */
class SealAgencyChannelCredentials extends Command
{
    protected $signature = 'agency-channels:seal-credentials {--dry-run : Report what would be sealed without writing}';

    protected $description = 'Encrypt plaintext provider credentials stored for agency-owned channels';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $sealed = 0;
        $already = 0;

        AgencyProspectingChannel::query()->with('sendingServer')->orderBy('id')->chunkById(100, function ($channels) use ($dry, &$sealed, &$already): void {
            foreach ($channels as $channel) {
                $server = $channel->sendingServer;

                if ($server === null) {
                    continue;
                }

                $updates = [];

                foreach (['api_key', 'auth_token'] as $column) {
                    $raw = $server->getRawOriginal($column);

                    if ($raw === null || $raw === '') {
                        continue;
                    }

                    if (SealedSecret::isSealed($raw)) {
                        $already++;

                        continue;
                    }

                    $updates[$column] = SealedSecret::seal($raw);
                }

                if ($updates !== []) {
                    $sealed += count($updates);

                    if (! $dry) {
                        DB::table('sending_servers')->where('id', $server->id)->update($updates);
                    }
                }
            }
        });

        $this->info(($dry ? 'Would seal ' : 'Sealed ') . $sealed . ' credential(s); ' . $already . ' already sealed.');

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Library\Voice\VoiceOutboundDialer;
use Illuminate\Console\Command;

/**
 * Browser Calling — closes abandoned bridge setups (never re-transfers) and re-attempts provider hangups that failed (or were never made) after a cancel, a failed
 * bridge or a browser-ended call. Bounded and idempotent: a leg Telnyx no longer has counts as hung up.
 */
class RetryVoiceLegTeardowns extends Command
{
    protected $signature = 'voice:retry-teardowns {--limit=50 : Maximum number of calls to retry}';

    protected $description = 'Retry provider hangups owed for canceled or failed browser calls';

    public function handle(VoiceOutboundDialer $dialer): int
    {
        $limit = $this->option('limit');

        if (! is_numeric($limit) || (int) $limit < 1) {
            $this->error('The --limit option must be a positive integer.');

            return self::INVALID;
        }

        $this->info('Abandoned bridges closed: ' . $dialer->recoverAbandonedBridges((int) $limit));
        $this->info('Provider legs confirmed hung up: ' . $dialer->retryPendingTeardowns((int) $limit));

        return self::SUCCESS;
    }
}

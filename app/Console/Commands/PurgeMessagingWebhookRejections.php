<?php

namespace App\Console\Commands;

use App\Models\MessagingWebhookRejection;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Customer Experience Slice 3 §4.2 — retention and disposal for the webhook
 * rejection audit.
 *
 * Hard-deletes, not soft-deletes: even the minimized content here (a payload
 * hash plus two low-sensitivity identifiers) should not accumulate
 * indefinitely. Rows are removed strictly by last_seen_at, so a rejection
 * that keeps recurring keeps its row and its occurrence_count.
 */
class PurgeMessagingWebhookRejections extends Command
{
    protected $signature = 'messaging:purge-webhook-rejections {--days= : Override the configured retention window}';

    protected $description = 'Delete messaging webhook rejection records older than the configured retention window.';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('messaging.webhook_rejection_retention_days', 30));

        if ($days < 1) {
            $this->error('The retention window must be at least one day.');

            return self::FAILURE;
        }

        $cutoff = Carbon::now()->subDays($days);

        $deleted = MessagingWebhookRejection::query()
            ->where('last_seen_at', '<', $cutoff)
            ->delete();

        $this->info(sprintf(
            'Purged %d messaging webhook rejection record(s) last seen before %s.',
            $deleted,
            $cutoff->toDateTimeString(),
        ));

        return self::SUCCESS;
    }
}

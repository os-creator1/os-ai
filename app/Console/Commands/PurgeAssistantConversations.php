<?php

namespace App\Console\Commands;

use App\Library\Assistant\AssistantRetention;
use App\Models\Business;
use Illuminate\Console\Command;

/** Ask MotionGrove retention: deletes conversations older than config('assistant.retention_days') (default 90). Idempotent. */
class PurgeAssistantConversations extends Command
{
    protected $signature = 'assistant:purge-expired {--business= : only this Business uid} {--chunk=200}';

    protected $description = 'Delete Ask MotionGrove conversations past their retention period (action audit facts are kept, scrubbed).';

    public function handle(AssistantRetention $retention): int
    {
        $businessId = null;
        $workspaceId = null;

        if ($uid = $this->option('business')) {
            $business = Business::query()->where('uid', $uid)->first();

            if ($business === null) {
                $this->error('Unknown business.');

                return self::FAILURE;
            }

            $businessId = (int) $business->id;
            $workspaceId = (int) $business->workspace_id;
        }

        if (AssistantRetention::keepsUntilDeleted()) {
            $this->info('Retention is set to keep conversations until the customer deletes them (ASSISTANT_RETENTION_DAYS=0): nothing to purge.');

            return self::SUCCESS;
        }

        $count = $retention->purgeExpired($workspaceId, $businessId, (int) $this->option('chunk'));
        $this->info("Deleted {$count} expired conversation(s).");

        return self::SUCCESS;
    }
}

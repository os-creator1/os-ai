<?php

namespace App\Console\Commands\Outreach;

use App\Jobs\Outreach\OutreachFollowUpJob;
use App\Models\AgencyProspectCampaign;
use App\Models\AgencyProspectCampaignMember;
use Illuminate\Console\Command;

/**
 * The safety net under the delayed follow-up job (contract §11): re-dispatches every
 * managed-campaign member whose follow-up is due and was neither sent nor cancelled, so
 * a lost or never-run delayed job still gets its one nudge.
 *
 * Harmless to repeat: the job claims `outreach:followup:{memberId}` under the member
 * lock, so dispatching twice sends once. Bounded per run; the next tick takes the rest.
 */
class DispatchDueOutreachFollowUps extends Command
{
    protected $signature = 'outreach:dispatch-due-followups {--limit=500}';

    protected $description = 'Dispatch Outreach follow-ups that are due and have not been sent or cancelled';

    public function handle(): int
    {
        $dispatched = 0;

        AgencyProspectCampaignMember::query()
            ->whereIn('campaign_id', AgencyProspectCampaign::query()->where('sending_mode', AgencyProspectCampaign::MODE_MANAGED)->select('id'))
            ->whereNotNull('followup_at')
            ->where('followup_at', '<=', now())
            ->whereNull('followup_sent_at')
            ->whereNull('followup_cancelled_at')
            ->whereNotIn('stage', [6, 99])
            ->orderBy('followup_at')
            ->limit(max(1, (int) $this->option('limit')))
            ->pluck('id')
            ->each(function (int $id) use (&$dispatched): void {
                OutreachFollowUpJob::dispatch($id);
                $dispatched++;
            });

        $this->info("Outreach follow-ups dispatched: {$dispatched}.");

        return self::SUCCESS;
    }
}

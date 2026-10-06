<?php

namespace App\Jobs\Seo;

use App\Enums\Business\BusinessStatus;
use App\Enums\Seo\SeoRankTrackingState;
use App\Library\Seo\Rank\SeoRankCheckPlanner;
use App\Library\Seo\Rank\SeoRankTrackingBudget;
use App\Models\SeoRankTarget;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Hourly scheduler tick: select tracked targets that are due, ask the budget
 * authority (through the planner) for each paid check, and queue a submit job
 * ONLY for runs the authority just created. It makes no provider call itself.
 *
 * Safe to run repeatedly and concurrently: the run idempotency key allows one
 * automatic check per target and type per cadence window. With the master switch
 * off it does nothing. Pass a target id to start that one target's first check
 * immediately after the owner begins tracking it.
 */
class ScheduleSeoRankChecks implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly ?int $targetId = null)
    {
    }

    public function handle(SeoRankCheckPlanner $planner, SeoRankTrackingBudget $budget): void
    {
        if (! $budget->enabled()) {
            return;
        }

        $now = CarbonImmutable::now('UTC');

        SeoRankTarget::query()
            ->where('tracking_state', SeoRankTrackingState::Tracking->value)
            ->when($this->targetId !== null, fn ($q) => $q->whereKey($this->targetId))
            ->where(fn ($q) => $q->whereNull('next_check_at')->orWhere('next_check_at', '<=', $now))
            ->whereHas('keyword', fn ($q) => $q->operational())
            ->whereHas('business', fn ($q) => $q->where('status', BusinessStatus::Active->value))
            ->with('business')
            ->orderBy('id')
            ->chunkById(100, function ($targets) use ($planner, $now) {
                foreach ($targets as $target) {
                    foreach ($planner->planScheduled($target, $now) as $decision) {
                        if ($decision->allowed && $decision->run !== null) {
                            SubmitSeoRankCheck::dispatch($decision->run->id);
                        }
                    }
                }
            });
    }
}

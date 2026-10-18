<?php

namespace App\Jobs\Seo;

use App\Enums\Seo\SeoRankRunState;
use App\Library\Seo\Rank\SeoRankCheckExecutor;
use App\Models\SeoRankCheckRun;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Five-minute pipeline sweep: the safety net that keeps reserved runs moving.
 * It holds crashed submissions, tries to recover held runs by tag, re-queues
 * rejected submits whose backoff has elapsed, and polls submitted runs that are
 * due. It reserves nothing and can create no new spend.
 */
class ProcessSeoRankChecks implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function handle(SeoRankCheckExecutor $executor): void
    {
        $now = CarbonImmutable::now('UTC');

        $executor->holdStuckSubmissions($now);
        $executor->reconcileHeld($now);

        SeoRankCheckRun::query()
            ->where('state', SeoRankRunState::Scheduled->value)
            ->where('next_attempt_at', '<=', $now)
            ->orderBy('id')
            ->limit(200)
            ->pluck('id')
            ->each(fn (int $id) => SubmitSeoRankCheck::dispatch($id));

        SeoRankCheckRun::query()
            ->where('state', SeoRankRunState::Submitted->value)
            ->where('next_attempt_at', '<=', $now)
            ->orderBy('id')
            ->limit(500)
            ->pluck('id')
            ->each(fn (int $id) => PollSeoRankCheck::dispatch($id));
    }
}

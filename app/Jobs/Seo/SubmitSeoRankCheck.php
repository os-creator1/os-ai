<?php

namespace App\Jobs\Seo;

use App\Library\Seo\Rank\SeoRankCheckExecutor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Submit ONE already-reserved rank check run to the provider's Standard queue.
 *
 * IDS ONLY. `$tries = 1` on purpose: a queue-level retry of a job that may have
 * reached the provider is exactly how double charges happen. Bounded retry of a
 * DEFINITIVELY rejected submit lives in SeoRankCheckExecutor (state-based, with
 * the same reservation); an ambiguous outcome holds the run instead.
 */
class SubmitSeoRankCheck implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $runId)
    {
    }

    public function handle(SeoRankCheckExecutor $executor): void
    {
        $executor->submit($this->runId);
    }
}

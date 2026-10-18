<?php

namespace App\Jobs\Seo;

use App\Library\Seo\Rank\SeoRankCheckExecutor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Collect ONE submitted rank check run by its stored provider task id. Collecting
 * is free and repeatable and can never submit anything, so it is safe to
 * duplicate; the executor's lease keeps two workers off the same task.
 */
class PollSeoRankCheck implements ShouldQueue
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
        $executor->poll($this->runId);
    }
}

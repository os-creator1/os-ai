<?php

namespace App\Jobs\PlatformAutomation;

use App\Library\PlatformAutomation\PlatformRunExecutor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Advances one Platform run: runs due steps, parks it for a wait / retry / approval. */
class ExecutePlatformAutomationRun implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $runId)
    {
    }

    public function handle(PlatformRunExecutor $executor): void
    {
        $executor->advance($this->runId);
    }
}

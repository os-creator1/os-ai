<?php

namespace App\Jobs\Growth;

use App\Library\Growth\GrowthEvaluationService;
use App\Repositories\Contracts\BusinessRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Runs one Growth evaluation for one Business. Long-running work belongs in a
 * job (project rule); GrowthEvaluationService owns all of the behaviour and
 * isolates each engine worker, so this class only loads the Business and
 * sequences the call.
 *
 * It never retries: the next debounce window or the daily sweep re-evaluates,
 * and each worker already records its own safe failure.
 */
class RunGrowthEvaluation implements ShouldQueue, ShouldQueueAfterCommit
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $businessId)
    {
        $this->onQueue(config('opportunity.queue', 'default'));
    }

    public function handle(BusinessRepository $businesses, GrowthEvaluationService $evaluation): void
    {
        $business = $businesses->findById($this->businessId);

        if ($business === null) {
            Log::warning('RunGrowthEvaluation found no Business to evaluate', ['business_id' => $this->businessId]);

            return;
        }

        $result = $evaluation->evaluate($business);

        if (! $result['ran']) {
            Log::info('RunGrowthEvaluation skipped', ['business_id' => $this->businessId, 'reason' => $result['reason']]);
        }
    }
}

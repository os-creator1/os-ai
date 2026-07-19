<?php

namespace App\Jobs\Opportunity;

use App\Library\Opportunity\BusinessAdvisorOpportunityProducer;
use App\Library\Opportunity\Exceptions\RunAlreadyActiveException;
use App\Library\Opportunity\OpportunityCandidateData;
use App\Library\Opportunity\OpportunityManager;
use App\Repositories\Contracts\BusinessRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Drives one business_advisor run end to end (RFC-002 §40): beginRun(),
 * loop produce() into stageCandidate(), then finalizeSuccessfulRun(). Never
 * duplicates OpportunityManager's own locking/timeout/recurrence/scoring
 * logic — this class only sequences calls into it and translates failures
 * into a safe, customer-facing summary.
 */
class RunBusinessAdvisorOpportunityProducer implements ShouldQueue, ShouldQueueAfterCommit
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 60, 300];

    private const PRODUCER_VERSION = 1;

    /**
     * The only string ever persisted to safe_error_summary — never an
     * exception message, SQL, a path, or any other internal detail.
     */
    private const SAFE_ERROR = 'We could not refresh your opportunities right now. Please try again later.';

    public function __construct(
        public readonly int $businessId,
    ) {
        $this->onQueue(config('opportunity.queue', 'default'));
    }

    public function handle(
        BusinessRepository $businessRepository,
        BusinessAdvisorOpportunityProducer $producer,
        OpportunityManager $manager,
    ): void {
        $business = $businessRepository->findById($this->businessId);

        if ($business === null) {
            Log::warning('RunBusinessAdvisorOpportunityProducer found no Business to run against', [
                'business_id' => $this->businessId,
            ]);

            return;
        }

        $run = null;

        try {
            try {
                $run = $manager->beginRun($business, $producer->workerKey(), self::PRODUCER_VERSION);
            } catch (RunAlreadyActiveException) {
                // Another healthy run already owns this work — a safe,
                // expected no-op, never routed through failRun().
                Log::info('RunBusinessAdvisorOpportunityProducer skipped — a healthy run is already active', [
                    'business_id' => $this->businessId,
                    'worker_key' => $producer->workerKey()->value,
                ]);

                return;
            }

            foreach ($producer->produce($business) as $candidate) {
                if (! $candidate instanceof OpportunityCandidateData) {
                    throw new RuntimeException('BusinessAdvisorOpportunityProducer yielded a value that is not an OpportunityCandidateData instance.');
                }

                $manager->stageCandidate($run, $candidate);
            }

            $manager->finalizeSuccessfulRun($run);
        } catch (Throwable $e) {
            Log::error('RunBusinessAdvisorOpportunityProducer failed', [
                'business_id' => $this->businessId,
                'run_id' => $run?->id,
                'worker_key' => $producer->workerKey()->value,
                'exception' => $e,
            ]);

            if ($run !== null) {
                try {
                    $manager->failRun($run, self::SAFE_ERROR);
                } catch (Throwable $failException) {
                    Log::error('RunBusinessAdvisorOpportunityProducer also failed to record the run failure itself', [
                        'business_id' => $this->businessId,
                        'run_id' => $run->id,
                        'worker_key' => $producer->workerKey()->value,
                        'exception' => $failException,
                    ]);
                }
            }

            throw $e;
        }
    }
}

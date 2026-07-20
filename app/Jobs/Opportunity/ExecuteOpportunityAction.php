<?php

namespace App\Jobs\Opportunity;

use App\DTO\Opportunity\OpportunityExecutionAttempt;
use App\Library\Opportunity\Exceptions\OpportunityActionNotExecutableException;
use App\Library\Opportunity\Exceptions\OpportunityActionVerificationException;
use App\Library\Opportunity\OpportunityActionExecutor;
use App\Library\Opportunity\OpportunityManager;
use App\Models\OpportunityActionExecution;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Drives one execution attempt end to end (RFC-002 §30.1, §30.2): locks and
 * validates the pending execution (OpportunityManager::beginExecutionAttempt()),
 * invokes the real registry handler in its own nested transaction
 * (OpportunityActionExecutor::execute()) so a Business mutation that fails
 * verification rolls back to that savepoint without losing the outer
 * transaction, then persists the terminal outcome
 * (OpportunityManager::recordExecutionResult()). The whole sequence is one
 * outer transaction: if terminal persistence itself fails, everything —
 * including the pending→running transition and any applied Business
 * mutation — rolls back together and the job is retried.
 */
class ExecuteOpportunityAction implements ShouldQueue, ShouldQueueAfterCommit
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 60, 300];

    /**
     * The only three strings ever persisted to safe_error_summary — never
     * an exception message, SQL, a path, or any other internal detail
     * (RFC-002 §47), matching OpportunityManager::ALLOWED_FAILURE_SUMMARIES
     * exactly (that constant is private to OpportunityManager, so these are
     * independently fixed here, the same way
     * RunBusinessAdvisorOpportunityProducer::SAFE_ERROR is its own literal).
     */
    private const SAFE_SUMMARY_GENERIC_FAILURE = 'Opportunity action could not be completed.';

    private const SAFE_SUMMARY_STATE_MISMATCH = 'Opportunity action state no longer matched the approved action.';

    private const SAFE_SUMMARY_VERIFICATION_FAILED = 'Business update could not be verified.';

    public function __construct(
        public readonly int $executionId,
    ) {
        $this->onQueue(config('opportunity.queue', 'default'));
    }

    public function handle(OpportunityManager $manager, OpportunityActionExecutor $executor): void
    {
        $execution = OpportunityActionExecution::find($this->executionId);

        if ($execution === null) {
            Log::warning('ExecuteOpportunityAction found no execution to run against', [
                'execution_id' => $this->executionId,
            ]);

            return;
        }

        try {
            DB::transaction(function () use ($manager, $executor, $execution) {
                try {
                    $attempt = $manager->beginExecutionAttempt($execution);
                } catch (OpportunityActionNotExecutableException $e) {
                    // A genuine §30.1 step 3 pre-invocation mismatch: the
                    // execution never reached running, so nothing was
                    // mutated. Route straight to the failure path, still
                    // inside this same outer transaction.
                    Log::error('ExecuteOpportunityAction found a pre-invocation action mismatch', [
                        'execution_id' => $execution->id,
                        'exception' => $e,
                    ]);

                    $manager->recordExecutionResult($execution, false, self::SAFE_SUMMARY_STATE_MISMATCH);

                    return;
                }

                if ($attempt === null) {
                    // Redelivery of an already running/succeeded/failed
                    // execution: a safe no-op, nothing to do.
                    return;
                }

                [$succeeded, $safeSummary] = $this->runHandler($executor, $attempt);

                $manager->recordExecutionResult($attempt->execution, $succeeded, $safeSummary);
            });
        } catch (Throwable $e) {
            Log::error('ExecuteOpportunityAction failed', [
                'execution_id' => $this->executionId,
                'exception' => $e,
            ]);

            throw $e;
        }
    }

    /**
     * Invokes the real handler inside its own explicit nested
     * transaction/savepoint (RFC-002 §30.2's "Transaction A" mutation +
     * verification) — never a DB::transaction() opened by the executor
     * itself, which owns no transaction of its own. A verification failure
     * or any other handler exception rolls back only this savepoint,
     * undoing an already-applied Business mutation, while the caller's
     * outer transaction stays open to record the failure.
     *
     * @return array{0: bool, 1: string}
     */
    private function runHandler(OpportunityActionExecutor $executor, OpportunityExecutionAttempt $attempt): array
    {
        try {
            $safeSummary = DB::transaction(fn () => $executor->execute($attempt->opportunity, $attempt->execution));

            return [true, $safeSummary];
        } catch (OpportunityActionVerificationException $e) {
            Log::error('ExecuteOpportunityAction could not verify its Business mutation', [
                'execution_id' => $attempt->execution->id,
                'exception' => $e,
            ]);

            return [false, self::SAFE_SUMMARY_VERIFICATION_FAILED];
        } catch (OpportunityActionNotExecutableException $e) {
            Log::error('ExecuteOpportunityAction found the action no longer executable immediately before invocation', [
                'execution_id' => $attempt->execution->id,
                'exception' => $e,
            ]);

            return [false, self::SAFE_SUMMARY_STATE_MISMATCH];
        } catch (Throwable $e) {
            Log::error('ExecuteOpportunityAction failed to apply its Business mutation', [
                'execution_id' => $attempt->execution->id,
                'exception' => $e,
            ]);

            return [false, self::SAFE_SUMMARY_GENERIC_FAILURE];
        }
    }
}

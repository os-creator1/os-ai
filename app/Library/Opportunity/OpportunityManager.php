<?php

declare(strict_types=1);

namespace App\Library\Opportunity;

use App\Enums\Opportunity\OpportunityRunStatus;
use App\Enums\Opportunity\OpportunityWorkerKey;
use App\Events\Opportunity\OpportunityRunFailed;
use App\Events\Opportunity\OpportunityRunStarted;
use App\Library\Opportunity\Exceptions\RunAlreadyActiveException;
use App\Models\Business;
use App\Models\OpportunityRun;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\OpportunityRunRepository;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Owns every transaction and invariant of the Opportunity Engine run
 * protocol (RFC-002 §37). This pass implements only beginRun(); every other
 * method (stageCandidate, finalizeSuccessfulRun, failRun, and the customer/
 * admin workflow methods) is added in later phases.
 */
class OpportunityManager
{
    private const HEARTBEAT_TIMEOUT_REASON_CODE = 'heartbeat_timeout';

    private const HEARTBEAT_TIMEOUT_SAFE_SUMMARY = 'This run stopped responding and was marked failed.';

    public function __construct(
        private readonly BusinessRepository $businessRepository,
        private readonly OpportunityRunRepository $runRepository,
    ) {
    }

    /**
     * Begins a new run for ($business, $workerKey) (RFC-002 §24, §25, §37).
     *
     * Lock order: Business row, then the existing running run row for the
     * same (business, worker), if any — never the reverse. If that run is
     * still within the configured heartbeat timeout, it is healthy and this
     * throws RunAlreadyActiveException without writing anything. If it has
     * timed out, it is marked failed/abandoned and the replacement run is
     * created — both in this same transaction, so abandonment and
     * replacement creation are atomic.
     */
    public function beginRun(Business $business, OpportunityWorkerKey $workerKey, int $producerVersion): OpportunityRun
    {
        return DB::transaction(function () use ($business, $workerKey, $producerVersion) {
            $now = now();

            $lockedBusiness = $this->businessRepository->findForUpdate($business->id);

            if ($lockedBusiness === null) {
                throw new RuntimeException('The Business no longer exists.');
            }

            $existingRun = $this->runRepository->findRunningForUpdate($lockedBusiness->id, $workerKey);

            if ($existingRun !== null) {
                $cutoff = $now->copy()->subMinutes(config('opportunity.run_timeout_minutes', 30));

                // "older than" the cutoff (§24.2) is a strict less-than — a
                // heartbeat exactly at the cutoff is still healthy, not
                // abandoned, and this run is left completely untouched.
                if ($existingRun->heartbeat_at->gte($cutoff)) {
                    throw new RunAlreadyActiveException(
                        "An active run already exists for business [{$lockedBusiness->id}], worker [{$workerKey->value}]."
                    );
                }

                $this->runRepository->update($existingRun, [
                    'status' => OpportunityRunStatus::Failed->value,
                    'completed_at' => $now,
                    'abandoned_at' => $now,
                    'reason_code' => self::HEARTBEAT_TIMEOUT_REASON_CODE,
                    'safe_error_summary' => self::HEARTBEAT_TIMEOUT_SAFE_SUMMARY,
                ]);

                OpportunityRunFailed::dispatch(
                    $existingRun->id,
                    $lockedBusiness->id,
                    $workerKey->value,
                    self::HEARTBEAT_TIMEOUT_SAFE_SUMMARY,
                );
            }

            $run = $this->runRepository->create([
                'business_id' => $lockedBusiness->id,
                'worker_key' => $workerKey->value,
                'producer_version' => $producerVersion,
                'status' => OpportunityRunStatus::Running->value,
                'started_at' => $now,
                'heartbeat_at' => $now,
            ]);

            OpportunityRunStarted::dispatch($run->id, $lockedBusiness->id, $workerKey->value);

            return $run;
        });
    }
}

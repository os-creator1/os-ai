<?php

declare(strict_types=1);

namespace App\Library\Opportunity;

use App\DTO\Opportunity\OpportunityExecutionAttempt;
use App\Enums\Business\BusinessGoal;
use App\Enums\Opportunity\OpportunityActionExecutionStatus;
use App\Enums\Opportunity\OpportunityCompletionPolicy;
use App\Enums\Opportunity\OpportunityFreshness;
use App\Enums\Opportunity\OpportunityRunStatus;
use App\Enums\Opportunity\OpportunityStatus;
use App\Enums\Opportunity\OpportunityTransitionActorType;
use App\Enums\Opportunity\OpportunityTransitionCategory;
use App\Enums\Opportunity\OpportunityWorkerKey;
use App\Events\Opportunity\OpportunityApprovalRequested;
use App\Events\Opportunity\OpportunityBecameCurrent;
use App\Events\Opportunity\OpportunityCompleted;
use App\Events\Opportunity\OpportunityCreated;
use App\Events\Opportunity\OpportunityDismissed;
use App\Events\Opportunity\OpportunityExecutionFailed;
use App\Events\Opportunity\OpportunityExecutionStarted;
use App\Events\Opportunity\OpportunityExecutionSucceeded;
use App\Events\Opportunity\OpportunityMarkedStale;
use App\Events\Opportunity\OpportunityReaffirmed;
use App\Events\Opportunity\OpportunityRunFailed;
use App\Events\Opportunity\OpportunityRunStarted;
use App\Events\Opportunity\OpportunityReopened;
use App\Events\Opportunity\OpportunityRunSucceeded;
use App\Events\Opportunity\OpportunitySnoozed;
use App\Jobs\Opportunity\ExecuteOpportunityAction;
use App\Library\Opportunity\Exceptions\CandidateLimitExceededException;
use App\Library\Opportunity\Exceptions\CrossWorkerFingerprintCollisionException;
use App\Library\Opportunity\Exceptions\ImmutableCandidateIdentityMismatchException;
use App\Library\Opportunity\Exceptions\InvalidOpportunityActionParametersException;
use App\Library\Opportunity\Exceptions\InvalidOpportunityCandidateException;
use App\Library\Opportunity\Exceptions\InvalidOpportunityExecutionStateException;
use App\Library\Opportunity\Exceptions\InvalidOpportunityStateException;
use App\Library\Opportunity\Exceptions\InvalidSnoozeUntilException;
use App\Library\Opportunity\Exceptions\OpportunityActionNotConfigurableException;
use App\Library\Opportunity\Exceptions\OpportunityActionNotExecutableException;
use App\Library\Opportunity\Exceptions\OpportunityApprovalNotRequiredException;
use App\Library\Opportunity\Exceptions\OpportunityEvidenceValidationException;
use App\Library\Opportunity\Exceptions\RunAbandonedException;
use App\Library\Opportunity\Exceptions\RunAlreadyActiveException;
use App\Library\Opportunity\Exceptions\RunAlreadyFailedException;
use App\Library\Opportunity\Exceptions\RunAlreadySucceededException;
use App\Library\Opportunity\Exceptions\RunNotActiveException;
use App\Library\Opportunity\Exceptions\RunNotFoundException;
use App\Library\Opportunity\Exceptions\UnsupportedOpportunityTypeException;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Opportunity;
use App\Models\OpportunityActionExecution;
use App\Models\OpportunityRun;
use App\Models\OpportunityRunCandidate;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\CustomerOnboardingRepository;
use App\Repositories\Contracts\OpportunityActionExecutionRepository;
use App\Repositories\Contracts\OpportunityRepository;
use App\Repositories\Contracts\OpportunityRunCandidateRepository;
use App\Repositories\Contracts\OpportunityRunRepository;
use App\Repositories\Contracts\OpportunityTransitionRepository;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Owns every transaction and invariant of the Opportunity Engine run
 * protocol (RFC-002 §37). This pass implements beginRun(), stageCandidate(),
 * finalizeSuccessfulRun(), and failRun(); the customer/admin workflow
 * methods are added in later phases.
 */
class OpportunityManager
{
    private const HEARTBEAT_TIMEOUT_REASON_CODE = 'heartbeat_timeout';

    private const HEARTBEAT_TIMEOUT_SAFE_SUMMARY = 'This run stopped responding and was marked failed.';

    /**
     * The one safe summary permitted when recordExecutionResult() records a
     * failure whose live action no longer matches the execution it was
     * bound to (RFC-002 §30.1 step 3, §47) — the only allowlisted string
     * that is ever accepted for a state-mismatch failure, regardless of
     * which other allowlisted strings exist for the matching-action case.
     */
    private const STATE_MISMATCH_SAFE_SUMMARY = 'Opportunity action state no longer matched the approved action.';

    /**
     * Fixed, source-controlled customer-safe failure summaries for
     * recordExecutionResult() (RFC-002 §47) — never a raw exception
     * message, SQL fragment, stack trace, or file path.
     */
    private const ALLOWED_FAILURE_SUMMARIES = [
        'Opportunity action could not be completed.',
        self::STATE_MISMATCH_SAFE_SUMMARY,
        'Business update could not be verified.',
    ];

    public function __construct(
        private readonly BusinessRepository $businessRepository,
        private readonly OpportunityRunRepository $runRepository,
        private readonly OpportunityRunCandidateRepository $candidateRepository,
        private readonly CustomerOnboardingRepository $onboardingRepository,
        private readonly OpportunityFingerprint $fingerprint,
        private readonly OpportunityActionHash $actionHash,
        private readonly OpportunityEvidenceValidator $evidenceValidator,
        private readonly OpportunityScorer $scorer,
        private readonly OpportunityRepository $opportunityRepository,
        private readonly OpportunityTransitionRepository $transitionRepository,
        private readonly OpportunityActionExecutionRepository $actionExecutionRepository,
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

    /**
     * Validates and upserts one candidate into a run's isolated staging
     * table (RFC-002 §21). Never touches `opportunities` — only the run and
     * candidate tables. One immutable $now, captured after the run lock is
     * acquired, is reused for the timeout check, scored_at, evidence
     * timestamp validation, evidence freshness, scoring, and the run's
     * refreshed heartbeat_at.
     */
    public function stageCandidate(OpportunityRun $run, OpportunityCandidateData $data): OpportunityRunCandidate
    {
        return DB::transaction(function () use ($run, $data) {
            $locked = $this->runRepository->findForUpdate($run->id);

            if ($locked === null || $locked->status !== OpportunityRunStatus::Running) {
                throw new RunNotActiveException("Run [{$run->id}] is not active.");
            }

            $now = now();

            $cutoff = $now->copy()->subMinutes(config('opportunity.run_timeout_minutes', 30));

            // Symmetric with beginRun()'s boundary (§24.2): a heartbeat
            // exactly at the cutoff is still active. Staging itself never
            // marks the run failed/abandoned — only beginRun()'s
            // abandonment-recovery path does that.
            if ($locked->heartbeat_at->lt($cutoff)) {
                throw new RunAbandonedException("Run [{$locked->id}] has been abandoned (stale heartbeat).");
            }

            $typeDefinition = OpportunityTypeRegistry::get($locked->worker_key->value, $data->type);

            if ($typeDefinition === null) {
                throw new UnsupportedOpportunityTypeException(
                    "Unsupported (worker_key, type) pair: ({$locked->worker_key->value}, {$data->type})."
                );
            }

            $this->assertCandidateRanges($data);
            $this->assertNoTemplateParameters($data);
            $this->assertNullContext($data);
            $this->assertNoActionParameters($data);
            $this->assertGoalKeysAreValid($data, $typeDefinition);

            $business = $locked->business;

            if ($business === null) {
                throw new InvalidOpportunityCandidateException(
                    "Run [{$locked->id}] references a Business that no longer exists."
                );
            }

            $evidence = $this->evidenceValidator->validate($data->evidence, $typeDefinition, $now);

            $fingerprintVersion = (int) config('opportunity.fingerprint_version', 1);

            $fingerprintValue = $this->fingerprint->compute(
                $locked->business_id,
                $locked->worker_key,
                $data->type,
                null,
                $fingerprintVersion,
            );

            $existingCandidate = $this->candidateRepository->findForRunByFingerprint($locked->id, $fingerprintValue);

            if ($existingCandidate === null) {
                $maxCandidates = (int) config('opportunity.max_candidates_per_run', 100);
                $distinctCount = $this->candidateRepository->countDistinctForRun($locked->id);

                if ($distinctCount >= $maxCandidates) {
                    throw new CandidateLimitExceededException(
                        "Run [{$locked->id}] has already reached the maximum of {$maxCandidates} staged candidates."
                    );
                }
            } else {
                $this->assertIdentityMatches($existingCandidate, $locked, $data, $fingerprintValue, $fingerprintVersion);
            }

            $storedGoalKeys = $this->resolveStoredGoalKeys($business);
            $goalRelevanceRank = $this->scorer->scoreGoalRelevance($data->relevantGoalKeys, $storedGoalKeys);
            $evidenceFreshnessRank = $this->scorer->scoreEvidenceFreshness($evidence, $now);

            $priorityScore = $this->scorer->computePriorityScore(
                $data->impact,
                $data->urgency,
                $goalRelevanceRank,
                $data->confidence,
                $evidenceFreshnessRank,
                $data->effort,
            );

            [$recommendedAction, $recommendedActionHash, $actionSchemaVersion] = $this->buildRecommendedAction($typeDefinition);

            $candidate = $this->candidateRepository->upsertMutableFields($locked->id, $fingerprintValue, [
                'type' => $data->type,
                'fingerprint_version' => $fingerprintVersion,
                'context_key' => null,
                'title' => $typeDefinition['title_template'],
                'summary' => $typeDefinition['summary_template'],
                'impact' => $data->impact,
                'urgency' => $data->urgency,
                'effort' => $data->effort,
                'confidence' => $data->confidence,
                'goal_relevance_rank' => $goalRelevanceRank,
                'evidence_freshness_rank' => $evidenceFreshnessRank,
                'priority_score' => $priorityScore,
                'scoring_version' => (int) config('opportunity.scoring_version', 1),
                'scored_at' => $now,
                'evidence' => $evidence,
                'recommended_action' => $recommendedAction,
                'recommended_action_hash' => $recommendedActionHash,
                'action_schema_version' => $actionSchemaVersion,
            ]);

            $this->runRepository->update($locked, ['heartbeat_at' => $now]);

            return $candidate;
        });
    }

    /**
     * Applies every staged candidate to live Opportunities atomically
     * (RFC-002 §22): every persisted candidate is fully revalidated before
     * any Opportunity is touched, so a single corrupted candidate rolls
     * back the entire finalization — nothing is ever partially applied. A
     * zero-candidate run is valid and simply stales everything this
     * business/worker previously confirmed as current.
     */
    public function finalizeSuccessfulRun(OpportunityRun $run): void
    {
        DB::transaction(function () use ($run) {
            $lockedRun = $this->runRepository->findForUpdate($run->id);

            if ($lockedRun === null) {
                throw new RunNotFoundException("Run [{$run->id}] does not exist.");
            }

            if ($lockedRun->status === OpportunityRunStatus::Succeeded) {
                return;
            }

            if ($lockedRun->status === OpportunityRunStatus::Failed) {
                throw new RunAlreadyFailedException("Run [{$lockedRun->id}] has already failed and cannot be finalized.");
            }

            $now = now();

            $candidates = $this->candidateRepository->orderedForRun($lockedRun->id);

            // Revalidate every candidate before applying any of them — a
            // corrupted candidate discovered halfway through must never
            // leave earlier candidates already applied (the enclosing
            // transaction alone would prevent that too, but validating
            // first avoids even attempting a lock/write we might undo).
            foreach ($candidates as $candidate) {
                $this->revalidateCandidateIntegrity($candidate, $lockedRun);
            }

            foreach ($candidates as $candidate) {
                $this->applyCandidateToOpportunity($candidate, $lockedRun, $now);
            }

            $this->staleMissingOpportunities($lockedRun, $now);

            $this->runRepository->update($lockedRun, [
                'status' => OpportunityRunStatus::Succeeded->value,
                'completed_at' => $now,
                'heartbeat_at' => $now,
            ]);

            OpportunityRunSucceeded::dispatch($lockedRun->id, $lockedRun->business_id, $lockedRun->worker_key->value);
        });
    }

    /**
     * Explicit failure of a running run (RFC-002 §23), symmetric with
     * beginRun()'s abandonment path but never itself abandonment: no
     * abandoned_at, no reason_code, and heartbeat_at is left untouched.
     * Idempotent only for an already-failed run — its original
     * completed_at/safe_error_summary/abandoned_at/reason_code are never
     * overwritten. Throws for an already-succeeded run; a succeeded run can
     * never be retroactively marked failed.
     */
    public function failRun(OpportunityRun $run, string $safeErrorSummary): void
    {
        DB::transaction(function () use ($run, $safeErrorSummary) {
            $locked = $this->runRepository->findForUpdate($run->id);

            if ($locked === null) {
                throw new RunNotFoundException("Run [{$run->id}] does not exist.");
            }

            if ($locked->status === OpportunityRunStatus::Failed) {
                return;
            }

            if ($locked->status === OpportunityRunStatus::Succeeded) {
                throw new RunAlreadySucceededException("Run [{$locked->id}] has already succeeded and cannot be marked failed.");
            }

            $now = now();

            $this->runRepository->update($locked, [
                'status' => OpportunityRunStatus::Failed->value,
                'completed_at' => $now,
                'safe_error_summary' => $safeErrorSummary,
            ]);

            OpportunityRunFailed::dispatch($locked->id, $locked->business_id, $locked->worker_key->value, $safeErrorSummary);
        });
    }

    /**
     * Customer-initiated dismissal (RFC-002 §17). Valid only from `open` or
     * `awaiting_approval` — every other status, including an
     * already-dismissed Opportunity, throws and leaves the row untouched;
     * this is deliberately not an idempotent no-op. Never touches
     * freshness, action fields, or any evidence/scoring field.
     */
    public function dismiss(Opportunity $opportunity, Customer $customer): Opportunity
    {
        return DB::transaction(function () use ($opportunity, $customer) {
            $locked = $this->opportunityRepository->findOwnedForUpdate($opportunity->id, $opportunity->business_id);

            $locked = $this->assertOpportunityOwnership($customer, $locked);

            if (! in_array($locked->status, [OpportunityStatus::Open, OpportunityStatus::AwaitingApproval], true)) {
                throw new InvalidOpportunityStateException(
                    "Opportunity [{$locked->id}] cannot be dismissed from status [{$locked->status->value}]."
                );
            }

            $now = now();
            $fromStatus = $locked->status;

            $updated = $this->opportunityRepository->update($locked, [
                'status' => OpportunityStatus::Dismissed->value,
                'dismissed_at' => $now,
            ]);

            $this->transitionRepository->create([
                'opportunity_id' => $locked->id,
                'category' => OpportunityTransitionCategory::Workflow->value,
                'from_status' => $fromStatus->value,
                'to_status' => OpportunityStatus::Dismissed->value,
                'actor_type' => OpportunityTransitionActorType::Customer->value,
                'actor_user_id' => $customer->user_id,
                'opportunity_run_id' => null,
                'action_execution_id' => null,
                'reason_code' => 'customer_dismissed',
                'safe_note' => null,
            ]);

            OpportunityDismissed::dispatch($locked->id, $locked->business_id, $customer->user_id, $fromStatus->value);

            return $updated;
        });
    }

    /**
     * Customer-initiated snooze (RFC-002 §17). Valid only from `open` or
     * `awaiting_approval` — every other status, including an
     * already-snoozed Opportunity, throws and leaves the row untouched;
     * this is deliberately not an idempotent no-op. $until is copied into
     * an immutable value before validation, so a mutable Carbon instance
     * the caller continues to hold cannot change the operation afterward.
     * Never touches freshness, action fields, or any evidence/scoring
     * field.
     */
    public function snooze(Opportunity $opportunity, Customer $customer, CarbonInterface $until): Opportunity
    {
        return DB::transaction(function () use ($opportunity, $customer, $until) {
            $locked = $this->opportunityRepository->findOwnedForUpdate($opportunity->id, $opportunity->business_id);

            $locked = $this->assertOpportunityOwnership($customer, $locked);

            if (! in_array($locked->status, [OpportunityStatus::Open, OpportunityStatus::AwaitingApproval], true)) {
                throw new InvalidOpportunityStateException(
                    "Opportunity [{$locked->id}] cannot be snoozed from status [{$locked->status->value}]."
                );
            }

            $now = now();
            $snoozedUntil = CarbonImmutable::instance($until);

            if ($snoozedUntil->lessThanOrEqualTo($now)) {
                throw new InvalidSnoozeUntilException(
                    'snoozedUntil must be strictly later than the current operation timestamp.'
                );
            }

            $fromStatus = $locked->status;

            $updated = $this->opportunityRepository->update($locked, [
                'status' => OpportunityStatus::Snoozed->value,
                'snoozed_until' => $snoozedUntil,
            ]);

            $this->transitionRepository->create([
                'opportunity_id' => $locked->id,
                'category' => OpportunityTransitionCategory::Workflow->value,
                'from_status' => $fromStatus->value,
                'to_status' => OpportunityStatus::Snoozed->value,
                'actor_type' => OpportunityTransitionActorType::Customer->value,
                'actor_user_id' => $customer->user_id,
                'opportunity_run_id' => null,
                'action_execution_id' => null,
                'reason_code' => 'customer_snoozed',
                'safe_note' => null,
            ]);

            OpportunitySnoozed::dispatch(
                $locked->id,
                $locked->business_id,
                $customer->user_id,
                $fromStatus->value,
                $snoozedUntil->toIso8601String(),
            );

            return $updated;
        });
    }

    /**
     * Explicit customer reopen (RFC-002 §17). Valid only from `snoozed`,
     * `dismissed`, or `completed` — an already-open Opportunity throws
     * rather than being absorbed as a no-op. Clears all three
     * workflow-state timestamps (snoozed_until/dismissed_at/completed_at)
     * so the resulting open row never retains a stale one. Never increments
     * occurrence_number — that belongs exclusively to automatic recurrence
     * inside finalizeSuccessfulRun(), never to an explicit customer reopen.
     */
    public function reopen(Opportunity $opportunity, Customer $customer, string $reasonCode): Opportunity
    {
        return DB::transaction(function () use ($opportunity, $customer, $reasonCode) {
            $locked = $this->opportunityRepository->findOwnedForUpdate($opportunity->id, $opportunity->business_id);

            $locked = $this->assertOpportunityOwnership($customer, $locked);

            if (! in_array($locked->status, [OpportunityStatus::Snoozed, OpportunityStatus::Dismissed, OpportunityStatus::Completed], true)) {
                throw new InvalidOpportunityStateException(
                    "Opportunity [{$locked->id}] cannot be reopened from status [{$locked->status->value}]."
                );
            }

            $fromStatus = $locked->status;

            $updated = $this->opportunityRepository->update($locked, [
                'status' => OpportunityStatus::Open->value,
                'snoozed_until' => null,
                'dismissed_at' => null,
                'completed_at' => null,
            ]);

            $this->transitionRepository->create([
                'opportunity_id' => $locked->id,
                'category' => OpportunityTransitionCategory::Workflow->value,
                'from_status' => $fromStatus->value,
                'to_status' => OpportunityStatus::Open->value,
                'actor_type' => OpportunityTransitionActorType::Customer->value,
                'actor_user_id' => $customer->user_id,
                'opportunity_run_id' => null,
                'action_execution_id' => null,
                'reason_code' => $reasonCode,
                'safe_note' => null,
            ]);

            OpportunityReopened::dispatch($locked->id, $locked->business_id, $customer->user_id, $fromStatus->value, $reasonCode);

            return $updated;
        });
    }

    /**
     * Customer-initiated approval request (RFC-002 §17, §30). Valid only
     * from `open` — an already-awaiting-approval Opportunity throws rather
     * than being absorbed as a no-op. Eligibility comes exclusively from
     * the locked Opportunity's own persisted recommended_action, re-verified
     * against the trusted OpportunityActionRegistry — never from
     * caller-supplied data. A merely-present recommended_action is not
     * enough: it must be a fully configured, registry-integrity-checked,
     * hash-valid executable action (RFC-002 §13.1, §28, §45) — an initial,
     * unconfigured action (empty `parameters`) can never reach
     * awaiting_approval.
     */
    public function requestApproval(Opportunity $opportunity, Customer $customer): Opportunity
    {
        return DB::transaction(function () use ($opportunity, $customer) {
            $locked = $this->opportunityRepository->findOwnedForUpdate($opportunity->id, $opportunity->business_id);

            $locked = $this->assertOpportunityOwnership($customer, $locked);

            if ($locked->status !== OpportunityStatus::Open) {
                throw new InvalidOpportunityStateException(
                    "Opportunity [{$locked->id}] cannot request approval from status [{$locked->status->value}]."
                );
            }

            $this->assertActionIsApprovableAndExecutable($locked);

            $updated = $this->opportunityRepository->update($locked, [
                'status' => OpportunityStatus::AwaitingApproval->value,
            ]);

            $this->transitionRepository->create([
                'opportunity_id' => $locked->id,
                'category' => OpportunityTransitionCategory::Workflow->value,
                'from_status' => OpportunityStatus::Open->value,
                'to_status' => OpportunityStatus::AwaitingApproval->value,
                'actor_type' => OpportunityTransitionActorType::Customer->value,
                'actor_user_id' => $customer->user_id,
                'opportunity_run_id' => null,
                'action_execution_id' => null,
                'reason_code' => 'customer_requested_approval',
                'safe_note' => null,
            ]);

            OpportunityApprovalRequested::dispatch($locked->id, $locked->business_id, $customer->user_id, $locked->recommended_action_hash);

            return $updated;
        });
    }

    /**
     * Re-verifies the locked Opportunity's persisted recommended_action
     * against the trusted OpportunityActionRegistry before requestApproval()
     * is allowed to change status (RFC-002 §13.1, §28, §45). Never rebuilds
     * or modifies the action — only validates the exact persisted,
     * configured state. `approval_required=false` in the trusted registry
     * throws OpportunityApprovalNotRequiredException; every other
     * malformed/unconfigured/unsupported/integrity-invalid state throws
     * OpportunityActionNotExecutableException.
     */
    private function assertActionIsApprovableAndExecutable(Opportunity $locked): void
    {
        $recommendedAction = $locked->recommended_action;

        if (! is_array($recommendedAction)) {
            throw new OpportunityActionNotExecutableException(
                "Opportunity [{$locked->id}] has no configured action."
            );
        }

        $expectedKeys = ['action_key', 'approval_required', 'completion_policy', 'parameters', 'schema_version'];
        $actualKeys = array_keys($recommendedAction);
        sort($actualKeys);

        if ($actualKeys !== $expectedKeys) {
            throw new OpportunityActionNotExecutableException(
                "Opportunity [{$locked->id}]'s recommended_action has an unexpected structure."
            );
        }

        $actionKey = $recommendedAction['action_key'];

        if ($actionKey !== 'add_phone') {
            throw new OpportunityActionNotExecutableException(
                "Action [{$actionKey}] is not executable."
            );
        }

        $actionDefinition = OpportunityActionRegistry::get($actionKey);

        if ($actionDefinition === null) {
            throw new OpportunityActionNotExecutableException(
                "Action [{$actionKey}] has no registered action definition."
            );
        }

        if (($actionDefinition['approval_required'] ?? null) !== true) {
            throw new OpportunityApprovalNotRequiredException(
                "Opportunity [{$locked->id}] has no current action requiring approval."
            );
        }

        if (($actionDefinition['handler_identifier'] ?? null) !== 'business.update_phone'
            || ($actionDefinition['verifier_identifier'] ?? null) !== 'business.phone_matches_parameter'
            || ($actionDefinition['mutates_business_data'] ?? null) !== true
        ) {
            throw new OpportunityActionNotExecutableException(
                "Action [{$actionKey}] is not a fully configured executable action."
            );
        }

        if ($recommendedAction['schema_version'] !== $actionDefinition['schema_version']
            || $locked->action_schema_version !== $actionDefinition['schema_version']
            || $recommendedAction['approval_required'] !== $actionDefinition['approval_required']
            || $recommendedAction['completion_policy'] !== $actionDefinition['completion_policy']->value
        ) {
            throw new OpportunityActionNotExecutableException(
                "Opportunity [{$locked->id}]'s persisted action disagrees with the trusted registry."
            );
        }

        $parameters = $recommendedAction['parameters'];

        if (! is_array($parameters) || array_keys($parameters) !== ['value']) {
            throw new OpportunityActionNotExecutableException(
                "Opportunity [{$locked->id}]'s action parameters are not fully configured."
            );
        }

        $value = $parameters['value'];

        if (! is_string($value) || trim($value) === '' || mb_strlen($value) > 50) {
            throw new OpportunityActionNotExecutableException(
                "Opportunity [{$locked->id}]'s configured phone value is invalid."
            );
        }

        $recomputedHash = $this->actionHash->compute($recommendedAction);

        if (! is_string($locked->recommended_action_hash) || $recomputedHash !== $locked->recommended_action_hash) {
            throw new OpportunityActionNotExecutableException(
                "Opportunity [{$locked->id}]'s recommended_action_hash does not match its persisted action."
            );
        }
    }

    /**
     * Customer-initiated explicit confirmation (RFC-002 §17, §30.1, §31,
     * §37). Valid only from `awaiting_approval` (creates a new pending
     * execution) or `in_progress` (idempotently returns the existing
     * matching active execution) — every other status rejects. Reuses
     * requestApproval()'s own executable-action validation unchanged, so
     * eligibility is always re-derived from the locked Opportunity's own
     * persisted state, never trusted from the caller.
     */
    public function confirmApproval(Opportunity $opportunity, Customer $customer): OpportunityActionExecution
    {
        return DB::transaction(function () use ($opportunity, $customer) {
            $locked = $this->opportunityRepository->findOwnedForUpdate($opportunity->id, $opportunity->business_id);

            $locked = $this->assertOpportunityOwnership($customer, $locked);

            if ($locked->status === OpportunityStatus::InProgress) {
                return $this->resolveDuplicateActiveExecution($locked);
            }

            if ($locked->status !== OpportunityStatus::AwaitingApproval) {
                throw new InvalidOpportunityStateException(
                    "Opportunity [{$locked->id}] cannot confirm approval from status [{$locked->status->value}]."
                );
            }

            $this->assertActionIsApprovableAndExecutable($locked);

            $attemptNumber = $this->actionExecutionRepository->nextAttemptNumberForUpdate($locked->id);

            $idempotencyKey = hash(
                'sha256',
                $locked->id . ':' . $locked->occurrence_number . ':' . $locked->recommended_action_hash . ':' . $attemptNumber
            );

            $existing = $this->actionExecutionRepository->findByIdempotencyKey($idempotencyKey);

            if ($existing !== null) {
                // Defense-in-depth only — normal committed duplicates are
                // resolved by the in_progress branch above, since a
                // successful confirmation always leaves the Opportunity
                // in_progress before any second caller could reach here.
                $this->assertExecutionMatchesLockedOpportunity($existing, $locked);

                if (! in_array($existing->status, [OpportunityActionExecutionStatus::Pending, OpportunityActionExecutionStatus::Running], true)) {
                    throw new InvalidOpportunityExecutionStateException(
                        "Execution [{$existing->id}] is no longer active."
                    );
                }

                return $existing;
            }

            $execution = $this->actionExecutionRepository->create([
                'opportunity_id' => $locked->id,
                'action_key' => 'add_phone',
                'recommended_action_hash' => $locked->recommended_action_hash,
                'action_schema_version' => $locked->action_schema_version,
                'occurrence_number' => $locked->occurrence_number,
                'attempt_number' => $attemptNumber,
                'idempotency_key' => $idempotencyKey,
                'status' => OpportunityActionExecutionStatus::Pending->value,
                'initiated_by_user_id' => $customer->user_id,
                'initiated_by_type' => 'customer',
                'completion_policy' => OpportunityCompletionPolicy::SystemVerified->value,
            ]);

            $this->opportunityRepository->update($locked, [
                'status' => OpportunityStatus::InProgress->value,
            ]);

            $this->transitionRepository->create([
                'opportunity_id' => $locked->id,
                'category' => OpportunityTransitionCategory::Workflow->value,
                'from_status' => OpportunityStatus::AwaitingApproval->value,
                'to_status' => OpportunityStatus::InProgress->value,
                'actor_type' => OpportunityTransitionActorType::Customer->value,
                'actor_user_id' => $customer->user_id,
                'opportunity_run_id' => null,
                'action_execution_id' => $execution->id,
                'reason_code' => 'customer_confirmed_approval',
                'safe_note' => null,
            ]);

            OpportunityExecutionStarted::dispatch(
                $locked->id,
                $locked->business_id,
                $customer->user_id,
                $execution->id,
                'add_phone',
            );

            ExecuteOpportunityAction::dispatch($execution->id);

            return $execution;
        });
    }

    /**
     * Resolves a duplicate confirmApproval() call against an Opportunity
     * that is already in_progress (RFC-002 §31) — the normal path for a
     * committed duplicate submission. Requires a genuinely active
     * (pending/running — findActiveForOpportunity() is already scoped to
     * those two statuses) execution that still matches the live Opportunity
     * action exactly, and that the live action itself is still valid.
     * Never creates a transition, event, or job, and never increments
     * attempt_number.
     */
    private function resolveDuplicateActiveExecution(Opportunity $lockedOpportunity): OpportunityActionExecution
    {
        $active = $this->actionExecutionRepository->findActiveForOpportunity($lockedOpportunity->id);

        if ($active === null) {
            throw new InvalidOpportunityExecutionStateException(
                "Opportunity [{$lockedOpportunity->id}] is in_progress but has no active execution."
            );
        }

        $this->assertExecutionMatchesLockedOpportunity($active, $lockedOpportunity);

        $this->assertActionIsApprovableAndExecutable($lockedOpportunity);

        return $active;
    }

    /**
     * Cross-validates an already-persisted execution against the live
     * locked Opportunity's action (RFC-002 §31) — used by both the
     * in_progress duplicate-resolution path and the idempotency-key
     * defense-in-depth path, since neither findActiveForOpportunity() nor
     * findByIdempotencyKey() itself scopes by opportunity_id or the live
     * action's identity.
     */
    private function assertExecutionMatchesLockedOpportunity(OpportunityActionExecution $execution, Opportunity $lockedOpportunity): void
    {
        if ($execution->opportunity_id !== $lockedOpportunity->id
            || $execution->action_key !== 'add_phone'
            || $execution->recommended_action_hash !== $lockedOpportunity->recommended_action_hash
            || $execution->action_schema_version !== $lockedOpportunity->action_schema_version
            || $execution->occurrence_number !== $lockedOpportunity->occurrence_number
            || $execution->completion_policy !== OpportunityCompletionPolicy::SystemVerified
        ) {
            throw new InvalidOpportunityExecutionStateException(
                "Execution [{$execution->id}] no longer matches Opportunity [{$lockedOpportunity->id}]'s live action."
            );
        }
    }

    /**
     * Customer-initiated action configuration (RFC-002 §13.1, §28, §45).
     * Only `add_phone` is configurable in this phase. The action_key is
     * read exclusively from the locked Opportunity's own persisted
     * recommended_action — never accepted from the caller — and the
     * rebuilt recommended_action's registry-owned fields (schema_version,
     * approval_required, completion_policy) always come from
     * OpportunityActionRegistry, never from caller-supplied data. Valid
     * only from `open`. A rebuilt action whose hash matches the current
     * recommended_action_hash is a no-op: nothing is written.
     */
    public function configureAction(Opportunity $opportunity, Customer $customer, array $parameters): Opportunity
    {
        return DB::transaction(function () use ($opportunity, $customer, $parameters) {
            $locked = $this->opportunityRepository->findOwnedForUpdate($opportunity->id, $opportunity->business_id);

            $locked = $this->assertOpportunityOwnership($customer, $locked);

            if ($locked->status !== OpportunityStatus::Open) {
                throw new InvalidOpportunityStateException(
                    "Opportunity [{$locked->id}] cannot be configured from status [{$locked->status->value}]."
                );
            }

            $actionKey = is_array($locked->recommended_action) ? ($locked->recommended_action['action_key'] ?? null) : null;

            if ($actionKey !== 'add_phone') {
                throw new OpportunityActionNotConfigurableException(
                    "Opportunity [{$locked->id}] has no configurable action."
                );
            }

            $actionDefinition = OpportunityActionRegistry::get($actionKey);

            if ($actionDefinition === null
                || ! isset($actionDefinition['parameter_rules']['value'])
                || ! isset($actionDefinition['handler_identifier'])
                || ! isset($actionDefinition['verifier_identifier'])
            ) {
                throw new OpportunityActionNotConfigurableException(
                    "Action [{$actionKey}] is not configurable."
                );
            }

            $value = $this->assertValidActionValueParameter($parameters, $actionDefinition['parameter_rules']['value']);

            $rebuiltRecommendedAction = [
                'schema_version' => $actionDefinition['schema_version'],
                'action_key' => $actionKey,
                'parameters' => ['value' => $value],
                'approval_required' => $actionDefinition['approval_required'],
                'completion_policy' => $actionDefinition['completion_policy']->value,
            ];

            $newHash = $this->actionHash->compute($rebuiltRecommendedAction);

            if ($newHash === $locked->recommended_action_hash) {
                return $locked;
            }

            $updated = $this->opportunityRepository->update($locked, [
                'recommended_action' => $rebuiltRecommendedAction,
                'recommended_action_hash' => $newHash,
            ]);

            $this->transitionRepository->create([
                'opportunity_id' => $locked->id,
                'category' => OpportunityTransitionCategory::Workflow->value,
                'from_status' => OpportunityStatus::Open->value,
                'to_status' => OpportunityStatus::Open->value,
                'actor_type' => OpportunityTransitionActorType::Customer->value,
                'actor_user_id' => $customer->user_id,
                'opportunity_run_id' => null,
                'action_execution_id' => null,
                'reason_code' => 'customer_configured_action',
                'safe_note' => null,
            ]);

            return $updated;
        });
    }

    /**
     * Validates a single-parameter `value` payload against a registry
     * parameter_rules entry (RFC-002 §45 — reject, never clamp). Exactly
     * one key, `value`, is accepted; unexpected or missing keys reject.
     * Trimming is used only to test blankness — the returned string is
     * always the caller-supplied value, untouched.
     */
    private function assertValidActionValueParameter(array $parameters, array $rule): string
    {
        if (array_keys($parameters) !== ['value']) {
            throw new InvalidOpportunityActionParametersException('Parameters must contain exactly one key: value.');
        }

        $value = $parameters['value'];

        if (! is_string($value)) {
            throw new InvalidOpportunityActionParametersException('Parameter [value] must be a string.');
        }

        if ($rule['allow_blank'] === false && trim($value) === '') {
            throw new InvalidOpportunityActionParametersException('Parameter [value] cannot be blank.');
        }

        if (mb_strlen($value) > $rule['max_length']) {
            throw new InvalidOpportunityActionParametersException('Parameter [value] exceeds the maximum length.');
        }

        return $value;
    }

    /**
     * Called only by ExecuteOpportunityAction (RFC-002 §30.1 steps 1-4).
     * Never trusts the supplied $execution model's mutable attributes —
     * both the Opportunity and the execution are re-locked and
     * independently revalidated. Redelivery of an already
     * running/succeeded/failed execution is a safe no-op (null, no writes);
     * only a genuinely pending execution is validated and transitioned to
     * running. Performs no Business mutation of its own.
     */
    public function beginExecutionAttempt(OpportunityActionExecution $execution): ?OpportunityExecutionAttempt
    {
        return DB::transaction(function () use ($execution) {
            $unlockedOpportunity = $execution->opportunity;

            if ($unlockedOpportunity === null) {
                throw new InvalidOpportunityExecutionStateException(
                    "Execution [{$execution->id}] has no associated Opportunity."
                );
            }

            $lockedOpportunity = $this->opportunityRepository->findOwnedForUpdate($execution->opportunity_id, $unlockedOpportunity->business_id);

            if ($lockedOpportunity === null) {
                throw new InvalidOpportunityExecutionStateException(
                    "Opportunity [{$execution->opportunity_id}] no longer exists."
                );
            }

            $lockedExecution = $this->actionExecutionRepository->findForUpdate($execution->id);

            if ($lockedExecution === null) {
                throw new InvalidOpportunityExecutionStateException(
                    "Execution [{$execution->id}] no longer exists."
                );
            }

            if ($lockedExecution->id !== $execution->id || $lockedExecution->opportunity_id !== $lockedOpportunity->id) {
                throw new InvalidOpportunityExecutionStateException(
                    "Execution [{$execution->id}] does not belong to Opportunity [{$lockedOpportunity->id}]."
                );
            }

            // OpportunityActionExecutionStatus is exhaustive (pending,
            // running, succeeded, failed) — every non-pending case is a
            // redelivered job for an execution already past this step, and
            // is a safe no-op, never silently absorbed into a new outcome.
            if ($lockedExecution->status !== OpportunityActionExecutionStatus::Pending) {
                return null;
            }

            $this->assertPendingExecutionAttemptIsValid($lockedOpportunity, $lockedExecution);

            $runningExecution = $this->actionExecutionRepository->update($lockedExecution, [
                'status' => OpportunityActionExecutionStatus::Running->value,
            ]);

            return new OpportunityExecutionAttempt($lockedOpportunity, $runningExecution);
        });
    }

    /**
     * Independently re-validates a pending execution's bound action against
     * the live Opportunity and the trusted OpportunityActionRegistry
     * (RFC-002 §30.1 step 3) before it may be transitioned to running.
     * Mirrors OpportunityActionExecutor::assertExecutable()'s invariants
     * exactly (that class cannot be called from here — it owns no lock of
     * its own and is invoked later, only once running) and
     * requestApproval()'s registry-integrity checks, but always throws
     * OpportunityActionNotExecutableException — never
     * OpportunityApprovalNotRequiredException — since every pending-row
     * mismatch here is a not-executable state, not an approval routing
     * decision.
     */
    private function assertPendingExecutionAttemptIsValid(Opportunity $lockedOpportunity, OpportunityActionExecution $lockedExecution): void
    {
        if ($lockedOpportunity->status !== OpportunityStatus::InProgress) {
            throw new OpportunityActionNotExecutableException(
                "Opportunity [{$lockedOpportunity->id}] is not in_progress."
            );
        }

        $recommendedAction = $lockedOpportunity->recommended_action;

        if (! is_array($recommendedAction)) {
            throw new OpportunityActionNotExecutableException(
                "Opportunity [{$lockedOpportunity->id}] has no configured action."
            );
        }

        $expectedKeys = ['action_key', 'approval_required', 'completion_policy', 'parameters', 'schema_version'];
        $actualKeys = array_keys($recommendedAction);
        sort($actualKeys);

        if ($actualKeys !== $expectedKeys) {
            throw new OpportunityActionNotExecutableException(
                "Opportunity [{$lockedOpportunity->id}]'s recommended_action has an unexpected structure."
            );
        }

        $actionKey = $recommendedAction['action_key'];

        if ($actionKey !== 'add_phone') {
            throw new OpportunityActionNotExecutableException(
                "Action [{$actionKey}] is not executable."
            );
        }

        $actionDefinition = OpportunityActionRegistry::get($actionKey);

        if ($actionDefinition === null) {
            throw new OpportunityActionNotExecutableException(
                "Action [{$actionKey}] has no registered action definition."
            );
        }

        if (($actionDefinition['handler_identifier'] ?? null) !== 'business.update_phone'
            || ($actionDefinition['verifier_identifier'] ?? null) !== 'business.phone_matches_parameter'
            || ($actionDefinition['mutates_business_data'] ?? null) !== true
            || ($actionDefinition['approval_required'] ?? null) !== true
            || ! isset($actionDefinition['completion_policy'])
            || $actionDefinition['completion_policy']->value !== 'system_verified'
        ) {
            throw new OpportunityActionNotExecutableException(
                "Action [{$actionKey}] is not a fully supported executable action."
            );
        }

        if ($recommendedAction['schema_version'] !== $actionDefinition['schema_version']
            || $recommendedAction['approval_required'] !== $actionDefinition['approval_required']
            || $recommendedAction['completion_policy'] !== $actionDefinition['completion_policy']->value
        ) {
            throw new OpportunityActionNotExecutableException(
                "Opportunity [{$lockedOpportunity->id}]'s persisted action disagrees with the trusted registry."
            );
        }

        if ($lockedOpportunity->action_schema_version !== $actionDefinition['schema_version']) {
            throw new OpportunityActionNotExecutableException(
                "Opportunity [{$lockedOpportunity->id}]'s action_schema_version disagrees with the trusted registry."
            );
        }

        $recomputedHash = $this->actionHash->compute($recommendedAction);

        if (! is_string($lockedOpportunity->recommended_action_hash) || $recomputedHash !== $lockedOpportunity->recommended_action_hash) {
            throw new OpportunityActionNotExecutableException(
                "Opportunity [{$lockedOpportunity->id}]'s recommended_action_hash does not match its persisted action."
            );
        }

        if ($lockedExecution->action_key !== $actionKey
            || $lockedExecution->recommended_action_hash !== $lockedOpportunity->recommended_action_hash
            || $lockedExecution->action_schema_version !== $lockedOpportunity->action_schema_version
            || $lockedExecution->occurrence_number !== $lockedOpportunity->occurrence_number
            || $lockedExecution->completion_policy->value !== $recommendedAction['completion_policy']
        ) {
            throw new OpportunityActionNotExecutableException(
                "Execution [{$lockedExecution->id}] no longer matches the live Opportunity/action."
            );
        }

        $parameters = $recommendedAction['parameters'];

        if (! is_array($parameters) || array_keys($parameters) !== ['value']) {
            throw new OpportunityActionNotExecutableException(
                "Opportunity [{$lockedOpportunity->id}]'s action parameters are not fully configured."
            );
        }

        $value = $parameters['value'];

        if (! is_string($value) || trim($value) === '' || mb_strlen($value) > 50) {
            throw new OpportunityActionNotExecutableException(
                "Opportunity [{$lockedOpportunity->id}]'s configured phone value is invalid."
            );
        }
    }

    /**
     * Called only by ExecuteOpportunityAction (RFC-002 §30.1 step 5, §30.2,
     * §37). Never trusts the supplied $execution model's mutable
     * attributes — both the Opportunity and the execution are re-locked and
     * independently revalidated against each other and against the trusted
     * OpportunityActionRegistry-derived state before anything is written.
     * Performs no Business mutation or verification of its own — that
     * already happened in OpportunityActionExecutor before this is called.
     */
    public function recordExecutionResult(OpportunityActionExecution $execution, bool $succeeded, ?string $safeSummary): Opportunity
    {
        return DB::transaction(function () use ($execution, $succeeded, $safeSummary) {
            $unlockedOpportunity = $execution->opportunity;

            if ($unlockedOpportunity === null) {
                throw new InvalidOpportunityExecutionStateException(
                    "Execution [{$execution->id}] has no associated Opportunity."
                );
            }

            $lockedOpportunity = $this->opportunityRepository->findOwnedForUpdate($execution->opportunity_id, $unlockedOpportunity->business_id);

            if ($lockedOpportunity === null) {
                throw new InvalidOpportunityExecutionStateException(
                    "Opportunity [{$execution->opportunity_id}] no longer exists."
                );
            }

            $lockedExecution = $this->actionExecutionRepository->findForUpdate($execution->id);

            if ($lockedExecution === null) {
                throw new InvalidOpportunityExecutionStateException(
                    "Execution [{$execution->id}] no longer exists."
                );
            }

            $this->assertCommonExecutionResultInvariants($lockedOpportunity, $lockedExecution, $execution->id, $succeeded);

            $liveActionMatches = $this->executionMatchesLiveAction($lockedOpportunity, $lockedExecution);

            $now = CarbonImmutable::now();

            if ($succeeded) {
                if (! $liveActionMatches) {
                    throw new InvalidOpportunityExecutionStateException(
                        "Execution [{$execution->id}] no longer matches a recordable Opportunity state."
                    );
                }

                $safeSummary = $this->assertValidSuccessSummary($safeSummary);

                $this->actionExecutionRepository->update($lockedExecution, [
                    'status' => OpportunityActionExecutionStatus::Succeeded->value,
                    'completed_at' => $now,
                    'safe_result_summary' => $safeSummary,
                    'safe_error_summary' => null,
                ]);

                $updatedOpportunity = $this->opportunityRepository->update($lockedOpportunity, [
                    'status' => OpportunityStatus::Completed->value,
                    'completed_at' => $now,
                ]);

                $this->transitionRepository->create([
                    'opportunity_id' => $lockedOpportunity->id,
                    'category' => OpportunityTransitionCategory::Workflow->value,
                    'from_status' => OpportunityStatus::InProgress->value,
                    'to_status' => OpportunityStatus::Completed->value,
                    'actor_type' => OpportunityTransitionActorType::System->value,
                    'actor_user_id' => null,
                    'opportunity_run_id' => null,
                    'action_execution_id' => $lockedExecution->id,
                    'reason_code' => 'execution_succeeded',
                    'safe_note' => null,
                ]);

                OpportunityExecutionSucceeded::dispatch(
                    $lockedOpportunity->id,
                    $lockedOpportunity->business_id,
                    $lockedExecution->initiated_by_user_id,
                    $lockedExecution->id,
                    $lockedExecution->action_key,
                );

                OpportunityCompleted::dispatch(
                    $lockedOpportunity->id,
                    $lockedOpportunity->business_id,
                    $lockedExecution->initiated_by_user_id,
                    $lockedExecution->id,
                    $lockedExecution->completion_policy->value,
                );

                return $updatedOpportunity;
            }

            if ($liveActionMatches) {
                $safeSummary = $this->assertValidFailureSummary($safeSummary);
            } elseif ($safeSummary !== self::STATE_MISMATCH_SAFE_SUMMARY) {
                throw new InvalidOpportunityExecutionStateException(
                    "Execution [{$execution->id}]'s failure summary does not match its recorded state."
                );
            }

            $this->actionExecutionRepository->update($lockedExecution, [
                'status' => OpportunityActionExecutionStatus::Failed->value,
                'completed_at' => $now,
                'safe_error_summary' => $safeSummary,
                'safe_result_summary' => null,
            ]);

            $updatedOpportunity = $this->opportunityRepository->update($lockedOpportunity, [
                'status' => OpportunityStatus::Open->value,
                'completed_at' => null,
            ]);

            $this->transitionRepository->create([
                'opportunity_id' => $lockedOpportunity->id,
                'category' => OpportunityTransitionCategory::Workflow->value,
                'from_status' => OpportunityStatus::InProgress->value,
                'to_status' => OpportunityStatus::Open->value,
                'actor_type' => OpportunityTransitionActorType::System->value,
                'actor_user_id' => null,
                'opportunity_run_id' => null,
                'action_execution_id' => $lockedExecution->id,
                'reason_code' => 'execution_failed',
                'safe_note' => null,
            ]);

            OpportunityExecutionFailed::dispatch(
                $lockedOpportunity->id,
                $lockedOpportunity->business_id,
                $lockedExecution->initiated_by_user_id,
                $lockedExecution->id,
                $lockedExecution->action_key,
                $safeSummary,
            );

            return $updatedOpportunity;
        });
    }

    /**
     * Identity/workflow invariants required regardless of outcome or live
     * action state (RFC-002 §31): the locked rows genuinely belong to each
     * other and to this call, the Opportunity is in_progress, and the
     * execution is in a status the given outcome may legally be recorded
     * from. A successful outcome may only be recorded for a running
     * execution; a failure may be recorded for a running execution (a
     * post-invocation handler/verification failure) or a still-pending one
     * (a pre-invocation §30.1 step 3 mismatch that never reached running).
     * Terminal (succeeded/failed) executions always reject. This never
     * inspects the live action itself — see executionMatchesLiveAction().
     */
    private function assertCommonExecutionResultInvariants(Opportunity $lockedOpportunity, OpportunityActionExecution $lockedExecution, int $suppliedExecutionId, bool $succeeded): void
    {
        $validExecutionStatus = $succeeded
            ? $lockedExecution->status === OpportunityActionExecutionStatus::Running
            : in_array($lockedExecution->status, [OpportunityActionExecutionStatus::Running, OpportunityActionExecutionStatus::Pending], true);

        if ($lockedExecution->id !== $suppliedExecutionId
            || $lockedExecution->opportunity_id !== $lockedOpportunity->id
            || ! $validExecutionStatus
            || $lockedOpportunity->status !== OpportunityStatus::InProgress
        ) {
            throw new InvalidOpportunityExecutionStateException(
                "Execution [{$suppliedExecutionId}] no longer matches a recordable Opportunity state."
            );
        }
    }

    /**
     * Whether the locked execution's bound action still exactly matches the
     * live Opportunity's current recommended_action (RFC-002 §31) — a
     * malformed/missing live recommended_action is treated as a mismatch,
     * not a separate case, since the execution's action_key can never equal
     * a null/absent live action_key. Success requires this to be true;
     * failure may be recorded either way (§30.1 step 3), with a different
     * safe-summary requirement for each (see recordExecutionResult()).
     */
    private function executionMatchesLiveAction(Opportunity $lockedOpportunity, OpportunityActionExecution $lockedExecution): bool
    {
        $recommendedAction = $lockedOpportunity->recommended_action;
        $liveActionKey = is_array($recommendedAction) ? ($recommendedAction['action_key'] ?? null) : null;

        return $lockedExecution->action_key === $liveActionKey
            && $lockedExecution->recommended_action_hash === $lockedOpportunity->recommended_action_hash
            && $lockedExecution->action_schema_version === $lockedOpportunity->action_schema_version
            && $lockedExecution->occurrence_number === $lockedOpportunity->occurrence_number
            && $lockedExecution->completion_policy === OpportunityCompletionPolicy::SystemVerified;
    }

    private function assertValidSuccessSummary(?string $safeSummary): string
    {
        if (! is_string($safeSummary) || trim($safeSummary) === '' || mb_strlen($safeSummary) > 255) {
            throw new InvalidOpportunityExecutionStateException('A valid success summary is required.');
        }

        return $safeSummary;
    }

    private function assertValidFailureSummary(?string $safeSummary): string
    {
        if (! is_string($safeSummary) || ! in_array($safeSummary, self::ALLOWED_FAILURE_SUMMARIES, true)) {
            throw new InvalidOpportunityExecutionStateException('A valid failure summary is required.');
        }

        return $safeSummary;
    }

    /**
     * Re-validates ownership against the freshly locked row's own Business
     * relationship — never the caller-supplied, possibly stale $opportunity
     * — matching BusinessManager::assertOwnership()'s exact convention
     * (customer_id compared against Customer::$user_id). A missing locked
     * row is treated identically to a wrong-owner row: both simply mean
     * this customer has no legitimate access to it, and neither should be
     * distinguishable to the caller.
     */
    private function assertOpportunityOwnership(Customer $customer, ?Opportunity $opportunity): Opportunity
    {
        if ($opportunity === null || (int) $opportunity->business->customer_id !== (int) $customer->user_id) {
            throw new AuthorizationException('This opportunity does not belong to the given customer.');
        }

        return $opportunity;
    }

    private function assertCandidateRanges(OpportunityCandidateData $data): void
    {
        foreach (['impact' => $data->impact, 'urgency' => $data->urgency, 'effort' => $data->effort] as $field => $value) {
            if ($value < 0 || $value > 5) {
                throw new InvalidOpportunityCandidateException("[{$field}] must be an integer between 0 and 5.");
            }
        }

        if (! is_finite($data->confidence) || $data->confidence < 0.0 || $data->confidence > 1.0) {
            throw new InvalidOpportunityCandidateException('confidence must be a finite decimal between 0 and 1.');
        }
    }

    private function assertNoTemplateParameters(OpportunityCandidateData $data): void
    {
        if ($data->templateParameters !== []) {
            throw new InvalidOpportunityCandidateException('templateParameters must be empty for the current RFC-002 types.');
        }
    }

    private function assertNullContext(OpportunityCandidateData $data): void
    {
        if ($data->context !== null) {
            throw new InvalidOpportunityCandidateException('context must be null for the current RFC-002 types.');
        }
    }

    /**
     * Every current OpportunityActionRegistry entry has parameter_rules =
     * [], so a non-empty actionParameters payload is always rejected —
     * checked unconditionally, before any type/action is even resolved.
     */
    private function assertNoActionParameters(OpportunityCandidateData $data): void
    {
        if ($data->actionParameters !== null && $data->actionParameters !== []) {
            throw new InvalidOpportunityCandidateException('actionParameters must be null or empty — no current action accepts parameters.');
        }
    }

    private function assertGoalKeysAreValid(OpportunityCandidateData $data, array $typeDefinition): void
    {
        $allowedGoalKeys = $typeDefinition['allowed_relevant_goal_keys'] ?? [];
        $validGoalValues = array_map(fn (BusinessGoal $goal) => $goal->value, BusinessGoal::cases());

        foreach ($data->relevantGoalKeys as $goalKey) {
            if (! is_string($goalKey)) {
                throw new InvalidOpportunityCandidateException('Every relevantGoalKey must be a string.');
            }

            if (! in_array($goalKey, $validGoalValues, true)) {
                throw new InvalidOpportunityCandidateException("[{$goalKey}] is not a valid BusinessGoal.");
            }

            if (! in_array($goalKey, $allowedGoalKeys, true)) {
                throw new InvalidOpportunityCandidateException("[{$goalKey}] is not an allowed relevant goal key for type [{$data->type}].");
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function resolveStoredGoalKeys(Business $business): array
    {
        $onboarding = $this->onboardingRepository->findByBusiness($business);

        return $onboarding?->primary_goals ?? [];
    }

    private function assertIdentityMatches(
        OpportunityRunCandidate $existing,
        OpportunityRun $locked,
        OpportunityCandidateData $data,
        string $fingerprintValue,
        int $fingerprintVersion,
    ): void {
        if ($existing->opportunity_run_id !== $locked->id
            || $existing->type !== $data->type
            || $existing->fingerprint_version !== $fingerprintVersion
            || $existing->fingerprint !== $fingerprintValue
            || $existing->context_key !== null) {
            throw new ImmutableCandidateIdentityMismatchException(
                "Re-staged candidate for run [{$locked->id}] has a mismatched immutable identity field."
            );
        }
    }

    /**
     * Constructs the authoritative, registry-only recommended_action (RFC-002
     * §28) — never worker-supplied. Returns [recommended_action,
     * recommended_action_hash, action_schema_version], all null when the
     * type has no action_key.
     *
     * @return array{0: ?array<string, mixed>, 1: ?string, 2: ?int}
     */
    private function buildRecommendedAction(array $typeDefinition): array
    {
        $actionKey = $typeDefinition['action_key'] ?? null;

        if ($actionKey === null) {
            return [null, null, null];
        }

        $actionDefinition = OpportunityActionRegistry::get($actionKey);

        if ($actionDefinition === null) {
            throw new InvalidOpportunityCandidateException("Type action_key [{$actionKey}] has no registered action definition.");
        }

        $recommendedAction = [
            'schema_version' => $actionDefinition['schema_version'],
            'action_key' => $actionKey,
            'parameters' => [],
            'approval_required' => $actionDefinition['approval_required'],
            'completion_policy' => $actionDefinition['completion_policy']->value,
        ];

        $recommendedActionHash = $this->actionHash->compute($recommendedAction);

        return [$recommendedAction, $recommendedActionHash, $actionDefinition['schema_version']];
    }

    /**
     * Re-derives every trusted value a persisted candidate claims and
     * throws the moment any of them disagrees — a staged row is trusted
     * data at rest, not an assumption (RFC-002 §22). Never mutates the
     * candidate; finalization only ever reads from it.
     */
    private function revalidateCandidateIntegrity(OpportunityRunCandidate $candidate, OpportunityRun $lockedRun): void
    {
        if ($candidate->opportunity_run_id !== $lockedRun->id) {
            throw new InvalidOpportunityCandidateException(
                "Candidate [{$candidate->id}] does not belong to run [{$lockedRun->id}]."
            );
        }

        $typeDefinition = OpportunityTypeRegistry::get($lockedRun->worker_key->value, $candidate->type);

        if ($typeDefinition === null) {
            throw new InvalidOpportunityCandidateException(
                "Candidate [{$candidate->id}] has an unregistered (worker_key, type) pair."
            );
        }

        $fingerprintVersion = (int) config('opportunity.fingerprint_version', 1);

        if ($candidate->fingerprint_version !== $fingerprintVersion) {
            throw new InvalidOpportunityCandidateException(
                "Candidate [{$candidate->id}] fingerprint_version does not match the current configuration."
            );
        }

        if ($candidate->context_key !== null) {
            throw new InvalidOpportunityCandidateException(
                "Candidate [{$candidate->id}] context_key must be null for the current RFC-002 types."
            );
        }

        if ($candidate->title !== $typeDefinition['title_template'] || $candidate->summary !== $typeDefinition['summary_template']) {
            throw new InvalidOpportunityCandidateException(
                "Candidate [{$candidate->id}] title/summary does not match its registry template."
            );
        }

        $this->assertRankInRange($candidate->impact, 'impact', $candidate->id);
        $this->assertRankInRange($candidate->urgency, 'urgency', $candidate->id);
        $this->assertRankInRange($candidate->effort, 'effort', $candidate->id);
        $this->assertRankInRange($candidate->goal_relevance_rank, 'goal_relevance_rank', $candidate->id);
        $this->assertRankInRange($candidate->evidence_freshness_rank, 'evidence_freshness_rank', $candidate->id);

        $confidence = (float) $candidate->confidence;

        if (! is_finite($confidence) || $confidence < 0.0 || $confidence > 1.0) {
            throw new InvalidOpportunityCandidateException("Candidate [{$candidate->id}] confidence is out of range.");
        }

        if ($candidate->scored_at === null) {
            throw new InvalidOpportunityCandidateException("Candidate [{$candidate->id}] is missing scored_at.");
        }

        if ((int) $candidate->scoring_version !== (int) config('opportunity.scoring_version', 1)) {
            throw new InvalidOpportunityCandidateException(
                "Candidate [{$candidate->id}] scoring_version does not match the current configuration."
            );
        }

        $recomputedFingerprint = $this->fingerprint->compute(
            $lockedRun->business_id,
            $lockedRun->worker_key,
            $candidate->type,
            null,
            $fingerprintVersion,
        );

        if ($recomputedFingerprint !== $candidate->fingerprint) {
            throw new InvalidOpportunityCandidateException(
                "Candidate [{$candidate->id}] fingerprint does not match its recomputed value."
            );
        }

        // Persisted evidence carries a registry-rendered `summary` the
        // hydration DTO structurally refuses — strip it back out before
        // re-hydrating, then let the validator re-render and re-check it.
        $evidenceFacts = array_map(
            fn (array $item): OpportunityEvidenceFactData => OpportunityEvidenceFactData::fromArray(Arr::except($item, ['summary'])),
            $candidate->evidence
        );

        $revalidatedEvidence = $this->evidenceValidator->validate($evidenceFacts, $typeDefinition, $candidate->scored_at);

        if (CanonicalJson::encode($revalidatedEvidence) !== CanonicalJson::encode($candidate->evidence)) {
            throw new OpportunityEvidenceValidationException(
                "Candidate [{$candidate->id}] evidence does not match its revalidated value."
            );
        }

        $recomputedEvidenceFreshnessRank = $this->scorer->scoreEvidenceFreshness($revalidatedEvidence, $candidate->scored_at);

        if ($recomputedEvidenceFreshnessRank !== $candidate->evidence_freshness_rank) {
            throw new InvalidOpportunityCandidateException(
                "Candidate [{$candidate->id}] evidence_freshness_rank does not match its recomputed value."
            );
        }

        $recomputedPriorityScore = $this->scorer->computePriorityScore(
            $candidate->impact,
            $candidate->urgency,
            $candidate->goal_relevance_rank,
            $confidence,
            $recomputedEvidenceFreshnessRank,
            $candidate->effort,
        );

        if ($recomputedPriorityScore !== $candidate->priority_score) {
            throw new InvalidOpportunityCandidateException(
                "Candidate [{$candidate->id}] priority_score does not match its recomputed value."
            );
        }

        $this->assertRecommendedActionMatches($candidate, $typeDefinition);
    }

    private function assertRankInRange(int $value, string $field, int $candidateId): void
    {
        if ($value < 0 || $value > 5) {
            throw new InvalidOpportunityCandidateException(
                "Candidate [{$candidateId}] [{$field}] is out of the supported 0-5 range."
            );
        }
    }

    /**
     * Rebuilds the authoritative recommended_action exactly as
     * stageCandidate() does (via the same buildRecommendedAction()) and
     * requires the persisted candidate to match it byte-for-byte.
     */
    private function assertRecommendedActionMatches(OpportunityRunCandidate $candidate, array $typeDefinition): void
    {
        $actionKey = $typeDefinition['action_key'] ?? null;

        if ($actionKey === null) {
            if ($candidate->recommended_action !== null
                || $candidate->recommended_action_hash !== null
                || $candidate->action_schema_version !== null) {
                throw new InvalidOpportunityCandidateException(
                    "Candidate [{$candidate->id}] has action fields set but its type has no action_key."
                );
            }

            return;
        }

        [$recommendedAction, $recommendedActionHash, $actionSchemaVersion] = $this->buildRecommendedAction($typeDefinition);

        if (CanonicalJson::encode($candidate->recommended_action) !== CanonicalJson::encode($recommendedAction)
            || $candidate->recommended_action_hash !== $recommendedActionHash
            || $candidate->action_schema_version !== $actionSchemaVersion) {
            throw new InvalidOpportunityCandidateException(
                "Candidate [{$candidate->id}] recommended_action does not match its recomputed representation."
            );
        }
    }

    private function applyCandidateToOpportunity(OpportunityRunCandidate $candidate, OpportunityRun $lockedRun, Carbon $now): void
    {
        $existing = $this->opportunityRepository->findByFingerprintForUpdate($candidate->fingerprint);

        if ($existing === null) {
            $this->createOpportunityFromCandidate($candidate, $lockedRun, $now);

            return;
        }

        $this->assertExistingOpportunityIdentityMatches($existing, $candidate, $lockedRun);
        $this->reaffirmExistingOpportunity($existing, $candidate, $lockedRun, $now);
    }

    private function assertExistingOpportunityIdentityMatches(Opportunity $existing, OpportunityRunCandidate $candidate, OpportunityRun $lockedRun): void
    {
        if ($existing->business_id !== $lockedRun->business_id
            || $existing->worker_key !== $lockedRun->worker_key
            || $existing->type !== $candidate->type
            || $existing->fingerprint_version !== $candidate->fingerprint_version
            || $existing->fingerprint !== $candidate->fingerprint
            || $existing->context_key !== $candidate->context_key) {
            throw new CrossWorkerFingerprintCollisionException(
                "Opportunity [{$existing->id}] identity does not match candidate [{$candidate->id}] for run [{$lockedRun->id}]."
            );
        }
    }

    private function createOpportunityFromCandidate(OpportunityRunCandidate $candidate, OpportunityRun $lockedRun, Carbon $now): void
    {
        $opportunity = $this->opportunityRepository->create([
            'business_id' => $lockedRun->business_id,
            'worker_key' => $lockedRun->worker_key->value,
            'type' => $candidate->type,
            'fingerprint_version' => $candidate->fingerprint_version,
            'fingerprint' => $candidate->fingerprint,
            'context_key' => $candidate->context_key,
            'title' => $candidate->title,
            'summary' => $candidate->summary,
            'status' => OpportunityStatus::Open->value,
            'freshness' => OpportunityFreshness::Current->value,
            'impact' => $candidate->impact,
            'urgency' => $candidate->urgency,
            'effort' => $candidate->effort,
            'confidence' => $candidate->confidence,
            'goal_relevance_rank' => $candidate->goal_relevance_rank,
            'evidence_freshness_rank' => $candidate->evidence_freshness_rank,
            'priority_score' => $candidate->priority_score,
            'scoring_version' => $candidate->scoring_version,
            'scored_at' => $candidate->scored_at,
            'evidence' => $candidate->evidence,
            'recommended_action' => $candidate->recommended_action,
            'recommended_action_hash' => $candidate->recommended_action_hash,
            'action_schema_version' => $candidate->action_schema_version,
            'occurrence_number' => 1,
            'last_confirmed_run_id' => $lockedRun->id,
            'last_confirmed_at' => $now,
            'first_detected_at' => $now,
            'snoozed_until' => null,
            'completed_at' => null,
            'dismissed_at' => null,
            'stale_at' => null,
        ]);

        // No initial transition is written — there is no previous state to
        // transition from (§41).
        OpportunityCreated::dispatch(
            $opportunity->id,
            $lockedRun->business_id,
            $lockedRun->id,
            $lockedRun->worker_key->value,
            $candidate->type,
            $candidate->fingerprint,
        );
    }

    /**
     * Applies a matching candidate to an already-live Opportunity (RFC-002
     * §22, §29): evidence/scoring/title/summary and freshness always
     * update; only the workflow-status branch below decides whether the
     * action revision and status/occurrence_number themselves change.
     */
    private function reaffirmExistingOpportunity(Opportunity $existing, OpportunityRunCandidate $candidate, OpportunityRun $lockedRun, Carbon $now): void
    {
        $wasStale = $existing->freshness === OpportunityFreshness::Stale;

        $updates = [
            'title' => $candidate->title,
            'summary' => $candidate->summary,
            'impact' => $candidate->impact,
            'urgency' => $candidate->urgency,
            'effort' => $candidate->effort,
            'confidence' => $candidate->confidence,
            'goal_relevance_rank' => $candidate->goal_relevance_rank,
            'evidence_freshness_rank' => $candidate->evidence_freshness_rank,
            'priority_score' => $candidate->priority_score,
            'scoring_version' => $candidate->scoring_version,
            'scored_at' => $candidate->scored_at,
            'evidence' => $candidate->evidence,
            'recommended_action' => $candidate->recommended_action,
            'recommended_action_hash' => $candidate->recommended_action_hash,
            'action_schema_version' => $candidate->action_schema_version,
            'freshness' => OpportunityFreshness::Current->value,
            'last_confirmed_run_id' => $lockedRun->id,
            'last_confirmed_at' => $now,
            'stale_at' => null,
        ];

        $isRecurrence = false;
        $isActionRevision = false;

        if ($existing->status === OpportunityStatus::InProgress) {
            $activeExecution = $this->actionExecutionRepository->findActiveForOpportunity($existing->id);

            if ($activeExecution === null) {
                throw new InvalidOpportunityCandidateException(
                    "Opportunity [{$existing->id}] is in_progress with no active execution — integrity failure."
                );
            }

            // Preserve the in-flight execution's bound action revision.
            unset($updates['recommended_action'], $updates['recommended_action_hash'], $updates['action_schema_version']);
        } elseif ($existing->status === OpportunityStatus::Completed && $wasStale) {
            $isRecurrence = true;
            $updates['status'] = OpportunityStatus::Open->value;
            $updates['occurrence_number'] = $existing->occurrence_number + 1;
            $updates['completed_at'] = null;
        } elseif ($existing->status === OpportunityStatus::AwaitingApproval
            && $existing->recommended_action_hash !== $candidate->recommended_action_hash) {
            // Hash changed underneath a pending approval (§29): apply the
            // new action revision (already in $updates) and kick the
            // customer back to open — they must restart approval. A hash
            // that has not changed simply falls through to the untouched
            // branch below and stays awaiting_approval.
            $isActionRevision = true;
            $updates['status'] = OpportunityStatus::Open->value;
        }
        // open / awaiting_approval with an unchanged hash / snoozed /
        // dismissed / continuously-current completed: status,
        // snoozed_until, dismissed_at, completed_at, and occurrence_number
        // are simply never included in $updates above.

        $updated = $this->opportunityRepository->update($existing, $updates);

        if ($isRecurrence) {
            $this->transitionRepository->create([
                'opportunity_id' => $existing->id,
                'category' => OpportunityTransitionCategory::Workflow->value,
                'from_status' => OpportunityStatus::Completed->value,
                'to_status' => OpportunityStatus::Open->value,
                'actor_type' => OpportunityTransitionActorType::Worker->value,
                'actor_user_id' => null,
                'opportunity_run_id' => $lockedRun->id,
                'action_execution_id' => null,
                'reason_code' => 'recurrence_detected',
                'safe_note' => null,
            ]);
        }

        if ($isActionRevision) {
            $this->transitionRepository->create([
                'opportunity_id' => $existing->id,
                'category' => OpportunityTransitionCategory::Workflow->value,
                'from_status' => OpportunityStatus::AwaitingApproval->value,
                'to_status' => OpportunityStatus::Open->value,
                'actor_type' => OpportunityTransitionActorType::Worker->value,
                'actor_user_id' => null,
                'opportunity_run_id' => $lockedRun->id,
                'action_execution_id' => null,
                'reason_code' => 'action_revised',
                'safe_note' => null,
            ]);
        }

        if ($wasStale) {
            $this->transitionRepository->create([
                'opportunity_id' => $existing->id,
                'category' => OpportunityTransitionCategory::Freshness->value,
                'from_status' => OpportunityFreshness::Stale->value,
                'to_status' => OpportunityFreshness::Current->value,
                'actor_type' => OpportunityTransitionActorType::Worker->value,
                'actor_user_id' => null,
                'opportunity_run_id' => $lockedRun->id,
                'action_execution_id' => null,
                'reason_code' => 'confirmed_in_successful_run',
                'safe_note' => null,
            ]);

            OpportunityBecameCurrent::dispatch(
                $existing->id,
                $lockedRun->business_id,
                $lockedRun->id,
                $lockedRun->worker_key->value,
                $updated->occurrence_number,
            );
        }

        OpportunityReaffirmed::dispatch($existing->id, $lockedRun->business_id, $lockedRun->id, $lockedRun->worker_key->value);
    }

    /**
     * Staleness sweep (RFC-002 §22): every Opportunity still freshness=current
     * that this run did not reconfirm becomes stale — including, with zero
     * staged candidates, every currently-current Opportunity for this
     * business/worker.
     */
    private function staleMissingOpportunities(OpportunityRun $lockedRun, Carbon $now): void
    {
        $missing = $this->opportunityRepository->currentMissingFromRunForUpdate(
            $lockedRun->business_id,
            $lockedRun->worker_key,
            $lockedRun->id,
        );

        foreach ($missing as $opportunity) {
            if ($opportunity->business_id !== $lockedRun->business_id || $opportunity->worker_key !== $lockedRun->worker_key) {
                throw new InvalidOpportunityCandidateException(
                    "Staleness sweep returned Opportunity [{$opportunity->id}] outside this run's business/worker scope."
                );
            }

            $this->opportunityRepository->update($opportunity, [
                'freshness' => OpportunityFreshness::Stale->value,
                'stale_at' => $now,
            ]);

            $this->transitionRepository->create([
                'opportunity_id' => $opportunity->id,
                'category' => OpportunityTransitionCategory::Freshness->value,
                'from_status' => OpportunityFreshness::Current->value,
                'to_status' => OpportunityFreshness::Stale->value,
                'actor_type' => OpportunityTransitionActorType::Worker->value,
                'actor_user_id' => null,
                'opportunity_run_id' => $lockedRun->id,
                'action_execution_id' => null,
                'reason_code' => 'missing_from_successful_run',
                'safe_note' => null,
            ]);

            OpportunityMarkedStale::dispatch($opportunity->id, $lockedRun->business_id, $lockedRun->id, $lockedRun->worker_key->value);
        }
    }
}

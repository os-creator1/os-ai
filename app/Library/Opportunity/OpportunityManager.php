<?php

declare(strict_types=1);

namespace App\Library\Opportunity;

use App\Enums\Business\BusinessGoal;
use App\Enums\Opportunity\OpportunityFreshness;
use App\Enums\Opportunity\OpportunityRunStatus;
use App\Enums\Opportunity\OpportunityStatus;
use App\Enums\Opportunity\OpportunityTransitionActorType;
use App\Enums\Opportunity\OpportunityTransitionCategory;
use App\Enums\Opportunity\OpportunityWorkerKey;
use App\Events\Opportunity\OpportunityBecameCurrent;
use App\Events\Opportunity\OpportunityCreated;
use App\Events\Opportunity\OpportunityDismissed;
use App\Events\Opportunity\OpportunityMarkedStale;
use App\Events\Opportunity\OpportunityReaffirmed;
use App\Events\Opportunity\OpportunityRunFailed;
use App\Events\Opportunity\OpportunityRunStarted;
use App\Events\Opportunity\OpportunityRunSucceeded;
use App\Library\Opportunity\Exceptions\CandidateLimitExceededException;
use App\Library\Opportunity\Exceptions\CrossWorkerFingerprintCollisionException;
use App\Library\Opportunity\Exceptions\ImmutableCandidateIdentityMismatchException;
use App\Library\Opportunity\Exceptions\InvalidOpportunityCandidateException;
use App\Library\Opportunity\Exceptions\InvalidOpportunityStateException;
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
use App\Models\OpportunityRun;
use App\Models\OpportunityRunCandidate;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\CustomerOnboardingRepository;
use App\Repositories\Contracts\OpportunityActionExecutionRepository;
use App\Repositories\Contracts\OpportunityRepository;
use App\Repositories\Contracts\OpportunityRunCandidateRepository;
use App\Repositories\Contracts\OpportunityRunRepository;
use App\Repositories\Contracts\OpportunityTransitionRepository;
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

        if ($actionDefinition['parameter_rules'] !== []) {
            throw new InvalidOpportunityCandidateException("Action [{$actionKey}] declares parameter_rules but Milestone 2B.2 supports none.");
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

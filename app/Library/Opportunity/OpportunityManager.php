<?php

declare(strict_types=1);

namespace App\Library\Opportunity;

use App\Enums\Business\BusinessGoal;
use App\Enums\Opportunity\OpportunityRunStatus;
use App\Enums\Opportunity\OpportunityWorkerKey;
use App\Events\Opportunity\OpportunityRunFailed;
use App\Events\Opportunity\OpportunityRunStarted;
use App\Library\Opportunity\Exceptions\CandidateLimitExceededException;
use App\Library\Opportunity\Exceptions\ImmutableCandidateIdentityMismatchException;
use App\Library\Opportunity\Exceptions\InvalidOpportunityCandidateException;
use App\Library\Opportunity\Exceptions\RunAbandonedException;
use App\Library\Opportunity\Exceptions\RunAlreadyActiveException;
use App\Library\Opportunity\Exceptions\RunNotActiveException;
use App\Library\Opportunity\Exceptions\UnsupportedOpportunityTypeException;
use App\Models\Business;
use App\Models\OpportunityRun;
use App\Models\OpportunityRunCandidate;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\CustomerOnboardingRepository;
use App\Repositories\Contracts\OpportunityRunCandidateRepository;
use App\Repositories\Contracts\OpportunityRunRepository;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Owns every transaction and invariant of the Opportunity Engine run
 * protocol (RFC-002 §37). This pass implements beginRun() and
 * stageCandidate(); every other method (finalizeSuccessfulRun, failRun, and
 * the customer/admin workflow methods) is added in later phases.
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
}

<?php

namespace Tests\Feature\Opportunity\Concerns;

use App\Enums\Opportunity\OpportunityActionExecutionStatus;
use App\Enums\Opportunity\OpportunityCompletionPolicy;
use App\Enums\Opportunity\OpportunityFreshness;
use App\Enums\Opportunity\OpportunityRunStatus;
use App\Enums\Opportunity\OpportunityStatus;
use App\Enums\Opportunity\OpportunityTransitionActorType;
use App\Enums\Opportunity\OpportunityTransitionCategory;
use App\Enums\Opportunity\OpportunityWorkerKey;
use App\Library\Opportunity\OpportunityCandidateData;
use App\Models\Business;
use App\Models\Opportunity;
use App\Models\OpportunityActionExecution;
use App\Models\OpportunityRun;
use App\Models\OpportunityRunCandidate;
use App\Models\OpportunityTransition;
use App\Models\User;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;

trait CreatesOpportunityTestData
{
    use CreatesBusinessTestData;

    protected function createBusinessForOpportunities(): Business
    {
        $customer = $this->createCustomer();

        return $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
    }

    protected function createUser(): User
    {
        return User::create([
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'user' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => false,
            'is_customer' => true,
            'active_portal' => 'customer',
        ]);
    }

    protected function opportunityRunAttributes(Business $business, array $overrides = []): array
    {
        return array_merge([
            'business_id' => $business->id,
            'worker_key' => OpportunityWorkerKey::BusinessAdvisor->value,
            'producer_version' => 1,
            'status' => OpportunityRunStatus::Running->value,
            'started_at' => now(),
            'heartbeat_at' => now(),
        ], $overrides);
    }

    protected function createOpportunityRun(Business $business, array $overrides = []): OpportunityRun
    {
        return OpportunityRun::create($this->opportunityRunAttributes($business, $overrides));
    }

    protected function opportunityAttributes(Business $business, array $overrides = []): array
    {
        $fingerprint = $overrides['fingerprint'] ?? hash('sha256', 'opportunity-' . uniqid('', true));

        return array_merge([
            'business_id' => $business->id,
            'worker_key' => OpportunityWorkerKey::BusinessAdvisor->value,
            'type' => 'missing_phone',
            'fingerprint_version' => 1,
            'fingerprint' => $fingerprint,
            'context_key' => null,
            'title' => 'Add your business phone number',
            'summary' => 'Customers need a reliable way to contact the business.',
            'status' => OpportunityStatus::Open->value,
            'freshness' => OpportunityFreshness::Current->value,
            'impact' => 3,
            'urgency' => 3,
            'effort' => 1,
            'confidence' => '0.90',
            'goal_relevance_rank' => 1,
            'evidence_freshness_rank' => 1,
            'priority_score' => 50,
            'scoring_version' => 1,
            'scored_at' => now(),
            'evidence' => ['phone_blank' => true],
            'recommended_action' => null,
            'recommended_action_hash' => null,
            'action_schema_version' => null,
            'occurrence_number' => 1,
            'last_confirmed_run_id' => null,
            'last_confirmed_at' => now(),
            'first_detected_at' => now(),
            'snoozed_until' => null,
            'completed_at' => null,
            'dismissed_at' => null,
            'stale_at' => null,
        ], $overrides);
    }

    protected function createOpportunity(Business $business, array $overrides = []): Opportunity
    {
        return Opportunity::create($this->opportunityAttributes($business, $overrides));
    }

    protected function opportunityRunCandidateAttributes(OpportunityRun $run, array $overrides = []): array
    {
        $fingerprint = $overrides['fingerprint'] ?? hash('sha256', 'candidate-' . uniqid('', true));

        return array_merge([
            'opportunity_run_id' => $run->id,
            'type' => 'missing_phone',
            'fingerprint_version' => 1,
            'fingerprint' => $fingerprint,
            'context_key' => null,
            'title' => 'Add your business phone number',
            'summary' => 'Customers need a reliable way to contact the business.',
            'impact' => 3,
            'urgency' => 3,
            'effort' => 1,
            'confidence' => '0.90',
            'goal_relevance_rank' => 1,
            'evidence_freshness_rank' => 1,
            'priority_score' => 50,
            'scoring_version' => 1,
            'scored_at' => now(),
            'evidence' => ['phone_blank' => true],
            'recommended_action' => null,
            'recommended_action_hash' => null,
            'action_schema_version' => null,
        ], $overrides);
    }

    protected function createOpportunityRunCandidate(OpportunityRun $run, array $overrides = []): OpportunityRunCandidate
    {
        return OpportunityRunCandidate::create($this->opportunityRunCandidateAttributes($run, $overrides));
    }

    /**
     * Raw fromArray()-shaped payload for a valid, staging-ready
     * OpportunityCandidateData — defaults to a single-item, business_advisor
     * missing_phone candidate. Evidence's retrieved_at defaults to "now" at
     * call time, which is always safely in the past relative to the later
     * scored_at captured inside stageCandidate().
     */
    protected function opportunityCandidateDataAttributes(array $overrides = []): array
    {
        return array_merge([
            'type' => 'missing_phone',
            'context' => null,
            'templateParameters' => [],
            'impact' => 3,
            'urgency' => 3,
            'effort' => 1,
            'confidence' => 0.90,
            'relevantGoalKeys' => [],
            'evidence' => [[
                'source_type' => 'business_profile',
                'source_identifier' => 'business:1:phone',
                'fact_key' => 'phone_blank',
                'observed_value' => null,
                'retrieved_at' => now()->toIso8601String(),
            ]],
            'actionParameters' => null,
        ], $overrides);
    }

    protected function createOpportunityCandidateData(array $overrides = []): OpportunityCandidateData
    {
        return OpportunityCandidateData::fromArray($this->opportunityCandidateDataAttributes($overrides));
    }

    /**
     * A single raw evidence-item payload for the given fact_key, retrieved
     * "now" — always safely in the past relative to any later scored_at.
     */
    protected function opportunityEvidenceItemFor(string $factKey, string $sourceIdentifier = 'business:1:x'): array
    {
        return [[
            'source_type' => 'business_profile',
            'source_identifier' => $sourceIdentifier,
            'fact_key' => $factKey,
            'observed_value' => null,
            'retrieved_at' => now()->toIso8601String(),
        ]];
    }

    protected function opportunityActionExecutionAttributes(Opportunity $opportunity, User $user, array $overrides = []): array
    {
        $idempotencyKey = $overrides['idempotency_key'] ?? hash('sha256', 'execution-' . uniqid('', true));

        return array_merge([
            'opportunity_id' => $opportunity->id,
            'action_key' => 'add_phone',
            'recommended_action_hash' => hash('sha256', 'recommended-action-' . uniqid('', true)),
            'action_schema_version' => 1,
            'occurrence_number' => 1,
            'attempt_number' => 1,
            'idempotency_key' => $idempotencyKey,
            'status' => OpportunityActionExecutionStatus::Pending->value,
            'initiated_by_user_id' => $user->id,
            'initiated_by_type' => 'customer',
            'completion_policy' => OpportunityCompletionPolicy::SystemVerified->value,
            'safe_result_summary' => null,
            'safe_error_summary' => null,
            'started_at' => null,
            'completed_at' => null,
        ], $overrides);
    }

    protected function createOpportunityActionExecution(Opportunity $opportunity, User $user, array $overrides = []): OpportunityActionExecution
    {
        return OpportunityActionExecution::create($this->opportunityActionExecutionAttributes($opportunity, $user, $overrides));
    }

    protected function opportunityTransitionAttributes(Opportunity $opportunity, array $overrides = []): array
    {
        return array_merge([
            'opportunity_id' => $opportunity->id,
            'category' => OpportunityTransitionCategory::Workflow->value,
            'from_status' => null,
            'to_status' => OpportunityStatus::Open->value,
            'actor_type' => OpportunityTransitionActorType::System->value,
            'actor_user_id' => null,
            'opportunity_run_id' => null,
            'action_execution_id' => null,
            'reason_code' => 'opportunity_created',
            'safe_note' => null,
            'created_at' => now(),
        ], $overrides);
    }

    protected function createOpportunityTransition(Opportunity $opportunity, array $overrides = []): OpportunityTransition
    {
        return OpportunityTransition::create($this->opportunityTransitionAttributes($opportunity, $overrides));
    }
}

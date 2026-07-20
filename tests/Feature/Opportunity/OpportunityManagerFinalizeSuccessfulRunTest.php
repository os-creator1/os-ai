<?php

namespace Tests\Feature\Opportunity;

use App\Enums\Opportunity\OpportunityActionExecutionStatus;
use App\Enums\Opportunity\OpportunityFreshness;
use App\Enums\Opportunity\OpportunityRunStatus;
use App\Enums\Opportunity\OpportunityStatus;
use App\Enums\Opportunity\OpportunityTransitionActorType;
use App\Enums\Opportunity\OpportunityTransitionCategory;
use App\Enums\Opportunity\OpportunityWorkerKey;
use App\Events\Opportunity\OpportunityBecameCurrent;
use App\Events\Opportunity\OpportunityCreated;
use App\Events\Opportunity\OpportunityMarkedStale;
use App\Events\Opportunity\OpportunityReaffirmed;
use App\Events\Opportunity\OpportunityRunSucceeded;
use App\Library\Opportunity\CanonicalJson;
use App\Library\Opportunity\Exceptions\CrossWorkerFingerprintCollisionException;
use App\Library\Opportunity\Exceptions\InvalidOpportunityCandidateException;
use App\Library\Opportunity\Exceptions\OpportunityEvidenceValidationException;
use App\Library\Opportunity\Exceptions\RunAlreadyFailedException;
use App\Library\Opportunity\Exceptions\RunNotFoundException;
use App\Library\Opportunity\OpportunityManager;
use App\Models\Opportunity;
use App\Models\OpportunityRun;
use App\Models\OpportunityRunCandidate;
use App\Models\OpportunityTransition;
use App\Repositories\Contracts\OpportunityRunRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Feature\Opportunity\Concerns\CreatesOpportunityTestData;
use Tests\TestCase;

class OpportunityManagerFinalizeSuccessfulRunTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOpportunityTestData;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('opportunity.enabled', true);
    }

    public function test_finalizing_a_running_run_marks_it_succeeded_with_one_captured_timestamp(): void
    {
        $business = $this->createBusinessForOpportunities();
        $manager = app(OpportunityManager::class);
        $run = $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);

        $manager->finalizeSuccessfulRun($run);

        $run->refresh();
        $this->assertSame(OpportunityRunStatus::Succeeded, $run->status);
        $this->assertNotNull($run->completed_at);
        $this->assertTrue($run->completed_at->equalTo($run->heartbeat_at));
    }

    public function test_new_candidate_creates_a_current_open_opportunity_with_occurrence_one(): void
    {
        $business = $this->createBusinessForOpportunities();
        $manager = app(OpportunityManager::class);
        $run = $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);
        $manager->stageCandidate($run, $this->createOpportunityCandidateData());

        $manager->finalizeSuccessfulRun($run);

        $opportunity = Opportunity::where('business_id', $business->id)->first();
        $this->assertNotNull($opportunity);
        $this->assertSame(OpportunityStatus::Open, $opportunity->status);
        $this->assertSame(OpportunityFreshness::Current, $opportunity->freshness);
        $this->assertSame(1, $opportunity->occurrence_number);
        $this->assertSame($run->id, $opportunity->last_confirmed_run_id);
    }

    public function test_all_candidate_derived_fields_are_copied_correctly(): void
    {
        $business = $this->createBusinessForOpportunities();
        $manager = app(OpportunityManager::class);
        $run = $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);
        $candidate = $manager->stageCandidate($run, $this->createOpportunityCandidateData());

        $manager->finalizeSuccessfulRun($run);

        $opportunity = Opportunity::where('fingerprint', $candidate->fingerprint)->first();
        $this->assertSame($candidate->type, $opportunity->type);
        $this->assertSame($candidate->fingerprint_version, $opportunity->fingerprint_version);
        $this->assertSame($candidate->fingerprint, $opportunity->fingerprint);
        $this->assertSame($candidate->title, $opportunity->title);
        $this->assertSame($candidate->summary, $opportunity->summary);
        $this->assertSame($candidate->impact, $opportunity->impact);
        $this->assertSame($candidate->urgency, $opportunity->urgency);
        $this->assertSame($candidate->effort, $opportunity->effort);
        $this->assertEqualsWithDelta((float) $candidate->confidence, (float) $opportunity->confidence, 0.001);
        $this->assertSame($candidate->goal_relevance_rank, $opportunity->goal_relevance_rank);
        $this->assertSame($candidate->evidence_freshness_rank, $opportunity->evidence_freshness_rank);
        $this->assertSame($candidate->priority_score, $opportunity->priority_score);
        // Semantically identical evidence can differ in associative key
        // order after MySQL JSON storage and model hydration — compare via
        // canonical encoding rather than raw array/JSON identity.
        $this->assertSame(
            CanonicalJson::encode($candidate->evidence),
            CanonicalJson::encode($opportunity->evidence),
        );
        $this->assertSame(
            CanonicalJson::encode($candidate->recommended_action),
            CanonicalJson::encode($opportunity->recommended_action),
        );
        $this->assertSame($candidate->recommended_action_hash, $opportunity->recommended_action_hash);
        $this->assertSame($candidate->action_schema_version, $opportunity->action_schema_version);
    }

    public function test_existing_current_opportunity_is_reaffirmed_without_resetting_first_detected_at(): void
    {
        $business = $this->createBusinessForOpportunities();
        $manager = app(OpportunityManager::class);

        $run1 = $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);
        $manager->stageCandidate($run1, $this->createOpportunityCandidateData());
        $manager->finalizeSuccessfulRun($run1);

        $opportunity = Opportunity::where('business_id', $business->id)->first();
        $originalFirstDetectedAt = $opportunity->first_detected_at;
        $originalId = $opportunity->id;

        Event::fake([OpportunityReaffirmed::class]);

        $run2 = $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);
        $manager->stageCandidate($run2, $this->createOpportunityCandidateData());
        $manager->finalizeSuccessfulRun($run2);

        $opportunity->refresh();
        $this->assertSame($originalId, $opportunity->id);
        $this->assertSame(1, Opportunity::where('business_id', $business->id)->count());
        $this->assertTrue($originalFirstDetectedAt->equalTo($opportunity->first_detected_at));
        $this->assertSame($run2->id, $opportunity->last_confirmed_run_id);

        Event::assertDispatched(OpportunityReaffirmed::class, function (OpportunityReaffirmed $event) use ($opportunity, $business, $run2) {
            return $event->opportunityId === $opportunity->id
                && $event->businessId === $business->id
                && $event->runId === $run2->id
                && $event->workerKey === OpportunityWorkerKey::BusinessAdvisor->value;
        });
    }

    public function test_zero_candidate_run_stales_all_current_opportunities_for_that_business_and_worker(): void
    {
        $business = $this->createBusinessForOpportunities();
        $manager = app(OpportunityManager::class);

        $run1 = $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);
        $manager->stageCandidate($run1, $this->createOpportunityCandidateData());
        $manager->finalizeSuccessfulRun($run1);

        $run2 = $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);
        $manager->finalizeSuccessfulRun($run2);

        $opportunity = Opportunity::where('business_id', $business->id)->first();
        $this->assertSame(OpportunityFreshness::Stale, $opportunity->freshness);
        $this->assertNotNull($opportunity->stale_at);
    }

    public function test_another_businesss_opportunities_are_untouched(): void
    {
        $businessA = $this->createBusinessForOpportunities();
        $businessB = $this->createBusinessForOpportunities();
        $manager = app(OpportunityManager::class);

        $runA1 = $manager->beginRun($businessA, OpportunityWorkerKey::BusinessAdvisor, 1);
        $manager->stageCandidate($runA1, $this->createOpportunityCandidateData());
        $manager->finalizeSuccessfulRun($runA1);

        $runB1 = $manager->beginRun($businessB, OpportunityWorkerKey::BusinessAdvisor, 1);
        $manager->stageCandidate($runB1, $this->createOpportunityCandidateData());
        $manager->finalizeSuccessfulRun($runB1);

        $opportunityB = Opportunity::where('business_id', $businessB->id)->first();
        $originalUpdatedAt = $opportunityB->updated_at;

        // A zero-candidate run for Business A must not touch Business B's Opportunity.
        $runA2 = $manager->beginRun($businessA, OpportunityWorkerKey::BusinessAdvisor, 1);
        $manager->finalizeSuccessfulRun($runA2);

        $opportunityB->refresh();
        $this->assertSame(OpportunityFreshness::Current, $opportunityB->freshness);
        $this->assertTrue($originalUpdatedAt->equalTo($opportunityB->updated_at));
    }

    public function test_another_workers_opportunities_are_untouched(): void
    {
        $business = $this->createBusinessForOpportunities();
        $manager = app(OpportunityManager::class);

        // A raw fixture Opportunity for a different worker — no
        // business_advisor run should ever touch it.
        $seoOpportunity = $this->createOpportunity($business, [
            'worker_key' => OpportunityWorkerKey::Seo->value,
            'fingerprint' => hash('sha256', 'seo-opportunity'),
            'freshness' => OpportunityFreshness::Current->value,
        ]);
        $originalUpdatedAt = $seoOpportunity->updated_at;

        $run = $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);
        $manager->finalizeSuccessfulRun($run);

        $seoOpportunity->refresh();
        $this->assertSame(OpportunityFreshness::Current, $seoOpportunity->freshness);
        $this->assertTrue($originalUpdatedAt->equalTo($seoOpportunity->updated_at));
    }

    public function test_missing_opportunity_becomes_stale_with_exact_transition_and_event(): void
    {
        Event::fake([OpportunityMarkedStale::class]);

        $business = $this->createBusinessForOpportunities();
        $manager = app(OpportunityManager::class);

        $run1 = $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);
        $manager->stageCandidate($run1, $this->createOpportunityCandidateData());
        $manager->finalizeSuccessfulRun($run1);

        $opportunity = Opportunity::where('business_id', $business->id)->first();

        $run2 = $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);
        $manager->finalizeSuccessfulRun($run2);

        $transition = OpportunityTransition::where('opportunity_id', $opportunity->id)
            ->where('category', OpportunityTransitionCategory::Freshness->value)
            ->latest('id')
            ->first();

        $this->assertNotNull($transition);
        $this->assertSame('current', $transition->from_status);
        $this->assertSame('stale', $transition->to_status);
        $this->assertSame(OpportunityTransitionActorType::Worker, $transition->actor_type);
        $this->assertSame('missing_from_successful_run', $transition->reason_code);
        $this->assertSame($run2->id, $transition->opportunity_run_id);

        Event::assertDispatched(OpportunityMarkedStale::class, function (OpportunityMarkedStale $event) use ($opportunity, $business, $run2) {
            return $event->opportunityId === $opportunity->id
                && $event->businessId === $business->id
                && $event->runId === $run2->id
                && $event->workerKey === OpportunityWorkerKey::BusinessAdvisor->value;
        });
    }

    public function test_stale_opportunity_reappears_and_becomes_current_with_exact_transition_and_event(): void
    {
        Event::fake([OpportunityBecameCurrent::class]);

        $business = $this->createBusinessForOpportunities();
        $manager = app(OpportunityManager::class);

        $run1 = $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);
        $manager->stageCandidate($run1, $this->createOpportunityCandidateData());
        $manager->finalizeSuccessfulRun($run1);

        $run2 = $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);
        $manager->finalizeSuccessfulRun($run2);

        $opportunity = Opportunity::where('business_id', $business->id)->first();
        $this->assertSame(OpportunityFreshness::Stale, $opportunity->freshness);

        $run3 = $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);
        $manager->stageCandidate($run3, $this->createOpportunityCandidateData());
        $manager->finalizeSuccessfulRun($run3);

        $opportunity->refresh();
        $this->assertSame(OpportunityFreshness::Current, $opportunity->freshness);
        $this->assertNull($opportunity->stale_at);

        $transition = OpportunityTransition::where('opportunity_id', $opportunity->id)
            ->where('category', OpportunityTransitionCategory::Freshness->value)
            ->where('opportunity_run_id', $run3->id)
            ->first();

        $this->assertNotNull($transition);
        $this->assertSame('stale', $transition->from_status);
        $this->assertSame('current', $transition->to_status);
        $this->assertSame('confirmed_in_successful_run', $transition->reason_code);

        Event::assertDispatched(OpportunityBecameCurrent::class, function (OpportunityBecameCurrent $event) use ($opportunity, $run3) {
            return $event->opportunityId === $opportunity->id && $event->runId === $run3->id;
        });
    }

    public function test_completed_current_reappearance_stays_completed_without_incrementing_occurrence(): void
    {
        $business = $this->createBusinessForOpportunities();
        $manager = app(OpportunityManager::class);

        $run1 = $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);
        $manager->stageCandidate($run1, $this->createOpportunityCandidateData());
        $manager->finalizeSuccessfulRun($run1);

        $opportunity = Opportunity::where('business_id', $business->id)->first();
        $opportunity->forceFill([
            'status' => OpportunityStatus::Completed->value,
            'completed_at' => now(),
        ])->save();

        $run2 = $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);
        $manager->stageCandidate($run2, $this->createOpportunityCandidateData());
        $manager->finalizeSuccessfulRun($run2);

        $opportunity->refresh();
        $this->assertSame(OpportunityStatus::Completed, $opportunity->status);
        $this->assertNotNull($opportunity->completed_at);
        $this->assertSame(1, $opportunity->occurrence_number);
    }

    public function test_completed_stale_reappearance_reopens_and_increments_occurrence_exactly_once(): void
    {
        $business = $this->createBusinessForOpportunities();
        $manager = app(OpportunityManager::class);

        $run1 = $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);
        $manager->stageCandidate($run1, $this->createOpportunityCandidateData());
        $manager->finalizeSuccessfulRun($run1);

        $opportunity = Opportunity::where('business_id', $business->id)->first();
        $opportunity->forceFill([
            'status' => OpportunityStatus::Completed->value,
            'completed_at' => now(),
        ])->save();

        $run2 = $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);
        $manager->finalizeSuccessfulRun($run2);

        $opportunity->refresh();
        $this->assertSame(OpportunityFreshness::Stale, $opportunity->freshness);
        $this->assertSame(OpportunityStatus::Completed, $opportunity->status);

        $run3 = $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);
        $manager->stageCandidate($run3, $this->createOpportunityCandidateData());
        $manager->finalizeSuccessfulRun($run3);

        $opportunity->refresh();
        $this->assertSame(OpportunityStatus::Open, $opportunity->status);
        $this->assertNull($opportunity->completed_at);
        $this->assertSame(2, $opportunity->occurrence_number);
        $this->assertSame(OpportunityFreshness::Current, $opportunity->freshness);

        $transition = OpportunityTransition::where('opportunity_id', $opportunity->id)
            ->where('category', OpportunityTransitionCategory::Workflow->value)
            ->where('reason_code', 'recurrence_detected')
            ->first();

        $this->assertNotNull($transition);
        $this->assertSame('completed', $transition->from_status);
        $this->assertSame('open', $transition->to_status);
        $this->assertSame($run3->id, $transition->opportunity_run_id);
    }

    public function test_dismissed_stale_reappearance_becomes_current_but_remains_dismissed(): void
    {
        $business = $this->createBusinessForOpportunities();
        $manager = app(OpportunityManager::class);

        $run1 = $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);
        $manager->stageCandidate($run1, $this->createOpportunityCandidateData());
        $manager->finalizeSuccessfulRun($run1);

        $opportunity = Opportunity::where('business_id', $business->id)->first();
        $opportunity->forceFill([
            'status' => OpportunityStatus::Dismissed->value,
            'dismissed_at' => now(),
        ])->save();

        $run2 = $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);
        $manager->finalizeSuccessfulRun($run2);

        $run3 = $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);
        $manager->stageCandidate($run3, $this->createOpportunityCandidateData());
        $manager->finalizeSuccessfulRun($run3);

        $opportunity->refresh();
        $this->assertSame(OpportunityStatus::Dismissed, $opportunity->status);
        $this->assertNotNull($opportunity->dismissed_at);
        $this->assertSame(1, $opportunity->occurrence_number);
        $this->assertSame(OpportunityFreshness::Current, $opportunity->freshness);
    }

    public function test_snoozed_opportunity_preserves_status_and_snoozed_until(): void
    {
        $business = $this->createBusinessForOpportunities();
        $manager = app(OpportunityManager::class);

        $run1 = $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);
        $manager->stageCandidate($run1, $this->createOpportunityCandidateData());
        $manager->finalizeSuccessfulRun($run1);

        $opportunity = Opportunity::where('business_id', $business->id)->first();
        $snoozedUntil = now()->addDays(3)->startOfSecond();
        $opportunity->forceFill([
            'status' => OpportunityStatus::Snoozed->value,
            'snoozed_until' => $snoozedUntil,
        ])->save();

        $run2 = $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);
        $manager->stageCandidate($run2, $this->createOpportunityCandidateData());
        $manager->finalizeSuccessfulRun($run2);

        $opportunity->refresh();
        $this->assertSame(OpportunityStatus::Snoozed, $opportunity->status);
        $this->assertTrue($snoozedUntil->equalTo($opportunity->snoozed_until));
    }

    public function test_awaiting_approval_opportunity_preserves_workflow_status(): void
    {
        $business = $this->createBusinessForOpportunities();
        $manager = app(OpportunityManager::class);

        $run1 = $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);
        $manager->stageCandidate($run1, $this->createOpportunityCandidateData());
        $manager->finalizeSuccessfulRun($run1);

        $opportunity = Opportunity::where('business_id', $business->id)->first();
        $opportunity->forceFill(['status' => OpportunityStatus::AwaitingApproval->value])->save();

        $run2 = $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);
        $manager->stageCandidate($run2, $this->createOpportunityCandidateData());
        $manager->finalizeSuccessfulRun($run2);

        $opportunity->refresh();
        $this->assertSame(OpportunityStatus::AwaitingApproval, $opportunity->status);
    }

    public function test_awaiting_approval_with_same_action_hash_preserves_status_and_creates_no_transition(): void
    {
        Event::fake([OpportunityReaffirmed::class]);

        $business = $this->createBusinessForOpportunities();
        $manager = app(OpportunityManager::class);

        $run1 = $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);
        $manager->stageCandidate($run1, $this->createOpportunityCandidateData());
        $manager->finalizeSuccessfulRun($run1);

        $opportunity = Opportunity::where('business_id', $business->id)->first();
        $opportunity->forceFill(['status' => OpportunityStatus::AwaitingApproval->value])->save();

        $run2 = $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);
        $manager->stageCandidate($run2, $this->createOpportunityCandidateData());
        $manager->finalizeSuccessfulRun($run2);

        $opportunity->refresh();
        $this->assertSame(OpportunityStatus::AwaitingApproval, $opportunity->status);

        $this->assertSame(
            0,
            OpportunityTransition::where('opportunity_id', $opportunity->id)
                ->where('reason_code', 'action_revised')
                ->count()
        );

        Event::assertDispatched(OpportunityReaffirmed::class, function (OpportunityReaffirmed $event) use ($opportunity, $run2) {
            return $event->opportunityId === $opportunity->id && $event->runId === $run2->id;
        });
    }

    /**
     * The Opportunity is first created normally, then placed in
     * awaiting_approval with an intentionally stale/different persisted
     * recommended_action_hash — simulating an approval bound to an action
     * revision the registry has since changed — before a later run
     * re-stages and finalizes the current, valid candidate (RFC-002 §29).
     */
    public function test_awaiting_approval_with_changed_action_hash_becomes_open_and_applies_new_revision(): void
    {
        Event::fake([OpportunityReaffirmed::class]);

        $business = $this->createBusinessForOpportunities();
        $manager = app(OpportunityManager::class);

        $run1 = $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);
        $manager->stageCandidate($run1, $this->createOpportunityCandidateData());
        $manager->finalizeSuccessfulRun($run1);

        $opportunity = Opportunity::where('business_id', $business->id)->first();
        $opportunity->forceFill([
            'status' => OpportunityStatus::AwaitingApproval->value,
            'recommended_action_hash' => str_repeat('c', 64),
        ])->save();

        $run2 = $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);
        $candidate2 = $manager->stageCandidate($run2, $this->createOpportunityCandidateData());
        $manager->finalizeSuccessfulRun($run2);

        $opportunity->refresh();
        $this->assertSame(OpportunityStatus::Open, $opportunity->status);
        $this->assertSame(1, $opportunity->occurrence_number);
        $this->assertSame($candidate2->recommended_action_hash, $opportunity->recommended_action_hash);
        $this->assertSame($candidate2->action_schema_version, $opportunity->action_schema_version);
        $this->assertSame(
            CanonicalJson::encode($candidate2->recommended_action),
            CanonicalJson::encode($opportunity->recommended_action),
        );

        $transition = OpportunityTransition::where('opportunity_id', $opportunity->id)
            ->where('reason_code', 'action_revised')
            ->first();

        $this->assertNotNull($transition);
        $this->assertSame(OpportunityTransitionCategory::Workflow, $transition->category);
        $this->assertSame('awaiting_approval', $transition->from_status);
        $this->assertSame('open', $transition->to_status);
        $this->assertSame(OpportunityTransitionActorType::Worker, $transition->actor_type);
        $this->assertNull($transition->actor_user_id);
        $this->assertSame($run2->id, $transition->opportunity_run_id);
        $this->assertNull($transition->action_execution_id);

        $this->assertSame(
            1,
            OpportunityTransition::where('opportunity_id', $opportunity->id)
                ->where('reason_code', 'action_revised')
                ->count()
        );

        Event::assertDispatched(OpportunityReaffirmed::class, function (OpportunityReaffirmed $event) use ($opportunity, $run2) {
            return $event->opportunityId === $opportunity->id && $event->runId === $run2->id;
        });
    }

    public function test_in_progress_opportunity_with_active_execution_preserves_action_revision(): void
    {
        $business = $this->createBusinessForOpportunities();
        $manager = app(OpportunityManager::class);

        $run1 = $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);
        $manager->stageCandidate($run1, $this->createOpportunityCandidateData());
        $manager->finalizeSuccessfulRun($run1);

        $opportunity = Opportunity::where('business_id', $business->id)->first();
        $originalRecommendedAction = $opportunity->recommended_action;
        $originalHash = $opportunity->recommended_action_hash;
        $originalSchemaVersion = $opportunity->action_schema_version;

        $user = $this->createUser();
        $this->createOpportunityActionExecution($opportunity, $user, [
            'status' => OpportunityActionExecutionStatus::Pending->value,
        ]);
        $opportunity->forceFill(['status' => OpportunityStatus::InProgress->value])->save();

        $run2 = $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);
        $manager->stageCandidate($run2, $this->createOpportunityCandidateData());
        $manager->finalizeSuccessfulRun($run2);

        $opportunity->refresh();
        $this->assertSame(OpportunityStatus::InProgress, $opportunity->status);
        $this->assertSame($originalRecommendedAction, $opportunity->recommended_action);
        $this->assertSame($originalHash, $opportunity->recommended_action_hash);
        $this->assertSame($originalSchemaVersion, $opportunity->action_schema_version);
        $this->assertSame($run2->id, $opportunity->last_confirmed_run_id);
    }

    public function test_in_progress_opportunity_without_active_execution_rejects_atomically(): void
    {
        $business = $this->createBusinessForOpportunities();
        $manager = app(OpportunityManager::class);

        $run1 = $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);
        $manager->stageCandidate($run1, $this->createOpportunityCandidateData());
        $manager->finalizeSuccessfulRun($run1);

        $opportunity = Opportunity::where('business_id', $business->id)->first();
        $opportunity->forceFill(['status' => OpportunityStatus::InProgress->value])->save();

        $run2 = $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);
        $manager->stageCandidate($run2, $this->createOpportunityCandidateData());

        try {
            $this->expectException(InvalidOpportunityCandidateException::class);

            $manager->finalizeSuccessfulRun($run2);
        } finally {
            $run2->refresh();
            $this->assertSame(OpportunityRunStatus::Running, $run2->status);
            $opportunity->refresh();
            $this->assertSame(OpportunityStatus::InProgress, $opportunity->status);
        }
    }

    public function test_existing_immutable_identity_mismatch_rejects_atomically(): void
    {
        $business = $this->createBusinessForOpportunities();
        $manager = app(OpportunityManager::class);

        $run1 = $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);
        $manager->stageCandidate($run1, $this->createOpportunityCandidateData());
        $manager->finalizeSuccessfulRun($run1);

        $opportunity = Opportunity::where('business_id', $business->id)->first();
        // Corrupt an immutable identity field directly, bypassing the
        // manager — the fingerprint column (the lookup key) is untouched.
        $opportunity->forceFill(['type' => 'missing_email'])->save();

        $run2 = $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);
        $manager->stageCandidate($run2, $this->createOpportunityCandidateData());

        try {
            $this->expectException(CrossWorkerFingerprintCollisionException::class);

            $manager->finalizeSuccessfulRun($run2);
        } finally {
            $run2->refresh();
            $this->assertSame(OpportunityRunStatus::Running, $run2->status);
        }
    }

    public function test_corrupted_fingerprint_rejects_atomically(): void
    {
        $business = $this->createBusinessForOpportunities();
        $manager = app(OpportunityManager::class);
        $run = $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);
        $candidate = $manager->stageCandidate($run, $this->createOpportunityCandidateData());

        $candidate->forceFill(['fingerprint' => str_repeat('a', 64)])->save();

        try {
            $this->expectException(InvalidOpportunityCandidateException::class);

            $manager->finalizeSuccessfulRun($run);
        } finally {
            $this->assertSame(0, Opportunity::where('business_id', $business->id)->count());
            $run->refresh();
            $this->assertSame(OpportunityRunStatus::Running, $run->status);
        }
    }

    public function test_corrupted_title_rejects_atomically(): void
    {
        $business = $this->createBusinessForOpportunities();
        $manager = app(OpportunityManager::class);
        $run = $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);
        $candidate = $manager->stageCandidate($run, $this->createOpportunityCandidateData());

        $candidate->forceFill(['title' => 'Attacker-supplied title'])->save();

        $this->expectException(InvalidOpportunityCandidateException::class);

        $manager->finalizeSuccessfulRun($run);
    }

    public function test_corrupted_evidence_rejects_atomically(): void
    {
        $business = $this->createBusinessForOpportunities();
        $manager = app(OpportunityManager::class);
        $run = $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);
        $candidate = $manager->stageCandidate($run, $this->createOpportunityCandidateData());

        $tamperedEvidence = $candidate->evidence;
        $tamperedEvidence[0]['summary'] = 'Attacker-supplied summary text.';
        $candidate->forceFill(['evidence' => $tamperedEvidence])->save();

        $this->expectException(OpportunityEvidenceValidationException::class);

        $manager->finalizeSuccessfulRun($run);
    }

    public function test_corrupted_action_hash_rejects_atomically(): void
    {
        $business = $this->createBusinessForOpportunities();
        $manager = app(OpportunityManager::class);
        $run = $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);
        $candidate = $manager->stageCandidate($run, $this->createOpportunityCandidateData());

        $candidate->forceFill(['recommended_action_hash' => str_repeat('b', 64)])->save();

        $this->expectException(InvalidOpportunityCandidateException::class);

        $manager->finalizeSuccessfulRun($run);
    }

    public function test_corrupted_priority_score_rejects_atomically(): void
    {
        $business = $this->createBusinessForOpportunities();
        $manager = app(OpportunityManager::class);
        $run = $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);
        $candidate = $manager->stageCandidate($run, $this->createOpportunityCandidateData());

        $candidate->forceFill(['priority_score' => 1])->save();

        $this->expectException(InvalidOpportunityCandidateException::class);

        $manager->finalizeSuccessfulRun($run);
    }

    public function test_failed_run_rejects_and_leaves_live_opportunities_unchanged(): void
    {
        $business = $this->createBusinessForOpportunities();
        $manager = app(OpportunityManager::class);

        $run1 = $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);
        $manager->stageCandidate($run1, $this->createOpportunityCandidateData());
        $manager->finalizeSuccessfulRun($run1);
        $opportunity = Opportunity::where('business_id', $business->id)->first();
        $originalUpdatedAt = $opportunity->updated_at;

        $run2 = $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);
        $manager->failRun($run2, 'Simulated producer failure.');

        try {
            $this->expectException(RunAlreadyFailedException::class);

            $manager->finalizeSuccessfulRun($run2);
        } finally {
            $opportunity->refresh();
            $this->assertTrue($originalUpdatedAt->equalTo($opportunity->updated_at));
            $this->assertSame(OpportunityFreshness::Current, $opportunity->freshness);
        }
    }

    public function test_succeeded_run_is_an_idempotent_no_op(): void
    {
        Event::fake([OpportunityRunSucceeded::class, OpportunityCreated::class]);

        $business = $this->createBusinessForOpportunities();
        $manager = app(OpportunityManager::class);
        $run = $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);
        $manager->stageCandidate($run, $this->createOpportunityCandidateData());
        $manager->finalizeSuccessfulRun($run);

        Event::assertDispatched(OpportunityRunSucceeded::class, 1);
        Event::assertDispatched(OpportunityCreated::class, 1);

        $opportunityCountBefore = Opportunity::where('business_id', $business->id)->count();
        $transitionCountBefore = OpportunityTransition::query()->count();

        $manager->finalizeSuccessfulRun($run);

        Event::assertDispatched(OpportunityRunSucceeded::class, 1);
        Event::assertDispatched(OpportunityCreated::class, 1);
        $this->assertSame($opportunityCountBefore, Opportunity::where('business_id', $business->id)->count());
        $this->assertSame($transitionCountBefore, OpportunityTransition::query()->count());
    }

    public function test_missing_run_rejects_safely(): void
    {
        $business = $this->createBusinessForOpportunities();
        $manager = app(OpportunityManager::class);
        $run = $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);
        $run->delete();

        $this->expectException(RunNotFoundException::class);

        $manager->finalizeSuccessfulRun($run);
    }

    /**
     * OpportunityCreated::dispatch() already fires mid-transaction (during
     * candidate application), well before the final run-status update. This
     * forces that final update() to throw, rolling back the whole
     * transaction, proving ShouldDispatchAfterCommit means the
     * already-called dispatch() is never actually delivered.
     */
    public function test_rolled_back_transaction_delivers_no_after_commit_events(): void
    {
        Event::fake([OpportunityRunSucceeded::class, OpportunityCreated::class]);

        $business = $this->createBusinessForOpportunities();
        $manager = app(OpportunityManager::class);
        $run = $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);
        $manager->stageCandidate($run, $this->createOpportunityCandidateData());

        $realRepository = app(OpportunityRunRepository::class);

        $repository = new class($realRepository) implements OpportunityRunRepository {
            public function __construct(
                private readonly OpportunityRunRepository $real
            ) {
            }

            public function query()
            {
                return $this->real->query();
            }

            public function search($query, $callback = null)
            {
                return $this->real->search($query, $callback);
            }

            public function select(array $columns = ['*'])
            {
                return $this->real->select($columns);
            }

            public function make(array $attributes = [])
            {
                return $this->real->make($attributes);
            }

            public function findForUpdate(int $id): ?OpportunityRun
            {
                return $this->real->findForUpdate($id);
            }

            public function findRunningForUpdate(int $businessId, OpportunityWorkerKey $workerKey): ?OpportunityRun
            {
                return $this->real->findRunningForUpdate($businessId, $workerKey);
            }

            public function create(array $attributes): OpportunityRun
            {
                return $this->real->create($attributes);
            }

            public function update(OpportunityRun $run, array $attributes): OpportunityRun
            {
                throw new RuntimeException('Forced rollback.');
            }
        };

        $this->app->instance(OpportunityRunRepository::class, $repository);

        // A manager resolved before the container rebinding above still
        // holds the real repository — resolve a fresh one now so
        // finalization actually goes through the delegating test double.
        $finalizingManager = app(OpportunityManager::class);

        $caught = null;

        try {
            $finalizingManager->finalizeSuccessfulRun($run);
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught);
        $this->assertSame('Forced rollback.', $caught->getMessage());

        $this->assertSame(0, Opportunity::where('business_id', $business->id)->count());
        Event::assertNotDispatched(OpportunityRunSucceeded::class);
        Event::assertNotDispatched(OpportunityCreated::class);
    }

    public function test_multiple_candidates_apply_atomically_corruption_in_one_leaves_none_applied(): void
    {
        $business = $this->createBusinessForOpportunities();
        $manager = app(OpportunityManager::class);
        $run = $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);

        $manager->stageCandidate($run, $this->createOpportunityCandidateData());
        $secondCandidate = $manager->stageCandidate($run, $this->createOpportunityCandidateData([
            'type' => 'missing_email',
            'evidence' => $this->opportunityEvidenceItemFor('email_blank'),
        ]));

        // Corrupt only the second candidate.
        $secondCandidate->forceFill(['title' => 'Attacker title'])->save();

        try {
            $this->expectException(InvalidOpportunityCandidateException::class);

            $manager->finalizeSuccessfulRun($run);
        } finally {
            $this->assertSame(0, Opportunity::where('business_id', $business->id)->count());
        }
    }

    public function test_successful_finalization_dispatches_correct_scalar_event_payloads(): void
    {
        Event::fake([OpportunityRunSucceeded::class, OpportunityCreated::class]);

        $business = $this->createBusinessForOpportunities();
        $manager = app(OpportunityManager::class);
        $run = $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);
        $candidate = $manager->stageCandidate($run, $this->createOpportunityCandidateData());
        $manager->finalizeSuccessfulRun($run);

        $opportunity = Opportunity::where('fingerprint', $candidate->fingerprint)->first();

        Event::assertDispatched(OpportunityCreated::class, function (OpportunityCreated $event) use ($opportunity, $business, $run, $candidate) {
            return $event->opportunityId === $opportunity->id
                && $event->businessId === $business->id
                && $event->runId === $run->id
                && $event->workerKey === OpportunityWorkerKey::BusinessAdvisor->value
                && $event->type === 'missing_phone'
                && $event->fingerprint === $candidate->fingerprint;
        });

        Event::assertDispatched(OpportunityRunSucceeded::class, function (OpportunityRunSucceeded $event) use ($run, $business) {
            return $event->runId === $run->id
                && $event->businessId === $business->id
                && $event->workerKey === OpportunityWorkerKey::BusinessAdvisor->value;
        });
    }

    public function test_staged_candidate_rows_remain_intact_after_successful_finalization(): void
    {
        $business = $this->createBusinessForOpportunities();
        $manager = app(OpportunityManager::class);
        $run = $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);
        $candidate = $manager->stageCandidate($run, $this->createOpportunityCandidateData());

        $manager->finalizeSuccessfulRun($run);

        $candidate->refresh();
        $this->assertNotNull($candidate->id);
        $this->assertSame($run->id, $candidate->opportunity_run_id);
        $this->assertSame(1, OpportunityRunCandidate::where('opportunity_run_id', $run->id)->count());
    }

    public function test_successful_finalization_performs_no_external_io(): void
    {
        Http::fake();
        Queue::fake();

        $business = $this->createBusinessForOpportunities();
        $manager = app(OpportunityManager::class);
        $run = $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);
        $manager->stageCandidate($run, $this->createOpportunityCandidateData());

        $manager->finalizeSuccessfulRun($run);

        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }
}

<?php

namespace Tests\Feature\Opportunity;

use App\Enums\Business\BusinessGoal;
use App\Enums\Opportunity\OpportunityRunStatus;
use App\Enums\Opportunity\OpportunityWorkerKey;
use App\Library\Opportunity\Exceptions\CandidateLimitExceededException;
use App\Library\Opportunity\Exceptions\ImmutableCandidateIdentityMismatchException;
use App\Library\Opportunity\Exceptions\InvalidOpportunityCandidateException;
use App\Library\Opportunity\Exceptions\OpportunityEvidenceValidationException;
use App\Library\Opportunity\Exceptions\RunAbandonedException;
use App\Library\Opportunity\Exceptions\RunNotActiveException;
use App\Library\Opportunity\Exceptions\UnsupportedOpportunityTypeException;
use App\Library\Opportunity\OpportunityActionHash;
use App\Library\Opportunity\OpportunityManager;
use App\Models\Opportunity;
use App\Models\OpportunityRunCandidate;
use App\Repositories\Contracts\CustomerOnboardingRepository;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Opportunity\Concerns\CreatesOpportunityTestData;
use Tests\TestCase;

class OpportunityManagerStageCandidateTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOpportunityTestData;

    private function evidenceFor(string $factKey, string $sourceIdentifier = 'business:1:x'): array
    {
        return [[
            'source_type' => 'business_profile',
            'source_identifier' => $sourceIdentifier,
            'fact_key' => $factKey,
            'observed_value' => null,
            'retrieved_at' => now()->toIso8601String(),
        ]];
    }

    public function test_valid_candidate_persists_all_trusted_and_computed_fields(): void
    {
        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business);
        $manager = app(OpportunityManager::class);

        $candidate = $manager->stageCandidate($run, $this->createOpportunityCandidateData());

        $this->assertSame($run->id, $candidate->opportunity_run_id);
        $this->assertSame('missing_phone', $candidate->type);
        $this->assertSame(1, $candidate->fingerprint_version);
        $this->assertNull($candidate->context_key);
        $this->assertSame(64, strlen($candidate->fingerprint));
        $this->assertSame('Add your business phone number', $candidate->title);
        $this->assertSame(
            'Customers and future platform modules need a reliable way to contact the business.',
            $candidate->summary
        );
        $this->assertSame(3, $candidate->impact);
        $this->assertSame(3, $candidate->urgency);
        $this->assertSame(1, $candidate->effort);
        $this->assertEqualsWithDelta(0.90, (float) $candidate->confidence, 0.001);
        $this->assertSame(0, $candidate->goal_relevance_rank);
        $this->assertSame(5, $candidate->evidence_freshness_rank);
        $this->assertGreaterThan(0, $candidate->priority_score);
        $this->assertSame(1, $candidate->scoring_version);
        $this->assertNotNull($candidate->scored_at);
        $this->assertCount(1, $candidate->evidence);
        $this->assertSame('The business phone number is not set.', $candidate->evidence[0]['summary']);
        $this->assertNotNull($candidate->recommended_action);
        $this->assertSame('add_phone', $candidate->recommended_action['action_key']);
        $this->assertNotNull($candidate->recommended_action_hash);
        $this->assertSame(1, $candidate->action_schema_version);
        $this->assertNotNull($candidate->uid);
    }

    public function test_staging_never_creates_or_modifies_a_live_opportunity(): void
    {
        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business);
        $manager = app(OpportunityManager::class);

        $manager->stageCandidate($run, $this->createOpportunityCandidateData());

        $this->assertSame(0, Opportunity::where('business_id', $business->id)->count());
    }

    public function test_missing_run_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business);
        $run->delete();
        $manager = app(OpportunityManager::class);

        $this->expectException(RunNotActiveException::class);

        $manager->stageCandidate($run, $this->createOpportunityCandidateData());
    }

    public function test_succeeded_run_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business, ['status' => OpportunityRunStatus::Succeeded->value]);
        $manager = app(OpportunityManager::class);

        $this->expectException(RunNotActiveException::class);

        $manager->stageCandidate($run, $this->createOpportunityCandidateData());
    }

    public function test_failed_run_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business, ['status' => OpportunityRunStatus::Failed->value]);
        $manager = app(OpportunityManager::class);

        $this->expectException(RunNotActiveException::class);

        $manager->stageCandidate($run, $this->createOpportunityCandidateData());
    }

    public function test_expired_running_run_rejects_without_marking_it_failed(): void
    {
        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business, ['heartbeat_at' => now()->subMinutes(45)]);
        $manager = app(OpportunityManager::class);

        try {
            $this->expectException(RunAbandonedException::class);

            $manager->stageCandidate($run, $this->createOpportunityCandidateData());
        } finally {
            $run->refresh();
            $this->assertSame(OpportunityRunStatus::Running, $run->status);
            $this->assertNull($run->abandoned_at);
            $this->assertNull($run->completed_at);
        }
    }

    public function test_heartbeat_exactly_at_the_timeout_cutoff_remains_accepted(): void
    {
        $business = $this->createBusinessForOpportunities();
        $manager = app(OpportunityManager::class);
        $timeoutMinutes = config('opportunity.run_timeout_minutes', 30);
        $frozenNow = CarbonImmutable::parse('2026-06-01T12:00:00+00:00');

        $this->travelTo($frozenNow);

        $run = $this->createOpportunityRun($business, [
            'heartbeat_at' => $frozenNow->subMinutes($timeoutMinutes),
        ]);

        $data = $this->createOpportunityCandidateData([
            'evidence' => [[
                'source_type' => 'business_profile',
                'source_identifier' => 'business:1:phone',
                'fact_key' => 'phone_blank',
                'observed_value' => null,
                'retrieved_at' => $frozenNow->toIso8601String(),
            ]],
        ]);

        $candidate = $manager->stageCandidate($run, $data);

        $this->assertNotNull($candidate->id);

        $this->travelBack();
    }

    public function test_unsupported_type_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business);
        $manager = app(OpportunityManager::class);

        $this->expectException(UnsupportedOpportunityTypeException::class);

        $manager->stageCandidate($run, $this->createOpportunityCandidateData(['type' => 'not_a_real_type']));
    }

    public function test_unsupported_worker_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business, ['worker_key' => OpportunityWorkerKey::Seo->value]);
        $manager = app(OpportunityManager::class);

        $this->expectException(UnsupportedOpportunityTypeException::class);

        $manager->stageCandidate($run, $this->createOpportunityCandidateData());
    }

    public function test_out_of_range_impact_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business);
        $manager = app(OpportunityManager::class);

        $this->expectException(InvalidOpportunityCandidateException::class);

        $manager->stageCandidate($run, $this->createOpportunityCandidateData(['impact' => 6]));
    }

    public function test_out_of_range_urgency_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business);
        $manager = app(OpportunityManager::class);

        $this->expectException(InvalidOpportunityCandidateException::class);

        $manager->stageCandidate($run, $this->createOpportunityCandidateData(['urgency' => -1]));
    }

    public function test_out_of_range_effort_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business);
        $manager = app(OpportunityManager::class);

        $this->expectException(InvalidOpportunityCandidateException::class);

        $manager->stageCandidate($run, $this->createOpportunityCandidateData(['effort' => 99]));
    }

    public function test_out_of_range_confidence_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business);
        $manager = app(OpportunityManager::class);

        $this->expectException(InvalidOpportunityCandidateException::class);

        $manager->stageCandidate($run, $this->createOpportunityCandidateData(['confidence' => 1.5]));
    }

    public function test_non_finite_confidence_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business);
        $manager = app(OpportunityManager::class);

        $this->expectException(InvalidOpportunityCandidateException::class);

        $manager->stageCandidate($run, $this->createOpportunityCandidateData(['confidence' => NAN]));
    }

    public function test_unknown_business_goal_key_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business);
        $manager = app(OpportunityManager::class);

        $data = $this->createOpportunityCandidateData([
            'type' => 'missing_website',
            'relevantGoalKeys' => ['not_a_real_goal'],
            'evidence' => $this->evidenceFor('website_url_blank'),
        ]);

        $this->expectException(InvalidOpportunityCandidateException::class);

        $manager->stageCandidate($run, $data);
    }

    public function test_goal_key_valid_but_not_allowed_for_type_rejects(): void
    {
        // missing_phone's allowed_relevant_goal_keys is [] — any real
        // BusinessGoal value is still disallowed for this specific type.
        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business);
        $manager = app(OpportunityManager::class);

        $data = $this->createOpportunityCandidateData([
            'relevantGoalKeys' => [BusinessGoal::LocalSeo->value],
        ]);

        $this->expectException(InvalidOpportunityCandidateException::class);

        $manager->stageCandidate($run, $data);
    }

    public function test_duplicate_goal_keys_do_not_inflate_rank(): void
    {
        $business = $this->createBusinessForOpportunities();
        $onboardingRepository = app(CustomerOnboardingRepository::class);
        $onboarding = $onboardingRepository->startForCustomer($business->customer, true);
        $onboardingRepository->attachBusiness($onboarding, $business);
        $onboardingRepository->updatePrimaryGoals($onboarding, [
            BusinessGoal::LocalSeo->value,
        ]);

        $run = $this->createOpportunityRun($business);
        $manager = app(OpportunityManager::class);

        $data = $this->createOpportunityCandidateData([
            'type' => 'missing_website',
            'relevantGoalKeys' => [BusinessGoal::LocalSeo->value, BusinessGoal::LocalSeo->value, BusinessGoal::LocalSeo->value],
            'evidence' => $this->evidenceFor('website_url_blank'),
        ]);

        $candidate = $manager->stageCandidate($run, $data);

        // 1 distinct match -> rank 3, not inflated by 3 duplicate entries.
        $this->assertSame(3, $candidate->goal_relevance_rank);
    }

    public function test_non_empty_template_parameters_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business);
        $manager = app(OpportunityManager::class);

        $this->expectException(InvalidOpportunityCandidateException::class);

        $manager->stageCandidate($run, $this->createOpportunityCandidateData(['templateParameters' => ['foo' => 'bar']]));
    }

    public function test_non_null_context_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business);
        $manager = app(OpportunityManager::class);

        $this->expectException(InvalidOpportunityCandidateException::class);

        $manager->stageCandidate($run, $this->createOpportunityCandidateData(['context' => ['x' => 1]]));
    }

    public function test_non_empty_action_parameters_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business);
        $manager = app(OpportunityManager::class);

        $this->expectException(InvalidOpportunityCandidateException::class);

        $manager->stageCandidate($run, $this->createOpportunityCandidateData(['actionParameters' => ['field' => 'phone']]));
    }

    public function test_empty_evidence_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business);
        $manager = app(OpportunityManager::class);

        $this->expectException(OpportunityEvidenceValidationException::class);

        $manager->stageCandidate($run, $this->createOpportunityCandidateData(['evidence' => []]));
    }

    public function test_stored_onboarding_goals_affect_goal_relevance(): void
    {
        $business = $this->createBusinessForOpportunities();
        $onboardingRepository = app(CustomerOnboardingRepository::class);
        $onboarding = $onboardingRepository->startForCustomer($business->customer, true);
        $onboardingRepository->attachBusiness($onboarding, $business);
        $onboardingRepository->updatePrimaryGoals($onboarding, [
            BusinessGoal::LocalSeo->value,
            BusinessGoal::WebsiteConversion->value,
        ]);

        $run = $this->createOpportunityRun($business);
        $manager = app(OpportunityManager::class);

        $data = $this->createOpportunityCandidateData([
            'type' => 'missing_website',
            'relevantGoalKeys' => [BusinessGoal::LocalSeo->value, BusinessGoal::WebsiteConversion->value],
            'evidence' => $this->evidenceFor('website_url_blank'),
        ]);

        $candidate = $manager->stageCandidate($run, $data);

        $this->assertSame(4, $candidate->goal_relevance_rank);
    }

    public function test_no_onboarding_produces_goal_relevance_rank_zero(): void
    {
        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business);
        $manager = app(OpportunityManager::class);

        $data = $this->createOpportunityCandidateData([
            'type' => 'missing_website',
            'relevantGoalKeys' => [BusinessGoal::LocalSeo->value],
            'evidence' => $this->evidenceFor('website_url_blank'),
        ]);

        $candidate = $manager->stageCandidate($run, $data);

        $this->assertSame(0, $candidate->goal_relevance_rank);
    }

    public function test_recommended_action_comes_entirely_from_registries(): void
    {
        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business);
        $manager = app(OpportunityManager::class);

        $candidate = $manager->stageCandidate($run, $this->createOpportunityCandidateData());

        $this->assertSame([
            'schema_version' => 1,
            'action_key' => 'add_phone',
            'parameters' => [],
            'approval_required' => true,
            'completion_policy' => 'system_verified',
        ], $candidate->recommended_action);
    }

    public function test_action_hash_matches_the_exact_persisted_representation(): void
    {
        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business);
        $manager = app(OpportunityManager::class);

        $candidate = $manager->stageCandidate($run, $this->createOpportunityCandidateData());

        $expectedHash = (new OpportunityActionHash())->compute($candidate->recommended_action);

        $this->assertSame($expectedHash, $candidate->recommended_action_hash);
    }

    public function test_candidate_cap_rejects_only_a_new_fingerprint(): void
    {
        config(['opportunity.max_candidates_per_run' => 1]);

        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business);
        $manager = app(OpportunityManager::class);

        $first = $manager->stageCandidate($run, $this->createOpportunityCandidateData());

        // Re-staging the same fingerprint must not be blocked by the cap
        // even though the run is already "full".
        $again = $manager->stageCandidate($run, $this->createOpportunityCandidateData());
        $this->assertSame($first->id, $again->id);

        // A genuinely new fingerprint must be rejected.
        $this->expectException(CandidateLimitExceededException::class);

        $manager->stageCandidate($run, $this->createOpportunityCandidateData([
            'type' => 'missing_email',
            'evidence' => $this->evidenceFor('email_blank'),
        ]));
    }

    public function test_restaging_the_same_fingerprint_updates_mutable_fields_and_leaves_count_unchanged(): void
    {
        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business);
        $manager = app(OpportunityManager::class);

        $first = $manager->stageCandidate($run, $this->createOpportunityCandidateData(['impact' => 3]));
        $second = $manager->stageCandidate($run, $this->createOpportunityCandidateData(['impact' => 5]));

        $this->assertSame($first->id, $second->id);
        $this->assertSame(5, $second->fresh()->impact);
        $this->assertSame(1, OpportunityRunCandidate::where('opportunity_run_id', $run->id)->count());
    }

    public function test_manually_corrupted_existing_candidate_identity_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business);
        $manager = app(OpportunityManager::class);

        $candidate = $manager->stageCandidate($run, $this->createOpportunityCandidateData());

        // Directly corrupt an immutable identity field, bypassing the
        // manager entirely — the fingerprint column is left untouched, so a
        // re-stage of the same candidate data will resolve to this same
        // (now-corrupted) row by fingerprint lookup.
        $candidate->forceFill(['type' => 'missing_email'])->save();

        $this->expectException(ImmutableCandidateIdentityMismatchException::class);

        $manager->stageCandidate($run, $this->createOpportunityCandidateData());
    }

    public function test_successful_staging_refreshes_heartbeat_to_scored_at(): void
    {
        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business, ['heartbeat_at' => now()->subMinutes(5)]);
        $manager = app(OpportunityManager::class);

        $candidate = $manager->stageCandidate($run, $this->createOpportunityCandidateData());

        $run->refresh();
        $this->assertTrue($run->heartbeat_at->equalTo($candidate->scored_at));
    }

    public function test_rejected_staging_leaves_heartbeat_and_candidate_rows_unchanged(): void
    {
        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business);
        $originalHeartbeat = $run->heartbeat_at;
        $manager = app(OpportunityManager::class);

        try {
            $this->expectException(InvalidOpportunityCandidateException::class);

            $manager->stageCandidate($run, $this->createOpportunityCandidateData(['impact' => 99]));
        } finally {
            $run->refresh();
            $this->assertTrue($originalHeartbeat->equalTo($run->heartbeat_at));
            $this->assertSame(0, OpportunityRunCandidate::where('opportunity_run_id', $run->id)->count());
        }
    }
}

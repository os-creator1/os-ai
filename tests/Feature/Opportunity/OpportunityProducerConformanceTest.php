<?php

namespace Tests\Feature\Opportunity;

use App\Enums\Opportunity\OpportunityRunStatus;
use App\Enums\Opportunity\OpportunityWorkerKey;
use App\Library\Opportunity\BusinessAdvisorOpportunityProducer;
use App\Library\Opportunity\Exceptions\OpportunityEngineDisabledException;
use App\Library\Opportunity\Exceptions\UnsupportedOpportunityTypeException;
use App\Library\Opportunity\OpportunityCandidateData;
use App\Library\Opportunity\OpportunityManager;
use App\Library\Opportunity\OpportunityProducer;
use App\Models\Business;
use App\Models\Opportunity;
use App\Models\OpportunityRun;
use App\Models\OpportunityRunCandidate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Opportunity\Concerns\CreatesOpportunityTestData;
use Tests\Feature\Opportunity\Support\FakeOpportunityProducer;
use Tests\TestCase;

/**
 * RFC-002 Milestone 6, Slice 1 — proves the run/candidate/finalization
 * protocol is genuinely driven by the OpportunityProducer interface, not
 * hardcoded to BusinessAdvisorOpportunityProducer. Does not duplicate the
 * exhaustive same-worker concurrency, abandonment, candidate-limit, schema
 * validation, atomic-rollback, execution, workflow-preservation, or
 * safe-failure-string coverage already proven elsewhere.
 */
class OpportunityProducerConformanceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOpportunityTestData;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('opportunity.enabled', true);
    }

    public function test_zero_candidate_fake_producer_finalizes_successfully_under_a_distinct_worker_key(): void
    {
        $business = $this->createBusinessForOpportunities();
        $existingOpportunity = $this->createOpportunity($business, ['fingerprint' => hash('sha256', 'conformance-zero-existing')]);
        $existingUpdatedAt = $existingOpportunity->updated_at;

        $manager = app(OpportunityManager::class);
        $producer = new FakeOpportunityProducer(OpportunityWorkerKey::Seo, []);

        $run = $this->runProducerThroughProtocol($manager, $producer, $business);

        $this->assertSame(OpportunityRunStatus::Succeeded, $run->status);
        $this->assertSame(OpportunityWorkerKey::Seo, $run->worker_key);
        $this->assertSame(0, OpportunityRunCandidate::where('opportunity_run_id', $run->id)->count());
        $this->assertSame(0, Opportunity::where('business_id', $business->id)->where('worker_key', OpportunityWorkerKey::Seo->value)->count());

        $existingOpportunity->refresh();
        $this->assertTrue($existingUpdatedAt->equalTo($existingOpportunity->updated_at));
    }

    public function test_one_candidate_fake_producer_drives_the_protocol_via_the_interface(): void
    {
        $business = $this->createBusinessForOpportunities();
        $candidate = $this->createOpportunityCandidateData();

        $manager = app(OpportunityManager::class);
        $producer = new FakeOpportunityProducer(OpportunityWorkerKey::BusinessAdvisor, [$candidate]);

        $this->assertNotInstanceOf(BusinessAdvisorOpportunityProducer::class, $producer);

        $run = $this->runProducerThroughProtocol($manager, $producer, $business);

        $this->assertSame(OpportunityRunStatus::Succeeded, $run->status);
        $this->assertSame(1, OpportunityRunCandidate::where('opportunity_run_id', $run->id)->count());

        $opportunity = Opportunity::where('business_id', $business->id)
            ->where('worker_key', OpportunityWorkerKey::BusinessAdvisor->value)
            ->where('type', 'missing_phone')
            ->first();

        $this->assertNotNull($opportunity);
        $this->assertSame('current', $opportunity->freshness->value);
        $this->assertSame($run->id, $opportunity->last_confirmed_run_id);
        $this->assertSame(1, $opportunity->occurrence_number);

        // The canonical fingerprint is manager-computed at staging time and
        // persisted onto the staged OpportunityRunCandidate row — never a
        // formula reimplemented here (mirrors
        // OpportunityManagerFinalizeSuccessfulRunTest::
        // test_all_candidate_derived_fields_are_copied_correctly()).
        $stagedCandidate = OpportunityRunCandidate::where('opportunity_run_id', $run->id)->first();
        $this->assertNotNull($stagedCandidate);
        $this->assertSame(OpportunityWorkerKey::BusinessAdvisor, $opportunity->worker_key);
        $this->assertSame('missing_phone', $opportunity->type);
        $this->assertSame($stagedCandidate->fingerprint, $opportunity->fingerprint);
        $this->assertSame($stagedCandidate->fingerprint_version, $opportunity->fingerprint_version);
        $this->assertSame($run->id, $opportunity->last_confirmed_run_id);
    }

    public function test_multiple_candidate_fake_producer_stages_all_under_the_same_run(): void
    {
        $business = $this->createBusinessForOpportunities();
        $phoneCandidate = $this->createOpportunityCandidateData();
        $emailCandidate = $this->createOpportunityCandidateData([
            'type' => 'missing_email',
            'evidence' => $this->opportunityEvidenceItemFor('email_blank'),
        ]);

        $manager = app(OpportunityManager::class);
        $producer = new FakeOpportunityProducer(OpportunityWorkerKey::BusinessAdvisor, [$phoneCandidate, $emailCandidate]);

        $run = $this->runProducerThroughProtocol($manager, $producer, $business);

        $this->assertSame(OpportunityRunStatus::Succeeded, $run->status);
        $this->assertSame(2, OpportunityRunCandidate::where('opportunity_run_id', $run->id)->count());

        $currentOpportunities = Opportunity::where('business_id', $business->id)
            ->where('worker_key', OpportunityWorkerKey::BusinessAdvisor->value)
            ->whereIn('type', ['missing_phone', 'missing_email'])
            ->get();

        $this->assertCount(2, $currentOpportunities);
        $this->assertSame(['missing_email', 'missing_phone'], $currentOpportunities->pluck('type')->sort()->values()->all());

        // Re-running the same producer/candidates must reconcile to the
        // same two live Opportunities, never create duplicates for the
        // same fingerprint.
        $secondProducer = new FakeOpportunityProducer(OpportunityWorkerKey::BusinessAdvisor, [$phoneCandidate, $emailCandidate]);
        $this->runProducerThroughProtocol($manager, $secondProducer, $business);

        $this->assertSame(2, Opportunity::where('business_id', $business->id)
            ->where('worker_key', OpportunityWorkerKey::BusinessAdvisor->value)
            ->whereIn('type', ['missing_phone', 'missing_email'])
            ->count());
    }

    public function test_cross_worker_active_runs_may_coexist_for_the_same_business(): void
    {
        $business = $this->createBusinessForOpportunities();
        $manager = app(OpportunityManager::class);
        $fakeProducer = new FakeOpportunityProducer(OpportunityWorkerKey::Seo, []);

        $businessAdvisorRun = $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);
        $seoRun = $manager->beginRun($business, $fakeProducer->workerKey(), 1);

        $this->assertSame(OpportunityRunStatus::Running, $businessAdvisorRun->fresh()->status);
        $this->assertSame(OpportunityRunStatus::Running, $seoRun->fresh()->status);
        $this->assertNotSame($businessAdvisorRun->id, $seoRun->id);
    }

    public function test_unregistered_seo_type_is_rejected_and_persists_nothing(): void
    {
        $business = $this->createBusinessForOpportunities();
        $manager = app(OpportunityManager::class);

        // 'missing_phone' is a valid business_advisor type but is not
        // registered for the 'seo' worker key.
        $unregisteredCandidate = $this->createOpportunityCandidateData();
        $producer = new FakeOpportunityProducer(OpportunityWorkerKey::Seo, [$unregisteredCandidate]);

        $run = $manager->beginRun($business, $producer->workerKey(), 1);
        $candidates = iterator_to_array($producer->produce($business));

        $this->assertCount(1, $candidates);
        $this->assertInstanceOf(OpportunityCandidateData::class, $candidates[0]);

        try {
            $this->expectException(UnsupportedOpportunityTypeException::class);

            $manager->stageCandidate($run, $candidates[0]);
        } finally {
            $this->assertSame(0, OpportunityRunCandidate::where('opportunity_run_id', $run->id)->count());
            $this->assertSame(0, Opportunity::where('business_id', $business->id)->where('worker_key', OpportunityWorkerKey::Seo->value)->count());

            $freshRun = $run->fresh();
            $this->assertSame(OpportunityRunStatus::Running, $freshRun->status);
            $this->assertNull($freshRun->safe_error_summary);
        }
    }

    public function test_feature_disabled_rejects_before_any_run_is_persisted(): void
    {
        $business = $this->createBusinessForOpportunities();
        $manager = app(OpportunityManager::class);
        $producer = new FakeOpportunityProducer(OpportunityWorkerKey::Seo, []);

        config()->set('opportunity.enabled', false);

        try {
            $this->expectException(OpportunityEngineDisabledException::class);

            $manager->beginRun($business, $producer->workerKey(), 1);
        } finally {
            $this->assertSame(0, OpportunityRun::where('business_id', $business->id)->count());
        }
    }

    /**
     * The one reusable conformance-execution helper: begins a run under the
     * producer's own worker key, drives every yielded candidate through
     * stageCandidate(), then finalizes. Uses only OpportunityManager's
     * existing public signatures — no production runner/dispatcher is
     * introduced.
     */
    private function runProducerThroughProtocol(
        OpportunityManager $manager,
        OpportunityProducer $producer,
        Business $business,
        int $producerVersion = 1,
    ): OpportunityRun {
        $run = $manager->beginRun($business, $producer->workerKey(), $producerVersion);

        foreach ($producer->produce($business) as $candidate) {
            $this->assertInstanceOf(OpportunityCandidateData::class, $candidate);
            $manager->stageCandidate($run, $candidate);
        }

        $manager->finalizeSuccessfulRun($run);

        return $run->fresh();
    }
}

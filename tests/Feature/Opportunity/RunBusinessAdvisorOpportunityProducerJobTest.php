<?php

namespace Tests\Feature\Opportunity;

use App\Enums\Opportunity\OpportunityRunStatus;
use App\Enums\Opportunity\OpportunityWorkerKey;
use App\Jobs\Opportunity\RunBusinessAdvisorOpportunityProducer;
use App\Models\Business;
use App\Models\Opportunity;
use App\Models\OpportunityRun;
use App\Models\OpportunityRunCandidate;
use App\Repositories\Contracts\BusinessLocationRepository;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\BusinessServiceRepository;
use App\Repositories\Contracts\OpportunityRunRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Feature\Opportunity\Concerns\CreatesOpportunityTestData;
use Tests\TestCase;

class RunBusinessAdvisorOpportunityProducerJobTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOpportunityTestData;

    public function test_job_implements_should_queue_and_should_queue_after_commit(): void
    {
        $job = new RunBusinessAdvisorOpportunityProducer(1);

        $this->assertInstanceOf(ShouldQueue::class, $job);
        $this->assertInstanceOf(ShouldQueueAfterCommit::class, $job);
    }

    public function test_constructor_stores_only_the_scalar_business_id(): void
    {
        $job = new RunBusinessAdvisorOpportunityProducer(42);

        $this->assertSame(42, $job->businessId);
        $this->assertIsInt($job->businessId);
    }

    public function test_tries_and_backoff_are_configured(): void
    {
        $job = new RunBusinessAdvisorOpportunityProducer(1);

        $this->assertSame(3, $job->tries);
        $this->assertSame([10, 60, 300], $job->backoff);
    }

    public function test_job_is_dispatched_on_the_configured_queue(): void
    {
        Queue::fake();
        config(['opportunity.queue' => 'opportunity-engine']);

        RunBusinessAdvisorOpportunityProducer::dispatch(1);

        Queue::assertPushedOn('opportunity-engine', RunBusinessAdvisorOpportunityProducer::class);
    }

    public function test_job_falls_back_to_the_default_queue_when_config_is_missing(): void
    {
        Queue::fake();

        RunBusinessAdvisorOpportunityProducer::dispatch(1);

        Queue::assertPushedOn('default', RunBusinessAdvisorOpportunityProducer::class);
    }

    public function test_incomplete_business_produces_one_succeeded_run_and_expected_opportunities(): void
    {
        $business = $this->createBusinessForOpportunities();

        $job = new RunBusinessAdvisorOpportunityProducer($business->id);
        app()->call([$job, 'handle']);

        $run = OpportunityRun::where('business_id', $business->id)->first();
        $this->assertNotNull($run);
        $this->assertSame(OpportunityRunStatus::Succeeded, $run->status);

        // phone/email/website/description/primary_location/services/gbp/facebook
        // all blank by default — instagram is not eligible for this industry.
        $this->assertSame(8, Opportunity::where('business_id', $business->id)->count());
        $this->assertSame(1, Opportunity::where('business_id', $business->id)->where('type', 'missing_phone')->count());
        $this->assertSame(1, Opportunity::where('business_id', $business->id)->where('type', 'missing_website')->count());
    }

    public function test_every_run_uses_worker_key_business_advisor_and_producer_version_one(): void
    {
        $business = $this->createBusinessForOpportunities();

        $job = new RunBusinessAdvisorOpportunityProducer($business->id);
        app()->call([$job, 'handle']);

        $run = OpportunityRun::where('business_id', $business->id)->first();
        $this->assertSame(OpportunityWorkerKey::BusinessAdvisor, $run->worker_key);
        $this->assertSame(1, $run->producer_version);
    }

    public function test_fully_complete_business_produces_a_succeeded_zero_candidate_run(): void
    {
        $business = $this->completeBusiness();

        $job = new RunBusinessAdvisorOpportunityProducer($business->id);
        app()->call([$job, 'handle']);

        $run = OpportunityRun::where('business_id', $business->id)->first();
        $this->assertSame(OpportunityRunStatus::Succeeded, $run->status);
        $this->assertSame(0, Opportunity::where('business_id', $business->id)->count());
        $this->assertSame(0, OpportunityRunCandidate::where('opportunity_run_id', $run->id)->count());
    }

    public function test_healthy_existing_running_run_causes_a_safe_no_op(): void
    {
        $business = $this->createBusinessForOpportunities();
        $existingRun = $this->createOpportunityRun($business);

        $job = new RunBusinessAdvisorOpportunityProducer($business->id);
        app()->call([$job, 'handle']);

        $this->assertSame(1, OpportunityRun::where('business_id', $business->id)->count());

        $existingRun->refresh();
        $this->assertSame(OpportunityRunStatus::Running, $existingRun->status);
        $this->assertNull($existingRun->completed_at);
        $this->assertNull($existingRun->abandoned_at);
        $this->assertNull($existingRun->safe_error_summary);

        $this->assertSame(0, Opportunity::where('business_id', $business->id)->count());
    }

    public function test_missing_business_returns_safely_and_creates_no_run(): void
    {
        $job = new RunBusinessAdvisorOpportunityProducer(999999999);
        app()->call([$job, 'handle']);

        $this->assertSame(0, OpportunityRun::where('business_id', 999999999)->count());
    }

    public function test_running_the_job_twice_after_success_reaffirms_rather_than_duplicates(): void
    {
        $business = $this->createBusinessForOpportunities();

        $firstJob = new RunBusinessAdvisorOpportunityProducer($business->id);
        app()->call([$firstJob, 'handle']);

        $firstRun = OpportunityRun::where('business_id', $business->id)->first();
        $opportunityCountAfterFirst = Opportunity::where('business_id', $business->id)->count();

        $secondJob = new RunBusinessAdvisorOpportunityProducer($business->id);
        app()->call([$secondJob, 'handle']);

        $this->assertSame(2, OpportunityRun::where('business_id', $business->id)->where('status', OpportunityRunStatus::Succeeded->value)->count());
        $this->assertSame($opportunityCountAfterFirst, Opportunity::where('business_id', $business->id)->count());

        $secondRun = OpportunityRun::where('business_id', $business->id)->latest('id')->first();
        $this->assertNotSame($firstRun->id, $secondRun->id);

        $reaffirmed = Opportunity::where('business_id', $business->id)->where('type', 'missing_phone')->first();
        $this->assertSame($secondRun->id, $reaffirmed->last_confirmed_run_id);
    }

    public function test_timed_out_existing_run_is_abandoned_and_a_replacement_run_succeeds(): void
    {
        $business = $this->createBusinessForOpportunities();
        $staleRun = $this->createOpportunityRun($business, ['heartbeat_at' => now()->subMinutes(45)]);
        $orphanedCandidate = $this->createOpportunityRunCandidate($staleRun);

        $job = new RunBusinessAdvisorOpportunityProducer($business->id);
        app()->call([$job, 'handle']);

        $staleRun->refresh();
        $this->assertSame(OpportunityRunStatus::Failed, $staleRun->status);
        $this->assertNotNull($staleRun->abandoned_at);

        $replacement = OpportunityRun::where('business_id', $business->id)->where('id', '!=', $staleRun->id)->first();
        $this->assertNotNull($replacement);
        $this->assertSame(OpportunityRunStatus::Succeeded, $replacement->status);

        // The orphaned candidate on the abandoned run is untouched, never applied.
        $orphanedCandidate->refresh();
        $this->assertSame($staleRun->id, $orphanedCandidate->opportunity_run_id);
        $this->assertSame(8, Opportunity::where('business_id', $business->id)->count());
    }

    public function test_forced_failure_after_at_least_one_candidate_staged_marks_the_run_failed(): void
    {
        $business = $this->createBusinessForOpportunities();
        $this->installFailingRunRepository(['succeeded' => 'Simulated internal failure with secret detail xyz123.']);

        $job = new RunBusinessAdvisorOpportunityProducer($business->id);

        $caught = null;

        try {
            app()->call([$job, 'handle']);
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'Expected the forced finalize failure to propagate.');
        $this->assertSame('Simulated internal failure with secret detail xyz123.', $caught->getMessage());

        $run = OpportunityRun::where('business_id', $business->id)->first();
        $this->assertSame(OpportunityRunStatus::Failed, $run->status);
        $this->assertSame(
            'We could not refresh your opportunities right now. Please try again later.',
            $run->safe_error_summary
        );
        $this->assertGreaterThan(0, OpportunityRunCandidate::where('opportunity_run_id', $run->id)->count());
        $this->assertSame(0, Opportunity::where('business_id', $business->id)->count());
    }

    public function test_retrying_after_a_failed_attempt_creates_a_new_succeeded_run_with_no_duplicates(): void
    {
        $business = $this->createBusinessForOpportunities();
        $realRepository = app(OpportunityRunRepository::class);
        $this->installFailingRunRepository(['succeeded' => 'Simulated internal failure.']);

        try {
            app()->call([new RunBusinessAdvisorOpportunityProducer($business->id), 'handle']);
        } catch (RuntimeException) {
            // expected — see test_forced_failure_after_at_least_one_candidate_staged_marks_the_run_failed
        }

        $this->assertSame(0, Opportunity::where('business_id', $business->id)->count());

        // Restore the real repository before retrying with normal dependencies.
        $this->app->instance(OpportunityRunRepository::class, $realRepository);

        app()->call([new RunBusinessAdvisorOpportunityProducer($business->id), 'handle']);

        $succeededRuns = OpportunityRun::where('business_id', $business->id)->where('status', OpportunityRunStatus::Succeeded->value)->get();
        $this->assertCount(1, $succeededRuns);
        $this->assertSame(8, Opportunity::where('business_id', $business->id)->count());

        $opportunity = Opportunity::where('business_id', $business->id)->where('type', 'missing_phone')->first();
        $this->assertSame($succeededRuns->first()->id, $opportunity->last_confirmed_run_id);
    }

    public function test_run_already_active_exception_is_not_routed_through_fail_run(): void
    {
        Log::spy();

        $business = $this->createBusinessForOpportunities();
        $existingRun = $this->createOpportunityRun($business);

        app()->call([new RunBusinessAdvisorOpportunityProducer($business->id), 'handle']);

        $existingRun->refresh();
        $this->assertSame(OpportunityRunStatus::Running, $existingRun->status);
        $this->assertNull($existingRun->safe_error_summary);

        Log::shouldHaveReceived('info')->once();
        Log::shouldNotHaveReceived('error');
    }

    public function test_when_begin_run_fails_before_returning_a_run_fail_run_is_not_called(): void
    {
        $business = $this->createBusinessForOpportunities();
        $repository = $this->installCreateFailingRunRepository();

        $caught = null;

        try {
            app()->call([new RunBusinessAdvisorOpportunityProducer($business->id), 'handle']);
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'Expected the forced beginRun() failure to propagate.');
        $this->assertSame(0, $repository->updateCalls, 'failRun() must never be called when beginRun() never returned a run.');
        $this->assertSame(0, OpportunityRun::where('business_id', $business->id)->count());
    }

    public function test_when_fail_run_also_fails_the_original_exception_remains_the_one_rethrown(): void
    {
        $business = $this->createBusinessForOpportunities();
        Log::spy();

        $this->installFailingRunRepository([
            'succeeded' => 'Original finalize failure.',
            'failed' => 'Secondary failRun failure.',
        ]);

        $caught = null;

        try {
            app()->call([new RunBusinessAdvisorOpportunityProducer($business->id), 'handle']);
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught);
        $this->assertSame('Original finalize failure.', $caught->getMessage());

        Log::shouldHaveReceived('error')->twice();
    }

    public function test_full_internal_exception_details_are_logged_while_safe_error_summary_stays_generic(): void
    {
        Log::spy();

        $business = $this->createBusinessForOpportunities();
        $this->installFailingRunRepository(['succeeded' => 'Simulated internal failure with secret detail xyz123.']);

        try {
            app()->call([new RunBusinessAdvisorOpportunityProducer($business->id), 'handle']);
        } catch (RuntimeException) {
            // expected
        }

        Log::shouldHaveReceived('error')->withArgs(function ($message, $context) {
            return isset($context['exception'])
                && str_contains($context['exception']->getMessage(), 'secret detail xyz123');
        })->atLeast()->once();

        $run = OpportunityRun::where('business_id', $business->id)->first();
        $this->assertSame(
            'We could not refresh your opportunities right now. Please try again later.',
            $run->safe_error_summary
        );
        $this->assertStringNotContainsString('secret detail xyz123', $run->safe_error_summary);
    }

    private function completeBusiness(): Business
    {
        $business = $this->createBusinessForOpportunities();

        app(BusinessRepository::class)->update($business, [
            'email' => 'hello@example.com',
            'phone' => '+15551234567',
            'website_url' => 'https://example.com',
            'description' => str_repeat('a', 60),
            'google_business_profile_url' => 'https://business.google.com/example',
            'facebook_url' => 'https://facebook.com/example',
            'instagram_url' => 'https://instagram.com/example',
        ]);

        app(BusinessLocationRepository::class)->upsertPrimary($business, [
            'service_mode' => 'storefront',
            'address_line_1' => '1 Main St',
            'city' => 'Austin',
            'region' => 'TX',
            'country_code' => 'US',
            'public_address' => true,
        ]);

        app(BusinessServiceRepository::class)->syncForBusiness($business, [
            ['name' => 'Digital Photo Booth', 'is_primary' => true],
        ]);

        return $business;
    }

    /**
     * A delegating OpportunityRunRepository (not a partial mock): every
     * method except update() delegates to the real, fully initialized
     * repository. update() throws only for the given statuses, with the
     * given message, so beginRun()/stageCandidate() and any other-status
     * write proceed for real.
     *
     * @param  array<string, string>  $throwMessagesByStatus
     */
    private function installFailingRunRepository(array $throwMessagesByStatus): void
    {
        $real = app(OpportunityRunRepository::class);

        $repository = new class($real, $throwMessagesByStatus) implements OpportunityRunRepository {
            public function __construct(
                private readonly OpportunityRunRepository $real,
                private readonly array $throwMessagesByStatus,
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
                $status = $attributes['status'] ?? null;

                if ($status !== null && array_key_exists($status, $this->throwMessagesByStatus)) {
                    throw new RuntimeException($this->throwMessagesByStatus[$status]);
                }

                return $this->real->update($run, $attributes);
            }
        };

        $this->app->instance(OpportunityRunRepository::class, $repository);
    }

    /**
     * A delegating OpportunityRunRepository whose create() always throws —
     * simulating a beginRun() failure before any run is returned — while
     * counting update() calls, so a test can prove failRun() (which would
     * call update()) was never invoked.
     */
    private function installCreateFailingRunRepository(): object
    {
        $real = app(OpportunityRunRepository::class);

        $repository = new class($real) implements OpportunityRunRepository {
            public int $updateCalls = 0;

            public function __construct(
                private readonly OpportunityRunRepository $real,
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
                throw new RuntimeException('Forced beginRun() failure before a run was created.');
            }

            public function update(OpportunityRun $run, array $attributes): OpportunityRun
            {
                $this->updateCalls++;

                return $this->real->update($run, $attributes);
            }
        };

        $this->app->instance(OpportunityRunRepository::class, $repository);

        return $repository;
    }
}

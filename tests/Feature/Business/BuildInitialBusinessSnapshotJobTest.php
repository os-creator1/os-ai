<?php

namespace Tests\Feature\Business;

use App\Enums\Business\OnboardingStatus;
use App\Enums\Business\OnboardingStep;
use App\Events\Business\InitialBusinessAnalysisCompleted;
use App\Events\Business\InitialBusinessAnalysisFailed;
use App\Jobs\Business\BuildInitialBusinessSnapshot;
use App\Models\Customer;
use App\Models\CustomerOnboarding;
use App\Repositories\Contracts\BusinessLocationRepository;
use App\Repositories\Contracts\BusinessServiceRepository;
use App\Repositories\Contracts\CustomerOnboardingRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

class BuildInitialBusinessSnapshotJobTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    public function test_tries_and_backoff_are_configured(): void
    {
        $job = new BuildInitialBusinessSnapshot(1, 1);

        $this->assertSame(3, $job->tries);
        $this->assertSame([10, 60, 300], $job->backoff);
    }

    public function test_job_is_dispatched_on_the_configured_queue(): void
    {
        Queue::fake();
        config(['business.onboarding.analysis_queue' => 'business-analysis']);

        BuildInitialBusinessSnapshot::dispatch(1, 1);

        Queue::assertPushedOn('business-analysis', BuildInitialBusinessSnapshot::class);
    }

    public function test_job_falls_back_to_the_default_queue_when_config_is_missing(): void
    {
        Queue::fake();

        BuildInitialBusinessSnapshot::dispatch(1, 1);

        Queue::assertPushedOn('default', BuildInitialBusinessSnapshot::class);
    }

    public function test_successful_run_stores_the_snapshot_and_marks_results_ready(): void
    {
        Event::fake([InitialBusinessAnalysisCompleted::class]);

        [$onboarding] = $this->readyOnboarding();
        $onboarding = app(CustomerOnboardingRepository::class)->startAnalysis($onboarding, 1);

        $job = new BuildInitialBusinessSnapshot($onboarding->id, 1);
        app()->call([$job, 'handle']);

        $onboarding->refresh();
        $this->assertSame(OnboardingStatus::ResultsReady, $onboarding->status);
        $this->assertSame(OnboardingStep::Results, $onboarding->current_step);
        $this->assertNotNull($onboarding->analysis_payload);
        $this->assertSame(1, $onboarding->analysis_payload['version']);
        $this->assertArrayHasKey('profile_completeness_percent', $onboarding->analysis_payload);
        $this->assertNotNull($onboarding->analysis_completed_at);
        $this->assertNull($onboarding->analysis_error);

        Event::assertDispatched(InitialBusinessAnalysisCompleted::class, fn ($event) => $event->onboardingId === $onboarding->id
            && $event->analysisVersion === 1);
    }

    public function test_stale_version_job_exits_without_writing(): void
    {
        Event::fake([InitialBusinessAnalysisCompleted::class]);

        [$onboarding] = $this->readyOnboarding();
        $onboardingRepository = app(CustomerOnboardingRepository::class);
        $onboarding = $onboardingRepository->startAnalysis($onboarding, 1);
        // Simulate a second analysis request superseding the first before the
        // first job runs.
        $onboarding = $onboardingRepository->startAnalysis($onboarding, 2);

        $job = new BuildInitialBusinessSnapshot($onboarding->id, 1);
        app()->call([$job, 'handle']);

        $onboarding->refresh();
        $this->assertSame(2, $onboarding->analysis_version);
        $this->assertSame(OnboardingStatus::AnalysisPending, $onboarding->status);
        $this->assertNull($onboarding->analysis_payload);

        Event::assertNotDispatched(InitialBusinessAnalysisCompleted::class);
    }

    public function test_job_exits_when_onboarding_is_completed(): void
    {
        Event::fake([InitialBusinessAnalysisCompleted::class]);

        [$onboarding] = $this->readyOnboarding();
        $onboarding = app(CustomerOnboardingRepository::class)->startAnalysis($onboarding, 1);
        $onboarding->status = OnboardingStatus::Completed;
        $onboarding->save();

        $job = new BuildInitialBusinessSnapshot($onboarding->id, 1);
        app()->call([$job, 'handle']);

        $onboarding->refresh();
        $this->assertNull($onboarding->analysis_payload);
        Event::assertNotDispatched(InitialBusinessAnalysisCompleted::class);
    }

    public function test_job_exits_when_onboarding_is_dismissed(): void
    {
        Event::fake([InitialBusinessAnalysisCompleted::class]);

        [$onboarding] = $this->readyOnboarding();
        $onboarding = app(CustomerOnboardingRepository::class)->startAnalysis($onboarding, 1);
        $onboarding->status = OnboardingStatus::Dismissed;
        $onboarding->save();

        $job = new BuildInitialBusinessSnapshot($onboarding->id, 1);
        app()->call([$job, 'handle']);

        $onboarding->refresh();
        $this->assertNull($onboarding->analysis_payload);
        Event::assertNotDispatched(InitialBusinessAnalysisCompleted::class);
    }

    public function test_a_missing_business_is_marked_failed_instead_of_left_pending_forever(): void
    {
        Event::fake([InitialBusinessAnalysisFailed::class]);

        $customer = $this->createCustomer();
        $onboarding = app(CustomerOnboardingRepository::class)->startForCustomer($customer, true);
        $onboarding = app(CustomerOnboardingRepository::class)->startAnalysis($onboarding, 1);

        $job = new BuildInitialBusinessSnapshot($onboarding->id, 1);
        app()->call([$job, 'handle']);

        $onboarding->refresh();
        $this->assertSame(OnboardingStatus::Failed, $onboarding->status);
        $this->assertSame(OnboardingStep::Analysis, $onboarding->current_step);
        $this->assertNull($onboarding->analysis_payload);
        $this->assertSame('We could not finish the analysis. Please retry.', $onboarding->analysis_error);

        Event::assertDispatched(InitialBusinessAnalysisFailed::class, fn ($event) => $event->onboardingId === $onboarding->id
            && $event->analysisVersion === 1
            && $event->safeError === 'We could not finish the analysis. Please retry.');
    }

    public function test_a_cross_customer_business_attachment_is_marked_failed_instead_of_left_pending_forever(): void
    {
        Event::fake([InitialBusinessAnalysisFailed::class]);

        [$onboarding, $customer] = $this->readyOnboarding();
        $stranger = $this->createCustomer();
        $strangerBusiness = $this->createBusinessWithWorkspace($stranger, $this->businessAttributes());

        // Simulate a data-integrity edge case (never reachable through
        // OnboardingManager's own ownership checks) where the onboarding row
        // references a business owned by someone else.
        $onboarding->business_id = $strangerBusiness->id;
        $onboarding->save();
        $onboarding = app(CustomerOnboardingRepository::class)->startAnalysis($onboarding, 1);

        $job = new BuildInitialBusinessSnapshot($onboarding->id, 1);
        app()->call([$job, 'handle']);

        $onboarding->refresh();
        $this->assertSame(OnboardingStatus::Failed, $onboarding->status);
        $this->assertSame(OnboardingStep::Analysis, $onboarding->current_step);
        $this->assertNull($onboarding->analysis_payload);
        $this->assertSame('We could not finish the analysis. Please retry.', $onboarding->analysis_error);

        // The stranger's business must never be touched by this failure path.
        $strangerBusiness->refresh();
        $this->assertSame($stranger->user_id, $strangerBusiness->customer_id);

        Event::assertDispatched(InitialBusinessAnalysisFailed::class, 1);
    }

    public function test_a_stale_version_is_left_untouched_even_when_the_business_is_missing_or_cross_tenant(): void
    {
        Event::fake([InitialBusinessAnalysisFailed::class, InitialBusinessAnalysisCompleted::class]);

        $customer = $this->createCustomer();
        $onboardingRepository = app(CustomerOnboardingRepository::class);
        $onboarding = $onboardingRepository->startForCustomer($customer, true);
        // No business attached — would hit the new failure path if the
        // version guard were not checked first.
        $onboarding = $onboardingRepository->startAnalysis($onboarding, 1);
        // A newer request supersedes it before the old job runs.
        $onboarding = $onboardingRepository->startAnalysis($onboarding, 2);

        $job = new BuildInitialBusinessSnapshot($onboarding->id, 1);
        app()->call([$job, 'handle']);

        $onboarding->refresh();
        $this->assertSame(2, $onboarding->analysis_version);
        $this->assertSame(OnboardingStatus::AnalysisPending, $onboarding->status);
        $this->assertNull($onboarding->analysis_error);

        Event::assertNotDispatched(InitialBusinessAnalysisFailed::class);
        Event::assertNotDispatched(InitialBusinessAnalysisCompleted::class);
    }

    public function test_failure_stores_only_a_safe_error_and_keeps_the_analysis_step(): void
    {
        Event::fake([InitialBusinessAnalysisFailed::class]);

        [$onboarding] = $this->readyOnboarding();
        $onboarding = app(CustomerOnboardingRepository::class)->startAnalysis($onboarding, 1);

        $job = new BuildInitialBusinessSnapshot($onboarding->id, 1);
        $job->failed(new RuntimeException(
            'SQLSTATE[42S02]: Base table not found for query on connection at /var/www/app/Library/Business/Foo.php with password=hunter2'
        ));

        $onboarding->refresh();
        $this->assertSame(OnboardingStatus::Failed, $onboarding->status);
        $this->assertSame(OnboardingStep::Analysis, $onboarding->current_step);
        $this->assertSame('We could not finish the analysis. Please retry.', $onboarding->analysis_error);
        $this->assertStringNotContainsString('password', $onboarding->analysis_error);
        $this->assertStringNotContainsString('SQLSTATE', $onboarding->analysis_error);
        $this->assertStringNotContainsString('/var/www', $onboarding->analysis_error);

        Event::assertDispatched(InitialBusinessAnalysisFailed::class, fn ($event) => $event->onboardingId === $onboarding->id
            && $event->analysisVersion === 1
            && $event->safeError === 'We could not finish the analysis. Please retry.');
    }

    public function test_failed_hook_is_a_noop_for_a_stale_version(): void
    {
        Event::fake([InitialBusinessAnalysisFailed::class]);

        [$onboarding] = $this->readyOnboarding();
        $onboardingRepository = app(CustomerOnboardingRepository::class);
        $onboarding = $onboardingRepository->startAnalysis($onboarding, 1);
        $onboarding = $onboardingRepository->startAnalysis($onboarding, 2);

        $job = new BuildInitialBusinessSnapshot($onboarding->id, 1);
        $job->failed(new RuntimeException('Simulated failure.'));

        $onboarding->refresh();
        $this->assertSame(OnboardingStatus::AnalysisPending, $onboarding->status);
        $this->assertNull($onboarding->analysis_error);
        Event::assertNotDispatched(InitialBusinessAnalysisFailed::class);
    }

    public function test_failed_hook_exits_safely_when_the_onboarding_no_longer_exists(): void
    {
        Event::fake([InitialBusinessAnalysisFailed::class]);

        $job = new BuildInitialBusinessSnapshot(999999999, 1);
        $job->failed(new RuntimeException('Simulated failure.'));

        Event::assertNotDispatched(InitialBusinessAnalysisFailed::class);
        $this->assertSame(0, CustomerOnboarding::whereKey(999999999)->count());
    }

    /**
     * @return array{0: CustomerOnboarding, 1: Customer}
     */
    private function readyOnboarding(): array
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

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

        $onboardingRepository = app(CustomerOnboardingRepository::class);
        $onboarding = $onboardingRepository->startForCustomer($customer, true);
        $onboarding = $onboardingRepository->attachBusiness($onboarding, $business);
        $onboarding->current_step = OnboardingStep::Analysis;
        $onboarding->completed_steps = ['goals', 'business', 'location', 'services', 'assets'];
        $onboarding->save();

        return [$onboarding, $customer];
    }
}

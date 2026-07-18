<?php

namespace Tests\Feature\Business;

use App\Enums\Business\BusinessServiceMode;
use App\Enums\Business\BusinessServiceStatus;
use App\Enums\Business\OnboardingStatus;
use App\Enums\Business\OnboardingStep;
use App\Events\Business\BusinessCreated;
use App\Events\Business\CustomerOnboardingCompleted;
use App\Events\Business\CustomerOnboardingStarted;
use App\Events\Business\CustomerOnboardingStepCompleted;
use App\Events\Business\InitialBusinessAnalysisRequested;
use App\Jobs\Business\BuildInitialBusinessSnapshot;
use App\Library\Business\InitialBusinessSnapshotBuilder;
use App\Library\Business\OnboardingManager;
use App\Models\AppConfig;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\BusinessService;
use App\Models\CustomerOnboarding;
use App\Models\User;
use App\Repositories\Contracts\AccountRepository;
use App\Repositories\Contracts\BusinessLocationRepository;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\BusinessServiceRepository;
use App\Repositories\Contracts\CustomerOnboardingRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

class OnboardingManagerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    public function test_start_is_idempotent(): void
    {
        Event::fake([CustomerOnboardingStarted::class]);

        $customer = $this->createCustomer();
        $manager = app(OnboardingManager::class);

        $first = $manager->start($customer, true);
        $second = $manager->start($customer, false);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, CustomerOnboarding::where('customer_id', $customer->user_id)->count());
        Event::assertDispatched(CustomerOnboardingStarted::class, 1);
    }

    public function test_valid_step_progression(): void
    {
        $customer = $this->createCustomer();
        $manager = app(OnboardingManager::class);
        $onboarding = $manager->start($customer, true);

        $this->assertSame(OnboardingStep::Goals, $onboarding->current_step);

        $onboarding = $manager->completeStep($onboarding, OnboardingStep::Goals, OnboardingStep::Business);
        $this->assertSame(OnboardingStep::Business, $onboarding->current_step);
        $this->assertSame(['goals'], $onboarding->completed_steps);

        $onboarding = $manager->completeStep($onboarding, OnboardingStep::Business, OnboardingStep::Location);
        $this->assertSame(OnboardingStep::Location, $onboarding->current_step);
        $this->assertSame(['goals', 'business'], $onboarding->completed_steps);
    }

    public function test_completing_a_step_ahead_of_the_current_step_throws(): void
    {
        $customer = $this->createCustomer();
        $manager = app(OnboardingManager::class);
        $onboarding = $manager->start($customer, true);

        $this->expectException(InvalidArgumentException::class);

        // Onboarding is still at 'goals'; 'services' has unmet prerequisites.
        $manager->completeStep($onboarding, OnboardingStep::Services, OnboardingStep::Assets);
    }

    public function test_completing_a_step_cannot_claim_to_skip_the_next_one(): void
    {
        $customer = $this->createCustomer();
        $manager = app(OnboardingManager::class);
        $onboarding = $manager->start($customer, true);

        $this->expectException(InvalidArgumentException::class);

        // Completing 'goals' but claiming 'services' is next skips 'business' and 'location'.
        $manager->completeStep($onboarding, OnboardingStep::Goals, OnboardingStep::Services);
    }

    public function test_repeated_completion_of_the_same_step_is_idempotent(): void
    {
        Event::fake([CustomerOnboardingStepCompleted::class]);

        $customer = $this->createCustomer();
        $manager = app(OnboardingManager::class);
        $onboarding = $manager->start($customer, true);

        $manager->completeStep($onboarding->fresh(), OnboardingStep::Goals, OnboardingStep::Business);
        $manager->completeStep($onboarding->fresh(), OnboardingStep::Goals, OnboardingStep::Business);

        $onboarding->refresh();
        $this->assertSame(['goals'], $onboarding->completed_steps);
        $this->assertSame(OnboardingStep::Business, $onboarding->current_step);

        Event::assertDispatched(CustomerOnboardingStepCompleted::class, 1);
    }

    public function test_revisiting_an_earlier_step_does_not_regress_current_step(): void
    {
        $customer = $this->createCustomer();
        $manager = app(OnboardingManager::class);
        $onboarding = $manager->start($customer, true);
        $onboarding = $manager->completeStep($onboarding, OnboardingStep::Goals, OnboardingStep::Business);
        $onboarding = $manager->completeStep($onboarding, OnboardingStep::Business, OnboardingStep::Location);

        $onboarding = $manager->completeStep($onboarding, OnboardingStep::Goals, OnboardingStep::Business);

        $this->assertSame(OnboardingStep::Location, $onboarding->current_step);
    }

    public function test_resume_returns_the_bookmark_and_clamps_requests_ahead_of_it(): void
    {
        $customer = $this->createCustomer();
        $manager = app(OnboardingManager::class);
        $onboarding = $manager->start($customer, true);
        $onboarding = $manager->completeStep($onboarding, OnboardingStep::Goals, OnboardingStep::Business);
        $onboarding = $manager->completeStep($onboarding, OnboardingStep::Business, OnboardingStep::Location);

        $this->assertSame(OnboardingStep::Location, $manager->resolveStep($onboarding));
        $this->assertSame(OnboardingStep::Goals, $manager->resolveStep($onboarding, OnboardingStep::Goals));
        $this->assertSame(OnboardingStep::Location, $manager->resolveStep($onboarding, OnboardingStep::Complete));
    }

    public function test_assets_step_can_be_skipped(): void
    {
        $customer = $this->createCustomer();
        $manager = app(OnboardingManager::class);
        $onboarding = $manager->start($customer, true);
        $onboarding = $manager->completeStep($onboarding, OnboardingStep::Goals, OnboardingStep::Business);
        $onboarding = $manager->completeStep($onboarding, OnboardingStep::Business, OnboardingStep::Location);
        $onboarding = $manager->completeStep($onboarding, OnboardingStep::Location, OnboardingStep::Services);
        $onboarding = $manager->completeStep($onboarding, OnboardingStep::Services, OnboardingStep::Assets);

        $onboarding = $manager->skipStep($onboarding, OnboardingStep::Assets);

        $this->assertSame(OnboardingStep::Analysis, $onboarding->current_step);
        $this->assertContains('assets', $onboarding->completed_steps);
    }

    public function test_only_the_assets_step_can_be_skipped(): void
    {
        $customer = $this->createCustomer();
        $manager = app(OnboardingManager::class);
        $onboarding = $manager->start($customer, true);

        $this->expectException(InvalidArgumentException::class);

        $manager->skipStep($onboarding, OnboardingStep::Goals);
    }

    public function test_save_business_step_rolls_back_and_dispatches_nothing_when_business_manager_fails(): void
    {
        Event::fake([CustomerOnboardingStepCompleted::class, BusinessCreated::class]);

        $customer = $this->createCustomer();
        $manager = app(OnboardingManager::class);
        $onboarding = $manager->start($customer, true);

        try {
            $manager->saveBusinessStep($onboarding, $customer, array_merge($this->businessAttributes(), [
                'website_url' => 'https://user:pass@example.com',
            ]));
            $this->fail('Expected an InvalidArgumentException to be thrown.');
        } catch (InvalidArgumentException) {
            // expected
        }

        $onboarding->refresh();
        $this->assertSame(OnboardingStep::Goals, $onboarding->current_step);
        $this->assertNull($onboarding->business_id);
        $this->assertSame(0, Business::where('customer_id', $customer->user_id)->count());

        Event::assertNotDispatched(CustomerOnboardingStepCompleted::class);
        Event::assertNotDispatched(BusinessCreated::class);
    }

    public function test_save_business_step_rolls_back_a_successful_business_write_when_the_step_advance_is_invalid(): void
    {
        Event::fake([CustomerOnboardingStepCompleted::class, BusinessCreated::class]);

        $customer = $this->createCustomer();
        $manager = app(OnboardingManager::class);
        // Still at 'goals' — the business step hasn't been reached, so the
        // Business -> Location advance inside saveBusinessStep() is invalid.
        // BusinessManager will successfully create the row before that failure.
        $onboarding = $manager->start($customer, true);

        try {
            $manager->saveBusinessStep($onboarding, $customer, $this->businessAttributes());
            $this->fail('Expected an InvalidArgumentException to be thrown.');
        } catch (InvalidArgumentException) {
            // expected
        }

        $onboarding->refresh();
        $this->assertSame(OnboardingStep::Goals, $onboarding->current_step);
        $this->assertNull($onboarding->business_id);
        $this->assertSame(0, Business::where('customer_id', $customer->user_id)->count());

        Event::assertNotDispatched(BusinessCreated::class);
        Event::assertNotDispatched(CustomerOnboardingStepCompleted::class);
    }

    public function test_save_business_step_attaches_the_business_and_advances_the_step(): void
    {
        $customer = $this->createCustomer();
        $manager = app(OnboardingManager::class);
        $onboarding = $manager->start($customer, true);

        // Prerequisite setup happens before Event::fake() so its own legitimate
        // Goals -> Business completion event isn't counted against saveBusinessStep(),
        // which only dispatches the Business -> Location completion.
        $onboarding = $manager->completeStep($onboarding, OnboardingStep::Goals, OnboardingStep::Business);

        Event::fake([CustomerOnboardingStepCompleted::class, BusinessCreated::class]);

        $onboarding = $manager->saveBusinessStep($onboarding, $customer, $this->businessAttributes());

        $this->assertNotNull($onboarding->business_id);
        $this->assertSame(OnboardingStep::Location, $onboarding->current_step);
        $this->assertSame(1, Business::where('customer_id', $customer->user_id)->count());

        Event::assertDispatched(BusinessCreated::class, 1);
        Event::assertDispatched(CustomerOnboardingStepCompleted::class, 1);
        Event::assertDispatched(CustomerOnboardingStepCompleted::class, function ($event) use ($onboarding) {
            return $event->onboardingId === $onboarding->id
                && $event->completedStep === OnboardingStep::Business->value
                && $event->nextStep === OnboardingStep::Location->value;
        });
    }

    public function test_started_event_is_not_dispatched_when_an_outer_transaction_fails(): void
    {
        Event::fake([CustomerOnboardingStarted::class]);

        $customer = $this->createCustomer();
        $manager = app(OnboardingManager::class);

        try {
            DB::transaction(function () use ($manager, $customer) {
                $manager->start($customer, true);

                throw new RuntimeException('Simulated outer transaction failure.');
            });
            $this->fail('Expected a RuntimeException to be thrown.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(0, CustomerOnboarding::where('customer_id', $customer->user_id)->count());
        Event::assertNotDispatched(CustomerOnboardingStarted::class);
    }

    public function test_tenant_isolation_prevents_cross_customer_business_step(): void
    {
        $owner = $this->createCustomer();
        $stranger = $this->createCustomer();
        $manager = app(OnboardingManager::class);
        $onboarding = $manager->start($owner, true);

        $this->expectException(AuthorizationException::class);

        $manager->saveBusinessStep($onboarding, $stranger, $this->businessAttributes());
    }

    public function test_registration_hook_starts_onboarding_when_enabled(): void
    {
        $this->ensureAdminUserRowExists();
        $this->ensureCustomerPermissionsAppConfigExists();
        config([
            'business.onboarding.enabled' => true,
            'business.onboarding.require_for_new_customers' => true,
        ]);
        Event::fake([CustomerOnboardingStarted::class]);

        $user = app(AccountRepository::class)->register($this->registrationInput());

        $this->assertSame(1, CustomerOnboarding::where('customer_id', $user->id)->count());
        Event::assertDispatched(CustomerOnboardingStarted::class, 1);
    }

    public function test_registration_hook_does_not_start_onboarding_when_disabled(): void
    {
        $this->ensureAdminUserRowExists();
        $this->ensureCustomerPermissionsAppConfigExists();
        config([
            'business.onboarding.enabled' => false,
            'business.onboarding.require_for_new_customers' => false,
        ]);

        $user = app(AccountRepository::class)->register($this->registrationInput());

        $this->assertSame(0, CustomerOnboarding::where('customer_id', $user->id)->count());
    }

    public function test_request_analysis_increments_version_marks_pending_and_dispatches_job_and_event_after_commit(): void
    {
        Bus::fake();
        Event::fake([InitialBusinessAnalysisRequested::class]);

        [$onboarding, $customer] = $this->onboardingAtAnalysisStep();
        $manager = app(OnboardingManager::class);

        $updated = $manager->requestAnalysis($onboarding, $customer);

        $this->assertSame(1, $updated->analysis_version);
        $this->assertSame(OnboardingStatus::AnalysisPending, $updated->status);
        $this->assertSame(OnboardingStep::Analysis, $updated->current_step);

        Bus::assertDispatched(BuildInitialBusinessSnapshot::class, fn ($job) => $job->onboardingId === $updated->id && $job->expectedVersion === 1);
        Event::assertDispatched(InitialBusinessAnalysisRequested::class, fn ($event) => $event->onboardingId === $updated->id && $event->analysisVersion === 1);
    }

    public function test_request_analysis_throws_when_earlier_steps_are_incomplete(): void
    {
        $customer = $this->createCustomer();
        $manager = app(OnboardingManager::class);
        $onboarding = $manager->start($customer, true);

        $this->expectException(InvalidArgumentException::class);

        $manager->requestAnalysis($onboarding, $customer);
    }

    public function test_repeated_analysis_requests_increment_the_version_each_time(): void
    {
        Bus::fake();
        Event::fake([InitialBusinessAnalysisRequested::class]);

        [$onboarding, $customer] = $this->onboardingAtAnalysisStep();
        $manager = app(OnboardingManager::class);

        // startAnalysis() in the Eloquent repository mutates and returns the
        // very same CustomerOnboarding instance it is given, rather than a
        // clone — so $first and $second end up being the same object. Capture
        // the scalar version right after the first call; reading it off
        // $first after the second call has run would see the mutated value.
        $first = $manager->requestAnalysis($onboarding, $customer);
        $firstVersion = $first->analysis_version;

        $second = $manager->requestAnalysis($first, $customer);

        $this->assertSame(1, $firstVersion);
        $this->assertSame(2, $second->analysis_version);
        // Confirms the two writes actually landed in the database in
        // sequence, independent of the shared in-memory object.
        $this->assertSame(2, $second->fresh()->analysis_version);

        Bus::assertDispatched(BuildInitialBusinessSnapshot::class, fn ($job) => $job->expectedVersion === 1);
        Bus::assertDispatched(BuildInitialBusinessSnapshot::class, fn ($job) => $job->expectedVersion === 2);
        Bus::assertDispatched(BuildInitialBusinessSnapshot::class, 2);

        Event::assertDispatched(InitialBusinessAnalysisRequested::class, fn ($event) => $event->analysisVersion === 1);
        Event::assertDispatched(InitialBusinessAnalysisRequested::class, fn ($event) => $event->analysisVersion === 2);
        Event::assertDispatched(InitialBusinessAnalysisRequested::class, 2);
    }

    /**
     * Bus::fake() records dispatch() calls immediately and unconditionally
     * (see BusFake::dispatch()) — it never consults ShouldQueueAfterCommit or
     * the database transactions manager, so Bus::assertNotDispatched() cannot
     * prove a job would actually be discarded by a real rollback. The real
     * deferral lives in SyncQueue::push(), which — like the real event
     * dispatcher — routes through DatabaseTransactionsManager::addCallback().
     * This test therefore runs the real sync queue (no Bus::fake()) and
     * proves the job's actual side effect never runs, by asserting its
     * InitialBusinessSnapshotBuilder dependency is never invoked. Event::fake()
     * is still valid here: EventFake::fakeEvent() explicitly re-checks
     * ShouldDispatchAfterCommit and routes through the same transactions
     * manager, so it does honor rollback.
     */
    public function test_request_analysis_dispatches_neither_job_nor_event_when_an_outer_transaction_fails(): void
    {
        Event::fake([InitialBusinessAnalysisRequested::class]);
        $this->mock(InitialBusinessSnapshotBuilder::class, function ($mock) {
            $mock->shouldNotReceive('build');
        });

        [$onboarding, $customer] = $this->onboardingAtAnalysisStep();
        $manager = app(OnboardingManager::class);

        try {
            DB::transaction(function () use ($manager, $onboarding, $customer) {
                $manager->requestAnalysis($onboarding, $customer);

                throw new RuntimeException('Simulated outer transaction failure.');
            });
            $this->fail('Expected a RuntimeException to be thrown.');
        } catch (RuntimeException) {
            // expected
        }

        $onboarding->refresh();
        $this->assertSame(0, $onboarding->analysis_version);
        $this->assertSame(OnboardingStatus::Started, $onboarding->status);
        $this->assertNull($onboarding->analysis_started_at);
        $this->assertNull($onboarding->analysis_payload);
        $this->assertNull($onboarding->analysis_completed_at);

        Event::assertNotDispatched(InitialBusinessAnalysisRequested::class);

        // Mockery::close() (invoked by the base TestCase's tearDown()) verifies
        // shouldNotReceive('build') above — if the queued job's handle() had
        // actually run, this test would fail there, proving the job's real
        // side effect never executed.
    }

    /**
     * The rollback proof above only holds because this job actually carries
     * the marker interface SyncQueue's real push() checks — pin that
     * contract explicitly so a future refactor that quietly drops it fails
     * loudly here instead of silently reopening the "left pending forever"
     * class of bug.
     */
    public function test_the_analysis_job_implements_should_queue_after_commit(): void
    {
        $this->assertInstanceOf(
            \Illuminate\Contracts\Queue\ShouldQueueAfterCommit::class,
            new BuildInitialBusinessSnapshot(1, 1)
        );
    }

    public function test_record_first_value_action_persists_the_key_and_advances_to_complete(): void
    {
        [$onboarding, $customer] = $this->onboardingAtAnalysisStep();
        $manager = app(OnboardingManager::class);

        $updated = $manager->recordFirstValueAction($onboarding, $customer, 'add_phone');

        $this->assertSame('add_phone', $updated->first_value_action_key);
        $this->assertNotNull($updated->first_value_action_completed_at);
        $this->assertSame(OnboardingStep::Complete, $updated->current_step);
    }

    public function test_record_first_value_action_enforces_tenant_isolation(): void
    {
        [$onboarding] = $this->onboardingAtAnalysisStep();
        $stranger = $this->createCustomer();
        $manager = app(OnboardingManager::class);

        $this->expectException(AuthorizationException::class);

        $manager->recordFirstValueAction($onboarding, $stranger, 'add_phone');
    }

    public function test_complete_throws_without_a_primary_location(): void
    {
        $customer = $this->createCustomer();
        $business = app(BusinessRepository::class)->createForCustomer($customer, $this->businessAttributes());
        $manager = app(OnboardingManager::class);
        $onboarding = $manager->start($customer, true);
        $onboarding = app(CustomerOnboardingRepository::class)->attachBusiness($onboarding, $business);

        $this->expectException(InvalidArgumentException::class);

        $manager->complete($onboarding, $customer);
    }

    public function test_complete_throws_without_an_active_primary_service(): void
    {
        [$onboarding, $customer, $business] = $this->onboardingAtAnalysisStep();
        // Remove the only service's primary flag so no active primary service exists.
        app(BusinessServiceRepository::class)->syncForBusiness($business, []);
        $manager = app(OnboardingManager::class);

        $this->expectException(InvalidArgumentException::class);

        $manager->complete($onboarding, $customer);
    }

    public function test_complete_throws_without_a_persisted_analysis_payload(): void
    {
        [$onboarding, $customer] = $this->onboardingAtAnalysisStep();
        $manager = app(OnboardingManager::class);

        $this->expectException(InvalidArgumentException::class);

        $manager->complete($onboarding, $customer);
    }

    public function test_complete_throws_when_findings_exist_and_no_first_value_action_was_recorded(): void
    {
        [$onboarding, $customer] = $this->onboardingAtAnalysisStep();
        $onboarding->analysis_payload = ['version' => 1, 'findings' => [['fingerprint' => 'business:1:missing_phone']]];
        $onboarding->save();
        $manager = app(OnboardingManager::class);

        $this->expectException(InvalidArgumentException::class);

        $manager->complete($onboarding, $customer);
    }

    public function test_complete_throws_when_there_are_zero_primary_locations(): void
    {
        [$onboarding, $customer, $business] = $this->onboardingAtAnalysisStep();
        $onboarding->analysis_payload = ['version' => 1, 'findings' => []];
        $onboarding->save();
        // Simulate a data-integrity edge case: a location row exists but
        // nothing is flagged primary (never reachable through the repository's
        // own upsertPrimary() invariant).
        $business->locations()->update(['is_primary' => false]);
        $manager = app(OnboardingManager::class);

        $this->expectException(InvalidArgumentException::class);

        $manager->complete($onboarding, $customer);
    }

    public function test_complete_throws_when_there_are_two_primary_locations(): void
    {
        [$onboarding, $customer, $business] = $this->onboardingAtAnalysisStep();
        $onboarding->analysis_payload = ['version' => 1, 'findings' => []];
        $onboarding->save();
        $this->createRawLocation($business, ['is_primary' => true, 'city' => 'Dallas']);
        $manager = app(OnboardingManager::class);

        $this->expectException(InvalidArgumentException::class);

        $manager->complete($onboarding, $customer);
    }

    public function test_complete_throws_when_active_services_exist_but_none_is_primary(): void
    {
        [$onboarding, $customer, $business] = $this->onboardingAtAnalysisStep();
        $onboarding->analysis_payload = ['version' => 1, 'findings' => []];
        $onboarding->save();
        // The one active service from onboardingAtAnalysisStep() stays active
        // but is no longer flagged primary — "at least one active service"
        // still holds, "exactly one active primary service" does not.
        $business->services()->update(['is_primary' => false]);
        $manager = app(OnboardingManager::class);

        $this->expectException(InvalidArgumentException::class);

        $manager->complete($onboarding, $customer);
    }

    public function test_complete_throws_when_there_are_two_active_primary_services(): void
    {
        [$onboarding, $customer, $business] = $this->onboardingAtAnalysisStep();
        $onboarding->analysis_payload = ['version' => 1, 'findings' => []];
        $onboarding->save();
        $this->createRawService($business, ['is_primary' => true, 'name' => 'Second Service']);
        $manager = app(OnboardingManager::class);

        $this->expectException(InvalidArgumentException::class);

        $manager->complete($onboarding, $customer);
    }

    public function test_complete_succeeds_with_a_recorded_first_value_action_and_dispatches_the_completed_event_once(): void
    {
        Event::fake([CustomerOnboardingCompleted::class]);

        [$onboarding, $customer] = $this->onboardingAtAnalysisStep();
        $onboarding->analysis_payload = ['version' => 1, 'findings' => [['fingerprint' => 'business:1:missing_phone']]];
        $onboarding->save();
        $manager = app(OnboardingManager::class);
        $onboarding = $manager->recordFirstValueAction($onboarding, $customer, 'add_phone');

        $updated = $manager->complete($onboarding, $customer);

        $this->assertSame(OnboardingStatus::Completed, $updated->status);
        $this->assertNotNull($updated->completed_at);
        Event::assertDispatched(CustomerOnboardingCompleted::class, 1);
    }

    public function test_complete_succeeds_with_no_findings_and_no_first_value_action(): void
    {
        Event::fake([CustomerOnboardingCompleted::class]);

        [$onboarding, $customer] = $this->onboardingAtAnalysisStep();
        $onboarding->analysis_payload = ['version' => 1, 'findings' => []];
        $onboarding->save();
        $manager = app(OnboardingManager::class);

        $updated = $manager->complete($onboarding, $customer);

        $this->assertSame(OnboardingStatus::Completed, $updated->status);
        Event::assertDispatched(CustomerOnboardingCompleted::class, 1);
    }

    public function test_complete_is_idempotent_and_does_not_redispatch_the_completed_event(): void
    {
        Event::fake([CustomerOnboardingCompleted::class]);

        [$onboarding, $customer] = $this->onboardingAtAnalysisStep();
        $onboarding->analysis_payload = ['version' => 1, 'findings' => []];
        $onboarding->save();
        $manager = app(OnboardingManager::class);
        $onboarding = $manager->complete($onboarding, $customer);

        $again = $manager->complete($onboarding, $customer);

        $this->assertSame($onboarding->completed_at?->toIso8601String(), $again->completed_at?->toIso8601String());
        Event::assertDispatched(CustomerOnboardingCompleted::class, 1);
    }

    /**
     * An onboarding with a complete business (primary location + one active
     * primary service), advanced to the Analysis step and ready for
     * requestAnalysis()/complete() to be exercised. No analysis has run yet.
     *
     * @return array{0: CustomerOnboarding, 1: \App\Models\Customer, 2: Business}
     */
    private function onboardingAtAnalysisStep(): array
    {
        $customer = $this->createCustomer();
        $business = app(BusinessRepository::class)->createForCustomer($customer, $this->businessAttributes());

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

        return [$onboarding, $customer, $business];
    }

    /**
     * Inserts a BusinessLocation directly, bypassing
     * BusinessLocationRepository::upsertPrimary()'s single-primary invariant
     * — used only to construct the inconsistent-data scenarios
     * assertCompletionPrerequisites() must defend against.
     */
    private function createRawLocation(Business $business, array $attributes = []): BusinessLocation
    {
        $location = new BusinessLocation();
        $location->forceFill(array_merge([
            'business_id' => $business->id,
            'service_mode' => BusinessServiceMode::Storefront,
            'address_line_1' => '1 Main St',
            'city' => 'Austin',
            'region' => 'TX',
            'country_code' => 'US',
            'is_primary' => true,
        ], $attributes));
        $location->save();

        return $location;
    }

    /**
     * Inserts a BusinessService directly, bypassing
     * BusinessServiceRepository::syncForBusiness()'s single-active-primary
     * invariant — used only to construct the inconsistent-data scenarios
     * assertCompletionPrerequisites() must defend against.
     */
    private function createRawService(Business $business, array $attributes = []): BusinessService
    {
        $service = new BusinessService();
        $service->forceFill(array_merge([
            'business_id' => $business->id,
            'name' => 'Extra Service',
            'slug' => 'extra-service-' . uniqid(),
            'status' => BusinessServiceStatus::Active,
            'is_primary' => true,
        ], $attributes));
        $service->save();

        return $service;
    }

    /**
     * @return array<string, mixed>
     */
    private function registrationInput(): array
    {
        return [
            'first_name' => 'Test',
            'last_name' => 'Registrant',
            'email' => 'registrant' . uniqid() . '@example.test',
            'password' => 'password123',
            'phone' => '+15551234567',
            'address' => '1 Main St',
            'company' => null,
            'city' => 'Austin',
            'postcode' => '78701',
            'country' => 'US',
        ];
    }

    /**
     * EloquentAccountRepository::register() hardcodes an admin notification
     * targeting user_id 1, which has a real FK to users.id. RefreshDatabase's
     * per-test rollback doesn't reset MySQL's auto-increment counter, so a
     * fresh test run can't assume id 1 exists — insert it explicitly if not.
     */
    private function ensureAdminUserRowExists(): void
    {
        if (User::whereKey(1)->exists()) {
            return;
        }

        DB::table('users')->insert([
            'id' => 1,
            'uid' => (string) Str::uuid(),
            'first_name' => 'Admin',
            'email' => 'admin-' . uniqid() . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Customer::customerPermissions(), called from EloquentUserRepository::store()
     * during registration, reads the 'customer_permissions' app_config row via
     * Helper::app_config() — which dereferences the lookup result unconditionally, so a
     * missing row is a hard error, not a graceful default. In a real install this row is
     * seeded by database/seeders/AppConfigSeeder, but that seeder truncates app_config
     * first, which implicitly commits in MySQL and would break RefreshDatabase's
     * per-test transaction. Reuse the same default-value source it draws from
     * (AppConfig::defaultSettings()) and insert only the one row registration needs.
     */
    private function ensureCustomerPermissionsAppConfigExists(): void
    {
        if (AppConfig::where('setting', 'customer_permissions')->exists()) {
            return;
        }

        $default = collect((new AppConfig())->defaultSettings())
            ->firstWhere('setting', 'customer_permissions');

        AppConfig::create($default);
    }
}

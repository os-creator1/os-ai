<?php

namespace Tests\Feature\Business;

use App\Enums\Business\OnboardingStep;
use App\Events\Business\BusinessCreated;
use App\Events\Business\CustomerOnboardingStarted;
use App\Events\Business\CustomerOnboardingStepCompleted;
use App\Library\Business\OnboardingManager;
use App\Models\AppConfig;
use App\Models\Business;
use App\Models\CustomerOnboarding;
use App\Models\User;
use App\Repositories\Contracts\AccountRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

<?php

namespace Tests\Feature\PlatformBilling;

use App\Enums\Business\BusinessStatus;
use App\Enums\Business\OnboardingStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Models\Business;
use App\Models\CustomerOnboarding;
use App\Models\PlatformSubscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspacePlanAssignment;
use App\Repositories\Contracts\CustomerOnboardingRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\PlatformBilling\Concerns\CreatesPlatformSubscriptions;
use Tests\TestCase;

/**
 * V1 release-risk closure, items 2 and 3.
 *
 *  2. A renewal / replayed subscription event must never send an Active
 *     Business back into onboarding.
 *  3. A signed-in customer WITHOUT a confirmed paid plan must never be able to
 *     finish onboarding into an Active Business.
 */
class V1ReleaseRiskOnboardingTest extends TestCase
{
    use RefreshDatabase;
    use CreatesPlatformSubscriptions;
    use CreatesBusinessTestData;

    private const PASSWORD = 'correct-horse-battery';

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindFakeStripe();
        $this->ensureRequiredAppConfigRowsExist();
        $this->platformAdminId();
        config(['account.can_register' => true]);
    }

    /** @return array<string, string> */
    private function form(): array
    {
        return [
            'first_name' => 'Pat',
            'last_name' => 'Rivera',
            'email' => 'pat' . uniqid() . '@example.test',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'business_name' => 'Harbor Lane Studios',
            'industry' => 'photo_booth_service',
            'country_code' => 'US',
            'timezone' => 'America/New_York',
            'currency_code' => 'USD',
            'tier' => 'growth',
        ];
    }

    /** Signs up; pays only when $pay. Returns [User, Workspace, Business]. */
    private function signupAs(bool $pay): array
    {
        $this->sellableTier(WorkspacePlanTier::Growth);
        $form = $this->form();
        $this->signUp($form);

        if ($pay) {
            $subscription = PlatformSubscription::query()->sole();
            $this->stripe->completeCheckout((string) $subscription->provider_checkout_session_id);
            $this->get(route('signup.success'));
        }

        $user = User::query()->where('email', $form['email'])->sole();
        $user->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($user->fresh());

        $workspace = Workspace::query()->where('owner_user_id', $user->id)->sole();

        return [
            $user->fresh(),
            $workspace,
            Business::query()->where('workspace_id', $workspace->id)->sole(),
        ];
    }

    private function walkWizardToResults(User $user): void
    {
        $this->post(route('customer.onboarding.goals.store'), ['primary_goals' => ['lead_generation']]);
        $this->post(route('customer.onboarding.business.store'), $this->businessAttributes(['name' => 'Harbor Lane Studios']));
        $this->post(route('customer.onboarding.location.store'), [
            'service_mode' => 'storefront', 'address_line_1' => '1 Main St', 'city' => 'Austin',
            'region' => 'TX', 'country_code' => 'US', 'public_address' => '1',
        ]);
        $this->post(route('customer.onboarding.services.store'), [
            'services' => [['name' => 'Digital Photo Booth', 'is_primary' => '1']],
        ]);
        $this->post(route('customer.onboarding.assets.skip'));

        $onboarding = CustomerOnboarding::query()->where('customer_id', $user->id)->sole();
        app(CustomerOnboardingRepository::class)->completeAnalysis($onboarding->refresh(), 0, [
            'version' => 1, 'generated_at' => now()->toIso8601String(), 'profile_completeness_percent' => 100,
            'facts' => [], 'findings' => [],
        ]);
    }

    // ----------------------------------------------------------------- 2

    public function test_a_renewal_or_replayed_event_never_reopens_onboarding_for_an_active_business(): void
    {
        [$user, , $business] = $this->signupAs(true);
        $this->walkWizardToResults($user);
        $this->post(route('customer.onboarding.complete'))->assertRedirect(route('user.home'));

        $this->assertSame(BusinessStatus::Active, $business->fresh()->status);
        $completed = CustomerOnboarding::query()->sole();
        $this->assertSame(OnboardingStatus::Completed, $completed->status);

        $subscription = PlatformSubscription::query()->sole()->refresh();
        $fixture = [
            'subscription' => $subscription,
            'provider_subscription_id' => (string) $subscription->provider_subscription_id,
        ];

        $this->deliver('invoice.paid', $fixture, ['event_id' => 'evt_renewal_1'])->assertOk();
        $this->deliver('invoice.paid', $fixture, ['event_id' => 'evt_renewal_1'])->assertOk();
        $this->deliver('customer.subscription.updated', $fixture, ['event_id' => 'evt_renewal_2'])->assertOk();

        $this->assertSame(BusinessStatus::Active, $business->fresh()->status);
        $this->assertSame(1, CustomerOnboarding::query()->count());
        $after = CustomerOnboarding::query()->sole();
        $this->assertSame($completed->id, $after->id);
        $this->assertSame(OnboardingStatus::Completed, $after->status);
        $this->assertSame(1, WorkspacePlanAssignment::query()->count());
        $this->get(route('user.home'))->assertOk();
    }

    public function test_a_renewal_does_not_create_a_required_onboarding_for_an_active_business_that_has_none(): void
    {
        [$user, , $business] = $this->signupAs(true);
        $this->walkWizardToResults($user);
        $this->post(route('customer.onboarding.complete'));

        // An Active Business whose onboarding row is gone (repair path,
        // historical account): a later renewal must not invent a requirement.
        CustomerOnboarding::query()->delete();
        $this->assertSame(BusinessStatus::Active, $business->fresh()->status);

        $subscription = PlatformSubscription::query()->sole()->refresh();
        $this->deliver('invoice.paid', [
            'subscription' => $subscription,
            'provider_subscription_id' => (string) $subscription->provider_subscription_id,
        ], ['event_id' => 'evt_renewal_none'])->assertOk();

        $this->assertSame(0, CustomerOnboarding::query()->count(), 'A renewal never starts onboarding for an Active Business.');
        $this->assertSame(BusinessStatus::Active, $business->fresh()->status);
        $this->get(route('user.home'))->assertOk();
    }

    public function test_the_initial_paid_signup_still_hands_off_to_onboarding_for_a_draft_business(): void
    {
        [$user, , $business] = $this->signupAs(true);

        $onboarding = CustomerOnboarding::query()->where('customer_id', $user->id)->sole();
        $this->assertSame($business->id, $onboarding->business_id);
        $this->assertSame(BusinessStatus::Draft, $business->fresh()->status);
    }

    // ----------------------------------------------------------------- 3

    public function test_an_unpaid_account_cannot_finish_onboarding_into_an_active_business(): void
    {
        [$user, $workspace, $business] = $this->signupAs(false);

        $this->assertSame(0, WorkspacePlanAssignment::query()->count());
        $this->assertSame(BusinessStatus::Draft, $business->fresh()->status);

        // Opened by hand: no hand-off ever ran for this account.
        $this->get(route('customer.onboarding.show'));
        $this->walkWizardToResults($user);
        $this->post(route('customer.onboarding.complete'));

        $this->assertSame(BusinessStatus::Draft, $business->fresh()->status, 'No confirmed plan, no activation.');
        $this->assertNotSame(
            OnboardingStatus::Completed,
            CustomerOnboarding::query()->where('customer_id', $user->id)->first()?->status,
        );
    }

    public function test_a_properly_paid_account_still_activates_through_onboarding(): void
    {
        [$user, , $business] = $this->signupAs(true);
        $this->walkWizardToResults($user);

        $this->post(route('customer.onboarding.complete'))->assertRedirect(route('user.home'));

        $this->assertSame(BusinessStatus::Active, $business->fresh()->status);
        $this->assertSame(OnboardingStatus::Completed, CustomerOnboarding::query()->sole()->status);
    }
}

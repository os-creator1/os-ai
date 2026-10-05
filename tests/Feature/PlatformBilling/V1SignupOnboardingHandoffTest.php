<?php

namespace Tests\Feature\PlatformBilling;

use App\Enums\Business\BusinessStatus;
use App\Enums\Business\OnboardingStatus;
use App\Enums\Business\OnboardingStep;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Exceptions\Workspace\WorkspaceAccessDeniedException;
use App\Library\Business\BusinessManager;
use App\Library\Business\OnboardingManager;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Customer;
use App\Models\CustomerOnboarding;
use App\Models\PlatformSubscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspacePlanAssignment;
use App\Repositories\Contracts\CustomerOnboardingRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\PlatformBilling\Concerns\CreatesPlatformSubscriptions;
use Tests\TestCase;

/**
 * Signup -> onboarding hand-off.
 *
 * A paid V1 signup provisions the Workspace's ONE Draft Business and its
 * initial Location before payment. Onboarding must continue THAT Business, not
 * create another (which the one-Business-per-Workspace rule refuses, and which
 * used to dead-end a freshly paid customer on "We can't create your business").
 */
class V1SignupOnboardingHandoffTest extends TestCase
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
    private function form(array $overrides = []): array
    {
        return array_merge([
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
        ], $overrides);
    }

    /** Signs up and pays; returns [User, Workspace, Business, form]. */
    private function paidSignup(array $overrides = []): array
    {
        $this->sellableTier(WorkspacePlanTier::Growth);
        $form = $this->form($overrides);
        $this->signUp($form);

        $subscription = PlatformSubscription::query()->sole();
        $this->stripe->completeCheckout((string) $subscription->provider_checkout_session_id);
        $this->get(route('signup.success'));

        $user = User::query()->where('email', $form['email'])->sole();
        // The verification step is its own, unchanged concern; here the
        // customer has already followed the link.
        $user->forceFill(['email_verified_at' => now()])->save();

        $workspace = Workspace::query()->where('owner_user_id', $user->id)->sole();
        $business = Business::query()->where('workspace_id', $workspace->id)->sole();

        // The test app keeps the guard's cached user across requests; re-seat
        // the session on the now-verified row.
        $this->actingAs($user->fresh());

        return [$user->fresh(), $workspace, $business, $form];
    }

    private function onboardingFor(User $user): ?CustomerOnboarding
    {
        return CustomerOnboarding::query()->where('customer_id', $user->id)->first();
    }

    private function assertOneGraph(bool $paid = true): void
    {
        $this->assertSame(1, User::query()->where('is_customer', true)->count());
        $this->assertSame(1, Workspace::query()->count());
        $this->assertSame(1, Business::query()->count());
        $this->assertSame(1, BusinessLocation::query()->count());
        $this->assertSame(1, PlatformSubscription::query()->count());
        $this->assertSame($paid ? 1 : 0, WorkspacePlanAssignment::query()->count());
    }

    /** Takes the wizard from Goals through Assets, with an analysis result ready. */
    private function walkWizardToResults(CustomerOnboarding $onboarding): void
    {
        $this->post(route('customer.onboarding.goals.store'), ['primary_goals' => ['lead_generation']]);
        $this->post(route('customer.onboarding.business.store'), $this->businessAttributes(['name' => 'Harbor Lane Studios']));
        $this->post(route('customer.onboarding.location.store'), [
            'service_mode' => 'storefront',
            'address_line_1' => '1 Main St',
            'city' => 'Austin',
            'region' => 'TX',
            'country_code' => 'US',
            'public_address' => '1',
        ]);
        $this->post(route('customer.onboarding.services.store'), [
            'services' => [['name' => 'Digital Photo Booth', 'is_primary' => '1']],
        ]);
        $this->post(route('customer.onboarding.assets.skip'));

        app(CustomerOnboardingRepository::class)->completeAnalysis($onboarding->refresh(), 0, [
            'version' => 1, 'generated_at' => now()->toIso8601String(), 'profile_completeness_percent' => 100,
            'facts' => [], 'findings' => [],
        ]);
    }

    // A ----------------------------------------------------------------

    public function test_paid_signup_provisions_one_graph_and_attaches_onboarding_to_the_same_business(): void
    {
        [$user, , $business] = $this->paidSignup();

        $this->assertOneGraph();

        $onboarding = $this->onboardingFor($user);
        $this->assertNotNull($onboarding, 'Payment confirmation starts the customer\'s onboarding.');
        $this->assertSame(1, CustomerOnboarding::query()->count());
        $this->assertSame($business->id, $onboarding->business_id, 'It is attached to the signup\'s own Business.');
        $this->assertTrue((bool) $onboarding->is_required);
        $this->assertSame(OnboardingStep::Goals, $onboarding->current_step);
        $this->assertSame(BusinessStatus::Draft, $business->fresh()->status, 'Nothing activates before onboarding completes.');
    }

    // B ----------------------------------------------------------------

    public function test_opening_onboarding_after_signup_resumes_the_same_business(): void
    {
        [$user, , $business] = $this->paidSignup();
        $uid = $business->uid;

        // A required, unfinished onboarding is where Home sends the customer.
        $this->get(route('user.home'))->assertRedirect(route('customer.onboarding.show', ['step' => 'goals']));

        $this->get(route('customer.onboarding.show'))->assertOk();
        $this->get(route('customer.onboarding.show'))->assertOk();

        $this->assertSame(1, CustomerOnboarding::query()->count());
        $this->assertSame($business->id, $this->onboardingFor($user)->business_id);
        $this->assertSame(1, Business::query()->count());
        $this->assertSame($uid, Business::query()->sole()->uid);
    }

    // C ----------------------------------------------------------------

    public function test_the_business_step_updates_the_signup_business_and_never_creates_a_second(): void
    {
        [$user, , $business] = $this->paidSignup();
        $location = BusinessLocation::query()->sole();

        $this->post(route('customer.onboarding.goals.store'), ['primary_goals' => ['lead_generation']]);
        $this->post(route('customer.onboarding.business.store'), $this->businessAttributes([
            'name' => 'Harbor Lane Studios Renamed',
            'description' => 'Photo booths for events.',
        ]))->assertRedirect(route('customer.onboarding.show', ['step' => 'location']))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Business::query()->count());
        $fresh = Business::query()->sole();
        $this->assertSame($business->id, $fresh->id);
        $this->assertSame($business->uid, $fresh->uid, 'The Business uid is never replaced.');
        $this->assertSame('Harbor Lane Studios Renamed', (string) $fresh->name);
        $this->assertSame($business->id, $this->onboardingFor($user)->business_id);

        // The Location step updates the initial Location in place.
        $this->post(route('customer.onboarding.location.store'), [
            'service_mode' => 'storefront',
            'address_line_1' => '1 Main St',
            'city' => 'Austin',
            'region' => 'TX',
            'country_code' => 'US',
            'public_address' => '1',
        ])->assertRedirect(route('customer.onboarding.show', ['step' => 'services']));

        $this->assertSame(1, BusinessLocation::query()->count());
        $this->assertSame($location->id, BusinessLocation::query()->sole()->id);
        $this->assertSame('Austin', BusinessLocation::query()->sole()->city);
    }

    public function test_an_onboarding_row_started_before_the_hand_off_adopts_the_signup_business_instead_of_creating_one(): void
    {
        // An account whose onboarding row exists without a Business (opened
        // by hand, or provisioned before this hand-off existed).
        [$user, , $business] = $this->paidSignup();
        $onboarding = $this->onboardingFor($user);
        $onboarding->forceFill(['business_id' => null])->save();

        $this->post(route('customer.onboarding.goals.store'), ['primary_goals' => ['lead_generation']]);
        $this->post(route('customer.onboarding.business.store'), $this->businessAttributes(['name' => 'Adopted']))
            ->assertRedirect(route('customer.onboarding.show', ['step' => 'location']))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Business::query()->count());
        $this->assertSame($business->id, $this->onboardingFor($user)->business_id);
        $this->assertSame('Adopted', (string) Business::query()->sole()->name);
    }

    public function test_the_services_step_renders_real_inputs_so_a_service_can_be_added(): void
    {
        $this->paidSignup();
        $this->post(route('customer.onboarding.goals.store'), ['primary_goals' => ['lead_generation']]);
        $this->post(route('customer.onboarding.business.store'), $this->businessAttributes());
        $this->post(route('customer.onboarding.location.store'), [
            'service_mode' => 'storefront', 'address_line_1' => '1 Main St', 'city' => 'Austin',
            'region' => 'TX', 'country_code' => 'US', 'public_address' => '1',
        ]);

        // Nested double quotes inside a component attribute used to leave the
        // <x-input> tags unrendered, so there was no field to type a service in.
        $html = $this->get(route('customer.onboarding.show', ['step' => 'services']))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('<x-input', $html);
        $this->assertStringContainsString('name="services[0][name]"', $html);
        $this->assertMatchesRegularExpression('/<input[^>]+name="services\[0\]\[name\]"/', $html);
        $this->assertMatchesRegularExpression('/<input[^>]+name="services\[0\]\[starting_price\]"/', $html);
    }

    // D ----------------------------------------------------------------

    public function test_completing_onboarding_activates_the_same_business_and_reaches_home(): void
    {
        [$user, , $business] = $this->paidSignup();

        $this->walkWizardToResults($this->onboardingFor($user));

        $this->post(route('customer.onboarding.complete'))->assertRedirect(route('user.home'));

        $onboarding = $this->onboardingFor($user);
        $this->assertSame(OnboardingStatus::Completed, $onboarding->status);
        $fresh = Business::query()->sole();
        $this->assertSame($business->id, $fresh->id);
        $this->assertSame(BusinessStatus::Active, $fresh->status);
        $this->assertNotNull($fresh->activated_at);

        // Home is the normal Business Home now, not onboarding or "no business yet".
        $this->get(route('user.home'))->assertOk();

        // A replayed completion changes nothing.
        $activatedAt = $fresh->activated_at;
        $this->post(route('customer.onboarding.complete'))->assertRedirect(route('user.home'));
        $this->assertEquals($activatedAt, Business::query()->sole()->activated_at);
        $this->assertOneGraph();
        $this->assertSame(1, CustomerOnboarding::query()->count());
    }

    public function test_the_complete_step_finishes_onboarding_and_is_not_a_dead_end(): void
    {
        [$user, , $business] = $this->paidSignup();
        $this->walkWizardToResults($this->onboardingFor($user));

        // The first-value action sends the customer to the Complete step.
        $onboarding = $this->onboardingFor($user);
        app(CustomerOnboardingRepository::class)->recordFirstValueAction($onboarding, 'add_phone');
        $onboarding->refresh()->forceFill(['current_step' => OnboardingStep::Complete])->save();

        $html = $this->get(route('customer.onboarding.show', ['step' => 'complete']))->assertOk()->getContent();

        // The step itself must be able to finish onboarding (it used to offer
        // only a link home, leaving the Business Draft and Home redirecting
        // straight back into the wizard).
        $this->assertStringContainsString('action="' . route('customer.onboarding.complete') . '"', $html);

        $this->post(route('customer.onboarding.complete'))->assertRedirect(route('user.home'));

        $this->assertSame(OnboardingStatus::Completed, $this->onboardingFor($user)->status);
        $this->assertSame(BusinessStatus::Active, $business->fresh()->status);
        $this->get(route('user.home'))->assertOk();
    }

    public function test_an_incomplete_onboarding_never_activates_the_business(): void
    {
        [$user] = $this->paidSignup();

        $this->post(route('customer.onboarding.goals.store'), ['primary_goals' => ['lead_generation']]);
        $this->post(route('customer.onboarding.complete'))->assertSessionHasErrors('onboarding');

        $this->assertSame(BusinessStatus::Draft, Business::query()->sole()->status);
        $this->assertNotSame(OnboardingStatus::Completed, $this->onboardingFor($user)->status);
    }

    // E ----------------------------------------------------------------

    public function test_a_cancelled_payment_has_no_plan_no_onboarding_and_no_activation(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);
        $this->signUp($this->form());

        $this->get(route('signup.cancelled'))->assertRedirect(route('signup.plan'));

        $this->assertSame(0, WorkspacePlanAssignment::query()->count());
        $this->assertSame(0, CustomerOnboarding::query()->count(), 'An unpaid signup is never sent into the wizard.');
        $this->assertSame(BusinessStatus::Draft, Business::query()->sole()->status);
        $this->assertSame('pending', PlatformSubscription::query()->sole()->status->value);
    }

    public function test_a_forged_success_return_without_payment_starts_no_onboarding(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);
        $this->signUp($this->form());

        $this->get(route('signup.success') . '?success=true');

        $this->assertSame(0, CustomerOnboarding::query()->count());
        $this->assertSame(0, WorkspacePlanAssignment::query()->count());
    }

    // F ----------------------------------------------------------------

    public function test_retrying_payment_after_a_cancel_creates_no_duplicates_and_then_hands_off_once(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);
        $form = $this->form();
        $this->signUp($form);
        $this->get(route('signup.cancelled'));

        $this->post(route('signup.resume'), ['tier' => 'growth'])->assertRedirectContains('checkout.stripe.test');
        $this->post(route('signup.resume'), ['tier' => 'growth'])->assertRedirectContains('checkout.stripe.test');

        $this->assertOneGraph(false);
        $this->assertSame(0, CustomerOnboarding::query()->count());

        $subscription = PlatformSubscription::query()->sole();
        $this->stripe->completeCheckout((string) $subscription->provider_checkout_session_id);
        $this->get(route('signup.success'));

        $this->assertOneGraph();
        $this->assertSame(1, CustomerOnboarding::query()->count());
        $this->assertSame(Business::query()->sole()->id, CustomerOnboarding::query()->sole()->business_id);
    }

    // G ----------------------------------------------------------------

    public function test_a_webhook_replay_never_duplicates_the_graph_or_the_onboarding(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);
        $this->signUp($this->form());
        $subscription = PlatformSubscription::query()->sole();
        $providerSubscriptionId = $this->stripe->completeCheckout((string) $subscription->provider_checkout_session_id);

        $body = json_encode([
            'id' => 'evt_handoff_replay',
            'type' => 'checkout.session.completed',
            'created' => now()->getTimestamp(),
            'data' => ['object' => [
                'id' => $subscription->provider_checkout_session_id,
                'object' => 'checkout.session',
                'client_reference_id' => $subscription->uid,
                'subscription' => $providerSubscriptionId,
            ]],
        ]);

        // The webhook ALONE finishes the hand-off (the customer closed the tab)…
        $this->postPlatformWebhook($body, ['Stripe-Signature' => $this->stripe->validSignature])->assertOk();
        $this->assertSame(1, CustomerOnboarding::query()->count());

        // …and replays change nothing.
        $this->postPlatformWebhook($body, ['Stripe-Signature' => $this->stripe->validSignature])->assertOk();
        $this->postPlatformWebhook($body, ['Stripe-Signature' => $this->stripe->validSignature])->assertOk();

        $this->assertOneGraph();
        $this->assertSame(1, CustomerOnboarding::query()->count());
        $this->assertSame(Business::query()->sole()->id, CustomerOnboarding::query()->sole()->business_id);
    }

    // H ----------------------------------------------------------------

    public function test_refreshing_the_signup_complete_page_never_duplicates_anything(): void
    {
        $this->paidSignup();

        $this->get(route('signup.success'));
        $this->get(route('signup.success'));
        $this->get(route('signup.success'));

        $this->assertOneGraph();
        $this->assertSame(1, CustomerOnboarding::query()->count());
    }

    public function test_the_hand_off_is_idempotent_and_converges_on_a_row_that_already_exists(): void
    {
        [$user, , $business] = $this->paidSignup();
        $customer = Customer::query()->where('user_id', $user->id)->sole();
        $first = $this->onboardingFor($user);

        $again = app(OnboardingManager::class)->startForProvisionedBusiness($customer, $business);

        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, CustomerOnboarding::query()->count());
        $this->assertSame($business->id, $again->business_id);
    }

    // I ----------------------------------------------------------------

    public function test_logging_out_and_back_in_before_finishing_resumes_the_same_onboarding(): void
    {
        // config/no-captcha.php defaults login captcha ON when no app_config row says otherwise.
        config(["no-captcha.login" => false]);
        [$user, , $business, $form] = $this->paidSignup();

        $this->post(route('customer.onboarding.goals.store'), ['primary_goals' => ['lead_generation']]);
        $before = $this->onboardingFor($user);
        $this->assertSame(OnboardingStep::Business, $before->current_step);

        $this->get(route('logout'));
        $this->assertGuest();

        $this->post(route('login'), ['email' => $form['email'], 'password' => self::PASSWORD]);
        $this->assertAuthenticatedAs($user);

        $this->get(route('user.home'))->assertRedirect(route('customer.onboarding.show', ['step' => 'business']));

        $after = $this->onboardingFor($user);
        $this->assertSame($before->id, $after->id);
        $this->assertSame($business->id, $after->business_id);
        $this->assertSame(1, CustomerOnboarding::query()->count());
        $this->assertSame(1, Business::query()->count());
    }

    // J ----------------------------------------------------------------

    public function test_legacy_onboarding_with_no_business_still_creates_one_through_the_canonical_path(): void
    {
        $customer = $this->createCustomer();
        $this->ensureRequiredAppConfigRowsExist();
        $customer->permissions = Customer::customerPermissions();
        $customer->save();
        $customer->user->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($customer->user);

        $this->assertSame(0, Business::query()->count());

        app(OnboardingManager::class)->start($customer);
        $this->post(route('customer.onboarding.goals.store'), ['primary_goals' => ['lead_generation']]);
        $this->post(route('customer.onboarding.business.store'), $this->businessAttributes())
            ->assertRedirect(route('customer.onboarding.show', ['step' => 'location']));

        $this->assertSame(1, Business::query()->where('customer_id', $customer->user_id)->count());
        $this->assertSame(
            Business::query()->where('customer_id', $customer->user_id)->sole()->id,
            CustomerOnboarding::query()->where('customer_id', $customer->user_id)->sole()->business_id,
        );
    }

    // Guards -------------------------------------------------------------

    /**
     * An Agency owner's OWN Business is operationally a Business like any
     * other, so it takes the same required first-run onboarding. It is the
     * Workspace's one Business: no fake client, no second Workspace/Business.
     */
    public function test_an_agency_signup_hands_its_own_business_to_onboarding_and_activates_it_with_no_fake_client(): void
    {
        $this->sellableTier(WorkspacePlanTier::Agency, price: '497.00');
        $form = $this->form(['tier' => 'agency', 'business_name' => 'North Shore Agency']);

        $this->post(route('register.plan.select'), ['tier' => 'agency']);
        $this->post(route('register.account.store'), \Illuminate\Support\Arr::only($form, ['first_name', 'last_name', 'email', 'password', 'password_confirmation']));
        $this->post(route('register.business.store'), ['business_name' => 'North Shore Agency', 'country_code' => 'US', 'timezone' => 'UTC']);
        $this->post(route('register.payment.start'));

        $subscription = PlatformSubscription::query()->sole();
        $this->stripe->completeCheckout((string) $subscription->provider_checkout_session_id);
        $this->get(route('signup.success'));
        $user = User::query()->where('email', $form['email'])->sole();
        $user->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($user->fresh());

        $business = Business::query()->sole();
        $onboarding = $this->onboardingFor($user);
        $this->assertNotNull($onboarding, 'The Agency owner configures their OWN Business through first-run onboarding.');
        $this->assertSame($business->id, $onboarding->business_id);
        $this->assertTrue((bool) $onboarding->is_required);
        $this->assertSame(BusinessStatus::Draft, $business->status);

        $this->walkWizardToResults($onboarding);
        $this->post(route('customer.onboarding.complete'))->assertRedirect(route('user.home'));

        $this->assertSame(BusinessStatus::Active, $business->fresh()->status);
        $this->get(route('user.home'))->assertOk();

        // Exactly the own Workspace/Business/Location; never a client.
        $this->assertSame(1, Workspace::query()->count());
        $this->assertSame(1, Business::query()->count());
        $this->assertSame(1, BusinessLocation::query()->count());
        $this->assertSame(1, WorkspacePlanAssignment::query()->count());
        $this->assertSame(0, \App\Models\AgencyClientWorkspaceRelationship::query()->count());
    }

    /** @return array<string, array{0: WorkspacePlanTier}> */
    public static function nonAgencyTiers(): array
    {
        return ['core' => [WorkspacePlanTier::Core], 'growth' => [WorkspacePlanTier::Growth]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonAgencyTiers')]
    public function test_core_and_growth_signups_hand_off_exactly_as_before(WorkspacePlanTier $tier): void
    {
        $this->sellableTier($tier);
        $form = $this->form(['tier' => $tier->value]);
        $this->signUp($form);
        $subscription = PlatformSubscription::query()->sole();
        $this->stripe->completeCheckout((string) $subscription->provider_checkout_session_id);
        $this->get(route('signup.success'));

        $user = User::query()->where('email', $form['email'])->sole();
        $this->assertOneGraph();
        $this->assertSame(1, CustomerOnboarding::query()->count());
        $this->assertSame(Business::query()->sole()->id, $this->onboardingFor($user)->business_id);
        $this->assertSame(BusinessStatus::Draft, Business::query()->sole()->status);
        $this->assertSame(0, \App\Models\AgencyClientWorkspaceRelationship::query()->count());
    }

    public function test_the_hand_off_refuses_another_customers_business(): void
    {
        [, , $business] = $this->paidSignup();
        $stranger = $this->createCustomer();

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);

        app(OnboardingManager::class)->startForProvisionedBusiness($stranger, $business);
    }

    public function test_onboarding_completion_never_activates_an_agency_managed_client_business(): void
    {
        [$user, $workspace, $business] = $this->paidSignup();
        $customer = Customer::query()->where('user_id', $user->id)->sole();

        $agencyOwner = User::create([
            'first_name' => 'Agency', 'last_name' => 'Owner', 'email' => 'agency' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);
        $agencyWorkspace = Workspace::create(['name' => 'Agency', 'owner_user_id' => $agencyOwner->id, 'is_active' => true]);
        DB::table('agency_client_workspace_relationships')->insert([
            'uid' => (string) \Illuminate\Support\Str::uuid(),
            'agency_workspace_id' => $agencyWorkspace->id,
            'client_workspace_id' => $workspace->id,
            'status' => 'active',
            'established_by_user_id' => $agencyOwner->id,
            'established_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            app(BusinessManager::class)->activateForCompletedOnboarding($customer, $business);
            $this->fail('An Agency-managed Client Business has its own owner-confirmation path.');
        } catch (WorkspaceAccessDeniedException) {
            $this->assertSame(BusinessStatus::Draft, $business->fresh()->status);
        }
    }
}

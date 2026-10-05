<?php

namespace Tests\Feature\PlatformBilling;

use App\Enums\Business\BusinessStatus;
use App\Enums\Business\OnboardingStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Entitlement\EntitlementManager;
use App\Library\ViewAs\ViewAsManager;
use App\Models\AgencyClientWorkspaceRelationship;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\CustomerOnboarding;
use App\Models\PlatformSubscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspacePlanAssignment;
use App\Repositories\Contracts\CustomerOnboardingRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\PlatformBilling\Concerns\CreatesPlatformSubscriptions;
use Tests\TestCase;

/**
 * Final signup + onboarding acceptance across all three tiers.
 *
 * Complements V1SignupHttpTest / V1SignupOnboardingHandoffTest (which prove
 * the individual seams) with the whole journey per tier, the shell each tier
 * lands in, and the auth edges around it. Test payment authority only.
 */
class V1SignupFinalAcceptanceTest extends TestCase
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
        // config/no-captcha.php defaults login captcha ON when no app_config row says otherwise.
        config(['account.can_register' => true, 'no-captcha.login' => false]);
    }

    /** @return array<string, array{0: WorkspacePlanTier}> */
    public static function tiers(): array
    {
        return [
            'core' => [WorkspacePlanTier::Core],
            'growth' => [WorkspacePlanTier::Growth],
            'agency' => [WorkspacePlanTier::Agency],
        ];
    }

    /** @return array<string, string> */
    private function form(WorkspacePlanTier $tier): array
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
            'tier' => $tier->value,
        ];
    }

    /** Signs up (stopping at Stripe Checkout) on the given tier. */
    private function startSignup(WorkspacePlanTier $tier): array
    {
        $this->sellableTier($tier, price: $tier === WorkspacePlanTier::Agency ? '497.00' : '97.00');
        $form = $this->form($tier);

        if ($tier === WorkspacePlanTier::Agency) {
            $this->post(route('register.plan.select'), ['tier' => 'agency']);
            $this->post(route('register.account.store'), Arr::only($form, ['first_name', 'last_name', 'email', 'password', 'password_confirmation']));
            $this->post(route('register.business.store'), ['business_name' => $form['business_name'], 'country_code' => 'US', 'timezone' => 'America/New_York']);
            $this->post(route('register.payment.start'));
        } else {
            $this->signUp($form);
        }

        return $form;
    }

    private function pay(): string
    {
        $subscription = PlatformSubscription::query()->sole();

        return $this->stripe->completeCheckout((string) $subscription->provider_checkout_session_id);
    }

    private function assertOneGraph(bool $paid = true): void
    {
        $this->assertSame(1, User::query()->where('is_customer', true)->count());
        $this->assertSame(1, Workspace::query()->count());
        $this->assertSame(1, Business::query()->count());
        $this->assertSame(1, BusinessLocation::query()->count());
        $this->assertSame(1, PlatformSubscription::query()->count());
        $this->assertSame($paid ? 1 : 0, WorkspacePlanAssignment::query()->count());
        $this->assertSame(0, AgencyClientWorkspaceRelationship::query()->count(), 'Signup never fabricates a client relationship.');
    }

    private function walkWizard(CustomerOnboarding $onboarding): void
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

        app(CustomerOnboardingRepository::class)->completeAnalysis($onboarding->refresh(), 0, [
            'version' => 1, 'generated_at' => now()->toIso8601String(), 'profile_completeness_percent' => 100,
            'facts' => [], 'findings' => [],
        ]);
    }

    /** @return array<int, string> */
    private function menuKeys(string $html): array
    {
        $start = strpos($html, 'id="main-menu-navigation"');
        $end = strpos($html, '<!-- END: Main Menu-->');
        $this->assertNotFalse($start, 'The sidebar navigation must be present.');
        preg_match_all('/data-nav-key="([^"]+)"/', substr($html, $start, $end - $start), $m);

        return $m[1];
    }

    private function logoutAndLogin(array $form): void
    {
        $this->get(route('logout'));
        $this->assertGuest();
        $this->flushSession();
        $this->post(route('login'), ['email' => $form['email'], 'password' => self::PASSWORD]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('tiers')]
    public function test_each_tier_goes_from_signup_through_onboarding_to_a_usable_business_home(WorkspacePlanTier $tier): void
    {
        $form = $this->startSignup($tier);
        $this->assertOneGraph(false);

        $this->pay();
        $this->get(route('signup.success'));
        $this->assertOneGraph();

        $user = User::query()->where('email', $form['email'])->sole();
        $user->forceFill(['email_verified_at' => now()])->save();
        $workspace = Workspace::query()->sole();
        $business = Business::query()->sole();
        $this->actingAs($user->fresh());

        // Correct plan assignment, not complimentary.
        $assignment = WorkspacePlanAssignment::query()->sole();
        $this->assertFalse((bool) $assignment->is_complimentary);
        $this->assertSame($tier, app(EntitlementManager::class)->getWorkspaceEntitlementSummary($workspace)->tier);

        // Onboarding is on the same Business; Home sends the customer there first.
        $onboarding = CustomerOnboarding::query()->sole();
        $this->assertSame($business->id, $onboarding->business_id);
        $this->assertSame(BusinessStatus::Draft, $business->status);
        $this->get(route('user.home'))->assertRedirect(route('customer.onboarding.show', ['step' => 'goals']));

        $this->walkWizard($onboarding);
        $this->post(route('customer.onboarding.complete'))->assertRedirect(route('user.home'));

        $this->assertSame(OnboardingStatus::Completed, $onboarding->fresh()->status);
        $this->assertSame(BusinessStatus::Active, $business->fresh()->status);
        $this->assertSame($business->id, Business::query()->sole()->id);

        // Business Home is usable, and the shell matches the tier.
        $home = $this->get(route('user.home'))->assertOk()->getContent();
        $keys = $this->menuKeys($home);
        $this->assertContains('home', $keys);
        $agencyKeys = ['agency-home', 'accounts', 'prospecting', 'agency-saas-plans', 'agency-white-label'];

        if ($tier === WorkspacePlanTier::Agency) {
            foreach ($agencyKeys as $key) {
                $this->assertContains($key, $keys, "Agency owner must reach [{$key}].");
            }
            // Agency management opens, and it is the owner's own Workspace.
            $this->get(route('customer.workspaces.clients.index', $workspace->uid))->assertOk();
        } else {
            foreach ($agencyKeys as $key) {
                $this->assertNotContains($key, $keys, "[{$tier->value}] must have no Agency menu.");
            }
            $this->assertNotSame(200, $this->get(route('customer.workspaces.clients.index', $workspace->uid))->getStatusCode());
        }

        $this->assertNull(session(ViewAsManager::SESSION_KEY), 'View As is never active by default.');

        // Refresh / retry / replay changes nothing.
        $this->get(route('signup.success'));
        $this->get(route('signup.success'));
        $this->get(route('customer.onboarding.show'));
        $this->assertOneGraph();
        $this->assertSame(1, CustomerOnboarding::query()->count());

        // Logout/login returns to the same Business Home.
        $this->logoutAndLogin($form);
        $this->assertAuthenticatedAs($user);
        $this->get(route('user.home'))->assertOk();
        $this->assertSame($business->id, Business::query()->sole()->id);
        $this->assertOneGraph();

        // An authenticated customer cannot use /register.
        $this->get(route('register'))->assertRedirect(route('user.home'));
        $this->assertOneGraph();
    }

    public function test_a_failed_or_unpaid_checkout_grants_no_entitlement_and_the_session_expiring_duplicates_nothing(): void
    {
        $form = $this->startSignup(WorkspacePlanTier::Growth);

        // Session expires mid-checkout; nothing was paid.
        $this->flushSession();
        $this->get(route('signup.success'));
        $this->assertOneGraph(false);
        $this->assertSame(0, CustomerOnboarding::query()->count());

        // The customer logs back in unpaid: still no entitlement, no onboarding, no second tenant.
        User::query()->where('email', $form['email'])->update(['email_verified_at' => now()]);
        $this->post(route('login'), ['email' => $form['email'], 'password' => self::PASSWORD]);
        $this->get(route('user.home'));
        $this->assertOneGraph(false);
        $this->assertSame(0, CustomerOnboarding::query()->count());

        // Payment later confirmed ONLY by the webhook (session long gone): one hand-off.
        $subscription = PlatformSubscription::query()->sole();
        $providerSubscriptionId = $this->pay();
        $body = json_encode([
            'id' => 'evt_final_acceptance',
            'type' => 'checkout.session.completed',
            'created' => now()->getTimestamp(),
            'data' => ['object' => [
                'id' => $subscription->provider_checkout_session_id,
                'object' => 'checkout.session',
                'client_reference_id' => $subscription->uid,
                'subscription' => $providerSubscriptionId,
            ]],
        ]);
        $this->postPlatformWebhook($body, ['Stripe-Signature' => $this->stripe->validSignature])->assertOk();
        $this->postPlatformWebhook($body, ['Stripe-Signature' => $this->stripe->validSignature])->assertOk();

        $this->assertOneGraph();
        $this->assertSame(1, CustomerOnboarding::query()->count());

        // Login resumes onboarding on that Business.
        $this->logoutAndLogin($form);
        $this->get(route('user.home'))->assertRedirect(route('customer.onboarding.show', ['step' => 'goals']));
        $this->assertSame(Business::query()->sole()->id, CustomerOnboarding::query()->sole()->business_id);
    }

    public function test_password_reset_works_and_the_new_password_logs_in_to_the_same_account(): void
    {
        $form = $this->startSignup(WorkspacePlanTier::Core);
        $this->pay();
        $this->get(route('signup.success'));
        $user = User::query()->where('email', $form['email'])->sole();
        $user->forceFill(['email_verified_at' => now()])->save();
        $this->flushSession();

        $token = Password::broker()->createToken($user);
        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $form['email'],
            'password' => 'a-brand-new-passphrase-1',
            'password_confirmation' => 'a-brand-new-passphrase-1',
        ]);
        $this->assertTrue(Hash::check('a-brand-new-passphrase-1', $user->fresh()->password));

        $this->flushSession();
        $this->app["auth"]->forgetGuards(); // the reset signs the user in; drop the cached guard user too
        $this->post(route('login'), ['email' => $form['email'], 'password' => self::PASSWORD]);
        $this->assertGuest();

        $this->post(route('login'), ['email' => $form['email'], 'password' => 'a-brand-new-passphrase-1']);
        $this->assertAuthenticatedAs($user);
        $this->assertOneGraph();
    }

    public function test_the_reset_link_request_responds_without_creating_anything(): void
    {
        $form = $this->startSignup(WorkspacePlanTier::Core);
        $this->flushSession();

        $this->post(route('password.email'), ['email' => $form['email']])->assertSessionHasNoErrors();

        $this->assertOneGraph(false);
    }

    public function test_a_suspended_user_cannot_sign_in(): void
    {
        $form = $this->startSignup(WorkspacePlanTier::Growth);
        $this->pay();
        $this->get(route('signup.success'));
        $user = User::query()->where('email', $form['email'])->sole();
        $user->forceFill(['email_verified_at' => now(), 'status' => false])->save();
        $this->flushSession();

        $this->post(route('login'), ['email' => $form['email'], 'password' => self::PASSWORD]);

        $this->assertGuest();
        $this->assertOneGraph();
    }
}

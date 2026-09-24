<?php

namespace Tests\Feature\PlatformBilling;

use App\Enums\Entitlement\CustomerAccountAccessState;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\PlatformBilling\PlatformSubscriptionStatus;
use App\Library\Entitlement\CustomerAccountAccessResolver;
use App\Library\Entitlement\EntitlementManager;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\PlatformSubscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspacePlanAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\PlatformBilling\Concerns\CreatesPlatformSubscriptions;
use Tests\TestCase;

/**
 * Implementation Contract 21 §7/§15 — the canonical V1 signup THROUGH THE
 * BROWSER, not only through the domain manager.
 */
class V1SignupHttpTest extends TestCase
{
    use RefreshDatabase;
    use CreatesPlatformSubscriptions;

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
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
            'business_name' => 'Harbor Lane Studios',
            'industry' => 'photo_booth_service',
            'country_code' => 'US',
            'timezone' => 'UTC',
            'tier' => 'growth',
        ], $overrides);
    }

    // =================================================================
    // The signup page
    // =================================================================

    public function test_the_signup_page_shows_only_sellable_v1_plans(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth, trialDays: 14, price: '297.00');
        // Priced but not offered for signup.
        $core = $this->sellableTier(WorkspacePlanTier::Core, price: '97.00');
        $core->forceFill(['available_for_signup' => false])->save();
        // Offered but with no Stripe Price, so not actually sellable.
        $agency = $this->sellableTier(WorkspacePlanTier::Agency, price: '497.00');
        $agency->forceFill(['provider_price_id' => null])->save();

        $response = $this->get(route('register'));

        $response->assertOk()
            ->assertSee('297.00')
            ->assertSee('14 day free trial')
            ->assertSee('value="growth"', false)
            ->assertDontSee('value="core"', false)
            ->assertDontSee('value="agency"', false);
    }

    public function test_the_signup_page_never_offers_a_legacy_payment_gateway(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);

        $response = $this->get(route('register'));

        foreach (['braintree', 'Braintree', 'nowpayments', 'NowPayments', 'authorize_net', 'Authorize', 'vodacom', 'fedapay', 'easypay'] as $legacy) {
            $response->assertDontSee($legacy, false);
        }
    }

    public function test_the_register_route_no_longer_resolves_to_the_legacy_controller(): void
    {
        $action = app('router')->getRoutes()->getByName('register')->getActionName();

        $this->assertStringContainsString('V1SignupController', $action);
        $this->assertStringNotContainsString('RegisterController', $action);
    }

    // =================================================================
    // POST /register
    // =================================================================

    #[DataProvider('tiers')]
    public function test_registration_provisions_the_account_and_sends_the_customer_to_checkout(WorkspacePlanTier $tier): void
    {
        $this->sellableTier($tier);

        $response = $this->post(route('register'), $this->form(['tier' => $tier->value]));

        $subscription = PlatformSubscription::query()->sole();
        $response->assertRedirect('https://checkout.stripe.test/' . $subscription->provider_checkout_session_id);

        // Account identity, durable before Stripe so a webhook can always
        // recover the signup.
        $user = User::query()->where('is_customer', true)->sole();
        $workspace = Workspace::query()->sole();
        $business = Business::query()->where('workspace_id', $workspace->id)->sole();
        $this->assertSame('Harbor Lane Studios', (string) $business->name);
        $this->assertSame('photo_booth_service', $business->industry->value, 'The niche survives provisioning.');
        $this->assertCount(1, BusinessLocation::query()->where('business_id', $business->id)->get());

        // The selected tier survives onto the durable local subscription.
        $this->assertSame(PlatformSubscriptionStatus::Pending, $subscription->status);
        $this->assertSame($tier->value, DB::table('workspace_plan_catalog')
            ->where('id', $subscription->workspace_plan_catalog_id)->value('tier'));

        // ...and nothing is paid yet.
        $this->assertSame(0, WorkspacePlanAssignment::query()->count());
        $this->assertNotNull($user->password);
        $this->assertNotSame('correct-horse-battery', $user->password, 'The password is hashed, never stored raw.');
        $this->assertTrue(Hash::check('correct-horse-battery', $user->password));
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

    public function test_registration_creates_no_legacy_subscription_authority(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);

        $this->post(route('register'), $this->form());

        foreach (['subscriptions', 'subscription_transactions', 'invoices'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "No legacy [{$table}] row.");
        }
    }

    public function test_registration_never_fabricates_a_complimentary_core_assignment(): void
    {
        $this->sellableTier(WorkspacePlanTier::Agency);

        $this->post(route('register'), $this->form(['tier' => 'agency']));

        $this->assertSame(0, WorkspacePlanAssignment::query()->count(),
            '§3.4 — nothing is assigned before the provider confirms, least of all a free Core.');
    }

    public function test_a_duplicate_email_is_refused(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);
        $form = $this->form();
        $this->post(route('register'), $form);

        // Registration signs the new customer in, and /register is guest-only,
        // so a second anonymous attempt starts from a signed-out session.
        $this->signOut();

        $this->post(route('register'), $form)->assertSessionHasErrors('email');

        $this->assertSame(1, User::query()->where('email', $form['email'])->count());
    }

    /** Back to anonymous, the way a second visitor actually arrives. */
    private function signOut(): void
    {
        Auth::logout();
        $this->flushSession();
    }

    public function test_a_tier_that_is_not_sellable_cannot_be_submitted(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);

        $this->post(route('register'), $this->form(['tier' => 'agency']))
            ->assertSessionHasErrors('tier');

        $this->assertSame(0, PlatformSubscription::query()->count());
        $this->assertSame(0, User::query()->where('is_customer', true)->count());
    }

    public function test_a_mismatched_password_confirmation_is_refused(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);

        $this->post(route('register'), $this->form(['password_confirmation' => 'something-else']))
            ->assertSessionHasErrors('password');

        $this->assertSame(0, Workspace::query()->count());
    }

    // =================================================================
    // Checkout return
    // =================================================================

    public function test_the_success_endpoint_activates_from_provider_truth_and_lands_on_home(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth, trialDays: 10);
        $this->post(route('register'), $this->form());

        $subscription = PlatformSubscription::query()->sole();
        $this->stripe->completeCheckout((string) $subscription->provider_checkout_session_id);

        $this->get(route('signup.success'))->assertRedirect(route('user.home'));

        $workspace = Workspace::query()->sole();
        $assignment = WorkspacePlanAssignment::query()->sole();
        $this->assertFalse((bool) $assignment->is_complimentary);
        $this->assertSame(WorkspacePlanTier::Growth, app(EntitlementManager::class)
            ->getWorkspaceEntitlementSummary($workspace)->tier);
        $this->assertSame(PlatformSubscriptionStatus::Trialing, $subscription->refresh()->status);
        $this->assertSame(CustomerAccountAccessState::Usable,
            app(CustomerAccountAccessResolver::class)->resolve($workspace)->state);
    }

    public function test_the_success_endpoint_does_not_trust_a_success_flag(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);
        $this->post(route('register'), $this->form());

        // The customer forges a return WITHOUT ever completing checkout.
        $this->get(route('signup.success') . '?success=true');

        $this->assertSame(0, WorkspacePlanAssignment::query()->count(),
            'Provider truth, not a query flag, is what activates an account.');
    }

    public function test_repeated_success_visits_activate_exactly_once(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);
        $this->post(route('register'), $this->form());
        $subscription = PlatformSubscription::query()->sole();
        $this->stripe->completeCheckout((string) $subscription->provider_checkout_session_id);

        $this->get(route('signup.success'));
        $this->get(route('signup.success'));
        $this->get(route('signup.success'));

        $this->assertSame(1, WorkspacePlanAssignment::query()->count());
    }

    public function test_cancelling_checkout_keeps_a_recoverable_account(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);
        $this->post(route('register'), $this->form());

        $this->get(route('signup.cancelled'))->assertRedirect(route('signup.plan'));

        $this->assertSame(1, Workspace::query()->count(), 'The account is not deleted…');
        $this->assertSame(0, WorkspacePlanAssignment::query()->count(), '…and is truthfully unassigned.');

        $this->get(route('signup.plan'))->assertOk()->assertSee('Choose a plan');
    }

    public function test_resuming_after_cancelling_creates_no_second_workspace(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);
        $this->post(route('register'), $this->form());
        $this->get(route('signup.cancelled'));

        $this->post(route('signup.resume'), ['tier' => 'growth'])->assertRedirectContains('checkout.stripe.test');

        $this->assertSame(1, Workspace::query()->count());
        $this->assertSame(1, Business::query()->count());
        $this->assertSame(1, PlatformSubscription::query()->count());
        $this->assertSame(1, BusinessLocation::query()->count());
    }

    // =================================================================
    // Webhook convergence, through HTTP
    // =================================================================

    public function test_the_webhook_alone_finishes_a_browser_signup(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth, trialDays: 12);
        $this->post(route('register'), $this->form());

        $subscription = PlatformSubscription::query()->sole();
        $providerSubscriptionId = $this->stripe->completeCheckout((string) $subscription->provider_checkout_session_id);

        // The browser NEVER returns.
        $body = json_encode([
            'id' => 'evt_browserless',
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

        $workspace = Workspace::query()->sole();
        $this->assertSame(1, WorkspacePlanAssignment::query()->count());
        $this->assertSame(WorkspacePlanTier::Growth, app(EntitlementManager::class)
            ->getWorkspaceEntitlementSummary($workspace)->tier);
        $this->assertSame(CustomerAccountAccessState::Usable,
            app(CustomerAccountAccessResolver::class)->resolve($workspace)->state);
    }

    // =================================================================
    // §8 — owner-configured trials actually drive signup
    // =================================================================

    public function test_the_configured_trial_per_tier_is_what_a_new_signup_receives(): void
    {
        $this->sellableTier(WorkspacePlanTier::Core, trialDays: 7, price: '97.00');
        $this->sellableTier(WorkspacePlanTier::Growth, trialDays: 21, price: '297.00');
        $this->sellableTier(WorkspacePlanTier::Agency, trialDays: null, price: '497.00');

        foreach ([['core', 7], ['growth', 21], ['agency', null]] as [$tier, $expected]) {
            // Each is a separate anonymous visitor.
            $this->signOut();
            $this->post(route('register'), $this->form(['tier' => $tier, 'email' => $tier . uniqid() . '@example.test']));

            $subscription = PlatformSubscription::query()->orderByDesc('id')->firstOrFail();
            $this->assertSame($expected, $subscription->trial_days_snapshot === null ? null : (int) $subscription->trial_days_snapshot,
                "[{$tier}] must receive exactly the configured trial.");

            $call = end($this->stripe->calls);
            $this->assertSame($expected, $call['args']['trial_days'] ?? null);
        }
    }

    public function test_changing_a_trial_afterwards_does_not_rewrite_an_existing_subscription(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth, trialDays: 5);
        $this->post(route('register'), $this->form());
        $subscription = PlatformSubscription::query()->sole();
        $this->stripe->completeCheckout((string) $subscription->provider_checkout_session_id);
        $this->get(route('signup.success'));

        $before = $subscription->refresh()->trial_ends_at;
        $this->sellableTier(WorkspacePlanTier::Growth, trialDays: 60);

        $this->assertSame(5, (int) $subscription->refresh()->trial_days_snapshot);
        $this->assertEquals($before, $subscription->refresh()->trial_ends_at);
    }

    // =================================================================
    // §7 correction — canonical country/timezone selection, not free text
    // =================================================================

    public function test_the_signup_page_renders_country_and_timezone_as_selects(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);

        $response = $this->get(route('register'));

        $response->assertOk()
            ->assertSee('<select id="country_code" name="country_code"', false)
            ->assertSee('<select id="timezone" name="timezone"', false)
            ->assertDontSee('<input id="country_code"', false)
            ->assertDontSee('<input id="timezone"', false)
            // The canonical option list from BusinessLocaleOptions, not a
            // second, page-local one.
            ->assertSee('value="NZ"', false)
            ->assertSee('value="Pacific/Auckland"', false);
    }

    public function test_a_valid_canonical_country_and_timezone_are_accepted(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);

        $response = $this->post(route('register'), $this->form([
            'country_code' => 'NZ',
            'timezone' => 'Pacific/Auckland',
        ]));

        $response->assertSessionDoesntHaveErrors(['country_code', 'timezone']);
        $this->assertSame(1, Workspace::query()->count());
    }

    public function test_an_arbitrary_two_letter_country_code_is_rejected(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);

        // §7 — the exact forged value the acceptance run persisted before
        // this correction: two characters, `size:2`-valid, not a country.
        $this->post(route('register'), $this->form(['country_code' => 'Ne']))
            ->assertSessionHasErrors('country_code');

        $this->assertSame(0, Workspace::query()->count());
        $this->assertSame(0, User::query()->where('is_customer', true)->count());
    }

    public function test_an_invalid_timezone_is_rejected(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);

        $this->post(route('register'), $this->form(['timezone' => 'Not/A_Real_Zone']))
            ->assertSessionHasErrors('timezone');

        $this->assertSame(0, Workspace::query()->count());
    }

    public function test_old_country_and_timezone_values_remain_selected_after_a_validation_failure(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);

        // Mismatched password confirmation fails validation; country/timezone
        // were otherwise valid and must come back selected, not reset.
        $response = $this->from(route('register'))->post(route('register'), $this->form([
            'country_code' => 'NZ',
            'timezone' => 'Pacific/Auckland',
            'password_confirmation' => 'something-else',
        ]));

        $response->assertSessionHasErrors('password');

        $redirect = $this->get(route('register'));
        $redirect->assertSee('value="NZ" selected', false)
            ->assertSee('value="Pacific/Auckland" selected', false);
    }
}

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
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
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
            'currency_code' => 'USD',
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

        $response = $this->signUp($this->form(['tier' => $tier->value]));

        $subscription = PlatformSubscription::query()->sole();
        $response->assertRedirect('https://checkout.stripe.test/' . $subscription->provider_checkout_session_id);

        // Account identity, durable before Stripe so a webhook can always
        // recover the signup.
        $user = User::query()->where('is_customer', true)->sole();
        $workspace = Workspace::query()->sole();
        $business = Business::query()->where('workspace_id', $workspace->id)->sole();
        $this->assertSame('Harbor Lane Studios', (string) $business->name);
        // The niche survives provisioning — except on the Agency branch,
        // which never asks for one: its own Workspace Business is "other".
        $this->assertSame(
            $tier === WorkspacePlanTier::Agency ? 'other' : 'photo_booth_service',
            $business->industry->value,
        );
        $this->assertSame('USD', $business->currency_code, 'The selected currency survives provisioning.');
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

    /**
     * Manual acceptance defect 3 (P1) — V1SignupController::store() created
     * the User directly and never fired Registered, even though
     * EventServiceProvider already maps it to SendEmailVerificationNotification.
     * The resend endpoint (which calls sendEmailVerificationNotification()
     * independently) worked fine, which is what made the missing initial
     * email easy to miss.
     */
    public function test_registration_sends_the_email_verification_notification(): void
    {
        Notification::fake();
        $this->sellableTier(WorkspacePlanTier::Growth);

        $email = 'verify' . uniqid() . '@example.test';
        $this->signUp($this->form(['email' => $email]));

        $user = User::query()->where('email', $email)->sole();

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_registration_creates_no_legacy_subscription_authority(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);

        $this->signUp($this->form());

        foreach (['subscriptions', 'subscription_transactions', 'invoices'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "No legacy [{$table}] row.");
        }
    }

    public function test_registration_never_fabricates_a_complimentary_core_assignment(): void
    {
        $this->sellableTier(WorkspacePlanTier::Agency);

        $this->signUp($this->form(['tier' => 'agency']));

        $this->assertSame(0, WorkspacePlanAssignment::query()->count(),
            '§3.4 — nothing is assigned before the provider confirms, least of all a free Core.');
    }

    public function test_a_duplicate_email_is_refused(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);
        $form = $this->form();
        $this->signUp($form);

        // Registration signs the new customer in, and /register is guest-only,
        // so a second anonymous attempt starts from a signed-out session.
        $this->signOut();

        $this->signUp($form)->assertSessionHasErrors('email');

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

        $this->signUp($this->form(['tier' => 'agency']))
            ->assertSessionHasErrors('plan');

        $this->assertSame(0, PlatformSubscription::query()->count());
        $this->assertSame(0, User::query()->where('is_customer', true)->count());
    }

    public function test_a_mismatched_password_confirmation_is_refused(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);

        $this->signUp($this->form(['password_confirmation' => 'something-else']))
            ->assertSessionHasErrors('password');

        $this->assertSame(0, Workspace::query()->count());
    }

    // =================================================================
    // Checkout return
    // =================================================================

    public function test_the_success_endpoint_activates_from_provider_truth_and_hands_off_to_verification(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth, trialDays: 10);
        $this->signUp($this->form());

        $subscription = PlatformSubscription::query()->sole();
        $this->stripe->completeCheckout((string) $subscription->provider_checkout_session_id);

        // The email is not verified yet, so the hand-off after provisioning
        // is the verification screen (the purchase was never blocked on it).
        $this->get(route('signup.success'))->assertRedirect(route('verification.notice'));

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
        $this->signUp($this->form());

        // The customer forges a return WITHOUT ever completing checkout.
        $this->get(route('signup.success') . '?success=true');

        $this->assertSame(0, WorkspacePlanAssignment::query()->count(),
            'Provider truth, not a query flag, is what activates an account.');
    }

    public function test_repeated_success_visits_activate_exactly_once(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);
        $this->signUp($this->form());
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
        $this->signUp($this->form());

        $this->get(route('signup.cancelled'))->assertRedirect(route('signup.plan'));

        $this->assertSame(1, Workspace::query()->count(), 'The account is not deleted…');
        $this->assertSame(0, WorkspacePlanAssignment::query()->count(), '…and is truthfully unassigned.');

        $this->get(route('signup.plan'))->assertOk()->assertSee('Choose a plan');
    }

    public function test_resuming_after_cancelling_creates_no_second_workspace(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);
        $this->signUp($this->form());
        $this->get(route('signup.cancelled'));

        $this->post(route('signup.resume'), ['tier' => 'growth'])->assertRedirectContains('checkout.stripe.test');

        $this->assertSame(1, Workspace::query()->count());
        $this->assertSame(1, Business::query()->count());
        $this->assertSame(1, PlatformSubscription::query()->count());
        $this->assertSame(1, BusinessLocation::query()->count());
    }

    public function test_resuming_never_rewrites_the_existing_businesss_currency(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);
        $this->seedCurrency('EUR', 'Euro');

        $this->signUp($this->form(['country_code' => 'DE', 'currency_code' => 'EUR']));
        $this->get(route('signup.cancelled'));

        // provision() re-runs on resume, but only creates a Business when
        // none exists yet; this one already does, so it must be reused
        // exactly, currency included — never re-derived from whatever
        // currency the resume form happens to imply.
        $this->post(route('signup.resume'), ['tier' => 'growth'])->assertRedirectContains('checkout.stripe.test');

        $workspace = Workspace::query()->sole();
        $business = Business::query()->where('workspace_id', $workspace->id)->sole();
        $this->assertSame('EUR', $business->currency_code);
    }

    // =================================================================
    // Webhook convergence, through HTTP
    // =================================================================

    public function test_the_webhook_alone_finishes_a_browser_signup(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth, trialDays: 12);
        $this->signUp($this->form());

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
            $this->signUp($this->form(['tier' => $tier, 'email' => $tier . uniqid() . '@example.test']));

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
        $this->signUp($this->form());
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
    // (now the BUSINESS step)
    // =================================================================

    public function test_the_business_step_renders_country_and_timezone_as_selects(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);
        $this->walk($this->form(), 2);

        $response = $this->get(route('register.business'));

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

        $response = $this->signUp($this->form([
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
        $this->signUp($this->form(['country_code' => 'Ne']))
            ->assertSessionHasErrors('country_code');

        $this->assertSame(0, Workspace::query()->count());
        $this->assertSame(0, User::query()->where('is_customer', true)->count());
    }

    public function test_an_invalid_timezone_is_rejected(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);

        $this->signUp($this->form(['timezone' => 'Not/A_Real_Zone']))
            ->assertSessionHasErrors('timezone');

        $this->assertSame(0, Workspace::query()->count());
    }

    public function test_old_country_and_timezone_values_remain_selected_after_a_validation_failure(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);
        $this->walk($this->form(), 2);

        // A missing business name fails validation; country/timezone were
        // otherwise valid and must come back selected, not reset.
        $this->from(route('register.business'))->post(route('register.business.store'), [
            'business_name' => '',
            'industry' => 'photographer',
            'country_code' => 'NZ',
            'timezone' => 'Pacific/Auckland',
        ])->assertSessionHasErrors('business_name');

        $this->get(route('register.business'))
            ->assertSee('value="NZ" selected', false)
            ->assertSee('value="Pacific/Auckland" selected', false);
    }

    // =================================================================
    // Business currency — a new self-service Business must still receive a
    // valid currency_code through signup so its usage wallet resolves
    // without an operator. The signup no longer ASKS for it: it follows the
    // country (ICU's single legal tender), falling back to USD, always from
    // the same canonical active-currencies list BusinessLocaleOptions offers.
    // =================================================================

    public function test_the_signup_never_asks_for_a_currency(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);
        $this->seedCurrency('EUR', 'Euro');
        $this->walk($this->form(), 2);

        $this->get(route('register.business'))
            ->assertOk()
            ->assertDontSee('name="currency_code"', false);
    }

    public function test_the_business_currency_follows_the_country(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);
        $this->seedCurrency('EUR', 'Euro');

        $this->signUp($this->form(['country_code' => 'DE']))
            ->assertSessionDoesntHaveErrors();

        $business = Business::query()->where('workspace_id', Workspace::query()->sole()->id)->sole();
        $this->assertSame('EUR', $business->currency_code);
    }

    public function test_a_country_whose_currency_is_not_offered_falls_back_to_usd(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);

        // JPY is a real ISO currency but is not active on this install.
        $this->signUp($this->form(['country_code' => 'JP']))
            ->assertSessionDoesntHaveErrors();

        $business = Business::query()->where('workspace_id', Workspace::query()->sole()->id)->sole();
        $this->assertSame('USD', $business->currency_code);
    }

    #[DataProvider('supportedCurrencies')]
    public function test_different_supported_currencies_retain_their_correct_code(string $country, string $code, string $name): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);
        $this->seedCurrency($code, $name);

        $this->signUp($this->form(['country_code' => $country]))
            ->assertSessionDoesntHaveErrors();

        $workspace = Workspace::query()->sole();
        $business = Business::query()->where('workspace_id', $workspace->id)->sole();
        $this->assertSame($code, $business->currency_code);
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function supportedCurrencies(): array
    {
        return [
            'EUR' => ['DE', 'EUR', 'Euro'],
            'GBP' => ['GB', 'GBP', 'British Pound'],
            'CAD' => ['CA', 'CAD', 'Canadian Dollar'],
        ];
    }

    public function test_wallet_initialization_resolves_the_new_businesss_currency_without_manual_intervention(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);
        $this->seedCurrency('EUR', 'Euro');

        $this->signUp($this->form(['country_code' => 'DE']))
            ->assertSessionDoesntHaveErrors();

        $workspace = Workspace::query()->sole();
        $business = Business::query()->where('workspace_id', $workspace->id)->sole();

        // The BusinessCreated listener (InitializeBusinessUsageProfile) runs
        // synchronously in this suite (QUEUE_CONNECTION=sync): no
        // usage:backfill-wallets, no operator action, no separate step.
        $this->assertDatabaseHas('business_usage_wallets', ['business_id' => $business->id]);

        $currencyId = DB::table('currencies')->where('code', 'EUR')->value('id');
        $this->assertDatabaseHas('business_usage_wallets', [
            'business_id' => $business->id,
            'currency_id' => $currencyId,
        ]);
    }

    // =================================================================
    // The locked flow: PLAN → ACCOUNT → BUSINESS → PAYMENT → PROVISIONING
    // → VERIFICATION → Home
    // =================================================================

    public function test_closed_registration_uses_the_branded_auth_shell(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);
        config(['account.can_register' => false]);

        $login = $this->get(route('login'))->assertOk()->getContent();

        $response = $this->get(route('register'));

        $response->assertOk()
            ->assertSee('Create your account')
            ->assertSee('New registrations are temporarily unavailable.')
            ->assertSee('Sign in')
            ->assertSee(route('login'), false)
            // The SAME shell /login renders: corner identity, brand panel,
            // form column — and never a bare, unstyled document.
            ->assertSee('auth-wrapper auth-cover', false)
            ->assertSee('data-role="auth-brand-panel"', false)
            ->assertSee('class="brand-logo"', false)
            ->assertSee('authentication.css', false)
            ->assertDontSee('<main>', false)
            ->assertDontSee('<form', false);

        foreach (['auth-wrapper auth-cover', 'data-role="auth-brand-panel"', 'class="brand-logo"'] as $shellMarker) {
            $this->assertStringContainsString($shellMarker, $login, "/login must carry the shell marker [{$shellMarker}].");
        }
    }

    public function test_every_closed_signup_step_keeps_the_branded_shell_and_creates_nothing(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);
        config(['account.can_register' => false]);

        foreach (['register.plan', 'register.account', 'register.business', 'register.payment'] as $name) {
            $this->get(route($name))->assertOk()
                ->assertSee('New registrations are temporarily unavailable.')
                ->assertSee('auth-wrapper auth-cover', false)
                ->assertDontSee('<main>', false);
        }

        $this->post(route('register.payment.start'))->assertOk()
            ->assertSee('New registrations are temporarily unavailable.');

        $this->assertSame(0, User::query()->where('is_customer', true)->count());
        $this->assertSame(0, Workspace::query()->count());
    }

    public function test_signup_with_nothing_sellable_is_the_same_closed_state(): void
    {
        // No tier is made sellable.
        $this->get(route('register'))->assertOk()
            ->assertSee('New registrations are temporarily unavailable.')
            ->assertSee('auth-wrapper auth-cover', false)
            ->assertDontSee('<main>', false);
    }

    public function test_direct_register_begins_with_plan_selection(): void
    {
        $this->sellableTier(WorkspacePlanTier::Core, price: '97.00');
        $this->sellableTier(WorkspacePlanTier::Growth, trialDays: 14, price: '297.00');
        $this->sellableTier(WorkspacePlanTier::Agency, price: '497.00');

        $this->get(route('register'))->assertOk()
            ->assertSee('Step 1 of 4')
            ->assertSee('Choose your plan')
            ->assertSee('Start with Core')
            ->assertSee('Start with Growth')
            ->assertSee('Start with Agency')
            // Prices are the catalog's, not the page's.
            ->assertSee('97.00')->assertSee('297.00')->assertSee('497.00')
            ->assertSee('auth-wrapper auth-cover', false)
            ->assertDontSee('name="first_name"', false);
    }

    public function test_a_valid_plan_link_pins_the_plan_and_skips_plan_selection(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth, price: '297.00');
        $this->sellableTier(WorkspacePlanTier::Core, price: '97.00');

        $this->get(route('register', ['plan' => 'growth']))->assertRedirect(route('register.account'));

        $this->get(route('register.account'))->assertOk()
            ->assertSee('Step 2 of 4')
            ->assertSee('name="first_name"', false)
            ->assertSee('Growth');

        // The pin survives a plain visit to /register: straight back to the
        // step the customer was on, never back to plan selection.
        $this->get(route('register'))->assertRedirect(route('register.account'));
    }

    public function test_a_disabled_or_foreign_plan_cannot_proceed(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);
        // Real catalog row, but not offered to new signups.
        $core = $this->sellableTier(WorkspacePlanTier::Core, price: '97.00');
        $core->forceFill(['available_for_signup' => false])->save();

        foreach (['core', 'agency', 'enterprise', '<script>'] as $unavailable) {
            $this->get(route('register', ['plan' => $unavailable]))
                ->assertOk()
                ->assertSee('That plan is not available right now')
                ->assertSee('Start with Growth')
                ->assertDontSee('name="first_name"', false);

            $this->post(route('register.plan.select'), ['tier' => $unavailable])
                ->assertRedirect(route('register.plan'))
                ->assertSessionHasErrors('plan');

            // Nothing was pinned: deeper steps send the customer back.
            $this->get(route('register.account'))->assertRedirect(route('register.plan'));
        }
    }

    public function test_a_plan_that_stops_being_sellable_mid_signup_is_dropped_not_trusted(): void
    {
        $growth = $this->sellableTier(WorkspacePlanTier::Growth);
        $this->sellableTier(WorkspacePlanTier::Core, price: '97.00');
        $this->walk($this->form(), 3);

        $growth->forceFill(['available_for_signup' => false])->save();

        $this->get(route('register.payment'))->assertRedirect(route('register.plan'));
        $this->post(route('register.payment.start'))->assertRedirect(route('register.plan'));

        $this->assertSame(0, User::query()->where('is_customer', true)->count());
    }

    public function test_the_account_step_validates_its_fields(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);
        $this->post(route('register.plan.select'), ['tier' => 'growth']);

        $this->post(route('register.account.store'), [])
            ->assertRedirect(route('register.account'))
            ->assertSessionHasErrors(['first_name', 'email', 'password']);

        $this->post(route('register.account.store'), $this->account(['email' => 'not-an-email']))
            ->assertSessionHasErrors('email');
        $this->post(route('register.account.store'), $this->account(['password' => 'short', 'password_confirmation' => 'short']))
            ->assertSessionHasErrors('password');
        $this->post(route('register.account.store'), $this->account(['password_confirmation' => 'different-password']))
            ->assertSessionHasErrors('password');

        // Business questions do not belong here.
        $this->get(route('register.account'))->assertOk()
            ->assertDontSee('name="business_name"', false)
            ->assertDontSee('name="country_code"', false);

        $this->post(route('register.account.store'), $this->account())
            ->assertRedirect(route('register.business'))
            ->assertSessionDoesntHaveErrors();

        $this->assertSame(0, User::query()->where('is_customer', true)->count(), 'The account step stores nothing durable.');
    }

    public function test_the_account_step_offers_a_language_only_when_more_than_one_is_enabled(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);
        \App\Models\Language::query()->update(['status' => 0]);
        \App\Models\Language::query()->updateOrCreate(['code' => 'en'], ['name' => 'English', 'iso_code' => 'us', 'status' => 1]);

        $this->post(route('register.plan.select'), ['tier' => 'growth']);
        $this->get(route('register.account'))->assertOk()->assertDontSee('name="locale"', false);

        \App\Models\Language::query()->updateOrCreate(['code' => 'fr'], ['name' => 'French', 'iso_code' => 'fr', 'status' => 1]);

        $this->get(route('register.account'))
            ->assertOk()
            ->assertSee('name="locale"', false)
            ->assertSee('French');
    }

    public function test_the_chosen_language_becomes_the_users_canonical_locale(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);
        \App\Models\Language::query()->updateOrCreate(['code' => 'fr'], ['name' => 'French', 'iso_code' => 'fr', 'status' => 1]);

        $this->signUp($this->form(['locale' => 'fr']));

        $this->assertSame('fr', User::query()->where('is_customer', true)->sole()->locale);
    }

    public function test_a_language_that_is_not_enabled_is_refused_at_the_account_step(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);
        \App\Models\Language::query()->updateOrCreate(['code' => 'de'], ['name' => 'German', 'iso_code' => 'de', 'status' => 0]);

        $this->post(route('register.plan.select'), ['tier' => 'growth']);
        $this->post(route('register.account.store'), $this->account(['locale' => 'de']))->assertSessionHasErrors('locale');
        $this->post(route('register.account.store'), $this->account(['locale' => 'zz']))->assertSessionHasErrors('locale');
    }

    public function test_the_account_step_refuses_an_email_that_is_already_registered(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);
        $existing = $this->form();
        $this->signUp($existing);
        $this->signOut();

        $this->post(route('register.plan.select'), ['tier' => 'growth']);
        $this->post(route('register.account.store'), $this->account(['email' => $existing['email']]))
            ->assertSessionHasErrors('email');
    }

    public function test_the_business_step_validates_its_fields_and_asks_only_for_provisioning_facts(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);
        $this->walk($this->form(), 2);

        $this->post(route('register.business.store'), [])
            ->assertRedirect(route('register.business'))
            ->assertSessionHasErrors(['business_name', 'industry', 'country_code', 'timezone']);

        $this->post(route('register.business.store'), $this->business(['industry' => 'not_a_niche']))
            ->assertSessionHasErrors('industry');

        $page = $this->get(route('register.business'))->assertOk()
            ->assertSee('Tell us about your business')
            ->assertSee('Step 3 of 4')
            ->assertSee('name="business_name"', false)
            ->assertSee('name="industry"', false)
            ->assertSee('name="country_code"', false)
            ->assertSee('name="timezone"', false);

        foreach (['website', 'seo', 'service', 'package', 'phone', 'calendar', 'stripe', 'messaging'] as $notHere) {
            $page->assertDontSee('name="' . $notHere, false);
        }

        $this->post(route('register.business.store'), $this->business())
            ->assertRedirect(route('register.payment'));
    }

    public function test_refresh_and_back_preserve_progress_without_keeping_the_password_visible(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);
        $form = $this->form();
        $this->walk($form, 3);

        // Refresh at any step re-renders it with the earlier answers.
        $account = $this->get(route('register.account'))->assertOk()
            ->assertSee('value="' . $form['email'] . '"', false)
            ->assertSee('value="Pat"', false)
            ->assertDontSee($form['password'], false);
        $this->assertStringNotContainsString('value="' . $form['password'] . '"', $account->getContent());

        $this->get(route('register.business'))->assertOk()
            ->assertSee('value="Harbor Lane Studios"', false)
            ->assertSee('value="photo_booth_service" selected', false);

        // The entry point resumes at the furthest completed step.
        $this->get(route('register'))->assertRedirect(route('register.payment'));
        $this->get(route('register.payment'))->assertOk()->assertSee('Step 4 of 4');

        // Nothing durable until the customer commits.
        $this->assertSame(0, User::query()->where('is_customer', true)->count());
        $this->assertSame(0, Workspace::query()->count());
    }

    public function test_the_payment_step_names_the_commercial_state_truthfully(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth, trialDays: 14, price: '297.00');
        $this->walk($this->form(), 3);

        $this->get(route('register.payment'))->assertOk()
            ->assertSee('Confirm your plan')
            ->assertSee('297.00')
            ->assertSee('Start 14-day trial');

        $this->signOut();
        $this->sellableTier(WorkspacePlanTier::Growth, trialDays: null, price: '297.00');
        $this->walk($this->form(), 3);

        $this->get(route('register.payment'))->assertOk()
            ->assertSee('Start Growth')
            ->assertDontSee('trial');
    }

    public function test_a_failed_or_cancelled_payment_resumes_on_the_same_plan_without_a_second_tenant(): void
    {
        $this->sellableTier(WorkspacePlanTier::Core, price: '97.00');
        $this->sellableTier(WorkspacePlanTier::Growth, price: '297.00');

        $this->signUp($this->form(['tier' => 'growth']))->assertRedirectContains('checkout.stripe.test');
        $this->get(route('signup.cancelled'))->assertRedirect(route('signup.plan'));

        // The chosen plan stays selected on the resume screen.
        $this->get(route('signup.plan'))->assertOk()
            ->assertSee('Choose a plan')
            ->assertSee('value="growth"', false)
            ->assertSeeInOrder(['id="resume_growth"', 'checked'], false);

        $this->post(route('signup.resume'), ['tier' => 'growth'])->assertRedirectContains('checkout.stripe.test');

        $this->assertSame(1, User::query()->where('is_customer', true)->count());
        $this->assertSame(1, Workspace::query()->count());
        $this->assertSame(1, Business::query()->count());
        $this->assertSame(1, BusinessLocation::query()->count());
        $this->assertSame(0, WorkspacePlanAssignment::query()->count());
    }

    public function test_replaying_the_payment_submit_never_duplicates_the_account_or_tenant(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);
        $this->walk($this->form(), 3);

        $first = $this->post(route('register.payment.start'));
        $first->assertRedirectContains('checkout.stripe.test');

        // Double-click / browser re-POST: the account is now signed in, and a
        // signed-in actor can never begin another guest signup.
        $this->post(route('register.payment.start'))->assertRedirect(route('signup.plan'));
        $this->get(route('register'))->assertRedirect(route('signup.plan'));

        $this->assertSame(1, User::query()->where('is_customer', true)->count());
        $this->assertSame(1, Workspace::query()->count());
        $this->assertSame(1, PlatformSubscription::query()->count());
    }

    public function test_a_successful_payment_provisions_exactly_one_tenant_graph(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth, trialDays: 14);
        $this->signUp($this->form());
        $subscription = PlatformSubscription::query()->sole();
        $this->stripe->completeCheckout((string) $subscription->provider_checkout_session_id);

        $this->get(route('signup.success'));

        $this->assertSame(1, User::query()->where('is_customer', true)->count());
        $workspace = Workspace::query()->sole();
        $business = Business::query()->where('workspace_id', $workspace->id)->sole();
        $location = BusinessLocation::query()->where('business_id', $business->id)->sole();
        $this->assertNotNull($location);
        $this->assertSame(1, PlatformSubscription::query()->count());
        $assignment = WorkspacePlanAssignment::query()->sole();
        $this->assertSame((int) $workspace->id, (int) $assignment->workspace_id);
        $this->assertSame(WorkspacePlanTier::Growth, app(EntitlementManager::class)
            ->getWorkspaceEntitlementSummary($workspace)->tier);
        $this->assertDatabaseHas('business_usage_wallets', ['business_id' => $business->id]);
        $this->assertSame(CustomerAccountAccessState::Usable,
            app(CustomerAccountAccessResolver::class)->resolve($workspace)->state);
    }

    public function test_browser_return_replays_and_a_webhook_replay_never_duplicate_the_graph(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth, trialDays: 14);
        $this->signUp($this->form());
        $subscription = PlatformSubscription::query()->sole();
        $providerSubscriptionId = $this->stripe->completeCheckout((string) $subscription->provider_checkout_session_id);

        $body = json_encode([
            'id' => 'evt_replay',
            'type' => 'checkout.session.completed',
            'created' => now()->getTimestamp(),
            'data' => ['object' => [
                'id' => $subscription->provider_checkout_session_id,
                'object' => 'checkout.session',
                'client_reference_id' => $subscription->uid,
                'subscription' => $providerSubscriptionId,
            ]],
        ]);

        $this->get(route('signup.success'));
        $this->postPlatformWebhook($body, ['Stripe-Signature' => $this->stripe->validSignature])->assertOk();
        $this->get(route('signup.success'));
        $this->postPlatformWebhook($body, ['Stripe-Signature' => $this->stripe->validSignature])->assertOk();
        $this->get(route('signup.success'));

        $this->assertSame(1, User::query()->where('is_customer', true)->count());
        $this->assertSame(1, Workspace::query()->count());
        $this->assertSame(1, Business::query()->count());
        $this->assertSame(1, BusinessLocation::query()->count());
        $this->assertSame(1, PlatformSubscription::query()->count());
        $this->assertSame(1, WorkspacePlanAssignment::query()->count());
    }

    public function test_an_unconfirmed_return_shows_the_setting_up_screen_and_assigns_nothing(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);
        $this->signUp($this->form());

        // Checkout was never completed at the provider.
        $this->get(route('signup.success'))->assertOk()
            ->assertSee('Setting up your Business OS')
            ->assertSee('auth-wrapper auth-cover', false)
            ->assertSee('http-equiv="refresh"', false);

        $this->assertSame(0, WorkspacePlanAssignment::query()->count());
    }

    public function test_the_verification_screen_appears_when_required_and_the_link_was_sent(): void
    {
        config(['account.verify_account' => true]);
        \Illuminate\Support\Facades\Notification::fake();

        $this->sellableTier(WorkspacePlanTier::Growth);
        $form = $this->form();
        $this->signUp($form);

        // Sent when the account was created — payment was never held up for it.
        $user = User::query()->where('email', $form['email'])->sole();
        \Illuminate\Support\Facades\Notification::assertSentTo($user, \Illuminate\Auth\Notifications\VerifyEmail::class);

        $subscription = PlatformSubscription::query()->sole();
        $this->stripe->completeCheckout((string) $subscription->provider_checkout_session_id);

        $this->get(route('signup.success'))->assertRedirect(route('verification.notice'));

        $this->get(route('verification.notice'))->assertOk()
            ->assertSee('Verify your email')
            ->assertSee('We sent a verification link to ' . $form['email'])
            ->assertSee(route('verification.send'), false)
            ->assertSee(route('logout'), false)
            ->assertSee('auth-wrapper auth-cover', false);
    }

    public function test_a_verified_customer_reaches_the_product(): void
    {
        config(['account.verify_account' => false]);

        $this->sellableTier(WorkspacePlanTier::Growth);
        $this->signUp($this->form());
        $subscription = PlatformSubscription::query()->sole();
        $this->stripe->completeCheckout((string) $subscription->provider_checkout_session_id);

        $this->get(route('signup.success'))->assertRedirect(route('user.home'));
    }

    public function test_a_completed_customer_cannot_begin_a_second_guest_signup(): void
    {
        config(['account.verify_account' => false]);

        $this->sellableTier(WorkspacePlanTier::Growth);
        $this->sellableTier(WorkspacePlanTier::Core, price: '97.00');
        $this->signUp($this->form());
        $subscription = PlatformSubscription::query()->sole();
        $this->stripe->completeCheckout((string) $subscription->provider_checkout_session_id);
        $this->get(route('signup.success'));

        $before = [User::query()->count(), Workspace::query()->count(), Business::query()->count()];

        // Every earlier URL and every submit sends a finished customer home.
        foreach (['register', 'register.plan', 'register.account', 'register.business', 'register.payment', 'signup.plan'] as $name) {
            $this->get(route($name))->assertRedirect(route('user.home'));
        }

        $this->post(route('register.payment.start'))->assertRedirect(route('user.home'));
        $this->post(route('register.account.store'), $this->account(['email' => 'second@example.test']))
            ->assertRedirect(route('user.home'));
        $this->post(route('signup.resume'), ['tier' => 'core'])->assertRedirect(route('user.home'));

        $this->assertSame($before, [User::query()->count(), Workspace::query()->count(), Business::query()->count()]);
        $this->assertSame(1, WorkspacePlanAssignment::query()->count());
    }

    public function test_the_agency_branch_asks_for_agency_basics_and_fabricates_no_client(): void
    {
        $this->sellableTier(WorkspacePlanTier::Agency, price: '497.00');
        $this->sellableTier(WorkspacePlanTier::Growth, price: '297.00');

        $this->post(route('register.plan.select'), ['tier' => 'agency'])->assertRedirect(route('register.account'));
        $this->post(route('register.account.store'), $this->account())->assertRedirect(route('register.business'));

        $this->get(route('register.business'))->assertOk()
            ->assertSee('Tell us about your agency')
            ->assertSee('Agency name')
            ->assertSee('Step 3 of 4')
            ->assertDontSee('name="industry"', false);

        // No niche is asked; the Agency's own workspace basics are enough.
        $this->post(route('register.business.store'), [
            'business_name' => 'North Shore Agency',
            'country_code' => 'US',
            'timezone' => 'UTC',
        ])->assertRedirect(route('register.payment'));

        $this->post(route('register.payment.start'))->assertRedirectContains('checkout.stripe.test');

        $workspace = Workspace::query()->sole();
        $this->assertSame('North Shore Agency', (string) $workspace->name);
        // The one Business is the Agency's own Workspace Business under the
        // existing provisioning — not a client — and no client relationship
        // or client Workspace exists.
        $this->assertSame(1, Business::query()->count());
        $this->assertSame('other', Business::query()->sole()->industry->value);
        $this->assertSame(0, \App\Models\AgencyClientWorkspaceRelationship::query()->count());
        $this->assertSame(1, Workspace::query()->count());
    }

    public function test_switching_between_the_agency_and_non_agency_branch_discards_stale_business_answers(): void
    {
        $this->sellableTier(WorkspacePlanTier::Agency, price: '497.00');
        $this->sellableTier(WorkspacePlanTier::Growth, price: '297.00');

        $this->walk($this->form(['tier' => 'growth']), 3);
        $this->post(route('register.plan.select'), ['tier' => 'agency']);

        // The earlier (niche-bearing) business answers do not ride across.
        $this->get(route('register.payment'))->assertRedirect(route('register.business'));
    }

    /** @return array<string, string> */
    private function account(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Pat',
            'last_name' => 'Rivera',
            'email' => 'pat' . uniqid() . '@example.test',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ], $overrides);
    }

    /** @return array<string, string> */
    private function business(array $overrides = []): array
    {
        return array_merge([
            'business_name' => 'Harbor Lane Studios',
            'industry' => 'photo_booth_service',
            'country_code' => 'US',
            'timezone' => 'UTC',
        ], $overrides);
    }

    private function seedCurrency(string $code, string $name): void
    {
        if (DB::table('currencies')->where('code', $code)->exists()) {
            return;
        }

        DB::table('currencies')->insert([
            'uid' => (string) \Illuminate\Support\Str::uuid(),
            'name' => $name,
            'code' => $code,
            'format' => $code,
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

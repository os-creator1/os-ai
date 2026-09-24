<?php

namespace Tests\Feature\PlatformBilling;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\PlatformBilling\PlatformSubscriptionStatus;
use App\Models\PlatformSubscription;
use App\Models\User;
use App\Models\WorkspacePlanCatalog;
use App\Models\WorkspacePlanCatalogPricingChange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\PlatformBilling\Concerns\CreatesPlatformSubscriptions;
use Tests\TestCase;

/**
 * Implementation Contract 21 §11 — the Platform Owner can operate lane A
 * without editing the database, and no secret is ever rendered.
 */
class PlatformOwnerControlsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesPlatformSubscriptions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindFakeStripe();
        $this->ensureRequiredAppConfigRowsExist();
    }

    private function signInAsPlatformOwner(): User
    {
        $admin = User::query()->findOrFail($this->platformAdminId());
        $admin->email_verified_at = now();
        $admin->save();

        // The admin route group gates on the `access backend` permission
        // STRING plus EnsureUserIsAdministrator's `users.is_admin` flag.
        $this->withSession(['permissions' => collect(['access backend'])]);
        $this->actingAs($admin);

        return $admin;
    }

    /** @return array<string, mixed> */
    private function config(array $overrides = []): array
    {
        return array_merge([
            'price' => '297.00',
            'currency_id' => $this->fixtureCurrencyId(),
            'billing_cycle' => 'monthly',
            'trial_enabled' => '1',
            'trial_days' => '14',
            'available_for_signup' => '1',
            'provider_price_id' => 'price_1AbCdEfGhIjKlMnO',
            'reason' => 'Launch pricing.',
        ], $overrides);
    }

    // =================================================================
    // Authorization
    // =================================================================

    public function test_an_anonymous_visitor_cannot_reach_the_owner_surface(): void
    {
        $this->get(route('admin.platform-billing.index'))->assertUnauthorized();
        $this->post(route('admin.platform-billing.update', ['growth']), $this->config())->assertUnauthorized();
    }

    public function test_an_ordinary_customer_cannot_reach_or_change_commercial_configuration(): void
    {
        $this->platformAdminId();
        $customer = $this->createCustomer();
        $this->authenticateAs($customer);

        $this->get(route('admin.platform-billing.index'))->assertUnauthorized();
        $this->post(route('admin.platform-billing.update', ['growth']), $this->config())->assertUnauthorized();

        $this->assertNull(WorkspacePlanCatalog::query()->where('tier', 'growth')->value('provider_price_id'));
    }

    // =================================================================
    // §11 — configuring a plan without a database edit
    // =================================================================

    public function test_the_owner_can_put_a_plan_on_sale_end_to_end(): void
    {
        $this->signInAsPlatformOwner();
        // §11 — the Price must genuinely exist on the platform account and
        // match the terms being saved.
        $this->stripe->definePrice('price_1AbCdEfGhIjKlMnO', [
            'currency' => 'USD', 'unit_amount' => 29700, 'interval' => 'month', 'interval_count' => 1,
        ]);

        $this->post(route('admin.platform-billing.update', ['growth']), $this->config())
            ->assertRedirect();

        $catalog = WorkspacePlanCatalog::query()->where('tier', 'growth')->firstOrFail();
        $this->assertSame('297.00', (string) $catalog->price);
        $this->assertSame('monthly', (string) $catalog->billing_cycle);
        $this->assertTrue((bool) $catalog->trial_enabled);
        $this->assertSame(14, (int) $catalog->trial_days);
        $this->assertTrue((bool) $catalog->available_for_signup);
        $this->assertSame('price_1AbCdEfGhIjKlMnO', (string) $catalog->provider_price_id);
        $this->assertTrue($catalog->isSellable(), 'The tier is now genuinely sellable.');

        // The change is audited through the EXISTING price-history authority.
        $this->assertSame(1, WorkspacePlanCatalogPricingChange::query()->count());
        $this->assertSame('Launch pricing.', (string) WorkspacePlanCatalogPricingChange::query()->sole()->reason);
    }

    public function test_the_owner_surface_shows_why_a_tier_is_not_on_sale(): void
    {
        $this->signInAsPlatformOwner();

        $response = $this->get(route('admin.platform-billing.index'));

        $response->assertOk()
            ->assertSee('Not on sale')
            ->assertSee('No Stripe Price ID configured.');
    }

    public function test_a_malformed_stripe_price_id_is_refused(): void
    {
        $this->signInAsPlatformOwner();

        foreach (['prod_12345678', 'sk_test_abcdef', 'not-a-price', 'price_'] as $bad) {
            $this->post(route('admin.platform-billing.update', ['growth']), $this->config(['provider_price_id' => $bad]))
                ->assertSessionHasErrors('provider_price_id');
        }

        $this->assertNull(WorkspacePlanCatalog::query()->where('tier', 'growth')->value('provider_price_id'));
    }

    public function test_a_trial_without_a_length_is_refused(): void
    {
        $this->signInAsPlatformOwner();

        $this->post(route('admin.platform-billing.update', ['growth']), $this->config(['trial_days' => null]))
            ->assertSessionHasErrors('trial_days');
    }

    public function test_a_change_without_a_reason_is_refused(): void
    {
        $this->signInAsPlatformOwner();

        $this->post(route('admin.platform-billing.update', ['growth']), $this->config(['reason' => '']))
            ->assertSessionHasErrors('reason');

        $this->assertSame(0, WorkspacePlanCatalogPricingChange::query()->count());
    }

    public function test_turning_off_signup_availability_stops_new_sales_without_deactivating_the_tier(): void
    {
        $this->signInAsPlatformOwner();
        $this->stripe->definePrice('price_1AbCdEfGhIjKlMnO', [
            'currency' => 'USD', 'unit_amount' => 29700, 'interval' => 'month', 'interval_count' => 1,
        ]);
        $this->post(route('admin.platform-billing.update', ['growth']), $this->config());

        $this->post(route('admin.platform-billing.update', ['growth']),
            $this->config(['available_for_signup' => null, 'reason' => 'Pausing sales.']));

        $catalog = WorkspacePlanCatalog::query()->where('tier', 'growth')->firstOrFail();
        $this->assertFalse((bool) $catalog->available_for_signup);
        $this->assertTrue((bool) $catalog->is_active,
            'Existing subscribers must still be able to change plans, so the tier stays assignable.');
        $this->assertFalse($catalog->isSellable());
    }

    // =================================================================
    // Disabling a trial clears its duration, rather than leaving it stale
    // =================================================================

    public function test_enabling_a_trial_stores_its_configured_length(): void
    {
        $this->signInAsPlatformOwner();
        $this->stripe->definePrice('price_1AbCdEfGhIjKlMnO', [
            'currency' => 'USD', 'unit_amount' => 29700, 'interval' => 'month', 'interval_count' => 1,
        ]);

        $this->post(route('admin.platform-billing.update', ['growth']),
            $this->config(['trial_enabled' => '1', 'trial_days' => '14']));

        $catalog = WorkspacePlanCatalog::query()->where('tier', 'growth')->firstOrFail();
        $this->assertTrue((bool) $catalog->trial_enabled);
        $this->assertSame(14, (int) $catalog->trial_days);
    }

    public function test_disabling_a_trial_through_the_owner_surface_clears_its_duration(): void
    {
        $this->signInAsPlatformOwner();
        $this->stripe->definePrice('price_1AbCdEfGhIjKlMnO', [
            'currency' => 'USD', 'unit_amount' => 29700, 'interval' => 'month', 'interval_count' => 1,
        ]);

        // A trial was configured once, exactly like a real operator would.
        $this->post(route('admin.platform-billing.update', ['growth']),
            $this->config(['trial_enabled' => '1', 'trial_days' => '14']));
        $this->assertSame(14, (int) WorkspacePlanCatalog::query()->where('tier', 'growth')->value('trial_days'));

        // Then turned off through the same supported action.
        $this->post(route('admin.platform-billing.update', ['growth']),
            $this->config(['trial_enabled' => null, 'trial_days' => null, 'reason' => 'Trial promotion ended.']))
            ->assertRedirect();

        $catalog = WorkspacePlanCatalog::query()->where('tier', 'growth')->firstOrFail();
        $this->assertFalse((bool) $catalog->trial_enabled);
        $this->assertNull($catalog->trial_days,
            'A disabled trial must not leave a stale duration behind — the owner surface is the only supported way to reach this state, and it must be able to say "no trial configured", not just "not offered right now".');
    }

    public function test_re_enabling_a_trial_after_disabling_it_still_requires_a_length(): void
    {
        $this->signInAsPlatformOwner();
        $this->stripe->definePrice('price_1AbCdEfGhIjKlMnO', [
            'currency' => 'USD', 'unit_amount' => 29700, 'interval' => 'month', 'interval_count' => 1,
        ]);

        $this->post(route('admin.platform-billing.update', ['growth']),
            $this->config(['trial_enabled' => '1', 'trial_days' => '14']));
        $this->post(route('admin.platform-billing.update', ['growth']),
            $this->config(['trial_enabled' => null, 'trial_days' => null, 'reason' => 'Trial promotion ended.']));

        // trial_days is now NULL. Re-enabling without a fresh length must
        // still be refused — nulling on disable must not weaken the
        // existing "a trial needs a length" rule on the way back on.
        $this->post(route('admin.platform-billing.update', ['growth']),
            $this->config(['trial_enabled' => '1', 'trial_days' => null, 'reason' => 'Re-launching the trial.']))
            ->assertSessionHasErrors('trial_days');

        $catalog = WorkspacePlanCatalog::query()->where('tier', 'growth')->firstOrFail();
        $this->assertFalse((bool) $catalog->trial_enabled, 'The refused request must not have changed anything.');
        $this->assertNull($catalog->trial_days);

        // A genuine new length re-enables it correctly.
        $this->post(route('admin.platform-billing.update', ['growth']),
            $this->config(['trial_enabled' => '1', 'trial_days' => '21', 'reason' => 'Re-launching the trial.']))
            ->assertRedirect();

        $catalog->refresh();
        $this->assertTrue((bool) $catalog->trial_enabled);
        $this->assertSame(21, (int) $catalog->trial_days);
    }

    public function test_disabling_a_trial_changes_nothing_else_beyond_what_was_submitted(): void
    {
        $this->signInAsPlatformOwner();
        $this->stripe->definePrice('price_1AbCdEfGhIjKlMnO', [
            'currency' => 'USD', 'unit_amount' => 29700, 'interval' => 'month', 'interval_count' => 1,
        ]);
        $this->post(route('admin.platform-billing.update', ['growth']),
            $this->config(['trial_enabled' => '1', 'trial_days' => '14']));
        $before = WorkspacePlanCatalog::query()->where('tier', 'growth')->firstOrFail();

        $this->post(route('admin.platform-billing.update', ['growth']), $this->config([
            'trial_enabled' => null,
            'trial_days' => null,
            'reason' => 'Trial promotion ended.',
        ]));

        $after = WorkspacePlanCatalog::query()->where('tier', 'growth')->firstOrFail();
        $this->assertSame((string) $before->price, (string) $after->price);
        $this->assertSame($before->currency_id, $after->currency_id);
        $this->assertSame((string) $before->billing_cycle, (string) $after->billing_cycle);
        $this->assertSame((string) $before->provider_price_id, (string) $after->provider_price_id);
        $this->assertSame((bool) $before->available_for_signup, (bool) $after->available_for_signup);
    }

    // =================================================================
    // §5.1 — secrets are never rendered
    // =================================================================

    public function test_no_stripe_secret_is_ever_rendered(): void
    {
        config([
            'services.stripe.secret' => 'sk_test_SUPERSECRETVALUE123456',
            'services.stripe.key' => 'pk_test_PUBLISHABLE123456',
            'services.stripe.platform_subscription_webhook.secret' => 'whsec_SUPERSECRETWEBHOOK123',
        ]);
        $this->signInAsPlatformOwner();

        $html = $this->get(route('admin.platform-billing.index'))->assertOk()->getContent();

        foreach (['sk_test_SUPERSECRETVALUE123456', 'SUPERSECRETVALUE', 'whsec_SUPERSECRETWEBHOOK123', 'SUPERSECRETWEBHOOK'] as $secret) {
            $this->assertStringNotContainsString($secret, $html, 'A secret must never reach the browser.');
        }

        // Not even a prefix or a length.
        $this->assertStringNotContainsString('sk_test_', $html);
        $this->assertStringNotContainsString('whsec_', $html);

        // What it DOES show is a status word and the endpoint to configure.
        $this->assertStringContainsString('stripe/webhook/platform-subscriptions', $html);
    }

    public function test_the_owner_surface_has_no_field_that_could_store_a_secret(): void
    {
        $this->signInAsPlatformOwner();
        $html = $this->get(route('admin.platform-billing.index'))->assertOk()->getContent();

        foreach (['name="stripe_secret"', 'name="api_key"', 'name="webhook_secret"', 'name="secret"'] as $field) {
            $this->assertStringNotContainsString($field, $html);
        }
    }

    // =================================================================
    // §11 — Billing & Revenue
    // =================================================================

    public function test_the_revenue_view_reports_lane_a_subscription_state(): void
    {
        $trial = $this->subscribedWorkspace(WorkspacePlanTier::Growth, trialDays: 14);
        $active = $this->subscribedWorkspace(WorkspacePlanTier::Core);
        $this->stripe->setSubscriptionStatus($active['provider_subscription_id'], PlatformSubscriptionStatus::PastDue);
        $this->deliver('invoice.payment_failed', $active);

        $this->signInAsPlatformOwner();
        $response = $this->get(route('admin.platform-billing.index'))->assertOk();

        $response->assertSee('Lane A subscriptions')
            ->assertSee('Needs attention')
            ->assertSee('Webhook health')
            // The disclaimer that keeps lanes apart.
            ->assertSee('separate money lanes and are not counted here');

        $this->assertSame(PlatformSubscriptionStatus::Trialing, $trial['subscription']->refresh()->status);
        $this->assertSame(PlatformSubscriptionStatus::PastDue, $active['subscription']->refresh()->status);
    }

    public function test_the_revenue_view_shows_no_lane_b_c_or_d_money(): void
    {
        $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $this->signInAsPlatformOwner();

        $html = $this->get(route('admin.platform-billing.index'))->assertOk()->getContent();

        // No other lane's DATA appears. (The page does NAME the other lanes,
        // in the disclaimer that says their money is not counted here — that
        // is the point, not a leak.)
        foreach (['business_document_payments', 'business_usage_wallets', 'business_stripe_connections', 'payment_provider_customers'] as $foreign) {
            $this->assertStringNotContainsString($foreign, $html);
        }

        $this->assertStringContainsString('not counted here', $html,
            'The page states plainly that lane B/C/D money is not platform SaaS revenue.');
    }

    public function test_the_subscription_list_answers_support_questions_without_stripe(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth, trialDays: 14);
        $before = count($this->stripe->calls);

        $this->signInAsPlatformOwner();
        $response = $this->get(route('admin.platform-billing.index'))->assertOk();

        $response->assertSee($fixture['workspace']->name)
            ->assertSee(PlatformSubscription::query()->sole()->price_snapshot);

        $this->assertSame($before, count($this->stripe->calls),
            'The operator page is built from durable local facts, not a provider round trip.');
    }
}

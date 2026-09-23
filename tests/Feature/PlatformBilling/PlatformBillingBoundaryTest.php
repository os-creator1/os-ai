<?php

namespace Tests\Feature\PlatformBilling;

use App\Enums\Entitlement\WorkspacePlanTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\PlatformBilling\Concerns\CreatesPlatformSubscriptions;
use Tests\TestCase;

/**
 * Implementation Contract 21 §2/§4/§14 — the boundaries that make "four lanes,
 * one provider" safe, asserted structurally rather than by hope.
 */
class PlatformBillingBoundaryTest extends TestCase
{
    use RefreshDatabase;
    use CreatesPlatformSubscriptions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindFakeStripe();
    }

    /**
     * Every lane-A source file, comments stripped — a docblock may legitimately
     * NAME a forbidden lane to explain why it is forbidden; what must not exist
     * is code that depends on it.
     *
     * @return array<string, string> path => source
     */
    private function laneASources(): array
    {
        $files = array_merge(
            glob(app_path('Library/PlatformBilling/*.php')) ?: [],
            glob(app_path('Exceptions/PlatformBilling/*.php')) ?: [],
            glob(app_path('Enums/PlatformBilling/*.php')) ?: [],
            glob(app_path('Jobs/PlatformBilling/*.php')) ?: [],
            [
                app_path('Models/PlatformSubscription.php'),
                app_path('Models/PlatformSubscriptionEvent.php'),
                app_path('Http/Controllers/Public/PlatformSubscriptionWebhookController.php'),
                app_path('Console/Commands/ApplyDuePlatformPlanChanges.php'),
            ],
        );

        $sources = [];

        foreach ($files as $file) {
            $code = '';

            foreach (token_get_all((string) file_get_contents($file)) as $token) {
                if (is_array($token)) {
                    if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }
                    $code .= $token[1];
                } else {
                    $code .= $token;
                }
            }

            $sources[$file] = $code;
        }

        $this->assertNotEmpty($sources);

        return $sources;
    }

    // =================================================================
    // §2 — lane A never reaches another lane's code or tables
    // =================================================================

    public function test_lane_a_never_references_lane_b_or_lane_d_code(): void
    {
        foreach ($this->laneASources() as $path => $code) {
            foreach ([
                'App\\Library\\Usage',
                'App\\Library\\Payments',
                'BusinessStripeConnection',
                'BusinessDocument',
                'UsageWallet',
                'EffectivePayer',
                'PayerType',
                'PaymentProviderEvent',
                'business_usage_',
                'business_document',
                'business_stripe_connections',
                'business_payment_instruments',
                'payment_provider_customers',
                'payment_provider_events',
            ] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code,
                    basename($path) . " must not reference [{$forbidden}] — that is another money lane.");
            }
        }
    }

    public function test_lane_a_never_touches_the_legacy_subscription_world(): void
    {
        foreach ($this->laneASources() as $path => $code) {
            foreach (['Models\\Plan', 'Models\\Subscription', 'PaymentMethods', 'Invoices', 'SubscriptionRepository'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code,
                    basename($path) . " must not reference legacy [{$forbidden}].");
            }
        }
    }

    public function test_lane_a_makes_only_direct_first_party_charges(): void
    {
        foreach ($this->laneASources() as $path => $code) {
            foreach (['stripe_account', 'on_behalf_of', 'transfer_data', 'application_fee'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code,
                    basename($path) . " must not contain [{$forbidden}] — lane A has no connected account.");
            }
        }
    }

    public function test_only_the_gateway_reaches_the_stripe_sdk_or_the_api_key(): void
    {
        foreach ($this->laneASources() as $path => $code) {
            if (basename($path) === 'StripeApiPlatformGateway.php') {
                continue;
            }

            $this->assertStringNotContainsString('Stripe\\', $code,
                basename($path) . ' must reach Stripe only through the gateway.');
            $this->assertStringNotContainsString('services.stripe.secret', $code,
                basename($path) . ' must not read the platform API key.');
        }
    }

    public function test_no_secret_is_ever_returned_logged_or_thrown(): void
    {
        $gateway = (string) file_get_contents(app_path('Library/PlatformBilling/StripeApiPlatformGateway.php'));

        $this->assertSame(1, substr_count($gateway, 'services.stripe.secret') > 0 ? 1 : 0);
        $this->assertSame(0, preg_match('/return\s+\$secret\b/', $gateway), 'A secret must never be returned.');
        $this->assertSame(0, preg_match('/(Exception|throw)[^;]*\$secret/', $gateway), 'A secret must never reach an exception.');
        $this->assertSame(0, preg_match('/Log::[^;]*\$secret/', $gateway), 'A secret must never be logged.');

        // The exception's own copy is chosen here; what it must never do is
        // read a PROVIDER exception's message or chain one as `previous`,
        // because that text carries customer and request detail.
        $exception = (string) file_get_contents(app_path('Exceptions/PlatformBilling/PlatformBillingException.php'));
        $this->assertSame(0, preg_match('/\$e->getMessage\(\)|\$previous|getPrevious\(\)/', $exception),
            'Provider error text is never propagated.');
        $this->assertStringNotContainsString('$e->', $gateway,
            'The provider exception is caught without being read.');

        // §11 — the status surface answers booleans and a mode word only.
        $status = $this->stripe->configurationStatus();
        $this->assertSame(['configured', 'webhook_configured', 'mode'], array_keys($status));
    }

    public function test_the_subscription_table_holds_no_card_data(): void
    {
        $columns = Schema::getColumnListing('platform_subscriptions');

        foreach ($columns as $column) {
            foreach (['card', 'pan', 'cvc', 'cvv', 'expiry', 'exp_month', 'exp_year', 'last4', 'brand'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $column,
                    "platform_subscriptions.{$column} looks like card data, which never reaches this application.");
            }
        }
    }

    public function test_lane_a_has_its_own_webhook_endpoint_and_secret(): void
    {
        // Three lanes, three config keys. Given three distinct secrets, each
        // lane must verify with its own — which is what makes a lane-B or
        // lane-D event unverifiable here.
        config([
            'services.stripe.platform_subscription_webhook.secret' => 'whsec_lane_a',
            'services.stripe.connect_webhook.secret' => 'whsec_lane_b',
            'services.stripe.webhook.secret' => 'whsec_lane_d',
        ]);

        $this->assertNotSame(
            config('services.stripe.platform_subscription_webhook.secret'),
            config('services.stripe.connect_webhook.secret'),
            'Lane A and lane B must not share a signing secret.',
        );
        $this->assertNotSame(
            config('services.stripe.platform_subscription_webhook.secret'),
            config('services.stripe.webhook.secret'),
            'Lane A and lane D must not share a signing secret.',
        );

        $gateway = (string) file_get_contents(app_path('Library/PlatformBilling/StripeApiPlatformGateway.php'));
        $this->assertStringContainsString('services.stripe.platform_subscription_webhook.secret', $gateway);
        $this->assertStringNotContainsString('services.stripe.connect_webhook', $gateway);
        $this->assertStringNotContainsString("services.stripe.webhook", $gateway,
            "Lane D's usage-billing webhook secret is not lane A's.");
    }

    // =================================================================
    // §4 — one V1 authority
    // =================================================================

    public function test_a_lane_a_signup_writes_no_legacy_subscription_rows(): void
    {
        $before = [
            'subscriptions' => DB::table('subscriptions')->count(),
            'subscription_transactions' => DB::table('subscription_transactions')->count(),
            'invoices' => DB::table('invoices')->count(),
        ];

        $this->subscribedWorkspace(WorkspacePlanTier::Growth);

        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(),
                "§4.1 — V1 signup must not create a legacy [{$table}] row.");
        }
    }

    public function test_a_lane_a_signup_produces_a_real_non_complimentary_assignment(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Agency);

        $assignment = DB::table('workspace_plan_assignments')
            ->where('workspace_id', $fixture['workspace']->id)
            ->sole();

        $this->assertSame(0, (int) $assignment->is_complimentary,
            '§3.4 — the fabricated complimentary Core is exactly what this replaces.');
        $this->assertNull($assignment->complimentary_reason);

        $catalogTier = DB::table('workspace_plan_catalog')
            ->where('id', $assignment->workspace_plan_catalog_id)->value('tier');
        $this->assertSame('agency', $catalogTier, 'The assignment is the tier the customer selected.');
    }

    public function test_a_lane_a_signup_never_routes_through_the_legacy_compatibility_assignment(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);

        $reasons = DB::table('workspace_entitlement_transitions')
            ->where('workspace_id', $fixture['workspace']->id)
            ->pluck('reason');

        foreach ($reasons as $reason) {
            $this->assertStringNotContainsString('Legacy onboarding Workspace', (string) $reason,
                '§4.1 — the legacy compatibility path must be unreachable from V1 signup.');
        }

        $this->assertTrue($reasons->contains('platform_subscription_signup'));
    }

    public function test_one_v1_assignment_per_workspace_is_structural(): void
    {
        $fixture = $this->subscribedWorkspace();

        $this->assertSame(1, DB::table('workspace_plan_assignments')
            ->where('workspace_id', $fixture['workspace']->id)->count());

        // unique(workspace_id) in DDL is what makes a second authority
        // impossible rather than merely unlikely.
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('workspace_plan_assignments')->insert([
            'workspace_id' => $fixture['workspace']->id,
            'workspace_plan_catalog_id' => 1,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_no_v1_entitlement_or_access_class_reads_the_legacy_subscription_world(): void
    {
        $authorities = [
            app_path('Library/Entitlement/EntitlementManager.php'),
            app_path('Library/Entitlement/CustomerAccountAccessResolver.php'),
            app_path('Library/Entitlement/CustomerAccountAccessGuard.php'),
            app_path('Library/Entitlement/WorkspacePlanPresenter.php'),
        ];

        foreach ($authorities as $path) {
            $code = '';

            foreach (token_get_all((string) file_get_contents($path)) as $token) {
                if (is_array($token)) {
                    if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }
                    $code .= $token[1];
                } else {
                    $code .= $token;
                }
            }

            foreach (['Models\\Plan', 'Models\\Subscription', 'SubscriptionRepository', 'PlanRepository'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code,
                    basename($path) . " must not read legacy [{$forbidden}]: legacy state can never override V1 access.");
            }
        }
    }

    // =================================================================
    // §14 — lane A mutates no other lane's rows
    // =================================================================

    public function test_a_full_lane_a_lifecycle_mutates_no_other_lane(): void
    {
        $otherLaneTables = [
            'business_documents',
            'business_document_payments',
            'business_document_refunds',
            'business_stripe_connections',
            'business_payment_events',
            'business_usage_wallets',
            'business_usage_ledger_entries',
            'payment_provider_customers',
            'payment_provider_events',
        ];

        $before = [];
        foreach ($otherLaneTables as $table) {
            $before[$table] = md5(DB::table($table)->orderBy('id')->get()->toJson());
        }

        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $this->stripe->setSubscriptionStatus($fixture['provider_subscription_id'], \App\Enums\PlatformBilling\PlatformSubscriptionStatus::PastDue);
        $this->deliver('invoice.payment_failed', $fixture);
        $this->stripe->setSubscriptionStatus($fixture['provider_subscription_id'], \App\Enums\PlatformBilling\PlatformSubscriptionStatus::Active);
        $this->deliver('invoice.paid', $fixture, ['event_id' => 'evt_ok']);

        foreach ($otherLaneTables as $table) {
            $this->assertSame($before[$table], md5(DB::table($table)->orderBy('id')->get()->toJson()),
                "§14 — lane A must not create or mutate [{$table}].");
        }
    }
}

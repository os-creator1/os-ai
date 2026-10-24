<?php

namespace Tests\Feature\Messaging;

use App\Enums\Entitlement\PlatformFeature;
use App\Library\Messaging\ChargeCarrierFee;
use App\Library\Messaging\ManagedMessageDispatcher;
use App\Library\Messaging\ManagedTransportBilling;
use App\Library\Usage\UsageWalletManager;
use App\Models\Business;
use App\Models\Currency;
use App\Models\User;
use App\Repositories\Contracts\UsageMeterRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\TestCase;

/**
 * Per-segment message billing is an ESTIMATE. These prove it is reconciled
 * against Telnyx's final per-message cost (cost includes the carrier fee),
 * only when that cost is final, once per message, on the owning Business.
 */
class ReconcileMessagingCostsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;
    use CreatesMessagingFixtures;

    private int $currencyId;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currencyId = Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true])->id;
        $this->admin = User::create(['first_name' => 'Ops', 'last_name' => 'Admin', 'email' => 'ops' . uniqid() . '@example.test', 'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin']);
        config(['services.telnyx.api_key' => 'fixture_key']);

        // 0.005 per segment estimate; pass-through (zero markup) for settlements.
        $this->meter(PlatformFeature::MessagingTransport->value, '5000');
        $this->meter(ChargeCarrierFee::FEATURE, '1000000');
    }

    private function meter(string $key, string $rate): void
    {
        $wallet = app(UsageWalletManager::class);

        if (! DB::table('usage_meters')->where('meter_key', $key)->exists()) {
            app(UsageMeterRepository::class)->create(['meter_key' => $key, 'feature_key' => PlatformFeature::MessagingTransport->value, 'business_id' => null, 'currency_id' => $this->currencyId, 'description' => 'Fixture (test-only).', 'updated_by_user_id' => $this->admin->id]);
        }

        $wallet->setActiveRate($key, $rate, '0', 'per unit', $this->currencyId, $this->admin->id, 'Test.');
        $wallet->activateMetering($key, $this->admin->id, 'Test.');
    }

    private function business(int $balance = 10_000_000): Business
    {
        $business = $this->makeBusiness();
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        DB::table('business_usage_wallets')->where('business_id', $business->id)->update(['available_balance_micro' => $balance]);

        return $business;
    }

    private function balance(Business $business): int
    {
        return (int) DB::table('business_usage_wallets')->where('business_id', $business->id)->value('available_balance_micro');
    }

    /** An accepted managed outbound message that was billed for $segments at the estimate. */
    private function outbound(Business $business, string $messageId, string $segments = '1'): int
    {
        $billing = app(ManagedTransportBilling::class);
        $key = 'op-' . $messageId;
        $reservation = $billing->reserve($business, $key, $segments);
        $billing->commit($reservation, $segments);

        return $this->operation($business, 'outbound', $messageId, $key);
    }

    private function operation(Business $business, string $direction, string $messageId, ?string $key = null): int
    {
        return (int) DB::table(ManagedMessageDispatcher::TABLE)->insertGetId([
            'business_id' => $business->id, 'transport_mode' => 'managed', 'provider' => 'telnyx', 'direction' => $direction, 'message_type' => 'sms',
            'operation_key' => $key ?? 'op-' . $messageId, 'provider_message_id' => $messageId, 'status' => 'accepted', 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function telnyxMessage(array $byId): void
    {
        Http::fake(['api.telnyx.com/v2/messages/*' => function ($request) use ($byId) {
            $id = basename(parse_url($request->url(), PHP_URL_PATH));

            return isset($byId[$id]) ? Http::response(['data' => $byId[$id]]) : Http::response(['errors' => []], 404);
        }]);
    }

    private function finalCost(string $amount, string $currency = 'USD'): array
    {
        return ['cost' => ['amount' => $amount, 'currency' => $currency], 'completed_at' => '2026-10-24T10:00:00Z', 'parts' => 1];
    }

    public function test_an_under_billed_message_is_settled_at_cost_once_and_only_when_applied(): void
    {
        $business = $this->business();
        $this->outbound($business, 'm-under'); // billed 0.005
        $this->telnyxMessage(['m-under' => $this->finalCost('0.0085')]); // carrier fee included: actually 0.0085

        $report = app(\App\Library\Messaging\MessageCostReconciler::class)->run(7, false);
        $this->assertSame(1, $report['undercharged']);
        $this->assertSame(3500, $report['variance_micro']);
        $this->assertSame(10_000_000 - 5000, $this->balance($business), 'A report never charges.');

        $applied = app(\App\Library\Messaging\MessageCostReconciler::class)->run(7, true);
        $this->assertSame(1, $applied['settled']);
        $this->assertSame(10_000_000 - 5000 - 3500, $this->balance($business));

        // Re-running never settles the same message twice.
        app(\App\Library\Messaging\MessageCostReconciler::class)->run(7, true);
        $this->assertSame(10_000_000 - 5000 - 3500, $this->balance($business));
        $this->assertSame(1, DB::table('messaging_cost_reconciliations')->count());
    }

    public function test_an_over_billed_message_is_credited_back_by_an_authorizing_administrator_only(): void
    {
        $business = $this->business();
        $this->outbound($business, 'm-over', '2'); // billed 0.010
        $this->telnyxMessage(['m-over' => $this->finalCost('0.0070')]);

        $reconciler = app(\App\Library\Messaging\MessageCostReconciler::class);
        $reconciler->run(7, true); // no actor: a credit is an audited administrator action
        $this->assertSame(10_000_000 - 10_000, $this->balance($business));
        $this->assertSame('overcharge_open', DB::table('messaging_cost_reconciliations')->value('status'));

        $reconciler->run(7, true, $this->admin->id);
        $this->assertSame(10_000_000 - 10_000 + 3000, $this->balance($business));
        $this->assertSame('overcharge_credited', DB::table('messaging_cost_reconciliations')->value('status'));

        $reconciler->run(7, true, $this->admin->id);
        $this->assertSame(10_000_000 - 10_000 + 3000, $this->balance($business));
    }

    public function test_a_matching_cost_changes_nothing(): void
    {
        $business = $this->business();
        $this->outbound($business, 'm-ok');
        $this->telnyxMessage(['m-ok' => $this->finalCost('0.0050')]);

        $result = app(\App\Library\Messaging\MessageCostReconciler::class)->run(7, true, $this->admin->id);

        $this->assertSame(1, $result['matched']);
        $this->assertSame(10_000_000 - 5000, $this->balance($business));
    }

    public function test_an_estimated_or_unfinished_cost_is_never_treated_as_final(): void
    {
        $business = $this->business();
        foreach (['m-null', 'm-open', 'm-eur', 'm-missing'] as $id) {
            $this->outbound($business, $id);
        }
        $this->telnyxMessage([
            'm-null' => ['cost' => null, 'completed_at' => null],                                  // pricing still being calculated
            'm-open' => ['cost' => ['amount' => '0.0085', 'currency' => 'USD'], 'completed_at' => null], // not completed yet
            'm-eur' => $this->finalCost('0.0085', 'EUR'),                                          // not USD
        ]);                                                                                         // m-missing: 404

        $result = app(\App\Library\Messaging\MessageCostReconciler::class)->run(7, true, $this->admin->id);

        $this->assertSame(4, $result['not_final']);
        $this->assertSame(0, DB::table('messaging_cost_reconciliations')->count());
        $this->assertSame(10_000_000 - 4 * 5000, $this->balance($business));
    }

    public function test_inbound_carrier_cost_is_billed_once_to_the_receiving_business_only(): void
    {
        $receiver = $this->business();
        $other = $this->business();
        $this->operation($receiver, 'inbound', 'm-in');
        $this->telnyxMessage(['m-in' => $this->finalCost('0.0055')]);

        $reconciler = app(\App\Library\Messaging\MessageCostReconciler::class);
        $reconciler->run(7, true);
        $reconciler->run(7, true);

        $this->assertSame(10_000_000 - 5500, $this->balance($receiver));
        $this->assertSame(10_000_000, $this->balance($other));
    }

    public function test_a_message_that_was_never_billed_is_not_charged_retroactively(): void
    {
        $business = $this->business();
        $this->operation($business, 'outbound', 'm-unbilled'); // no committed reservation (e.g. sent while unmetered)
        $this->telnyxMessage(['m-unbilled' => $this->finalCost('0.0085')]);

        app(\App\Library\Messaging\MessageCostReconciler::class)->run(7, true, $this->admin->id);

        $this->assertSame(10_000_000, $this->balance($business));
        $this->assertSame(0, DB::table('messaging_cost_reconciliations')->count());
    }

    public function test_the_command_reports_without_charging_and_demands_an_administrator_actor(): void
    {
        $business = $this->business();
        $this->outbound($business, 'm-cmd');
        $this->telnyxMessage(['m-cmd' => $this->finalCost('0.0085')]);

        $this->artisan('messaging:reconcile-costs')->expectsOutputToContain('Report only')->assertSuccessful();
        $this->assertSame(10_000_000 - 5000, $this->balance($business));

        $nonAdmin = User::create(['first_name' => 'C', 'last_name' => 'U', 'email' => 'c' . uniqid() . '@example.test', 'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer']);
        $this->artisan('messaging:reconcile-costs', ['--apply' => true, '--actor-user-id' => (string) $nonAdmin->id])->assertFailed();

        $this->artisan('messaging:reconcile-costs', ['--apply' => true, '--actor-user-id' => (string) $this->admin->id])->assertSuccessful();
        $this->assertSame(10_000_000 - 5000 - 3500, $this->balance($business));
    }
}

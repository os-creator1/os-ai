<?php

namespace Tests\Feature\Messaging;

use App\Enums\Entitlement\PlatformFeature;
use App\Library\Messaging\ManagedMessageDispatcher;
use App\Library\Messaging\ManagedTransportBilling;
use App\Library\Messaging\MessageCostReconciler;
use App\Library\Messaging\MessageMeters;
use App\Library\Usage\UsageWalletManager;
use App\Models\Business;
use App\Models\Currency;
use App\Models\User;
use App\Repositories\Contracts\UsageMeterRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\TestCase;

/**
 * Message charges settle to the approved retail base x Telnyx's billable
 * parts + ONLY the actual carrier surcharge. The provider's total cost is
 * never added on top of the retail base, and anything that cannot be read
 * exactly becomes an incident instead of a guess.
 */
class ReconcileMessagingCostsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesMessagingFixtures;

    private const FUNDS = 10_000_000;

    private int $currencyId;
    private User $admin;
    private ?array $records = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currencyId = Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true])->id;
        $this->admin = User::create(['first_name' => 'Ops', 'last_name' => 'Admin', 'email' => 'ops' . uniqid() . '@example.test', 'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin']);
        config([
            'services.telnyx.api_key' => 'fixture_key',
            'messaging.estimated_carrier_surcharge_micro' => 5000,
            'messaging.reconciliation.actor_user_id' => $this->admin->id,
            'messaging.reconciliation.max_attempts' => 3,
        ]);

        foreach ([
            MessageMeters::SMS_OUTBOUND => '7470', MessageMeters::SMS_INBOUND => '7470', MessageMeters::MMS_OUTBOUND => '22000',
            MessageMeters::MMS_INBOUND_LOCAL => '16500', MessageMeters::MMS_INBOUND_TOLL_FREE => '20000', MessageMeters::SURCHARGE => '1000000',
        ] as $key => $rate) {
            $this->meter($key, $rate);
        }
    }

    private function meter(string $key, string $rate): void
    {
        app(UsageMeterRepository::class)->create(['meter_key' => $key, 'feature_key' => PlatformFeature::MessagingTransport->value, 'business_id' => null, 'currency_id' => $this->currencyId, 'description' => 'Fixture (test-only).', 'updated_by_user_id' => $this->admin->id]);
        $wallet = app(UsageWalletManager::class);
        $wallet->setActiveRate($key, $rate, '0', 'per unit', $this->currencyId, $this->admin->id, 'Test.');
        $wallet->activateMetering($key, $this->admin->id, 'Test.');
    }

    /** @return array{0: Business, 1: int} business and its identity id */
    private function business(int $balance = self::FUNDS, string $numberType = 'local'): array
    {
        [$business, $identity, $number] = $this->managedBusiness();
        $number->update(['number_type' => $numberType]);
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        DB::table('business_usage_wallets')->where('business_id', $business->id)->update(['available_balance_micro' => $balance, 'refundable_paid_available_micro' => $balance]);

        return [$business, (int) $identity->id];
    }

    private function balance(Business $business): int
    {
        return (int) DB::table('business_usage_wallets')->where('business_id', $business->id)->value('available_balance_micro');
    }

    /** An accepted managed outbound message billed through the real reserve/commit path. */
    private function outbound(Business $business, int $identityId, string $messageId, string $type = 'sms', string $parts = '1', string $numberType = 'local'): void
    {
        $billing = app(ManagedTransportBilling::class);
        $reservation = $billing->reserve($business, 'op-' . $messageId, $parts, $type, $numberType);
        $billing->commit($reservation, $parts);
        $this->operation($business, $identityId, 'outbound', $type, $messageId, 'op-' . $messageId);
    }

    private function operation(Business $business, int $identityId, string $direction, string $type, string $messageId, ?string $key = null): int
    {
        return (int) DB::table(ManagedMessageDispatcher::TABLE)->insertGetId([
            'business_id' => $business->id, 'business_messaging_identity_id' => $identityId, 'transport_mode' => 'managed', 'provider' => 'telnyx', 'direction' => $direction, 'message_type' => $type,
            'operation_key' => $key, 'provider_message_id' => $messageId, 'status' => $direction === 'inbound' ? 'delivered' : 'accepted', 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param array<string, array<string, mixed>> $byId */
    private function telnyx(array $byId): void
    {
        $first = $this->records === null;
        $this->records = $byId + ($this->records ?? []); // Http::fake stubs accumulate: one stub, changing data

        if ($first) {
            Http::fake(['api.telnyx.com/v2/messages/*' => function ($request) {
                $id = basename(parse_url($request->url(), PHP_URL_PATH));

                return isset($this->records[$id]) ? Http::response(['data' => $this->records[$id]]) : Http::response(['errors' => []], 404);
            }]);
        }
    }

    private function record(string $type, string $direction, int $parts, string $rate, ?string $fee, ?string $total = null): array
    {
        $breakdown = ['rate' => ['amount' => $rate, 'currency' => 'USD']];

        if ($fee !== null) {
            $breakdown['carrier_fee'] = ['amount' => $fee, 'currency' => 'USD'];
        }

        return [
            'type' => strtoupper($type), 'direction' => $direction, 'parts' => $parts, 'completed_at' => '2026-10-24T10:00:00Z',
            'cost' => ['amount' => $total ?? bcadd($rate, $fee ?? '0', 6), 'currency' => 'USD'], 'cost_breakdown' => $breakdown,
        ];
    }

    private function reconcile(bool $apply = true, int $recheck = 0): array
    {
        return app(MessageCostReconciler::class)->run(7, $apply, null, 500, $recheck);
    }

    public function test_outbound_sms_settles_to_retail_base_times_parts_plus_only_the_actual_surcharge(): void
    {
        [$business, $identity] = $this->business();
        $this->outbound($business, $identity, 'm1', 'sms', '2');
        // Held up front: 2 x 7470 retail + 2 x 5000 surcharge estimate.
        $this->assertSame(self::FUNDS - 14_940 - 10_000, $this->balance($business));
        // Telnyx's own rate (0.004/part) is irrelevant to the retail price; the actual surcharge is 0.0034.
        $this->telnyx(['m1' => $this->record('sms', 'outbound', 2, '0.008', '0.0034')]);

        $summary = $this->reconcile();

        $this->assertSame(1, $summary['settled']);
        $this->assertSame(self::FUNDS - 14_940 - 3_400, $this->balance($business), 'retail base x parts + actual surcharge; the provider cost is never added on top');
        $row = DB::table('messaging_cost_reconciliations')->first();
        $this->assertSame('settled', $row->status);
        $this->assertSame(14_940 + 3_400, (int) $row->expected_micro);
    }

    public function test_a_report_run_never_charges_and_a_rerun_never_settles_twice(): void
    {
        [$business, $identity] = $this->business();
        $this->outbound($business, $identity, 'm2');
        $this->telnyx(['m2' => $this->record('sms', 'outbound', 1, '0.004', '0.0090')]); // surcharge above the 0.005 estimate
        $after = $this->balance($business);

        $this->reconcile(false);
        $this->assertSame($after, $this->balance($business));
        $this->assertSame('open', DB::table('messaging_cost_reconciliations')->value('status'));

        $this->reconcile();
        $this->reconcile();
        $this->reconcile();
        $this->assertSame($after - 4_000, $this->balance($business));
        $this->assertSame(1, DB::table('messaging_cost_reconciliations')->count());
    }

    public function test_a_matching_estimate_changes_nothing(): void
    {
        [$business, $identity] = $this->business();
        $this->outbound($business, $identity, 'm3');
        $after = $this->balance($business);
        $this->telnyx(['m3' => $this->record('sms', 'outbound', 1, '0.004', '0.0050')]);

        $summary = $this->reconcile();

        $this->assertSame(1, $summary['matched']);
        $this->assertSame($after, $this->balance($business));
    }

    public function test_outbound_mms_toll_free_uses_the_mms_outbound_meter(): void
    {
        [$business, $identity] = $this->business(self::FUNDS, 'toll_free');
        $this->outbound($business, $identity, 'mm1', 'mms', '1', 'toll_free');
        $this->telnyx(['mm1' => $this->record('mms', 'outbound', 1, '0.015', '0.0050')]);

        $this->reconcile();

        $this->assertSame(self::FUNDS - 22_000 - 5_000, $this->balance($business));
        $this->assertSame(MessageMeters::MMS_OUTBOUND, DB::table('messaging_cost_reconciliations')->value('meter_key'));
    }

    public function test_inbound_sms_is_billed_once_at_the_inbound_base_plus_the_actual_surcharge(): void
    {
        [$business, $identity] = $this->business();
        $this->operation($business, $identity, 'inbound', 'sms', 'in1');
        $this->telnyx(['in1' => $this->record('sms', 'inbound', 1, '0.004', '0.0030')]);

        $this->reconcile();
        $this->reconcile();

        $this->assertSame(self::FUNDS - 7_470 - 3_000, $this->balance($business));
        $this->assertSame(MessageMeters::SMS_INBOUND, DB::table('messaging_cost_reconciliations')->value('meter_key'));
    }

    public function test_inbound_mms_is_priced_by_the_number_type(): void
    {
        [$local, $localIdentity] = $this->business(self::FUNDS, 'local');
        [$tollFree, $tollFreeIdentity] = $this->business(self::FUNDS, 'toll_free');
        $this->operation($local, $localIdentity, 'inbound', 'mms', 'inl');
        $this->operation($tollFree, $tollFreeIdentity, 'inbound', 'mms', 'intf');
        $this->telnyx([
            'inl' => $this->record('mms', 'inbound', 1, '0.01', '0.0020'),
            'intf' => $this->record('mms', 'inbound', 1, '0.01', '0.0020'),
        ]);

        $this->reconcile();

        $this->assertSame(self::FUNDS - 16_500 - 2_000, $this->balance($local));
        $this->assertSame(self::FUNDS - 20_000 - 2_000, $this->balance($tollFree));
    }

    public function test_a_message_telnyx_did_not_charge_for_is_credited_back_in_full(): void
    {
        [$business, $identity] = $this->business();
        $this->outbound($business, $identity, 'failed1');
        $record = $this->record('sms', 'outbound', 1, '0', '0');
        $this->telnyx(['failed1' => $record]);

        $this->reconcile();

        $this->assertSame(self::FUNDS, $this->balance($business));
    }

    public function test_only_a_total_with_no_breakdown_is_an_incident_not_a_guess(): void
    {
        [$business, $identity] = $this->business();
        $this->outbound($business, $identity, 'tot1');
        $after = $this->balance($business);
        $this->telnyx(['tot1' => ['type' => 'SMS', 'direction' => 'outbound', 'parts' => 1, 'completed_at' => '2026-10-24T10:00:00Z', 'cost' => ['amount' => '0.0090', 'currency' => 'USD']]]);

        $summary = $this->reconcile();

        $this->assertSame(1, $summary['incidents']);
        $this->assertSame('surcharge_unavailable', DB::table('messaging_cost_reconciliations')->value('incident_code'));
        $this->assertSame($after, $this->balance($business));
    }

    public function test_a_total_derivable_from_the_rate_is_settled_with_the_documented_derivation(): void
    {
        [$business, $identity] = $this->business();
        $this->outbound($business, $identity, 'der1');
        $record = $this->record('sms', 'outbound', 1, '0.004', null, '0.0070'); // surcharge = total - rate = 0.003
        $this->telnyx(['der1' => $record]);

        $this->reconcile();

        $this->assertSame(self::FUNDS - 7_470 - 3_000, $this->balance($business));
    }

    public function test_contradictory_figures_missing_parts_and_wrong_message_type_are_incidents(): void
    {
        [$business, $identity] = $this->business();
        foreach (['bad1', 'bad2', 'bad3'] as $id) {
            $this->outbound($business, $identity, $id);
        }
        $noParts = $this->record('sms', 'outbound', 1, '0.004', '0.003');
        unset($noParts['parts']);
        $this->telnyx([
            'bad1' => $this->record('sms', 'outbound', 1, '0.004', '0.003', '0.0500'),
            'bad2' => $noParts,
            'bad3' => $this->record('mms', 'outbound', 1, '0.004', '0.003'),
        ]);

        $this->reconcile();

        $this->assertEqualsCanonicalizing(['breakdown_mismatch', 'parts_missing', 'record_mismatch'], DB::table('messaging_cost_reconciliations')->pluck('incident_code')->all());
    }

    public function test_a_cost_that_never_finalises_retries_a_bounded_number_of_times_then_becomes_an_incident(): void
    {
        [$business, $identity] = $this->business();
        $this->outbound($business, $identity, 'slow1');
        $pending = $this->record('sms', 'outbound', 1, '0.004', '0.003');
        $pending['completed_at'] = null;
        $this->telnyx(['slow1' => $pending]);

        for ($i = 0; $i < 3; $i++) {
            $this->reconcile();
            DB::table('messaging_cost_reconciliations')->update(['next_attempt_at' => null]);
        }

        $this->assertSame('incident', DB::table('messaging_cost_reconciliations')->value('status'));
        $this->assertSame(3, (int) DB::table('messaging_cost_reconciliations')->value('attempts'));
    }

    public function test_a_variance_above_the_automatic_limit_is_an_incident_for_an_administrator(): void
    {
        config(['messaging.reconciliation.max_auto_adjustment_micro' => 1_000]);
        [$business, $identity] = $this->business();
        $this->outbound($business, $identity, 'big1');
        $after = $this->balance($business);
        $this->telnyx(['big1' => $this->record('sms', 'outbound', 1, '0.004', '0.0200')]);

        $this->reconcile();

        $this->assertSame('variance_exceeds_auto_limit', DB::table('messaging_cost_reconciliations')->value('incident_code'));
        $this->assertSame($after, $this->balance($business));
    }

    public function test_a_wallet_shortfall_on_settlement_retries_then_surfaces_and_never_charges_partially(): void
    {
        [$business, $identity] = $this->business(20_000);
        $this->outbound($business, $identity, 'short1');
        DB::table('business_usage_wallets')->where('business_id', $business->id)->update(['available_balance_micro' => 0, 'refundable_paid_available_micro' => 0]);
        $this->telnyx(['short1' => $this->record('sms', 'outbound', 1, '0.004', '0.0090')]);

        $this->reconcile();

        $this->assertSame(0, $this->balance($business));
        $this->assertSame('pending', DB::table('messaging_cost_reconciliations')->value('status'));
        $this->assertStringContainsString('wallet_shortfall', (string) DB::table('messaging_cost_reconciliations')->value('last_error'));
    }

    public function test_a_later_telnyx_billing_adjustment_is_settled_once_by_its_own_delta(): void
    {
        [$business, $identity] = $this->business();
        $this->outbound($business, $identity, 'adj1');
        $this->telnyx(['adj1' => $this->record('sms', 'outbound', 1, '0.004', '0.0050')]);
        $this->reconcile();
        $after = $this->balance($business);

        $this->telnyx(['adj1' => $this->record('sms', 'outbound', 1, '0.004', '0.0030')]); // a partial refund of the surcharge
        $this->reconcile(true, 3);
        $this->reconcile(true, 3);

        $this->assertSame($after + 2_000, $this->balance($business));
        $this->assertSame(1, (int) DB::table('messaging_cost_reconciliations')->value('revision'));
    }

    public function test_each_business_is_charged_and_credited_only_for_its_own_messages(): void
    {
        [$a, $aIdentity] = $this->business();
        [$b, $bIdentity] = $this->business();
        $this->outbound($a, $aIdentity, 'iso-a');
        $bBefore = $this->balance($b);
        $this->telnyx(['iso-a' => $this->record('sms', 'outbound', 1, '0.004', '0.0090')]);

        $this->reconcile();

        $this->assertSame(self::FUNDS - 7_470 - 9_000, $this->balance($a));
        $this->assertSame($bBefore, $this->balance($b));
        unset($bIdentity);
    }

    public function test_byo_traffic_is_never_reconciled(): void
    {
        [$business, $identity] = $this->business();
        DB::table(ManagedMessageDispatcher::TABLE)->insert([
            'business_id' => $business->id, 'business_messaging_identity_id' => $identity, 'transport_mode' => 'byo', 'provider' => 'telnyx', 'direction' => 'outbound', 'message_type' => 'sms',
            'operation_key' => 'byo-1', 'provider_message_id' => 'byo-1', 'status' => 'accepted', 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        Http::fake();

        $this->reconcile();

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('messaging_cost_reconciliations')->count());
    }
}

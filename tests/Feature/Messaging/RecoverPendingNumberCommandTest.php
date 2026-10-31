<?php

namespace Tests\Feature\Messaging;

use App\Library\Messaging\ProvisioningIncidentRecorder;
use App\Models\Business;
use App\Models\BusinessMessagingIdentity;
use App\Models\BusinessMessagingNumber;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\TestCase;

/**
 * messaging:recover-pending-number — mocked Telnyx only (GET fakes). The target
 * is discovered from the incident, so these tests need no config overrides.
 */
class RecoverPendingNumberCommandTest extends TestCase
{
    use RefreshDatabase;
    use CreatesMessagingFixtures;

    private const PHONE = '+18449506346';
    private const RESOURCE = '3082808573835609725';
    private const PROFILE = '4001a191-a73e-4277-baef-197ca13a0a5d';
    private const ITEM = '1d312144-9c5c-4978-a50f-a2b206807fb9';
    private const PARENT = 'a1b2c3d4-5555-4666-8777-parentorder01';
    private const PURCHASED = '2026-10-30T12:00:00Z';

    private Business $business;
    private int $incidentId;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-31 12:00:00');
        config([
            'services.telnyx.api_key' => 'fixture_api_key_not_a_real_credential',
            'messaging.number_sms_addon_monthly_cost_micro' => 100000,
        ]);

        $this->business = $this->makeBusiness();
        $this->incidentId = $this->makeIncident($this->business);
        $this->insertReservation($this->business, 'fixture-res-a');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeIncident(Business $business): int
    {
        return app(ProvisioningIncidentRecorder::class)->record(
            $business, 'number_order_outcome_unknown', self::PROFILE, null, self::PHONE, 'toll_free', 'order status=503',
        )->id;
    }

    private function insertReservation(Business $business, string $key): int
    {
        Schema::disableForeignKeyConstraints();
        $id = DB::table('business_usage_reservations')->insertGetId([
            'business_id' => $business->id, 'wallet_id' => 1,
            'feature_key' => 'messaging_transport', 'meter_key' => 'messaging_number_purchase',
            'period_key' => '2026-10', 'status' => 'committed',
            'reserved_amount_micro' => 2_100_000, 'paid_attributable_amount_micro' => 0, 'estimated_quantity' => '2.100000',
            'rate_id' => 1, 'rate_version' => 1, 'retail_rate_micro' => 1_000_000, 'provider_cost_micro' => 1_000_000,
            'rounding_rule' => 'half_up', 'idempotency_key' => $key, 'correlation_key' => $key,
            'reserved_at' => now(), 'expires_at' => now()->addHour(), 'committed_at' => now(),
            'final_quantity' => '2.100000', 'final_amount_micro' => 2_100_000,
        ]);
        Schema::enableForeignKeyConstraints();

        return $id;
    }

    private function row(string $category, string $name, string $cost): array
    {
        return ['cost_type' => $category, 'name' => $name, 'cost' => $cost];
    }

    /** @param array<string, mixed> $over */
    private function fakeTelnyx(array $over = []): void
    {
        $services = [$this->row('otc', 'numbers', '-1.00000'), $this->row('partial-mrc', 'numbers', '-0.06452'), $this->row('partial-mrc', 'SMS', '-0.00645')];
        $item = ['id' => self::ITEM, 'phone_number' => self::PHONE, 'status' => 'success', 'order_request_id' => self::PARENT];

        $list = $over['list'] ?? [['id' => self::RESOURCE, 'phone_number' => self::PHONE, 'status' => 'active'], ['id' => '999', 'phone_number' => '+18449506340']];
        $items = $over['items'] ?? [['id' => 'other', 'phone_number' => '+18005550100', 'status' => 'success', 'order_request_id' => 'zzz'], $item];
        $order = array_replace(['id' => self::PARENT, 'status' => 'success', 'user_id' => 'acct-1', 'phone_numbers' => [
            ['id' => self::ITEM, 'phone_number' => self::PHONE, 'status' => 'success'],
        ]], $over['order'] ?? []);
        $number = array_replace(['id' => self::RESOURCE, 'phone_number' => self::PHONE, 'status' => 'active', 'user_id' => 'acct-1',
            'messaging_profile_id' => self::PROFILE, 'purchased_at' => self::PURCHASED], $over['number'] ?? []);
        $report = array_replace(['user_id' => 'acct-1', 'start_date' => '2026-10-01', 'end_date' => '2026-11-01', 'currency' => 'USD',
            'results' => $over['results'] ?? [['tn' => self::PHONE, 'charge_type' => 'toll-free-number', 'services' => $services]]], $over['report'] ?? []);

        Http::fake(function (Request $request) use ($list, $items, $order, $number, $report) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                $path === '/v2/phone_numbers' => Http::response(['data' => $list]),
                $path === '/v2/phone_numbers/' . self::RESOURCE => Http::response(['data' => $number]),
                $path === '/v2/number_order_phone_numbers' => Http::response(['data' => $items]),
                $path === '/v2/number_orders/' . self::PARENT => Http::response(['data' => $order]),
                $path === '/v2/messaging_profiles/' . self::PROFILE => Http::response(['data' => ['id' => self::PROFILE]]),
                $path === '/v2/charges_breakdown' => Http::response($report),
                default => Http::response(['errors' => [['code' => '10005']]], 404),
            };
        });
    }

    private function run_(bool $execute = true, ?int $business = null, ?int $incident = null)
    {
        return $this->artisan('messaging:recover-pending-number', [
            '--business' => $business ?? $this->business->id,
            '--incident' => $incident ?? $this->incidentId,
            $execute ? '--execute' : '--dry-run' => true,
        ]);
    }

    private function assertNothingWritten(): void
    {
        $this->assertSame(0, BusinessMessagingIdentity::query()->count());
        $this->assertSame(0, BusinessMessagingNumber::query()->count());
        $this->assertNull(DB::table('business_messaging_provisioning_incidents')->where('id', $this->incidentId)->value('resolved_at'));
    }

    private function assertOnlyGets(): void
    {
        Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET');
    }

    public function test_dry_run_reports_the_discovered_target_and_writes_nothing(): void
    {
        $this->fakeTelnyx();

        $this->run_(false)
            ->expectsOutputToContain('Target: Business #' . $this->business->id)
            ->expectsOutputToContain(self::RESOURCE)
            ->expectsOutputToContain('parent order ' . self::PARENT)
            ->expectsOutputToContain('DRY RUN')
            ->assertExitCode(0);

        $this->assertNothingWritten();
        $this->assertOnlyGets();
    }

    public function test_execute_attaches_the_number_with_resolved_billing_and_closes_the_incident_last(): void
    {
        $this->fakeTelnyx();

        $this->run_()->assertExitCode(0);

        $identity = BusinessMessagingIdentity::query()->sole();
        $this->assertSame((int) $this->business->id, (int) $identity->business_id);
        $this->assertSame(self::PROFILE, $identity->messaging_profile_id);

        $number = BusinessMessagingNumber::query()->sole();
        $this->assertSame(self::PHONE, $number->phone_number);
        // The phone-number RESOURCE id, not the order-item id.
        $this->assertSame(self::RESOURCE, $number->provider_number_reference);
        $this->assertSame(1_000_000, (int) $number->upfront_cost_micro);
        $this->assertSame(1_100_000, (int) $number->monthly_cost_micro);
        $purchased = Carbon::parse(self::PURCHASED);
        $this->assertSame($purchased->getTimestamp(), $number->activated_at->getTimestamp());
        // Billing resolved: the normal purchase-anniversary renewal.
        $this->assertSame($purchased->copy()->addMonthNoOverflow()->getTimestamp(), $number->next_renewal_at->getTimestamp());

        $incident = DB::table('business_messaging_provisioning_incidents')->where('id', $this->incidentId)->first();
        $this->assertNotNull($incident->resolved_at);
        $this->assertStringContainsString('[recovered by messaging:recover-pending-number]', $incident->resolution_note);
        $this->assertStringContainsString('Telnyx order ' . self::PARENT . ' verified', $incident->resolution_note);

        // The parent order was read; the order-item id was never used as an order id.
        Http::assertSent(fn (Request $r) => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/number_orders/' . self::PARENT));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/number_orders/' . self::ITEM));
        $this->assertOnlyGets();

        // The original reservation is untouched and no second one exists.
        $this->assertSame(1, DB::table('business_usage_reservations')->count());
        $this->assertSame('committed', DB::table('business_usage_reservations')->value('status'));
    }

    public function test_repeat_execution_is_idempotent_and_makes_no_provider_calls(): void
    {
        $this->fakeTelnyx();
        $this->run_()->assertExitCode(0);
        $resolvedAt = DB::table('business_messaging_provisioning_incidents')->where('id', $this->incidentId)->value('resolved_at');

        Http::fake();
        $this->run_()->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertSame(1, BusinessMessagingIdentity::query()->count());
        $this->assertSame(1, BusinessMessagingNumber::query()->count());
        $this->assertSame($resolvedAt, DB::table('business_messaging_provisioning_incidents')->where('id', $this->incidentId)->value('resolved_at'));
    }

    public function test_wrong_business_incident_stage_or_missing_arguments_are_refused_before_any_call(): void
    {
        Http::fake();
        $other = $this->makeBusiness();

        $this->run_(true, $other->id)->assertExitCode(1);
        $this->run_(true, null, $this->incidentId + 50)->assertExitCode(1);
        DB::table('business_messaging_provisioning_incidents')->where('id', $this->incidentId)->update(['stage' => 'number_attach_failed_after_provider_success']);
        $this->run_()->assertExitCode(1);
        DB::table('business_messaging_provisioning_incidents')->where('id', $this->incidentId)->update(['stage' => 'number_order_outcome_unknown', 'number_type' => 'local']);
        $this->run_()->assertExitCode(1);
        $this->artisan('messaging:recover-pending-number', ['--execute' => true])->assertExitCode(1);
        $this->artisan('messaging:recover-pending-number', ['--business' => $this->business->id, '--incident' => $this->incidentId, '--execute' => true, '--dry-run' => true])->assertExitCode(1);

        Http::assertNothingSent();
        $this->assertSame(0, BusinessMessagingNumber::query()->count());
    }

    public function test_carrier_blockers_abort_without_writing(): void
    {
        $item = ['id' => self::ITEM, 'phone_number' => self::PHONE, 'status' => 'success', 'order_request_id' => self::PARENT];

        foreach ([
            'number resource missing' => ['list' => []],
            'duplicate number resource' => ['list' => [['id' => '1', 'phone_number' => self::PHONE], ['id' => '2', 'phone_number' => self::PHONE]]],
            'no order item' => ['items' => []],
            'duplicate order items' => ['items' => [$item, $item]],
            'order item not successful' => ['items' => [array_replace($item, ['status' => 'pending'])]],
            'item names no parent' => ['items' => [array_replace($item, ['order_request_id' => null])]],
            'parent order not success' => ['order' => ['status' => 'pending']],
            'number inactive' => ['number' => ['status' => 'port-out-pending']],
            'number on another profile' => ['number' => ['messaging_profile_id' => 'other-profile']],
            'other account' => ['order' => ['user_id' => 'acct-2']],
            'billing records missing' => ['results' => []],
            'billing proration mismatch' => ['results' => [['tn' => self::PHONE, 'charge_type' => 'toll-free-number', 'services' => [
                $this->row('otc', 'numbers', '-1.00000'), $this->row('partial-mrc', 'numbers', '-0.50000'), $this->row('partial-mrc', 'SMS', '-0.00645'),
            ]]]],
        ] as $label => $override) {
            $this->fakeTelnyx($override);
            $this->run_()->assertExitCode(1);
            $this->assertNothingWritten();
        }
    }

    public function test_an_ambiguous_or_missing_original_reservation_aborts(): void
    {
        $this->fakeTelnyx();

        $this->insertReservation($this->business, 'fixture-res-b');
        $this->run_()->assertExitCode(1);
        $this->assertNothingWritten();

        DB::table('business_usage_reservations')->delete();
        $this->run_()->assertExitCode(1);
        $this->assertNothingWritten();
    }

    public function test_billing_that_cannot_be_left_resolved_attaches_nothing_and_keeps_the_incident_open(): void
    {
        $this->fakeTelnyx();
        // The purchase anniversary (30 Nov) has passed: the first renewal would bill immediately.
        Carbon::setTestNow('2026-12-15 12:00:00');
        DB::table('business_usage_reservations')->update(['committed_at' => now()]);
        DB::table('business_messaging_provisioning_incidents')->where('id', $this->incidentId)->update(['created_at' => now()]);

        $this->run_()
            ->expectsOutputToContain('Billing would be unresolved')
            ->assertExitCode(1);

        $this->assertNothingWritten();
    }

    public function test_a_number_attached_without_resolved_billing_is_never_called_complete(): void
    {
        $this->fakeTelnyx();
        $identity = $this->attachIdentity($this->business, self::PROFILE);
        $number = $this->attachNumber($identity, self::PHONE, true, \App\Enums\Messaging\BusinessMessagingNumberStatus::Active, self::RESOURCE);
        BusinessMessagingNumber::query()->whereKey($number->id)->update([
            'number_type' => 'toll_free', 'upfront_cost_micro' => 1_000_000, 'monthly_cost_micro' => 1_100_000, 'cost_currency' => 'USD', 'next_renewal_at' => null,
        ]);

        $this->run_()->assertExitCode(1);

        $this->assertNull(DB::table('business_messaging_provisioning_incidents')->where('id', $this->incidentId)->value('resolved_at'));
        $this->assertSame(1, BusinessMessagingNumber::query()->count());
    }

    public function test_an_existing_identity_or_number_elsewhere_is_a_collision_and_nothing_is_written(): void
    {
        $this->fakeTelnyx();

        $this->managedBusiness(self::PHONE, 'mp_other');
        $this->run_()->assertExitCode(1);
        $this->assertSame(1, BusinessMessagingNumber::query()->count());
        $this->assertNull(DB::table('business_messaging_provisioning_incidents')->where('id', $this->incidentId)->value('resolved_at'));
    }

    public function test_the_business_3_command_keeps_its_own_behaviour(): void
    {
        // Its target is the fixed config record, which names neither this Business nor this incident.
        Http::fake();
        $this->artisan('messaging:recover-telnyx-order', ['--incident' => $this->incidentId, '--order' => self::PARENT, '--dry-run' => true])->assertExitCode(1);
        Http::assertNothingSent();
    }
}

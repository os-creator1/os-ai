<?php

namespace Tests\Feature\Messaging;

use App\Library\Messaging\ProvisioningIncidentRecorder;
use App\Models\Business;
use App\Models\BusinessMessagingIdentity;
use App\Models\BusinessMessagingNumber;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\TestCase;

/**
 * messaging:recover-telnyx-order — mocked Telnyx only (GET fakes). The recovery
 * target is passed as explicit options; nothing is read from config.
 */
class RecoverTelnyxOrderCommandTest extends TestCase
{
    use RefreshDatabase;
    use CreatesMessagingFixtures;

    private const ORDER = '9b1c7e52-0a7d-4f43-9d2e-6f1a2c3b4d5e';
    private const ITEM = '1d312144-9c5c-4978-a50f-a2b206807fb9';
    private const PHONE = '+18449506346';
    private const RESOURCE = '3082808573835609725';
    private const PROFILE = '4001a191-a73e-4277-baef-197ca13a0a5d';
    private const PURCHASED = '2026-10-29T12:00:00Z';

    private Business $business;
    private int $incidentId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = $this->makeBusiness();
        $this->incidentId = app(ProvisioningIncidentRecorder::class)->record(
            $this->business, 'number_order_outcome_unknown', self::PROFILE, null, self::PHONE, 'toll_free', 'order status=503',
        )->id;

        config([
            'services.telnyx.api_key' => 'fixture_api_key_not_a_real_credential',
            'messaging.number_sms_addon_monthly_cost_micro' => 100000,
        ]);

        $this->insertReservation();
    }

    private function insertReservation(): void
    {
        Schema::disableForeignKeyConstraints();
        DB::table('business_usage_reservations')->insert([
            'id' => 21,
            'business_id' => $this->business->id,
            'wallet_id' => 1,
            // As in production: the feature is the meter's canonical one.
            'feature_key' => 'messaging_transport',
            'meter_key' => 'messaging_number_purchase',
            'period_key' => '2026-10',
            'status' => 'committed',
            'reserved_amount_micro' => 2_100_000,
            'paid_attributable_amount_micro' => 0,
            'estimated_quantity' => '2.100000',
            'rate_id' => 1,
            'rate_version' => 1,
            'retail_rate_micro' => 1_000_000,
            'provider_cost_micro' => 1_000_000,
            'rounding_rule' => 'half_up',
            'idempotency_key' => 'fixture-reservation-21',
            'correlation_key' => 'fixture-reservation-21',
            'reserved_at' => now(),
            'expires_at' => now()->addHour(),
            'committed_at' => now(),
            'final_quantity' => '2.100000',
            'final_amount_micro' => 2_100_000,
        ]);
        Schema::enableForeignKeyConstraints();
    }

    /**
     * The real shape: order/number GETs carry no cost_information, and
     * /charges_breakdown returns `results` + `currency` at the top level.
     *
     * @param array<string, mixed> $override
     */
    private function fakeTelnyx(array $override = []): void
    {
        $order = ['id' => self::ORDER, 'status' => 'success', 'user_id' => 'acct-1', 'cost_information' => null, 'phone_numbers' => [
            ['id' => self::ITEM, 'phone_number' => self::PHONE, 'status' => 'success'],
        ]];
        $number = ['id' => self::RESOURCE, 'phone_number' => self::PHONE, 'status' => 'active', 'user_id' => 'acct-1',
            'messaging_profile_id' => self::PROFILE, 'purchased_at' => self::PURCHASED, 'cost_information' => null];
        $profile = ['id' => self::PROFILE];

        $results = $override['results'] ?? [
            $this->billingRow('+15550000001', [['otc', 'numbers', '-5.00000']], 'local-number'),
            $this->billingRow(self::PHONE),
            $this->billingRow('+15550000002', [['otc', 'numbers', '-1.00000']]),
        ];

        Http::fake([
            'api.telnyx.com/v2/number_orders/*' => Http::response(['data' => array_replace($order, $override['order'] ?? [])]),
            'api.telnyx.com/v2/phone_numbers/*' => Http::response(['data' => array_replace($number, $override['number'] ?? [])]),
            'api.telnyx.com/v2/messaging_profiles/*' => Http::response(['data' => $profile]),
            'api.telnyx.com/v2/charges_breakdown*' => Http::response(array_replace([
                'user_id' => 'acct-1',
                'user_email' => 'owner@example.test',
                'start_date' => '2026-10-01',
                'end_date' => '2026-11-01',
                'results' => $results,
                'currency' => 'USD',
            ], $override['report'] ?? [], isset($override['currency']) ? ['currency' => $override['currency']] : [])),
        ]);
    }

    /**
     * One grouped billing row, as Telnyx returns it.
     *
     * @param list<array{0: string, 1: string, 2: string}>|null $services [cost_type, name, cost]
     * @return array<string, mixed>
     */
    private function billingRow(string $tn, ?array $services = null, string $chargeType = 'toll-free-number'): array
    {
        $services ??= [['otc', 'numbers', '-1.00000'], ['partial-mrc', 'numbers', '-0.09677'], ['partial-mrc', 'SMS', '-0.00968']];

        return [
            'tn' => $tn,
            'charge_type' => $chargeType,
            'services' => array_map(fn (array $s): array => ['cost_type' => $s[0], 'name' => $s[1], 'cost' => $s[2]], $services),
        ];
    }

    /** @param array<string, mixed> $options overrides or (null) removals of the explicit target options */
    private function run_(bool $execute = true, array $options = [])
    {
        $args = array_filter(array_merge([
            '--business' => (string) $this->business->id,
            '--incident' => (string) $this->incidentId,
            '--order' => self::ORDER,
            '--order-item' => self::ITEM,
            '--phone' => self::PHONE,
            '--resource' => self::RESOURCE,
            '--profile' => self::PROFILE,
            '--reservation' => '21',
            '--reservation-amount-micro' => '2100000',
        ], $options), fn ($v) => $v !== null);
        $args[$execute ? '--execute' : '--dry-run'] = true;

        return $this->artisan('messaging:recover-telnyx-order', $args);
    }

    private function assertNothingWritten(): void
    {
        $this->assertSame(0, BusinessMessagingIdentity::query()->count());
        $this->assertSame(0, BusinessMessagingNumber::query()->count());
        $this->assertNull(DB::table('business_messaging_provisioning_incidents')->where('id', $this->incidentId)->value('resolved_at'));
    }

    private function assertOnlyGetsSent(): void
    {
        Http::assertSent(fn ($request) => $request->method() === 'GET');
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_dry_run_verifies_and_writes_nothing(): void
    {
        $this->fakeTelnyx();

        $this->run_(false)->assertExitCode(0);

        $this->assertNothingWritten();
        $this->assertOnlyGetsSent();
        Http::assertSent(fn ($request) => str_contains($request->url(), 'charges_breakdown')
            && str_contains($request->url(), 'start_date=2026-10-01')
            && str_contains($request->url(), 'end_date=2026-11-01'));
    }

    public function test_execute_reconciles_identity_number_costs_timing_and_resolves_the_incident(): void
    {
        $this->fakeTelnyx();

        $this->run_()->assertExitCode(0);

        $identity = BusinessMessagingIdentity::query()->sole();
        $this->assertSame((int) $this->business->id, (int) $identity->business_id);
        $this->assertSame(self::PROFILE, $identity->messaging_profile_id);

        $number = BusinessMessagingNumber::query()->sole();
        $this->assertSame(self::PHONE, $number->phone_number);
        $this->assertSame(self::RESOURCE, $number->provider_number_reference);
        $this->assertSame('toll_free', $number->number_type->value);
        $this->assertSame('active', $number->status->value);
        $this->assertTrue((bool) $number->is_primary);
        $this->assertSame(1_000_000, (int) $number->upfront_cost_micro);
        $this->assertSame(1_100_000, (int) $number->monthly_cost_micro);
        $this->assertSame('USD', $number->cost_currency);

        $purchased = Carbon::parse(self::PURCHASED);
        $this->assertSame($purchased->getTimestamp(), $number->activated_at->getTimestamp());
        // No automatic renewal is scheduled until the billing decision is made.
        $this->assertNull($number->next_renewal_at);

        $incident = DB::table('business_messaging_provisioning_incidents')->where('id', $this->incidentId)->first();
        $this->assertNotNull($incident->resolved_at);
        $this->assertNull($incident->resolved_by_user_id);
        $this->assertStringContainsString('reservation #21', $incident->resolution_note);
        $this->assertStringContainsString(self::RESOURCE, $incident->resolution_note);
        $this->assertStringContainsString('$1.10645', $incident->resolution_note);

        // Reservation #21 is preserved, and no second one exists.
        $this->assertSame(1, DB::table('business_usage_reservations')->count());
        $this->assertSame('committed', DB::table('business_usage_reservations')->where('id', 21)->value('status'));
        $this->assertOnlyGetsSent();
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
        $this->assertSame(1, DB::table('business_usage_reservations')->count());
    }

    public function test_carrier_mismatch_aborts_without_writing(): void
    {
        foreach ([
            ['number' => ['status' => 'inactive']],
            ['number' => ['messaging_profile_id' => 'some-other-profile']],
            ['number' => ['id' => '999']],
            ['order' => ['status' => 'pending']],
            ['order' => ['user_id' => 'acct-2']],
        ] as $override) {
            $this->fakeTelnyx($override);
            $this->run_()->assertExitCode(1);
            $this->assertNothingWritten();
        }
    }

    public function test_missing_ambiguous_or_inconsistent_billing_records_fail_closed(): void
    {
        $otc = ['otc', 'numbers', '-1.00000'];
        $mrc = ['partial-mrc', 'numbers', '-0.09677'];
        $sms = ['partial-mrc', 'SMS', '-0.00968'];
        $mine = fn (array $services, string $type = 'toll-free-number') => ['results' => [$this->billingRow('+15550000001'), $this->billingRow(self::PHONE, $services, $type)]];

        foreach ([
            'no results' => ['results' => []],
            'no row for the number' => ['results' => [$this->billingRow('+15550000001')]],
            'duplicate matching rows' => ['results' => [$this->billingRow(self::PHONE), $this->billingRow(self::PHONE)]],
            'wrong charge type' => $mine([$otc, $mrc, $sms], 'local-number'),
            'missing SMS service' => $mine([$otc, $mrc]),
            'missing otc service' => $mine([$mrc, $sms]),
            'duplicate service' => $mine([$otc, $mrc, $mrc]),
            'extra service' => $mine([$otc, $mrc, $sms, ['partial-mrc', 'voice', '-0.01000']]),
            'unexpected service in place of one' => $mine([$otc, $mrc, ['usage', 'SMS', '-0.00968']]),
            'credit instead of debit' => $mine([$otc, $mrc, ['partial-mrc', 'SMS', '0.00968']]),
            'malformed cost' => $mine([$otc, $mrc, ['partial-mrc', 'SMS', 'n/a']]),
            'six-decimal ambiguity' => $mine([$otc, $mrc, ['partial-mrc', 'SMS', '-0.009681']]),
            'wrong proration' => $mine([$otc, ['partial-mrc', 'numbers', '-0.50000'], $sms]),
            'wrong one-time fee' => $mine([['otc', 'numbers', '-2.00000'], $mrc, $sms]),
            'services not an array' => ['results' => [['tn' => self::PHONE, 'charge_type' => 'toll-free-number', 'services' => 'x']]],
            'wrong currency' => ['currency' => 'EUR'],
            'other account' => ['report' => ['user_id' => 'acct-2']],
            'report for another month' => ['report' => ['start_date' => '2026-09-01', 'end_date' => '2026-10-01']],
            'report extends past the exclusive end' => ['report' => ['end_date' => '2026-11-02']],
            'row names another resource' => ['results' => [$this->billingRow(self::PHONE) + ['phone_number_id' => '999']]],
        ] as $override) {
            $this->fakeTelnyx($override);
            $this->run_()->assertExitCode(1);
            $this->assertNothingWritten();
        }
    }

    public function test_billing_must_agree_with_the_original_reservation(): void
    {
        // A reservation that charged more/less than one-time + full month + SMS add-on cannot yield a matching proration.
        DB::table('business_usage_reservations')->where('id', 21)->update(['final_quantity' => '3.100000', 'estimated_quantity' => '3.100000', 'final_amount_micro' => 2_100_000]);
        $this->fakeTelnyx();
        $this->run_()->assertExitCode(1);
        $this->assertNothingWritten();
    }

    public function test_dry_run_reports_the_wallet_versus_carrier_difference_and_never_credits(): void
    {
        $this->fakeTelnyx();

        $this->run_(false)
            ->expectsOutputToContain('$2.10000')
            ->expectsOutputToContain('$1.10645')
            ->expectsOutputToContain('$0.99355')
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('business_usage_ledger_entries')->count());
        $this->assertSame(1, DB::table('business_usage_reservations')->count());
    }

    public function test_existing_identity_or_number_collision_aborts_without_writing(): void
    {
        $this->fakeTelnyx();

        // Another Business already holds the number.
        [, , $held] = $this->managedBusiness(self::PHONE, 'mp_other');
        $this->run_()->assertExitCode(1);
        $this->assertSame(1, BusinessMessagingNumber::query()->count());
        $held->delete();

        // This Business already has an identity.
        $this->attachIdentity($this->business);
        $this->run_()->assertExitCode(1);
        $this->assertSame(0, BusinessMessagingNumber::query()->count());
        $this->assertNull(DB::table('business_messaging_provisioning_incidents')->where('id', $this->incidentId)->value('resolved_at'));
    }

    public function test_missing_or_malformed_options_are_refused_before_any_call(): void
    {
        Http::fake();

        foreach ([
            ['--order' => null],
            ['--reservation' => null],
            ['--order' => 'some-other-order'],
            ['--order' => self::ITEM, '--order-item' => self::ITEM],
            ['--phone' => '+14155550100'],
            ['--resource' => 'abc'],
            ['--profile' => 'not-a-uuid'],
            ['--reservation-amount-micro' => '-1'],
            ['--business' => '0'],
        ] as $options) {
            $this->run_(true, $options)->assertExitCode(1);
        }

        $this->run_(true, ['--incident' => (string) ($this->incidentId + 1)])->assertExitCode(1);
        $this->run_(true, ['--business' => (string) ($this->business->id + 1)])->assertExitCode(1);

        Http::assertNothingSent();
        $this->assertNothingWritten();
    }

    public function test_a_supplied_order_item_must_be_the_orders_own_entry_for_the_number(): void
    {
        $this->fakeTelnyx();

        $this->run_(true, ['--order-item' => '00000000-0000-4000-8000-000000000000'])->assertExitCode(1);
        $this->assertNothingWritten();
    }

    public function test_a_missing_or_uncommitted_original_reservation_is_refused_on_dry_run_and_execute(): void
    {
        $this->fakeTelnyx();

        foreach ([false, true] as $execute) {
            // No reservation with that id.
            $this->run_($execute, ['--reservation' => '9999'])->assertExitCode(1);

            // Present but not committed.
            DB::table('business_usage_reservations')->where('id', 21)->update(['status' => 'released']);
            $this->run_($execute)->assertExitCode(1);
            DB::table('business_usage_reservations')->where('id', 21)->update(['status' => 'committed']);

            // Committed for a different amount than the operator stated.
            $this->run_($execute, ['--reservation-amount-micro' => '1000000'])->assertExitCode(1);

            // Belonging to another Business.
            DB::table('business_usage_reservations')->where('id', 21)->update(['business_id' => $this->business->id + 100]);
            $this->run_($execute)->assertExitCode(1);
            DB::table('business_usage_reservations')->where('id', 21)->update(['business_id' => $this->business->id]);
        }

        $this->assertNothingWritten();
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_an_unresolved_carrier_status_is_never_reconciled(): void
    {
        foreach ([
            ['order' => ['status' => 'pending']],
            ['order' => ['phone_numbers' => [['id' => self::ITEM, 'phone_number' => self::PHONE, 'status' => 'pending']]]],
            ['order' => ['phone_numbers' => [['id' => self::ITEM, 'phone_number' => self::PHONE, 'status' => 'failure']]]],
            ['number' => ['status' => 'port-in-pending']],
        ] as $override) {
            $this->fakeTelnyx($override);
            $this->run_()->assertExitCode(1);
            $this->run_(false)->assertExitCode(1);
            $this->assertNothingWritten();
        }
    }

    public function test_reservation_with_the_wrong_meter_or_feature_is_refused(): void
    {
        $this->fakeTelnyx();

        foreach ([['meter_key' => 'messaging_number_rental_renewal'], ['feature_key' => 'messaging_number_purchase']] as $change) {
            DB::table('business_usage_reservations')->where('id', 21)->update($change);
            $this->run_()->assertExitCode(1);
            $this->assertNothingWritten();
            DB::table('business_usage_reservations')->where('id', 21)->update(['meter_key' => 'messaging_number_purchase', 'feature_key' => 'messaging_transport']);
        }
    }

    public function test_incident_must_be_the_unknown_order_stage_with_exact_phone_and_profile(): void
    {
        $this->fakeTelnyx();

        foreach ([
            ['stage' => 'number_attach_failed_after_provider_success'],
            ['phone_number' => null],
            ['phone_number' => '+18446980766'],
            ['messaging_profile_id' => null],
            ['messaging_profile_id' => 'another-profile'],
        ] as $change) {
            $original = (array) DB::table('business_messaging_provisioning_incidents')->where('id', $this->incidentId)->first();
            DB::table('business_messaging_provisioning_incidents')->where('id', $this->incidentId)->update($change);
            $this->run_()->assertExitCode(1);
            $this->assertNothingWritten();
            DB::table('business_messaging_provisioning_incidents')->where('id', $this->incidentId)->update(array_intersect_key($original, $change));
        }
    }

    public function test_partial_state_with_a_resolved_incident_is_not_treated_as_already_reconciled(): void
    {
        $this->fakeTelnyx();
        $this->run_()->assertExitCode(0);
        Http::fake();

        // Number, identity and a resolved incident exist — but the number is no longer the active primary.
        BusinessMessagingNumber::query()->update(['is_primary' => false]);
        $this->run_()->assertExitCode(1);
        BusinessMessagingNumber::query()->update(['is_primary' => true]);

        // Resolved by someone else (no recovery audit note): not ours to call done.
        DB::table('business_messaging_provisioning_incidents')->where('id', $this->incidentId)->update(['resolution_note' => 'closed by hand']);
        $this->run_()->assertExitCode(1);

        Http::assertNothingSent();
        $this->assertSame(1, BusinessMessagingNumber::query()->count());
    }
}

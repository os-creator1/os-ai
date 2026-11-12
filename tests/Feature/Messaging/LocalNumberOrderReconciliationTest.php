<?php

namespace Tests\Feature\Messaging;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Messaging\MessagingRegistrationStatus;
use App\Library\Messaging\Contracts\MessagingProvisioningAdapter;
use App\Library\Messaging\FakeProvisioningAdapter;
use App\Library\Messaging\ProvisioningIncidentRecorder;
use App\Models\Business;
use App\Models\BusinessMessagingIdentity;
use App\Models\BusinessMessagingNumber;
use App\Models\BusinessMessagingRegistration;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * A LOCAL number the carrier accepted but reported "still pending" (incident stage number_order_outcome_unknown, the
 * shape of production incident 6 / Business 2 / +17206640969). Covers the guarded recovery command for local numbers,
 * the automatic reconciler (bounded retries, escalation, no duplicate charge) and the customer-facing state.
 * Telnyx is faked; nothing here can buy, charge or refund.
 */
class LocalNumberOrderReconciliationTest extends TestCase
{
    use CreatesCustomerContextFixtures;
    use CreatesMessagingFixtures;
    use RefreshDatabase;

    private const PHONE = '+17206640969';
    private const RESOURCE = '3082808573835609999';
    private const PROFILE = '4001a191-a73e-4277-baef-197ca13a0a5d';
    private const ITEM = '1d312144-9c5c-4978-a50f-a2b206807fb9';
    private const PARENT = 'a1b2c3d4-5555-4666-8777-parentorder01';
    private const PURCHASED = '2026-10-30T12:00:00Z';
    private const CONN = 'conn_callcontrol_1';

    private Business $business;
    private int $incidentId;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-31 12:00:00');
        config([
            'services.telnyx.api_key' => 'fixture_api_key_not_a_real_credential',
            'services.telnyx.mode' => 'live',
            'messaging.number_sms_addon_monthly_cost_micro' => 100000,
            'messaging.order_reconciliation.enabled' => true,
            'messaging.voice.connection_id' => self::CONN,
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

    private function makeIncident(Business $business, string $type = 'local'): int
    {
        return app(ProvisioningIncidentRecorder::class)->record(
            $business, 'number_order_outcome_unknown', self::PROFILE, null, self::PHONE, $type, 'order accepted but still pending',
        )->id;
    }

    private function insertReservation(Business $business, string $key, int $amount = 2_000_000): int
    {
        Schema::disableForeignKeyConstraints();
        $id = DB::table('business_usage_reservations')->insertGetId([
            'business_id' => $business->id, 'wallet_id' => 1,
            'feature_key' => 'messaging_transport', 'meter_key' => 'messaging_number_purchase',
            'period_key' => '2026-10', 'status' => 'committed',
            'reserved_amount_micro' => $amount, 'paid_attributable_amount_micro' => 0, 'estimated_quantity' => number_format($amount / 1_000_000, 6, '.', ''),
            'rate_id' => 1, 'rate_version' => 1, 'retail_rate_micro' => 1_000_000, 'provider_cost_micro' => 1_000_000,
            'rounding_rule' => 'half_up', 'idempotency_key' => $key, 'correlation_key' => $key,
            'reserved_at' => now(), 'expires_at' => now()->addHour(), 'committed_at' => now(),
            'final_quantity' => number_format($amount / 1_000_000, 6, '.', ''), 'final_amount_micro' => $amount,
        ]);
        Schema::enableForeignKeyConstraints();

        return $id;
    }

    /**
     * One-time $1.00 + $1.00/month local number (no SMS add-on line), purchased 2 days before month end.
     *
     * @param array<string, mixed> $over
     */
    private function fakeTelnyx(array $over = []): void
    {
        $services = $over['services'] ?? [
            ['cost_type' => 'otc', 'name' => 'numbers', 'cost' => '-1.00000'],
            ['cost_type' => 'partial-mrc', 'name' => 'numbers', 'cost' => '-0.06452'],
        ];
        $item = ['id' => self::ITEM, 'phone_number' => self::PHONE, 'status' => $over['item_status'] ?? 'success', 'order_request_id' => self::PARENT];
        $items = $over['items'] ?? [$item];
        $list = $over['list'] ?? [['id' => self::RESOURCE, 'phone_number' => self::PHONE, 'status' => 'active']];
        $order = array_replace(['id' => self::PARENT, 'status' => $over['order_status'] ?? 'success', 'user_id' => 'acct-1', 'phone_numbers' => [
            ['id' => self::ITEM, 'phone_number' => self::PHONE, 'status' => $over['item_status'] ?? 'success'],
        ]], $over['order'] ?? []);
        $number = array_replace(['id' => self::RESOURCE, 'phone_number' => self::PHONE, 'status' => 'active', 'user_id' => 'acct-1',
            'messaging_profile_id' => self::PROFILE, 'purchased_at' => self::PURCHASED], $over['number'] ?? []);
        $report = ['user_id' => 'acct-1', 'start_date' => '2026-10-01', 'end_date' => '2026-11-01', 'currency' => 'USD',
            'results' => $over['results'] ?? [['tn' => self::PHONE, 'charge_type' => 'local-number', 'services' => $services]]];

        Http::fake(function (Request $request) use ($list, $items, $order, $number, $report) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                $path === '/v2/phone_numbers' => Http::response(['data' => $list]),
                // The voice assignment PATCH reports the connection back, as Telnyx does.
                $path === '/v2/phone_numbers/' . self::RESOURCE && $request->method() === 'PATCH' => Http::response(['data' => $number + ['connection_id' => self::CONN]]),
                $path === '/v2/phone_numbers/' . self::RESOURCE => Http::response(['data' => $number]),
                $path === '/v2/number_order_phone_numbers' => Http::response(['data' => $items]),
                $path === '/v2/number_orders/' . self::PARENT => Http::response(['data' => $order]),
                $path === '/v2/messaging_profiles/' . self::PROFILE => Http::response(['data' => ['id' => self::PROFILE]]),
                $path === '/v2/charges_breakdown' => Http::response($report),
                default => Http::response(['errors' => [['code' => '10005']]], 404),
            };
        });
    }

    private function recover(bool $execute = true)
    {
        return $this->artisan('messaging:recover-pending-number', [
            '--business' => $this->business->id, '--incident' => $this->incidentId, $execute ? '--execute' : '--dry-run' => true,
        ]);
    }

    private function incident(): object
    {
        return DB::table('business_messaging_provisioning_incidents')->where('id', $this->incidentId)->first();
    }

    private function assertNothingWritten(): void
    {
        $this->assertSame(0, BusinessMessagingIdentity::query()->count());
        $this->assertSame(0, BusinessMessagingNumber::query()->count());
        $this->assertNull($this->incident()->resolved_at);
    }

    private function approvedRegistration(): void
    {
        $registration = new BusinessMessagingRegistration(['business_id' => $this->business->id, 'number_type' => 'local', 'status' => MessagingRegistrationStatus::Approved->value]);
        $registration->forceFill(['provider_campaign_id' => 'campaign_approved_1'])->save();
    }

    // ------------------------------------------------------------ the guarded recovery command, local numbers

    public function test_dry_run_verifies_the_local_incident_and_writes_nothing(): void
    {
        $this->fakeTelnyx();

        $this->recover(false)
            ->expectsOutputToContain('Target: Business #' . $this->business->id . ', incident #' . $this->incidentId . ', number ' . self::PHONE . ', Telnyx resource ' . self::RESOURCE)
            ->expectsOutputToContain('DRY RUN')
            ->assertExitCode(0);

        $this->assertNothingWritten();
        Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET');
    }

    public function test_execute_attaches_the_local_number_preserves_the_charge_links_the_campaign_and_assigns_voice(): void
    {
        $this->fakeTelnyx();
        $this->approvedRegistration();
        $this->app->instance(MessagingProvisioningAdapter::class, new FakeProvisioningAdapter());
        config(['messaging.managed_messaging_enabled' => true, 'messaging.managed_messaging_provisioning_enabled' => true]);
        $reservations = DB::table('business_usage_reservations')->count();

        $this->recover()->assertExitCode(0);

        $number = BusinessMessagingNumber::query()->sole();
        $this->assertSame(self::PHONE, $number->phone_number);
        $this->assertSame('local', $number->number_type->value);
        $this->assertSame(self::RESOURCE, $number->provider_number_reference, 'The phone-number RESOURCE id, not an order-item id.');
        $this->assertSame(1_000_000, (int) $number->upfront_cost_micro);
        $this->assertSame(1_000_000, (int) $number->monthly_cost_micro);
        $this->assertSame(Carbon::parse(self::PURCHASED)->addMonthNoOverflow()->getTimestamp(), $number->next_renewal_at->getTimestamp());
        $this->assertSame(self::PROFILE, BusinessMessagingIdentity::query()->sole()->messaging_profile_id);
        $this->assertNotNull($this->incident()->resolved_at);

        // The money is preserved exactly: no new reservation, no second purchase request of any kind.
        $this->assertSame($reservations, DB::table('business_usage_reservations')->count());
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/number_orders') && $r->method() !== 'GET');
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/messaging_profiles'));

        // The approved registration is untouched and the number is linked to its campaign; voice is assigned.
        $this->assertSame('campaign_approved_1', BusinessMessagingRegistration::query()->sole()->provider_campaign_id);
        $this->assertNotNull($number->fresh()->campaign_assignment_status);
        $this->assertNotNull($number->fresh()->voice_assigned_at);
    }

    public function test_running_recovery_again_is_a_no_op(): void
    {
        $this->fakeTelnyx();
        $this->recover()->assertExitCode(0);
        Http::fake();

        $this->recover()->expectsOutputToContain('Already reconciled')->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertSame(1, BusinessMessagingNumber::query()->count());
    }

    public function test_a_still_pending_order_aborts_tagged_pending_and_writes_nothing(): void
    {
        $this->fakeTelnyx(['order_status' => 'pending', 'item_status' => 'pending', 'list' => [], 'items' => []]);

        $this->recover()->expectsOutputToContain('[pending]')->assertExitCode(1);

        $this->assertNothingWritten();
    }

    public function test_a_pending_order_that_exists_at_the_carrier_is_tagged_pending_too(): void
    {
        $this->fakeTelnyx(['order_status' => 'pending', 'item_status' => 'pending']);

        $this->recover()->expectsOutputToContain('[pending]')->assertExitCode(1);

        $this->assertNothingWritten();
    }

    public function test_billing_that_does_not_match_the_charge_aborts_without_writing(): void
    {
        $this->fakeTelnyx();
        DB::table('business_usage_reservations')->update(['final_quantity' => '2.500000', 'estimated_quantity' => '2.500000', 'final_amount_micro' => 2_500_000, 'reserved_amount_micro' => 2_500_000]);

        $this->recover()->expectsOutputToContain('Pricing mismatch')->assertExitCode(1);

        $this->assertNothingWritten();
    }

    public function test_a_toll_free_billing_row_is_refused_for_a_local_incident(): void
    {
        $this->fakeTelnyx(['results' => [['tn' => self::PHONE, 'charge_type' => 'toll-free-number', 'services' => []]]]);

        $this->recover()->expectsOutputToContain('toll-free charge, not a local-number charge')->assertExitCode(1);

        $this->assertNothingWritten();
    }

    public function test_a_number_on_a_different_messaging_profile_is_refused(): void
    {
        $this->fakeTelnyx(['number' => ['messaging_profile_id' => 'some-other-profile']]);

        $this->recover()->assertExitCode(1);

        $this->assertNothingWritten();
    }

    // ------------------------------------------------------------ the automatic reconciler

    public function test_the_reconciler_finishes_a_confirmed_order_exactly_once(): void
    {
        $this->fakeTelnyx();

        $this->artisan('messaging:reconcile-pending-orders')->expectsOutputToContain('Reconciled')->assertExitCode(0);
        $this->assertNotNull($this->incident()->resolved_at);
        $this->assertSame(1, BusinessMessagingNumber::query()->count());

        // A second run finds nothing open: no further Telnyx call, no duplicate.
        Http::fake();
        $this->artisan('messaging:reconcile-pending-orders')->expectsOutputToContain('No open local')->assertExitCode(0);
        Http::assertNothingSent();
        $this->assertSame(1, BusinessMessagingNumber::query()->count());
    }

    public function test_a_pending_order_is_retried_with_backoff_then_completes_when_the_carrier_confirms(): void
    {
        $this->fakeTelnyx(['order_status' => 'pending', 'item_status' => 'pending', 'list' => [], 'items' => []]);

        $this->artisan('messaging:reconcile-pending-orders')->assertExitCode(0);
        $row = $this->incident();
        $this->assertSame(1, (int) $row->reconcile_attempts);
        $this->assertSame('pending', $row->reconcile_outcome);
        $this->assertNull($row->escalated_at);
        $this->assertEqualsWithDelta(2, now()->diffInMinutes(Carbon::parse($row->reconcile_next_attempt_at), false), 0.5, 'Backoff starts at two minutes.');
        $this->assertNull($row->resolved_at);

        // Not due yet: the next run does not touch it (no attempt consumed, no Telnyx call).
        Http::fake();
        $this->artisan('messaging:reconcile-pending-orders')->expectsOutputToContain('No open local')->assertExitCode(0);
        Http::assertNothingSent();
        $this->assertSame(1, (int) $this->incident()->reconcile_attempts);

        // The carrier confirms; the next due run finishes the setup.
        Carbon::setTestNow(now()->addMinutes(3));
        Http::swap(new \Illuminate\Http\Client\Factory()); // drop the earlier catch-all stub
        $this->fakeTelnyx();
        $this->artisan('messaging:reconcile-pending-orders')->assertExitCode(0);

        $this->assertNotNull($this->incident()->resolved_at);
        $this->assertSame(1, BusinessMessagingNumber::query()->count());
    }

    public function test_an_unresolved_order_is_escalated_after_the_attempt_limit_and_then_left_to_an_operator(): void
    {
        config(['messaging.order_reconciliation.max_attempts' => 2]);
        $this->fakeTelnyx(['order_status' => 'pending', 'item_status' => 'pending', 'list' => [], 'items' => []]);

        $this->artisan('messaging:reconcile-pending-orders')->assertExitCode(0);
        Carbon::setTestNow(now()->addMinutes(5));
        $this->artisan('messaging:reconcile-pending-orders')->expectsOutputToContain('ESCALATED')->assertExitCode(0);

        $row = $this->incident();
        $this->assertSame(2, (int) $row->reconcile_attempts);
        $this->assertNotNull($row->escalated_at);
        $this->assertNull($row->reconcile_next_attempt_at);
        $this->assertNull($row->resolved_at);

        // Escalated cases are not retried automatically (an operator owns them).
        Carbon::setTestNow(now()->addHours(1));
        Http::fake();
        $this->artisan('messaging:reconcile-pending-orders')->assertExitCode(0);
        Http::assertNothingSent();
    }

    public function test_the_reconciler_is_off_by_default_and_dry_run_never_writes_or_consumes_attempts(): void
    {
        $this->fakeTelnyx();
        config(['messaging.order_reconciliation.enabled' => false]);

        $this->artisan('messaging:reconcile-pending-orders')->expectsOutputToContain('disabled')->assertExitCode(0);
        $this->assertNothingWritten();

        $this->artisan('messaging:reconcile-pending-orders', ['--dry-run' => true])->expectsOutputToContain('Verified: --execute would finish this setup')->assertExitCode(0);
        $this->assertNothingWritten();
        $this->assertSame(0, (int) $this->incident()->reconcile_attempts);
        Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET');
    }

    public function test_toll_free_incidents_are_left_to_the_manual_command(): void
    {
        DB::table('business_messaging_provisioning_incidents')->where('id', $this->incidentId)->update(['number_type' => 'toll_free']);
        $this->fakeTelnyx();

        $this->artisan('messaging:reconcile-pending-orders')->expectsOutputToContain('No open local')->assertExitCode(0);

        $this->assertNothingWritten();
    }

    public function test_an_attempt_already_leased_by_another_run_is_not_repeated(): void
    {
        $this->fakeTelnyx();
        DB::table('business_messaging_provisioning_incidents')->where('id', $this->incidentId)->update(['reconcile_next_attempt_at' => now()->addMinutes(8)]);

        $this->artisan('messaging:reconcile-pending-orders')->assertExitCode(0);

        $this->assertNothingWritten();
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------ what the customer sees

    public function test_the_customer_sees_confirming_your_number_then_the_escalation_and_failure_states(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);
        $incident = app(ProvisioningIncidentRecorder::class)->record($business, 'number_order_outcome_unknown', self::PROFILE, null, self::PHONE, 'local', 'order accepted but still pending')->id;
        $url = route('customer.workspaces.businesses.text-messaging.show', [$workspace->uid, $business->uid]);

        $this->get($url)->assertOk()->assertSee('Activating your number')->assertSee(self::PHONE)
            ->assertSee('you will not be charged again')->assertSee('You can leave this page', false)->assertDontSee('Get a texting number')->assertSee('data-state="confirming"', false);

        DB::table('business_messaging_provisioning_incidents')->where('id', $incident)->update(['escalated_at' => now(), 'reconcile_outcome' => 'pending']);
        $this->get($url)->assertOk()->assertSee('taking longer than usual')->assertSee('data-state="escalated"', false);

        DB::table('business_messaging_provisioning_incidents')->where('id', $incident)->update(['reconcile_outcome' => 'failed', 'escalated_at' => null]);
        $this->get($url)->assertOk()->assertSee('could not complete the order')->assertSee('returned to your balance automatically', false)->assertSee('data-state="failed"', false);

        DB::table('business_messaging_provisioning_incidents')->where('id', $incident)->update(['escalated_at' => now()]);
        $this->get($url)->assertOk()->assertSee('returning the payment', false)->assertSee('data-state="failed_review"', false);
    }

    public function test_a_resolved_incident_no_longer_shows_the_confirming_state(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);
        $incident = app(ProvisioningIncidentRecorder::class)->record($business, 'number_order_outcome_unknown', self::PROFILE, null, self::PHONE, 'local', 'x')->id;
        app(ProvisioningIncidentRecorder::class)->resolve($incident, null, 'resolved');

        $this->get(route('customer.workspaces.businesses.text-messaging.show', [$workspace->uid, $business->uid]))
            ->assertOk()->assertDontSee('data-role="number-order-pending"', false);
    }

    public function test_an_order_the_carrier_has_not_confirmed_lands_on_the_confirming_page_not_an_error(): void
    {
        // The real adapter records the incident (and commits the held charge) before it throws; emulate both.
        $adapter = new class extends FakeProvisioningAdapter {
            public function provisionNumber(Business $business, \App\Library\Messaging\DTO\AvailableNumberCandidate $candidate): \App\Library\Messaging\DTO\ProvisionedNumberResult
            {
                app(ProvisioningIncidentRecorder::class)->record($business, 'number_order_outcome_unknown', 'profile-x', null, $candidate->phoneNumber, $candidate->numberType->value, 'order accepted but still pending');

                throw new \App\Library\Messaging\Exceptions\MessagingRegistrationRecoveryRequiredException('The number order outcome could not be confirmed.');
            }
        };
        $this->app->instance(MessagingProvisioningAdapter::class, $adapter);
        config(['messaging.managed_messaging_enabled' => true, 'messaging.managed_messaging_provisioning_enabled' => true]);

        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);
        $token = \App\Library\Messaging\CandidateToken::encode($business, new \App\Library\Messaging\DTO\AvailableNumberCandidate('+17206640969', \App\Enums\Messaging\PhoneNumberType::TollFree, 'cand-1'));
        $show = route('customer.workspaces.businesses.text-messaging.show', [$workspace->uid, $business->uid]);

        $response = $this->post(route('customer.workspaces.businesses.text-messaging.number.order', [$workspace->uid, $business->uid]), ['candidate_token' => $token]);

        $response->assertRedirect($show);
        $this->assertNull(session('status'), 'The customer is not shown a purchase error.');
        $this->get($show)->assertOk()->assertSee('Activating your number')->assertSee('+17206640969');
        $this->assertSame(0, BusinessMessagingNumber::query()->count());
    }

    // ------------------------------------------------------------ a carrier-failed order: safe, single, idempotent refund

    private function walletActor(): int
    {
        $actor = $this->platformAdminId();
        if (\App\Models\Currency::query()->where('code', 'USD')->doesntExist()) {
            \App\Models\Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        }
        app(\App\Library\Usage\UsageWalletManager::class)->initializeWalletForNewBusiness($this->business->id);
        config(['messaging.reconciliation.actor_user_id' => $actor]);

        return $actor;
    }

    private function credits(): \Illuminate\Support\Collection
    {
        return DB::table('business_usage_ledger_entries')->where('business_id', $this->business->id)->where('entry_type', 'manual_credit')->get();
    }

    private function failedFake(array $extra = []): void
    {
        // The carrier says the order failed and lists no such number on the account.
        $this->fakeTelnyx($extra + ['order_status' => 'failed', 'item_status' => 'failure', 'list' => []]);
    }

    public function test_a_carrier_failed_order_is_not_acted_on_from_one_sighting(): void
    {
        $this->walletActor();
        $this->failedFake();

        $this->artisan('messaging:reconcile-pending-orders')->assertExitCode(0);

        $row = $this->incident();
        $this->assertSame('failed', $row->reconcile_outcome);
        $this->assertNull($row->escalated_at);
        $this->assertNull($row->resolved_at);
        $this->assertSame(0, $this->credits()->count(), 'One sighting never credits.');
        $this->assertSame(0, BusinessMessagingNumber::query()->count());
    }

    public function test_a_second_consecutive_carrier_failure_returns_the_charge_once_and_unblocks_the_customer(): void
    {
        $this->walletActor();
        $this->failedFake();
        $this->artisan('messaging:reconcile-pending-orders')->assertExitCode(0);

        Carbon::setTestNow(now()->addMinutes(3));
        $this->artisan('messaging:reconcile-pending-orders')->expectsOutputToContain('charge was returned')->assertExitCode(0);

        $credit = $this->credits();
        $this->assertCount(1, $credit);
        $this->assertSame(2_000_000, (int) $credit->first()->gross_amount_micro, 'Exactly the original committed charge.');
        $row = $this->incident();
        $this->assertNotNull($row->resolved_at);
        $this->assertStringStartsWith('[auto-refunded]', $row->resolution_note);
        $this->assertSame(0, BusinessMessagingNumber::query()->count());

        // Re-running (or a repeated/concurrent run) can never credit again.
        Carbon::setTestNow(now()->addMinutes(10));
        $this->artisan('messaging:reconcile-pending-orders')->assertExitCode(0);
        $this->assertCount(1, $this->credits());
        $this->assertSame(1, DB::table('business_usage_reservations')->count(), 'No new reservation either.');
    }

    public function test_the_refund_is_idempotent_even_if_the_incident_resolution_is_lost(): void
    {
        $actor = $this->walletActor();
        $this->failedFake();
        $refunder = app(\App\Library\Messaging\FailedNumberOrderRefunder::class);

        $first = $refunder->refund($this->incident());
        // Simulate a crash between the credit and the resolution: re-open the incident and refund again.
        DB::table('business_messaging_provisioning_incidents')->where('id', $this->incidentId)->update(['resolved_at' => null, 'resolution_note' => null]);
        $second = $refunder->refund($this->incident());

        $this->assertSame('refunded', $first['status']);
        $this->assertSame('refunded', $second['status']);
        $this->assertCount(1, $this->credits(), 'The same operation id replays the same single ledger entry.');
        $this->assertSame($actor, (int) $this->credits()->first()->actor_user_id);
    }

    /** @return array<string, array{0: string}> */
    public static function unsafeRefunds(): array
    {
        return [
            'no administrator actor configured' => ['no_actor'],
            'number exists at the carrier' => ['number_exists'],
            'charge above the refund limit' => ['over_cap'],
            'local rows already exist' => ['local_rows'],
            'automatic refunds switched off' => ['switched_off'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeRefunds')]
    public function test_an_unsafe_refund_is_never_credited_and_is_escalated_to_operators(string $case): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        \App\Models\User::query()->where('id', $this->platformAdminId())->update(['is_admin' => true]);
        $this->walletActor();
        $this->failedFake($case === 'number_exists' ? ['list' => [['id' => self::RESOURCE, 'phone_number' => self::PHONE, 'status' => 'active']]] : []);

        match ($case) {
            'no_actor' => config(['messaging.reconciliation.actor_user_id' => null]),
            'over_cap' => config(['messaging.order_reconciliation.max_auto_refund_micro' => 1_000_000]),
            'local_rows' => $this->attachNumber($this->attachIdentity($this->business), self::PHONE, true),
            'switched_off' => config(['messaging.order_reconciliation.auto_refund_failed_orders' => false]),
            default => null,
        };

        $this->artisan('messaging:reconcile-pending-orders')->assertExitCode(0);
        Carbon::setTestNow(now()->addMinutes(3));
        $this->artisan('messaging:reconcile-pending-orders')->expectsOutputToContain('ESCALATED')->assertExitCode(0);

        $this->assertCount(0, $this->credits(), 'Nothing is credited unless every safeguard holds.');
        $row = $this->incident();
        $this->assertNotNull($row->escalated_at);
        $this->assertNull($row->resolved_at);
        $this->assertStringContainsString('not refunded automatically', (string) $row->reconcile_detail);
        \Illuminate\Support\Facades\Notification::assertSentTimes(\App\Notifications\Messaging\NumberOrderEscalatedNotification::class, \App\Models\User::query()->where('is_admin', true)->count());
    }

    public function test_an_ambiguous_reservation_blocks_any_refund_and_is_escalated_by_age(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        \App\Models\User::query()->where('id', $this->platformAdminId())->update(['is_admin' => true]);
        $this->walletActor();
        $this->failedFake();
        $this->insertReservation($this->business, 'fixture-res-b'); // two committed purchase charges: which one is this order's?

        $this->artisan('messaging:reconcile-pending-orders')->assertExitCode(0);
        $this->assertSame('blocked', $this->incident()->reconcile_outcome);

        Carbon::setTestNow(now()->addHours(7)); // past the escalation window
        $this->artisan('messaging:reconcile-pending-orders')->expectsOutputToContain('ESCALATED')->assertExitCode(0);

        $this->assertCount(0, $this->credits());
        $this->assertNull($this->incident()->resolved_at);
        $this->assertNotNull($this->incident()->escalated_at);
        \Illuminate\Support\Facades\Notification::assertSentTimes(\App\Notifications\Messaging\NumberOrderEscalatedNotification::class, \App\Models\User::query()->where('is_admin', true)->count());
    }

    public function test_operators_are_alerted_automatically_exactly_once_when_a_pending_order_cannot_be_settled(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        \App\Models\User::query()->where('id', $this->platformAdminId())->update(['is_admin' => true]);
        $admins = \App\Models\User::query()->where('is_admin', true)->count();
        config(['messaging.order_reconciliation.max_attempts' => 2]);
        $this->fakeTelnyx(['order_status' => 'pending', 'item_status' => 'pending', 'list' => [], 'items' => []]);

        $this->artisan('messaging:reconcile-pending-orders')->assertExitCode(0);
        \Illuminate\Support\Facades\Notification::assertNothingSent();

        Carbon::setTestNow(now()->addMinutes(5));
        $this->artisan('messaging:reconcile-pending-orders')->expectsOutputToContain('ESCALATED')->assertExitCode(0);
        \Illuminate\Support\Facades\Notification::assertSentTimes(\App\Notifications\Messaging\NumberOrderEscalatedNotification::class, $admins);

        // Already escalated: automatic runs leave it to the operators and never alert again.
        Carbon::setTestNow(now()->addHours(2));
        $this->artisan('messaging:reconcile-pending-orders')->assertExitCode(0);
        \Illuminate\Support\Facades\Notification::assertSentTimes(\App\Notifications\Messaging\NumberOrderEscalatedNotification::class, $admins);
    }

    // ------------------------------------------------------------ duplicate purchases, persistence, live status

    public function test_a_second_purchase_is_refused_while_an_order_is_pending_without_any_provider_call_or_charge(): void
    {
        $adapter = new FakeProvisioningAdapter();
        $this->app->instance(MessagingProvisioningAdapter::class, $adapter);
        config(['messaging.managed_messaging_enabled' => true, 'messaging.managed_messaging_provisioning_enabled' => true]);
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);
        app(ProvisioningIncidentRecorder::class)->record($business, 'number_order_outcome_unknown', self::PROFILE, null, self::PHONE, 'local', 'order accepted but still pending');
        $show = route('customer.workspaces.businesses.text-messaging.show', [$workspace->uid, $business->uid]);
        $token = \App\Library\Messaging\CandidateToken::encode($business, new \App\Library\Messaging\DTO\AvailableNumberCandidate('+17205550123', \App\Enums\Messaging\PhoneNumberType::TollFree, 'cand-2'));

        $this->post(route('customer.workspaces.businesses.text-messaging.number.order', [$workspace->uid, $business->uid]), ['candidate_token' => $token])->assertRedirect($show);
        $this->post(route('customer.workspaces.businesses.text-messaging.number.search', [$workspace->uid, $business->uid]), ['number_type' => 'toll_free'])->assertRedirect($show);

        $this->assertCount(0, $adapter->provisionedOrders, 'The provider was never asked to buy anything.');
        $this->assertCount(0, $adapter->searches);
        $this->assertSame(0, DB::table('business_usage_reservations')->where('business_id', $business->id)->count());
    }

    public function test_a_purchase_still_running_shows_activating_and_a_duplicate_submit_is_sent_to_that_status(): void
    {
        $adapter = new FakeProvisioningAdapter();
        $this->app->instance(MessagingProvisioningAdapter::class, $adapter);
        config(['messaging.managed_messaging_enabled' => true, 'messaging.managed_messaging_provisioning_enabled' => true]);
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);
        $this->attachIdentity($business, null, \App\Enums\Messaging\BusinessMessagingIdentityStatus::Pending); // the in-flight request's slot
        $show = route('customer.workspaces.businesses.text-messaging.show', [$workspace->uid, $business->uid]);
        $token = \App\Library\Messaging\CandidateToken::encode($business, new \App\Library\Messaging\DTO\AvailableNumberCandidate('+17205550124', \App\Enums\Messaging\PhoneNumberType::TollFree, 'cand-3'));

        $this->get($show)->assertOk()->assertSee('Activating your number')->assertSee('data-state="activating"', false);
        $this->post(route('customer.workspaces.businesses.text-messaging.number.order', [$workspace->uid, $business->uid]), ['candidate_token' => $token])->assertRedirect($show);
        $this->assertCount(0, $adapter->provisionedOrders);
    }

    public function test_the_pending_state_persists_across_a_new_login_and_the_status_endpoint_reports_each_state(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $incident = app(ProvisioningIncidentRecorder::class)->record($business, 'number_order_outcome_unknown', self::PROFILE, null, self::PHONE, 'local', 'x')->id;
        $show = route('customer.workspaces.businesses.text-messaging.show', [$workspace->uid, $business->uid]);
        $status = route('customer.workspaces.businesses.text-messaging.number-order.status', [$workspace->uid, $business->uid]);

        $this->authenticateAs($customer);
        $this->getJson($status)->assertOk()->assertJson(['state' => 'confirming']);

        // A brand-new session (new login, other device): same persisted state, no browser memory involved.
        $this->flushSession();
        $this->authenticateAs($customer);
        $this->get($show)->assertOk()->assertSee('Activating your number')->assertSee(self::PHONE);

        DB::table('business_messaging_provisioning_incidents')->where('id', $incident)->update(['escalated_at' => now(), 'reconcile_outcome' => 'pending']);
        $this->getJson($status)->assertJson(['state' => 'escalated']);
        DB::table('business_messaging_provisioning_incidents')->where('id', $incident)->update(['reconcile_outcome' => 'failed', 'escalated_at' => null]);
        $this->getJson($status)->assertJson(['state' => 'failed']);

        // The reconciler finishes the setup (incident resolved + number attached): the same endpoint now says confirmed.
        app(ProvisioningIncidentRecorder::class)->resolve($incident, null, 'resolved');
        $this->getJson($status)->assertJson(['state' => 'none']);
        $identity = $this->attachIdentity($business);
        $this->attachNumber($identity, self::PHONE, true);
        $this->getJson($status)->assertJson(['state' => 'confirmed']);
    }

    public function test_after_an_automatic_refund_the_purchase_page_says_what_happened_and_offers_a_new_number(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);
        $incident = app(ProvisioningIncidentRecorder::class)->record($business, 'number_order_outcome_unknown', self::PROFILE, null, self::PHONE, 'local', 'x')->id;
        app(ProvisioningIncidentRecorder::class)->resolve($incident, null, '[auto-refunded] credited');

        $this->get(route('customer.workspaces.businesses.text-messaging.show', [$workspace->uid, $business->uid]))
            ->assertOk()->assertSee('payment for it has been returned to your balance')->assertSee('data-role="number-order-refunded"', false);
    }

    public function test_the_buy_button_script_shows_the_activating_state_immediately_and_blocks_a_second_submit(): void
    {
        $script = view('customer.settings.text-messaging._number-flow-script')->render();

        $this->assertStringContainsString('Activating your number…', $script);
        $this->assertStringContainsString("data-submitting", $script);
        $this->assertStringContainsString('You can leave this page', $script);
    }
}

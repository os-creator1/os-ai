<?php

namespace Tests\Feature\Usage;

use App\Enums\Entitlement\PlatformFeature;
use App\Library\Messaging\ManagedDispatchDelegate;
use App\Library\Messaging\ManagedMessageDispatcher;
use App\Library\Usage\UsageWalletManager;
use App\Models\Business;
use App\Models\BusinessUsageMeasurement;
use App\Models\CustomerBasedSendingServer;
use App\Models\Reports;
use App\Models\SendingServer;
use App\Repositories\Contracts\BusinessUsageMeasurementRepository;
use App\Repositories\Eloquent\EloquentBusinessUsageMeasurementRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\TestCase;

/**
 * Customer Experience Slice 3 §4.8 — T-MSG-36 and T-MSG-48.
 *
 * Measurement is not accounting. These two requirements are the ones that
 * keep it that way: nothing about messaging transport may become priced
 * (T-MSG-36), and the one write that does happen must go through RFC-005's
 * own repository layer rather than around it (T-MSG-48).
 */
class MessagingTransportMeasurementLayeringTest extends TestCase
{
    use RefreshDatabase;
    use CreatesMessagingFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->bindFakeAdapter();
    }

    // ---------------------------------------------------------------
    // T-MSG-36 — measured, never priced
    // ---------------------------------------------------------------

    /**
     * The contract's original wording required "no row at all" in
     * `platform_feature_usage_classifications`. That is unreachable and the
     * contract has been corrected in place (§4.8, "Correction —
     * Implementation Round 1", and T-MSG-36's own row in §4.12): the merged
     * migration `2026_08_16_120008_backfill_platform_feature_usage_classifications`
     * inserts one row per `PlatformFeature` case and THROWS if any case
     * lacks one, and merged migrations are not edited.
     *
     * The corrected invariant asserted here is strictly stronger than the
     * original, not a weakening of it: an absent classification row proves
     * nothing about whether a rate was activated somewhere else, whereas
     * "the row exists and is inactive, unmetered and unpriced, and no rate
     * or activation row exists for the feature" is the thing "no retail
     * charging" actually means.
     */
    public function test_the_messaging_transport_classification_is_inactive_unmetered_and_unpriced(): void
    {
        [$business] = $this->managedBusiness();

        // A real managed send first, so this asserts the steady state AFTER
        // measurement has happened — not merely the state of a feature
        // nothing has touched.
        $this->dispatchOnce($business, 'op_classification');

        $this->assertSame(1, DB::table('business_usage_measurements')->count());

        $classification = DB::table('platform_feature_usage_classifications')
            ->where('feature_key', PlatformFeature::MessagingTransport->value)
            ->first();

        $this->assertNotNull(
            $classification,
            'The merged backfill migration classifies every PlatformFeature case; see the §4.8 correction.',
        );
        $this->assertSame(0, (int) $classification->is_metered, 'Messaging transport must never become metered.');
        $this->assertNull($classification->active_rate_id, 'Messaging transport must never carry an active rate.');

        // The row is not special-cased: it sits exactly as every other
        // unpriced feature sits.
        $conversations = DB::table('platform_feature_usage_classifications')
            ->where('feature_key', PlatformFeature::Conversations->value)
            ->first();

        $this->assertSame((int) $conversations->is_metered, (int) $classification->is_metered);
    }

    public function test_no_rate_activation_or_reservation_row_exists_for_messaging_transport(): void
    {
        [$business] = $this->managedBusiness();

        $this->dispatchOnce($business, 'op_no_rate');

        $key = PlatformFeature::MessagingTransport->value;

        // RFC-005 prices by `meter_key`, not by feature key; the two rate
        // tables carry no feature column at all. Asserting these are empty
        // OUTRIGHT is therefore stronger than filtering by messaging
        // transport would have been: it proves the managed send activated no
        // retail pricing anywhere in the system, not merely none under one
        // key. `business_usage_reservations` does carry `feature_key`, so
        // that one is asserted both ways.
        $this->assertSame(
            0,
            DB::table('business_usage_rates')->count(),
            'Slice 3 must never create a retail rate.',
        );
        $this->assertSame(
            0,
            DB::table('business_usage_rate_activations')->count(),
            'Slice 3 must never activate a rate.',
        );
        $this->assertSame(
            0,
            DB::table('business_usage_reservations')->where('feature_key', $key)->count(),
            'Managed transport takes no RFC-005 reservation in Slice 3.',
        );
        $this->assertSame(
            0,
            DB::table('business_usage_reservations')->count(),
            'A managed send takes no reservation of any kind.',
        );
    }

    public function test_no_telecom_feature_becomes_metered_by_a_managed_send(): void
    {
        [$business] = $this->managedBusiness();

        $before = DB::table('platform_feature_usage_classifications')
            ->pluck('is_metered', 'feature_key')
            ->map(fn ($v) => (int) $v)
            ->all();

        $this->dispatchOnce($business, 'op_metering_unchanged');

        $after = DB::table('platform_feature_usage_classifications')
            ->pluck('is_metered', 'feature_key')
            ->map(fn ($v) => (int) $v)
            ->all();

        $this->assertSame($before, $after, 'A managed send must not change any feature\'s metered flag.');
        $this->assertSame(0, $after[PlatformFeature::MessagingTransport->value] ?? -1);
    }

    // ---------------------------------------------------------------
    // T-MSG-48 — the write goes through the repository, at the right layer
    // ---------------------------------------------------------------

    public function test_record_measurement_delegates_to_the_repository(): void
    {
        [$business] = $this->managedBusiness();

        // A spy that IS the real repository except for the one method under
        // test, mirroring how UsageWalletManager's other repository
        // dependencies are already doubled. Subclassing rather than
        // reimplementing the interface keeps the rest of BaseRepository's
        // surface genuine, so this test cannot pass against a repository
        // whose contract has drifted.
        $spy = new class(new BusinessUsageMeasurement()) extends EloquentBusinessUsageMeasurementRepository
        {
            /** @var list<array<string, mixed>> */
            public array $calls = [];

            public function recordOnce(
                Business $business,
                PlatformFeature $featureKey,
                string $quantity,
                string $unit,
                string $idempotencyKey,
                ?string $transportMarker = null,
            ): BusinessUsageMeasurement {
                $this->calls[] = compact('featureKey', 'quantity', 'unit', 'idempotencyKey', 'transportMarker')
                    + ['business_id' => (int) $business->id];

                // Deliberately does NOT call parent::recordOnce(), so the
                // assertion below that nothing was written proves the write
                // happens here and nowhere else.
                return new BusinessUsageMeasurement();
            }
        };

        $this->app->instance(BusinessUsageMeasurementRepository::class, $spy);

        app(UsageWalletManager::class)->recordMeasurement(
            $business,
            PlatformFeature::MessagingTransport,
            '3',
            'segment',
            'op_layering_probe',
            'managed',
        );

        $this->assertCount(1, $spy->calls, 'recordMeasurement() must delegate, not write directly.');
        $this->assertSame(PlatformFeature::MessagingTransport, $spy->calls[0]['featureKey']);
        $this->assertSame('3', $spy->calls[0]['quantity']);
        $this->assertSame('segment', $spy->calls[0]['unit']);
        $this->assertSame('op_layering_probe', $spy->calls[0]['idempotencyKey']);
        $this->assertSame('managed', $spy->calls[0]['transportMarker']);
        $this->assertSame((int) $business->id, $spy->calls[0]['business_id']);

        // Nothing was written behind the repository's back.
        $this->assertSame(0, DB::table('business_usage_measurements')->count());
    }

    public function test_no_messaging_library_class_reaches_the_measurement_table_directly(): void
    {
        $offenders = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path('Library/Messaging'), \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            $relative = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file->getPathname());

            foreach ([
                'business_usage_measurements',
                'BusinessUsageMeasurementRepository',
                'EloquentBusinessUsageMeasurementRepository',
                'BusinessUsageMeasurement',
            ] as $forbidden) {
                if (str_contains($source, $forbidden)) {
                    $offenders[] = "{$relative} references {$forbidden}";
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "app/Library/Messaging/** must reach measurement only through UsageWalletManager::recordMeasurement():\n"
                . implode("\n", $offenders),
        );
    }

    // ---------------------------------------------------------------
    // T-MSG-35 / T-BYO-1 / T-BYO-2 — a BYO send is measured, never charged
    // ---------------------------------------------------------------

    /**
     * §4.7: "BYO transport gets zero platform transport rate or wallet
     * debit; §4.8's recordMeasurement() call with transport_marker: 'byo'
     * is how a BYO send is still measured."
     *
     * These drive `ManagedDispatchDelegate::recordByoMeasurement()` — the
     * seam both production convergence points call at their authoritative
     * successful-dispatch boundary — with the same `Reports` row the legacy
     * provider layer produces.
     */
    public function test_a_byo_send_writes_exactly_one_measurement_marked_byo(): void
    {
        [$business, $server] = $this->byoBusiness();
        $report = $this->deliveredReport($business, $server, smsCount: 3);

        $measured = ManagedDispatchDelegate::recordByoMeasurement($server->id, $report, '3');

        $this->assertTrue($measured, 'A delivered BYO send must be measured.');
        $this->assertSame(1, DB::table('business_usage_measurements')->count());

        $row = DB::table('business_usage_measurements')->first();
        $this->assertSame((int) $business->id, (int) $row->business_id);
        $this->assertSame(PlatformFeature::MessagingTransport->value, $row->feature_key);
        $this->assertSame('byo', $row->transport_marker);
        $this->assertSame('segment', $row->unit);
        $this->assertSame(3.0, (float) $row->quantity, 'The measurement records the real segment count.');
        $this->assertStringContainsString($report->uid, $row->idempotency_key);
    }

    public function test_a_byo_send_takes_no_reservation_no_debit_and_no_cap(): void
    {
        [$business, $server] = $this->byoBusiness();

        $walletBefore = DB::table('business_usage_wallets')->where('business_id', $business->id)->first();

        ManagedDispatchDelegate::recordByoMeasurement($server->id, $this->deliveredReport($business, $server), '1');

        // T-BYO-1, asserted three ways rather than one.
        $this->assertSame(0, DB::table('business_usage_reservations')->count(), 'No reservation.');
        $this->assertSame(0, DB::table('business_usage_ledger_entries')->count(), 'No ledger entry, so no debit.');
        $this->assertSame(0, DB::table('business_usage_rates')->count(), 'No retail rate.');
        $this->assertSame(0, DB::table('business_usage_rate_activations')->count(), 'No activation.');

        // The wallet — and therefore any spending cap measured against it —
        // is byte-identical to before the send.
        $walletAfter = DB::table('business_usage_wallets')->where('business_id', $business->id)->first();
        $this->assertEquals($walletBefore, $walletAfter, 'A BYO send must not move the wallet at all.');
    }

    public function test_repeating_the_same_byo_dispatch_creates_no_second_measurement(): void
    {
        [$business, $server] = $this->byoBusiness();
        $report = $this->deliveredReport($business, $server);

        // The same dispatch record, processed three times — a retry, or a
        // duplicate callback replaying it. The key is a pure function of the
        // Reports row's uid, so all three converge on one row.
        foreach (range(1, 3) as $ignored) {
            ManagedDispatchDelegate::recordByoMeasurement($server->id, $report, '1');
        }

        $this->assertSame(1, DB::table('business_usage_measurements')->count());

        // A genuinely different dispatch IS a second transport event.
        ManagedDispatchDelegate::recordByoMeasurement($server->id, $this->deliveredReport($business, $server), '1');
        $this->assertSame(2, DB::table('business_usage_measurements')->count());
    }

    public function test_a_failed_byo_send_is_never_recorded_as_transport(): void
    {
        [$business, $server] = $this->byoBusiness();

        foreach (['Failed', 'Rejected', 'Queued', ''] as $status) {
            $report = $this->deliveredReport($business, $server);
            $report->status = $status;
            $report->save();

            $this->assertFalse(
                ManagedDispatchDelegate::recordByoMeasurement($server->id, $report, '1'),
                "A [{$status}] send must not be measured as transport that happened.",
            );
        }

        // The legacy layer also returns a plain error string on some paths.
        $this->assertFalse(ManagedDispatchDelegate::recordByoMeasurement($server->id, 'something went wrong', '1'));
        $this->assertFalse(ManagedDispatchDelegate::recordByoMeasurement($server->id, null, '1'));

        $this->assertSame(0, DB::table('business_usage_measurements')->count());
    }

    public function test_a_managed_send_is_never_mislabelled_as_byo(): void
    {
        [$business, $server] = $this->byoBusiness();

        // Give the same Business an active managed identity. Its traffic
        // never reaches the legacy switch in production; if some future path
        // sent it there anyway, it must not acquire a 'byo' marker.
        $identity = $this->attachIdentity($business);
        $this->attachNumber($identity, $this->uniqueNumber(), true);

        $this->assertFalse(ManagedDispatchDelegate::recordByoMeasurement($server->id, $this->deliveredReport($business, $server), '1'));
        $this->assertSame(0, DB::table('business_usage_measurements')->count());

        // And a real managed dispatch is marked managed, not byo.
        $this->dispatchOnce($business, 'op_managed_marker');

        $this->assertSame('managed', DB::table('business_usage_measurements')->value('transport_marker'));
    }

    public function test_a_byo_send_creates_no_managed_operation_record(): void
    {
        [$business, $server] = $this->byoBusiness();

        ManagedDispatchDelegate::recordByoMeasurement($server->id, $this->deliveredReport($business, $server), '1');

        $this->assertSame(
            0,
            DB::table(ManagedMessageDispatcher::TABLE)->count(),
            'A BYO send must never masquerade as a managed operation the platform carried.',
        );
    }

    public function test_an_unassigned_gateway_is_not_treated_as_anyones_byo_connection(): void
    {
        // An admin/legacy shared SendingServer with no
        // customer_based_sending_servers assignment belongs to no Business,
        // so nothing is measured and nothing is guessed.
        $server = SendingServer::create([
            'name' => 'Shared Admin Gateway',
            'settings' => SendingServer::TYPE_TWILIO,
            'status' => true,
            'plain' => true,
        ]);

        [$business] = $this->byoBusiness();

        $this->assertFalse(ManagedDispatchDelegate::recordByoMeasurement($server->id, $this->deliveredReport($business, $server), '1'));
        $this->assertSame(0, DB::table('business_usage_measurements')->count());
    }

    public function test_the_byo_measurement_carries_no_credential(): void
    {
        [$business, $server] = $this->byoBusiness();

        ManagedDispatchDelegate::recordByoMeasurement($server->id, $this->deliveredReport($business, $server), '1');

        $encoded = (string) json_encode(DB::table('business_usage_measurements')->get());

        foreach (['AC_BYO_SID', 'byo_auth_token_fixture', 'api_key', 'auth_token', 'secret', 'password'] as $needle) {
            $this->assertStringNotContainsString($needle, $encoded, "The measurement retained [{$needle}].");
        }
    }

    /**
     * A Business whose transport is its own gateway, connected exactly the
     * way the relocated advanced-provider surface connects one: a dedicated
     * SendingServer plus a CustomerBasedSendingServer assignment row. That
     * assignment is what makes the send authoritatively BYO — not anything
     * in a request.
     *
     * @return array{0: Business, 1: SendingServer}
     */
    private function byoBusiness(): array
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        $server = SendingServer::create([
            'name' => 'BYO Twilio',
            'settings' => SendingServer::TYPE_TWILIO,
            'status' => true,
            'plain' => true,
            'user_id' => $customer->user_id,
            'account_sid' => 'AC_BYO_SID',
            'auth_token' => 'byo_auth_token_fixture',
        ]);

        CustomerBasedSendingServer::create([
            'user_id' => $customer->user_id,
            'business_id' => $business->id,
            'sending_server' => $server->id,
            'status' => true,
        ]);

        return [$business->fresh(), $server];
    }

    /**
     * The exact kind of value the legacy provider layer returns on success:
     * a persisted Reports row whose status says Delivered.
     */
    private function deliveredReport(Business $business, SendingServer $server, int $smsCount = 1): Reports
    {
        return Reports::create([
            'user_id' => $business->customer->user_id,
            'business_id' => $business->id,
            'to' => '14155559700',
            'message' => 'byo fixture',
            'sms_type' => 'plain',
            'status' => 'Delivered',
            'customer_status' => 'Delivered',
            'direction' => Reports::DIRECTION_OUTGOING,
            'cost' => 0,
            'sms_count' => $smsCount,
            'sending_server_id' => $server->id,
        ]);
    }
    private function dispatchOnce(Business $business, string $operationKey): void
    {
        app(\App\Library\Messaging\ManagedMessageDispatcher::class)
            ->dispatch($business, '+14155559400', 'measurement fixture', $operationKey);
    }
}

<?php

namespace Tests\Feature\Usage;

use App\Enums\Entitlement\PlatformFeature;
use App\Exceptions\Usage\UsageMeterBusinessScopeMismatchException;
use App\Exceptions\Usage\UsageMeterCurrencyMismatchException;
use App\Exceptions\Usage\UsageMeterNotMeteredException;
use App\Exceptions\Usage\UsageMeterRateIntegrityException;
use App\Library\Usage\UsageWalletManager;
use App\Models\Business;
use App\Models\Currency;
use App\Models\User;
use App\Repositories\Contracts\UsageMeterRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 Amendment 1 Slice 2 CUTOVER §8, proofs 5-8 and 10 — reserve()'s
 * new meter-authority checks (business-scope mismatch, currency mismatch,
 * not-metered, rate-integrity) and its happy-path dual-write;
 * commit()/release() propagating meter_key onto every ledger entry they
 * create; activateMetering()'s UsageMeterTransition write, never touching
 * the legacy classification table; and evaluateCoarseCapacity()'s
 * unconditional authorized decision. Proof 10 (the five pre-existing
 * files' unmodified assertions passing after their fixture extension) is
 * exercised by those files themselves, not duplicated here.
 */
class UsageWalletManagerMeterAuthorityTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
    }

    private function business(): Business
    {
        $customer = $this->createCustomer();

        return $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
    }

    private function createActorUserId(): int
    {
        return User::create([
            'first_name' => 'Test',
            'last_name' => 'Actor',
            'email' => 'actor'.uniqid().'@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
        ])->id;
    }

    private function createMeter(string $meterKey, string $featureKey, int $currencyId, ?int $businessId, int $actorId): void
    {
        app(UsageMeterRepository::class)->create([
            'meter_key' => $meterKey,
            'feature_key' => $featureKey,
            'business_id' => $businessId,
            'currency_id' => $currencyId,
            'description' => 'Meter authority fixture meter.',
            'updated_by_user_id' => $actorId,
        ]);
    }

    /**
     * The full locked fixture sequence (RFC-005 Amendment 1 Slice 2
     * CUTOVER §2): a genuine UsageMeter, an active rate, and metering
     * activated — the only state from which reserve() will grant.
     */
    private function fullyActivateMeter(string $meterKey, string $featureKey, int $currencyId, ?int $businessId, int $actorId): void
    {
        $this->createMeter($meterKey, $featureKey, $currencyId, $businessId, $actorId);
        app(UsageWalletManager::class)->setActiveRate($meterKey, '1000000', '500000', 'per message', $currencyId, $actorId, 'Fixture.');
        app(UsageWalletManager::class)->activateMetering($meterKey, $actorId, 'Fixture.');
    }

    /**
     * Proof 5 — the happy path dual-writes feature_key/meter_key
     * correctly on the reservation and its own reservation-type ledger
     * entry, resolved from the UsageMeter.
     */
    public function test_reserve_happy_path_dual_writes_feature_key_and_meter_key(): void
    {
        $business = $this->business();
        $actorId = $this->createActorUserId();
        $currencyId = Currency::query()->first()->id;
        $meterKey = 'crm.meter.'.uniqid();

        $manager = app(UsageWalletManager::class);
        $manager->initializeWalletForNewBusiness($business->id);
        $this->fullyActivateMeter($meterKey, 'crm', $currencyId, null, $actorId);
        DB::table('business_usage_wallets')->where('business_id', $business->id)->update(['available_balance_micro' => 5_000_000]);

        $result = $manager->reserve($business, $meterKey, (string) Str::uuid(), '1');

        $this->assertTrue($result->granted);
        $this->assertDatabaseHas('business_usage_reservations', [
            'id' => $result->reservationId,
            'feature_key' => 'crm',
            'meter_key' => $meterKey,
        ]);
        $this->assertDatabaseHas('business_usage_ledger_entries', [
            'reservation_id' => $result->reservationId,
            'entry_type' => 'reservation',
            'feature_key' => 'crm',
            'meter_key' => $meterKey,
        ]);
    }

    /**
     * Proof 5 — business-scope mismatch.
     */
    public function test_reserve_throws_business_scope_mismatch_when_meter_is_scoped_to_a_different_business(): void
    {
        $business = $this->business();
        $otherCustomer = $this->createCustomer();
        $otherBusiness = $this->createBusinessWithWorkspace($otherCustomer, $this->businessAttributes(['name' => 'Other Co']));
        $actorId = $this->createActorUserId();
        $currencyId = Currency::query()->first()->id;
        $meterKey = 'crm.scoped.'.uniqid();

        $manager = app(UsageWalletManager::class);
        $manager->initializeWalletForNewBusiness($business->id);
        // Scoped to the OTHER business — not the one attempting to use it.
        $this->fullyActivateMeter($meterKey, 'crm', $currencyId, $otherBusiness->id, $actorId);
        DB::table('business_usage_wallets')->where('business_id', $business->id)->update(['available_balance_micro' => 5_000_000]);

        $this->expectException(UsageMeterBusinessScopeMismatchException::class);
        $manager->reserve($business, $meterKey, (string) Str::uuid(), '1');
    }

    /**
     * Proof 5 — currency mismatch: the meter's own immutable currency
     * does not match the requesting Business's wallet currency.
     */
    public function test_reserve_throws_currency_mismatch_when_wallet_currency_differs_from_meter_currency(): void
    {
        $business = $this->business(); // wallet currency resolves to USD (business.currency_code)
        $eurCurrencyId = Currency::create(['name' => 'Euro', 'code' => 'EUR', 'format' => '€', 'status' => true])->id;
        $actorId = $this->createActorUserId();
        $meterKey = 'crm.eur.'.uniqid();

        $manager = app(UsageWalletManager::class);
        $manager->initializeWalletForNewBusiness($business->id);
        // The meter (and its rate) are entirely EUR-denominated.
        $this->fullyActivateMeter($meterKey, 'crm', $eurCurrencyId, null, $actorId);
        DB::table('business_usage_wallets')->where('business_id', $business->id)->update(['available_balance_micro' => 5_000_000]);

        $this->expectException(UsageMeterCurrencyMismatchException::class);
        $manager->reserve($business, $meterKey, (string) Str::uuid(), '1');
    }

    /**
     * Proof 5 — not-metered: an active rate exists, but activateMetering()
     * was never called.
     */
    public function test_reserve_throws_not_metered_when_metering_was_never_activated(): void
    {
        $business = $this->business();
        $actorId = $this->createActorUserId();
        $currencyId = Currency::query()->first()->id;
        $meterKey = 'crm.unmetered.'.uniqid();

        $manager = app(UsageWalletManager::class);
        $manager->initializeWalletForNewBusiness($business->id);
        $this->createMeter($meterKey, 'crm', $currencyId, null, $actorId);
        $manager->setActiveRate($meterKey, '1000000', '500000', 'per message', $currencyId, $actorId, 'Fixture.');
        // activateMetering() deliberately never called.
        DB::table('business_usage_wallets')->where('business_id', $business->id)->update(['available_balance_micro' => 5_000_000]);

        $this->expectException(UsageMeterNotMeteredException::class);
        $manager->reserve($business, $meterKey, (string) Str::uuid(), '1');
    }

    /**
     * Proof 5 — rate-integrity: defensive-only, structurally unreachable
     * through any real write path (the composite
     * meters_active_rate_foreign/rates_meter_currency_foreign FKs
     * guarantee a meter's own active_rate_id always belongs to a rate
     * with that exact same meter_key and currency_id). Engineered here
     * only by disabling FK checks to reach the otherwise-impossible
     * state — never a realistic production path.
     */
    public function test_reserve_throws_rate_integrity_exception_when_active_rate_does_not_genuinely_belong_to_the_meter(): void
    {
        $business = $this->business();
        $actorId = $this->createActorUserId();
        $currencyId = Currency::query()->first()->id;
        $meterKey = 'crm.integrity.'.uniqid();
        $otherMeterKey = 'crm.integrity.other.'.uniqid();

        $manager = app(UsageWalletManager::class);
        $manager->initializeWalletForNewBusiness($business->id);
        $this->fullyActivateMeter($meterKey, 'crm', $currencyId, null, $actorId);
        $this->createMeter($otherMeterKey, 'crm', $currencyId, null, $actorId);
        $otherRate = $manager->setActiveRate($otherMeterKey, '2000000', '500000', 'per message', $currencyId, $actorId, 'Fixture.');

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('usage_meters')->where('meter_key', $meterKey)->update(['active_rate_id' => $otherRate->id]);
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        DB::table('business_usage_wallets')->where('business_id', $business->id)->update(['available_balance_micro' => 5_000_000]);

        $this->expectException(UsageMeterRateIntegrityException::class);
        $manager->reserve($business, $meterKey, (string) Str::uuid(), '1');
    }

    /**
     * Proof 6 — commit() propagates meter_key onto the UsageCharge entry
     * (exact-match) and the UsageOverageCharge entry (overage), and
     * release() propagates it onto the ReservationRelease entry.
     */
    public function test_commit_propagates_meter_key_onto_usage_charge_and_overage_ledger_entries(): void
    {
        $business = $this->business();
        $actorId = $this->createActorUserId();
        $currencyId = Currency::query()->first()->id;
        $meterKey = 'crm.commit.'.uniqid();

        $manager = app(UsageWalletManager::class);
        $manager->initializeWalletForNewBusiness($business->id);
        $this->fullyActivateMeter($meterKey, 'crm', $currencyId, null, $actorId);

        // Exact-match commit -> UsageCharge entry.
        DB::table('business_usage_wallets')->where('business_id', $business->id)->update(['available_balance_micro' => 5_000_000]);
        $r1 = $manager->reserve($business, $meterKey, (string) Str::uuid(), '2');
        $manager->commit($r1->reservationId, '2');
        $this->assertDatabaseHas('business_usage_ledger_entries', [
            'reservation_id' => $r1->reservationId,
            'entry_type' => 'usage_charge',
            'meter_key' => $meterKey,
        ]);

        // Overage commit -> UsageOverageCharge entry.
        DB::table('business_usage_wallets')->where('business_id', $business->id)->update(['available_balance_micro' => 5_000_000]);
        $r2 = $manager->reserve($business, $meterKey, (string) Str::uuid(), '1');
        $manager->commit($r2->reservationId, '2');
        $this->assertDatabaseHas('business_usage_ledger_entries', [
            'reservation_id' => $r2->reservationId,
            'entry_type' => 'usage_overage_charge',
            'meter_key' => $meterKey,
        ]);

        // Under-reservation commit -> commit()'s own ReservationRelease entry.
        DB::table('business_usage_wallets')->where('business_id', $business->id)->update(['available_balance_micro' => 5_000_000]);
        $r3 = $manager->reserve($business, $meterKey, (string) Str::uuid(), '2');
        $manager->commit($r3->reservationId, '1');
        $this->assertDatabaseHas('business_usage_ledger_entries', [
            'reservation_id' => $r3->reservationId,
            'entry_type' => 'reservation_release',
            'meter_key' => $meterKey,
        ]);
    }

    public function test_release_propagates_meter_key_onto_its_ledger_entry(): void
    {
        $business = $this->business();
        $actorId = $this->createActorUserId();
        $currencyId = Currency::query()->first()->id;
        $meterKey = 'crm.release.'.uniqid();

        $manager = app(UsageWalletManager::class);
        $manager->initializeWalletForNewBusiness($business->id);
        $this->fullyActivateMeter($meterKey, 'crm', $currencyId, null, $actorId);
        DB::table('business_usage_wallets')->where('business_id', $business->id)->update(['available_balance_micro' => 5_000_000]);

        $reservation = $manager->reserve($business, $meterKey, (string) Str::uuid(), '2');
        $manager->release($reservation->reservationId);

        $this->assertDatabaseHas('business_usage_ledger_entries', [
            'reservation_id' => $reservation->reservationId,
            'entry_type' => 'reservation_release',
            'meter_key' => $meterKey,
        ]);
    }

    /**
     * Proof 7 — activateMetering() writes a UsageMeterTransition row and
     * flips is_metered to true via UsageMeterRepository::update()'s
     * existing whitelist; the legacy classification table is never
     * touched.
     */
    public function test_activate_metering_writes_transition_row_and_never_touches_the_classification_table(): void
    {
        $actorId = $this->createActorUserId();
        $currencyId = Currency::query()->first()->id;
        $meterKey = 'crm.activate.'.uniqid();

        $classificationTransitionsBefore = DB::table('platform_feature_usage_classification_transitions')->count();
        $classificationsBefore = DB::table('platform_feature_usage_classifications')->count();

        $manager = app(UsageWalletManager::class);
        $this->createMeter($meterKey, 'crm', $currencyId, null, $actorId);
        $manager->setActiveRate($meterKey, '1000000', '500000', 'per message', $currencyId, $actorId, 'Fixture.');

        $meterBefore = DB::table('usage_meters')->where('meter_key', $meterKey)->first();
        $this->assertFalse((bool) $meterBefore->is_metered);

        $manager->activateMetering($meterKey, $actorId, 'Activation reason.');

        $meterAfter = DB::table('usage_meters')->where('meter_key', $meterKey)->first();
        $this->assertTrue((bool) $meterAfter->is_metered);

        $this->assertDatabaseHas('usage_meter_transitions', [
            'meter_key' => $meterKey,
            'from_is_metered' => 0,
            'to_is_metered' => 1,
            'from_active_rate_id' => $meterBefore->active_rate_id,
            'to_active_rate_id' => $meterBefore->active_rate_id,
            'actor_user_id' => $actorId,
            'reason' => 'Activation reason.',
        ]);

        $this->assertSame(
            $classificationTransitionsBefore,
            DB::table('platform_feature_usage_classification_transitions')->count(),
        );
        $this->assertSame(
            $classificationsBefore,
            DB::table('platform_feature_usage_classifications')->count(),
        );
    }

    /**
     * Proof 8 — evaluateCoarseCapacity() unconditionally returns an
     * allowed decision, regardless of wallet or meter state (no wallet
     * at all; a suspended wallet with outstanding debt).
     */
    public function test_evaluate_coarse_capacity_unconditionally_authorizes_regardless_of_wallet_or_meter_state(): void
    {
        $business = $this->business();
        $manager = app(UsageWalletManager::class);

        // No wallet exists yet at all.
        $decision = $manager->evaluateCoarseCapacity($business, PlatformFeature::Crm);
        $this->assertTrue($decision->authorized);
        $this->assertNull($decision->reason);

        // A suspended wallet with outstanding debt — the exact state that
        // used to deny under the pre-cutover classification-driven gate.
        $manager->initializeWalletForNewBusiness($business->id);
        DB::table('business_usage_wallets')->where('business_id', $business->id)->update([
            'billing_status' => 'suspended',
            'debt_balance_micro' => 1,
        ]);

        $decision = $manager->evaluateCoarseCapacity($business, PlatformFeature::Crm);
        $this->assertTrue($decision->authorized);
        $this->assertNull($decision->reason);
    }
}

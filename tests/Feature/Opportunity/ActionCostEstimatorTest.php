<?php

namespace Tests\Feature\Opportunity;

use App\Enums\Usage\PayerType;
use App\Library\Opportunity\ActionCostEstimate;
use App\Library\Opportunity\ActionCostEstimator;
use App\Library\Usage\UsageWalletManager;
use App\Models\Business;
use App\Models\Currency;
use App\Models\User;
use App\Repositories\Contracts\BusinessUsageWalletRepository;
use App\Repositories\Contracts\UsageMeterRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * Implementation Contract 19 §5.3, §12 19.E — ActionCostEstimator is the
 * deterministic computation itself: the same UsageMeter/BusinessUsageRate
 * seams UsageWalletManager::reserve() reads, EffectivePayerResolver's plain
 * (non-locking) resolve(), and a read of the payer Business's own wallet —
 * never a reservation, never a write, never a value from model output (R-4).
 */
class ActionCostEstimatorTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
    }

    public function test_the_estimate_matches_the_rate_exactly_and_the_wallet_is_sufficient(): void
    {
        $business = $this->businessWithWallet(20_000_000);
        $actorId = $this->createActorUserId();
        $meterKey = $this->activateMeter($actorId, retailRateMicro: '1000000', providerCostMicro: '500000');

        $estimate = app(ActionCostEstimator::class)->estimate($business, $meterKey);

        $this->assertNotNull($estimate);
        $this->assertSame(PayerType::Workspace, $estimate->payerType, 'No payer assignment row: RFC-005 §32\'s own Workspace default.');
        $this->assertSame((int) $business->workspace_id, $estimate->payerWorkspaceId);
        $this->assertSame('USD', $estimate->currencyCode);
        $this->assertSame(100, $estimate->amountMinorUpperBound, 'retail_rate_micro (1,000,000) x quantity (1) = 1,000,000 micro = 100 USD minor units (cents), exactly.');
        $this->assertSame('per message', $estimate->unitKind);
        $this->assertSame(ActionCostEstimate::BASIS_UPPER_BOUND, $estimate->basis);
        $this->assertSame('1', $estimate->priceVersion);
        $this->assertTrue($estimate->walletSufficient, '20,000,000 available covers a 1,000,000 estimate.');
        $this->assertTrue($estimate->expiresAt->isAfter($estimate->estimatedAt));
    }

    public function test_a_higher_quantity_scales_the_estimate_exactly(): void
    {
        $business = $this->businessWithWallet(20_000_000);
        $actorId = $this->createActorUserId();
        $meterKey = $this->activateMeter($actorId, retailRateMicro: '250000', providerCostMicro: '100000');

        $estimate = app(ActionCostEstimator::class)->estimate($business, $meterKey, '4');

        $this->assertSame(100, $estimate->amountMinorUpperBound, '250,000 x 4 = 1,000,000 micro = 100 USD minor units, exactly.');
    }

    public function test_insufficient_wallet_is_detected_and_reported_never_thrown(): void
    {
        $business = $this->businessWithWallet(500_000);
        $actorId = $this->createActorUserId();
        $meterKey = $this->activateMeter($actorId, retailRateMicro: '1000000', providerCostMicro: '500000');

        $estimate = app(ActionCostEstimator::class)->estimate($business, $meterKey);

        $this->assertNotNull($estimate, 'An estimate is still produced — insufficiency is a fact to show, not a refusal to compute.');
        $this->assertFalse($estimate->walletSufficient);
        $this->assertSame(100, $estimate->amountMinorUpperBound, 'The ceiling itself never changes because funds are short.');
    }

    /**
     * Correction (Contract 19 §5.3 review) — `amountMinorUpperBound` is the
     * payer currency's real MINOR units, converted from RFC-005 micro-units
     * via {@see \App\Library\Money\MicroAmountConverter}, never the raw
     * micro figure. Wallet sufficiency stays decided against the exact
     * micro balance regardless.
     */
    public function test_the_estimate_is_converted_to_real_minor_units_not_the_raw_micro_figure(): void
    {
        $business = $this->businessWithWallet(20_000_000);
        $actorId = $this->createActorUserId();
        // 1,230,000 micro-units, exactly 123 USD minor units (cents) —
        // divides evenly, so ceiling and floor agree here.
        $meterKey = $this->activateMeter($actorId, retailRateMicro: '1230000', providerCostMicro: '500000');

        $estimate = app(ActionCostEstimator::class)->estimate($business, $meterKey);

        $this->assertSame(123, $estimate->amountMinorUpperBound);
    }

    /**
     * Correction — the conversion is an UPPER BOUND: it must never round
     * down and understate the approved ceiling. 1,230,001 micro-units is
     * NOT an exact multiple of 10,000 (the micro-per-cent divisor for a
     * two-decimal currency), so it ceils to 124 cents, never floors to 123.
     */
    public function test_a_fractional_minor_unit_ceils_rather_than_floors(): void
    {
        $business = $this->businessWithWallet(20_000_000);
        $actorId = $this->createActorUserId();
        $meterKey = $this->activateMeter($actorId, retailRateMicro: '1230001', providerCostMicro: '500000');

        $estimate = app(ActionCostEstimator::class)->estimate($business, $meterKey);

        $this->assertSame(124, $estimate->amountMinorUpperBound, '1,230,001 micro must ceil to 124 minor units, never floor to 123 — an upper bound may never understate.');
    }

    /**
     * Correction — a zero-decimal currency (JPY) has NO minor-unit scaling
     * at all: 1 micro-per-major-unit divisor is 1,000,000, so 5,000,000
     * micro converts to exactly 5 JPY, with no cents concept in between.
     */
    public function test_a_zero_decimal_currency_converts_with_no_decimal_scaling(): void
    {
        Currency::create(['name' => 'Japanese Yen', 'code' => 'JPY', 'format' => '¥', 'status' => true]);

        $business = $this->businessWithWallet(20_000_000);
        $actorId = $this->createActorUserId();
        $meterKey = 'crm.meter.' . uniqid('', true);
        $currencyId = Currency::query()->where('code', 'JPY')->firstOrFail()->id;

        app(UsageMeterRepository::class)->create([
            'meter_key' => $meterKey,
            'feature_key' => 'crm',
            'business_id' => null,
            'currency_id' => $currencyId,
            'description' => 'JPY fixture meter.',
            'updated_by_user_id' => $actorId,
        ]);

        $manager = app(UsageWalletManager::class);
        $manager->setActiveRate($meterKey, '5000000', '2500000', 'per message', $currencyId, $actorId, 'Fixture.');
        $manager->activateMetering($meterKey, $actorId, 'Fixture.');

        $estimate = app(ActionCostEstimator::class)->estimate($business, $meterKey);

        $this->assertSame('JPY', $estimate->currencyCode);
        $this->assertSame(5, $estimate->amountMinorUpperBound, '5,000,000 micro = 5 JPY exactly — JPY has no minor-unit fraction.');
    }

    public function test_no_active_rate_is_a_null_estimate_never_a_fabricated_one(): void
    {
        $business = $this->businessWithWallet(20_000_000);
        $actorId = $this->createActorUserId();
        $meterKey = 'crm.meter.' . uniqid('', true);

        app(UsageMeterRepository::class)->create([
            'meter_key' => $meterKey,
            'feature_key' => 'crm',
            'business_id' => null,
            'currency_id' => Currency::query()->first()->id,
            'description' => 'Fixture meter with no active rate.',
            'updated_by_user_id' => $actorId,
        ]);

        $this->assertNull(app(ActionCostEstimator::class)->estimate($business, $meterKey));
    }

    public function test_an_unknown_meter_key_is_a_null_estimate(): void
    {
        $business = $this->businessWithWallet(20_000_000);

        $this->assertNull(app(ActionCostEstimator::class)->estimate($business, 'no.such.meter'));
    }

    public function test_a_meter_scoped_to_another_business_is_a_null_estimate(): void
    {
        $business = $this->businessWithWallet(20_000_000);
        $otherBusiness = $this->businessWithWallet(20_000_000);
        $actorId = $this->createActorUserId();
        $meterKey = $this->activateMeter($actorId, retailRateMicro: '1000000', providerCostMicro: '500000', scopedToBusinessId: (int) $otherBusiness->id);

        $this->assertNull(app(ActionCostEstimator::class)->estimate($business, $meterKey));
    }

    /**
     * R-4 — the estimate is deterministic server pricing over RFC-005's own
     * seams, never a value from model output. Mechanical proof: nothing
     * `App\Library\Ai\**` is ever wired into this class.
     */
    public function test_no_ai_dependency_participates_in_the_estimate(): void
    {
        $constructor = new \ReflectionMethod(ActionCostEstimator::class, '__construct');

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : '';

            $this->assertFalse(
                str_starts_with($typeName, 'App\\Library\\Ai\\'),
                "ActionCostEstimator must never depend on {$typeName} — the estimate is deterministic server pricing, never model output (R-4)."
            );
        }
    }

    public function test_estimate_for_action_is_null_for_every_shipped_action_including_add_phone(): void
    {
        $business = $this->businessWithWallet(20_000_000);

        foreach (array_keys(\App\Library\Opportunity\OpportunityActionRegistry::all()) as $actionKey) {
            $this->assertNull(
                app(ActionCostEstimator::class)->estimateForAction($business, $actionKey),
                "[{$actionKey}] is not paid_effect today, so estimateForAction() must return null."
            );
        }
    }

    private function businessWithWallet(int $availableBalanceMicro): Business
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        app(UsageWalletManager::class)->initializeWalletForNewBusiness((int) $business->id);

        $wallet = app(BusinessUsageWalletRepository::class)->findByBusinessId((int) $business->id);
        app(BusinessUsageWalletRepository::class)->update($wallet, ['available_balance_micro' => $availableBalanceMicro]);

        return $business->fresh();
    }

    private function createActorUserId(): int
    {
        return User::create([
            'first_name' => 'Test',
            'last_name' => 'Actor',
            'email' => 'actor' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
        ])->id;
    }

    /** The full locked fixture sequence: a genuine UsageMeter, an active rate, metering on. */
    private function activateMeter(int $actorId, string $retailRateMicro, string $providerCostMicro, ?int $scopedToBusinessId = null): string
    {
        $meterKey = 'crm.meter.' . uniqid('', true);
        $currencyId = Currency::query()->first()->id;

        app(UsageMeterRepository::class)->create([
            'meter_key' => $meterKey,
            'feature_key' => 'crm',
            'business_id' => $scopedToBusinessId,
            'currency_id' => $currencyId,
            'description' => 'ActionCostEstimator fixture meter.',
            'updated_by_user_id' => $actorId,
        ]);

        $manager = app(UsageWalletManager::class);
        $manager->setActiveRate($meterKey, $retailRateMicro, $providerCostMicro, 'per message', $currencyId, $actorId, 'Fixture.');
        $manager->activateMetering($meterKey, $actorId, 'Fixture.');

        return $meterKey;
    }
}

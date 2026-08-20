<?php

namespace Tests\Feature\Entitlement;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Events\Entitlement\WorkspacePlanCatalogPricingChanged;
use App\Exceptions\Entitlement\PlanCatalogPricingInUseException;
use App\Library\Entitlement\EntitlementManager;
use App\Models\Currency;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspacePlanCatalog;
use App\Models\WorkspacePlanCatalogPricingChange;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Tests\TestCase;

class EntitlementManagerCatalogPricingTest extends TestCase
{
    use RefreshDatabase;

    private function createAdmin(): int
    {
        return User::create([
            'first_name' => 'Admin', 'last_name' => 'User', 'email' => 'admin' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ])->id;
    }

    private function createNonAdmin(): int
    {
        return User::create([
            'first_name' => 'Non', 'last_name' => 'Admin', 'email' => 'nonadmin' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ])->id;
    }

    private function currency(bool $active = true): int
    {
        return Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => $active])->id;
    }

    /**
     * @dataProvider normalizationProvider
     */
    public function test_price_normalization(string $input, string $expected): void
    {
        $catalog = WorkspacePlanCatalog::where('tier', 'core')->first();

        $updated = app(EntitlementManager::class)->updateCatalogPricing($catalog, $input, $this->currency(), null, $this->createAdmin(), 'Fixture.');

        $this->assertSame($expected, (string) $updated->fresh()->getRawOriginal('price'));
    }

    public static function normalizationProvider(): array
    {
        return [
            'integer form' => ['49', '49.00'],
            'one fractional digit' => ['49.5', '49.50'],
            'leading zeros' => ['00049.50', '49.50'],
            'zero' => ['0', '0.00'],
            'max boundary' => ['99999999999999.99', '99999999999999.99'],
        ];
    }

    /**
     * @dataProvider rejectionProvider
     */
    public function test_price_rejection(string $input): void
    {
        $catalog = WorkspacePlanCatalog::where('tier', 'core')->first();

        $this->expectException(InvalidArgumentException::class);
        app(EntitlementManager::class)->updateCatalogPricing($catalog, $input, $this->currency(), null, $this->createAdmin(), 'Fixture.');
    }

    public static function rejectionProvider(): array
    {
        return [
            'blank' => [''],
            'negative' => ['-1.00'],
            'plus sign' => ['+1.00'],
            'scientific notation' => ['4.9e1'],
            'three decimals' => ['49.999'],
            'overflow' => ['100000000000000.00'],
            'whitespace' => [' 49.00 '],
            'comma' => ['49,00'],
        ];
    }

    public function test_max_boundary_reads_back_exactly_through_the_decimal_cast(): void
    {
        $catalog = WorkspacePlanCatalog::where('tier', 'core')->first();

        app(EntitlementManager::class)->updateCatalogPricing($catalog, '99999999999999.99', $this->currency(), null, $this->createAdmin(), 'Fixture.');

        $this->assertSame('99999999999999.99', $catalog->fresh()->price);
    }

    public function test_price_and_currency_must_both_be_null_or_both_populated(): void
    {
        $catalog = WorkspacePlanCatalog::where('tier', 'core')->first();

        $this->expectException(InvalidArgumentException::class);
        app(EntitlementManager::class)->updateCatalogPricing($catalog, '49.00', null, null, $this->createAdmin(), 'Fixture.');
    }

    public function test_clearing_is_blocked_while_a_non_complimentary_assignment_references_the_row(): void
    {
        $catalog = WorkspacePlanCatalog::where('tier', 'core')->first();
        $currencyId = $this->currency();
        app(EntitlementManager::class)->updateCatalogPricing($catalog, '49.00', $currencyId, null, $this->createAdmin(), 'Fixture.');

        $owner = User::create([
            'first_name' => 'Owner', 'last_name' => 'User', 'email' => 'owner' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);
        $workspace = Workspace::create(['name' => 'W', 'owner_user_id' => $owner->id, 'is_active' => true]);
        app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Core, $this->createAdmin(), 'Fixture.', false, 0);

        $this->expectException(PlanCatalogPricingInUseException::class);
        app(EntitlementManager::class)->updateCatalogPricing($catalog->fresh(), null, null, null, $this->createAdmin(), 'Fixture.');
    }

    public function test_complimentary_reference_never_blocks_clearing(): void
    {
        $catalog = WorkspacePlanCatalog::where('tier', 'core')->first();
        $currencyId = $this->currency();
        app(EntitlementManager::class)->updateCatalogPricing($catalog, '49.00', $currencyId, null, $this->createAdmin(), 'Fixture.');

        $owner = User::create([
            'first_name' => 'Owner', 'last_name' => 'User', 'email' => 'owner' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);
        $workspace = Workspace::create(['name' => 'W', 'owner_user_id' => $owner->id, 'is_active' => true]);
        app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Core, $this->createAdmin(), 'Fixture.', true, 0);

        $updated = app(EntitlementManager::class)->updateCatalogPricing($catalog->fresh(), null, null, null, $this->createAdmin(), 'Fixture.');

        $this->assertNull($updated->fresh()->price);
    }

    public function test_non_administrator_actor_is_denied_before_the_catalog_row_is_locked(): void
    {
        $catalog = WorkspacePlanCatalog::where('tier', 'core')->first();

        $this->expectException(AuthorizationException::class);
        app(EntitlementManager::class)->updateCatalogPricing($catalog, '49.00', $this->currency(), null, $this->createNonAdmin(), 'Fixture.');
    }

    public function test_non_administrator_clearing_an_in_use_catalog_still_receives_authorization_exception(): void
    {
        $catalog = WorkspacePlanCatalog::where('tier', 'core')->first();
        $currencyId = $this->currency();
        app(EntitlementManager::class)->updateCatalogPricing($catalog, '49.00', $currencyId, null, $this->createAdmin(), 'Fixture.');

        $owner = User::create([
            'first_name' => 'Owner', 'last_name' => 'User', 'email' => 'owner' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);
        $workspace = Workspace::create(['name' => 'W', 'owner_user_id' => $owner->id, 'is_active' => true]);
        app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Core, $this->createAdmin(), 'Fixture.', false, 0);

        try {
            app(EntitlementManager::class)->updateCatalogPricing($catalog->fresh(), null, null, null, $this->createNonAdmin(), 'Fixture.');
            $this->fail('Expected AuthorizationException.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        } catch (PlanCatalogPricingInUseException $e) {
            $this->fail('Expected AuthorizationException, got PlanCatalogPricingInUseException: ' . $e->getMessage());
        }
    }

    public function test_empty_reason_is_rejected(): void
    {
        $catalog = WorkspacePlanCatalog::where('tier', 'core')->first();

        $this->expectException(InvalidArgumentException::class);
        app(EntitlementManager::class)->updateCatalogPricing($catalog, '49.00', $this->currency(), null, $this->createAdmin(), '   ');
    }

    /**
     * @dataProvider ratioNormalizationProvider
     */
    public function test_ratio_normalization(string $input, string $expected): void
    {
        $catalog = WorkspacePlanCatalog::where('tier', 'core')->first();

        $updated = app(EntitlementManager::class)->updateCatalogPricing($catalog, null, null, $input, $this->createAdmin(), 'Fixture.');

        $this->assertSame($expected, (string) $updated->fresh()->getRawOriginal('additional_business_slot_price_ratio'));
    }

    public static function ratioNormalizationProvider(): array
    {
        return [
            'integer form' => ['0', '0.0000'],
            'two fractional digits' => ['0.50', '0.5000'],
            'four fractional digits' => ['0.1234', '0.1234'],
            'leading zeros' => ['00.5', '0.5000'],
            'max boundary' => ['99.9999', '99.9999'],
        ];
    }

    /**
     * @dataProvider ratioRejectionProvider
     */
    public function test_ratio_rejection(string $input): void
    {
        $catalog = WorkspacePlanCatalog::where('tier', 'core')->first();

        $this->expectException(InvalidArgumentException::class);
        app(EntitlementManager::class)->updateCatalogPricing($catalog, null, null, $input, $this->createAdmin(), 'Fixture.');
    }

    public static function ratioRejectionProvider(): array
    {
        return [
            'negative' => ['-0.5'],
            'plus sign' => ['+0.5'],
            'five fractional digits' => ['0.50000'],
            'three integer digits' => ['100.0'],
            'scientific notation' => ['5e-1'],
            'comma' => ['0,5'],
        ];
    }

    public function test_ratio_is_rejected_for_agency_tier(): void
    {
        $catalog = WorkspacePlanCatalog::where('tier', 'agency')->first();

        $this->expectException(InvalidArgumentException::class);
        app(EntitlementManager::class)->updateCatalogPricing($catalog, null, null, '0.5000', $this->createAdmin(), 'Fixture.');
    }

    public function test_null_ratio_is_always_permitted_for_agency_tier(): void
    {
        $catalog = WorkspacePlanCatalog::where('tier', 'agency')->first();

        $updated = app(EntitlementManager::class)->updateCatalogPricing($catalog, null, null, null, $this->createAdmin(), 'Fixture.');

        $this->assertNull($updated->fresh()->additional_business_slot_price_ratio);
    }

    public function test_ratio_is_accepted_for_growth_tier(): void
    {
        $catalog = WorkspacePlanCatalog::where('tier', 'growth')->first();

        $updated = app(EntitlementManager::class)->updateCatalogPricing($catalog, null, null, '0.5000', $this->createAdmin(), 'Fixture.');

        $this->assertSame('0.5000', (string) $updated->fresh()->getRawOriginal('additional_business_slot_price_ratio'));
    }

    public function test_currency_must_exist(): void
    {
        $catalog = WorkspacePlanCatalog::where('tier', 'core')->first();

        $this->expectException(InvalidArgumentException::class);
        app(EntitlementManager::class)->updateCatalogPricing($catalog, '49.00', 999999999, null, $this->createAdmin(), 'Fixture.');
    }

    public function test_currency_must_be_active(): void
    {
        $catalog = WorkspacePlanCatalog::where('tier', 'core')->first();
        $inactiveCurrencyId = $this->currency(false);

        $this->expectException(InvalidArgumentException::class);
        app(EntitlementManager::class)->updateCatalogPricing($catalog, '49.00', $inactiveCurrencyId, null, $this->createAdmin(), 'Fixture.');
    }

    public function test_audit_row_records_exact_before_after_state_and_event_dispatches_after_commit(): void
    {
        Event::fake([WorkspacePlanCatalogPricingChanged::class]);

        // Core is seeded (RFC-004 §12.1/§25) with
        // additional_business_slot_price_ratio = 0.5000 already — price and
        // currency_id remain unfrozen (null) until an operator sets them.
        $catalog = WorkspacePlanCatalog::where('tier', 'core')->first();
        $this->assertSame('0.5000', (string) $catalog->getRawOriginal('additional_business_slot_price_ratio'));
        $currencyId = $this->currency();
        $admin = $this->createAdmin();

        $updated = app(EntitlementManager::class)->updateCatalogPricing($catalog, '49.00', $currencyId, '0.3000', $admin, 'Initial commercial pricing.');

        $row = WorkspacePlanCatalogPricingChange::where('workspace_plan_catalog_id', $catalog->id)->first();

        $this->assertNotNull($row);
        $this->assertSame($admin, $row->actor_user_id);
        $this->assertSame('Initial commercial pricing.', $row->reason);
        $this->assertNull($row->from_price);
        $this->assertSame('49.00', (string) $row->getRawOriginal('to_price'));
        $this->assertNull($row->from_currency_id);
        $this->assertSame($currencyId, $row->to_currency_id);
        $this->assertSame('0.5000', (string) $row->getRawOriginal('from_additional_business_slot_price_ratio'));
        $this->assertSame('0.3000', (string) $row->getRawOriginal('to_additional_business_slot_price_ratio'));
        $this->assertSame('0.3000', (string) $updated->fresh()->getRawOriginal('additional_business_slot_price_ratio'));

        Event::assertDispatched(
            WorkspacePlanCatalogPricingChanged::class,
            fn ($e) => $e->catalogId === $updated->id && $e->actorUserId === $admin
        );
    }
}

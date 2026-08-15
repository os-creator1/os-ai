<?php

namespace Tests\Feature\Entitlement;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Exceptions\Entitlement\PlanCatalogPricingInUseException;
use App\Library\Entitlement\EntitlementManager;
use App\Models\Currency;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspacePlanCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    private function currency(): int
    {
        return Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true])->id;
    }

    /**
     * @dataProvider normalizationProvider
     */
    public function test_price_normalization(string $input, string $expected): void
    {
        $catalog = WorkspacePlanCatalog::where('tier', 'core')->first();

        $updated = app(EntitlementManager::class)->updateCatalogPricing($catalog, $input, $this->currency(), $this->createAdmin());

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
        app(EntitlementManager::class)->updateCatalogPricing($catalog, $input, $this->currency(), $this->createAdmin());
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

        app(EntitlementManager::class)->updateCatalogPricing($catalog, '99999999999999.99', $this->currency(), $this->createAdmin());

        $this->assertSame('99999999999999.99', $catalog->fresh()->price);
    }

    public function test_price_and_currency_must_both_be_null_or_both_populated(): void
    {
        $catalog = WorkspacePlanCatalog::where('tier', 'core')->first();

        $this->expectException(InvalidArgumentException::class);
        app(EntitlementManager::class)->updateCatalogPricing($catalog, '49.00', null, $this->createAdmin());
    }

    public function test_clearing_is_blocked_while_a_non_complimentary_assignment_references_the_row(): void
    {
        $catalog = WorkspacePlanCatalog::where('tier', 'core')->first();
        $currencyId = $this->currency();
        app(EntitlementManager::class)->updateCatalogPricing($catalog, '49.00', $currencyId, $this->createAdmin());

        $owner = User::create([
            'first_name' => 'Owner', 'last_name' => 'User', 'email' => 'owner' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);
        $workspace = Workspace::create(['name' => 'W', 'owner_user_id' => $owner->id, 'is_active' => true]);
        app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Core, $this->createAdmin(), 'Fixture.', false, 0);

        $this->expectException(PlanCatalogPricingInUseException::class);
        app(EntitlementManager::class)->updateCatalogPricing($catalog->fresh(), null, null, $this->createAdmin());
    }

    public function test_complimentary_reference_never_blocks_clearing(): void
    {
        $catalog = WorkspacePlanCatalog::where('tier', 'core')->first();
        $currencyId = $this->currency();
        app(EntitlementManager::class)->updateCatalogPricing($catalog, '49.00', $currencyId, $this->createAdmin());

        $owner = User::create([
            'first_name' => 'Owner', 'last_name' => 'User', 'email' => 'owner' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);
        $workspace = Workspace::create(['name' => 'W', 'owner_user_id' => $owner->id, 'is_active' => true]);
        app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Core, $this->createAdmin(), 'Fixture.', true, 0);

        $updated = app(EntitlementManager::class)->updateCatalogPricing($catalog->fresh(), null, null, $this->createAdmin());

        $this->assertNull($updated->fresh()->price);
    }

    public function test_non_administrator_actor_is_denied_before_the_catalog_row_is_locked(): void
    {
        $catalog = WorkspacePlanCatalog::where('tier', 'core')->first();

        $this->expectException(AuthorizationException::class);
        app(EntitlementManager::class)->updateCatalogPricing($catalog, '49.00', $this->currency(), $this->createNonAdmin());
    }

    public function test_non_administrator_clearing_an_in_use_catalog_still_receives_authorization_exception(): void
    {
        $catalog = WorkspacePlanCatalog::where('tier', 'core')->first();
        $currencyId = $this->currency();
        app(EntitlementManager::class)->updateCatalogPricing($catalog, '49.00', $currencyId, $this->createAdmin());

        $owner = User::create([
            'first_name' => 'Owner', 'last_name' => 'User', 'email' => 'owner' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);
        $workspace = Workspace::create(['name' => 'W', 'owner_user_id' => $owner->id, 'is_active' => true]);
        app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Core, $this->createAdmin(), 'Fixture.', false, 0);

        try {
            app(EntitlementManager::class)->updateCatalogPricing($catalog->fresh(), null, null, $this->createNonAdmin());
            $this->fail('Expected AuthorizationException.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        } catch (PlanCatalogPricingInUseException $e) {
            $this->fail('Expected AuthorizationException, got PlanCatalogPricingInUseException: ' . $e->getMessage());
        }
    }
}

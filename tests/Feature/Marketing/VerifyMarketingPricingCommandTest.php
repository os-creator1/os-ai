<?php

namespace Tests\Feature\Marketing;

use App\Models\Currency;
use App\Models\WorkspacePlanCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `marketing:verify-pricing` is the guard that keeps the public website's prices equal to the
 * authoritative plan catalog. It is read-only: these tests also prove it writes nothing.
 */
class VerifyMarketingPricingCommandTest extends TestCase
{
    use RefreshDatabase;

    private function priceCatalog(array $prices = ['core' => 97, 'growth' => 297, 'agency' => 497], string $cycle = 'every_28_days'): void
    {
        $currencyId = (int) (Currency::query()->where('code', 'USD')->value('id') ?? DB::table('currencies')->insertGetId([
            'uid' => (string) Str::uuid(), 'name' => 'US Dollar', 'code' => 'USD', 'format' => '$',
            'status' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]));

        foreach ($prices as $tier => $price) {
            WorkspacePlanCatalog::query()->where('tier', $tier)->update([
                'price' => $price,
                'currency_id' => $currencyId,
                'billing_cycle' => $cycle,
                'is_active' => true,
                'available_for_signup' => true,
                'provider_price_id' => 'price_test_'.$tier,
            ]);
        }
    }

    public function test_passes_when_the_catalog_matches_the_public_prices(): void
    {
        $this->priceCatalog();

        $this->artisan('marketing:verify-pricing')
            ->expectsOutputToContain('$297 / 28 days')
            ->assertExitCode(0);
    }

    public function test_fails_when_a_catalog_price_differs_from_the_website(): void
    {
        $this->priceCatalog(['core' => 97, 'growth' => 197, 'agency' => 497]);

        $this->artisan('marketing:verify-pricing')
            ->expectsOutputToContain('catalog price is 197.00, website shows 297')
            ->assertExitCode(1);
    }

    public function test_fails_when_the_catalog_is_not_on_the_28_day_cycle_or_not_priced(): void
    {
        $this->priceCatalog(cycle: 'monthly');
        $this->artisan('marketing:verify-pricing')->expectsOutputToContain('billing cycle is monthly')->assertExitCode(1);

        WorkspacePlanCatalog::query()->where('tier', 'agency')->update(['price' => null]);
        $this->artisan('marketing:verify-pricing')->expectsOutputToContain('catalog price is unset')->assertExitCode(1);
    }

    public function test_it_flags_a_plan_comparison_claim_the_catalog_packaging_contradicts(): void
    {
        $this->priceCatalog();
        $core = WorkspacePlanCatalog::query()->where('tier', 'core')->value('id');
        DB::table('workspace_plan_features')->where('workspace_plan_catalog_id', $core)->where('feature_key', 'website_generation')->delete();

        $this->artisan('marketing:verify-pricing')
            ->expectsOutputToContain('Plan comparison does not match the catalog packaging')
            ->assertExitCode(1);
    }

    public function test_the_shipped_comparison_matches_the_shipped_packaging(): void
    {
        $this->priceCatalog();

        $this->artisan('marketing:verify-pricing')
            ->expectsOutputToContain('Plan comparison matches the catalog feature packaging.')
            ->assertExitCode(0);
    }

    public function test_it_changes_nothing(): void
    {
        $this->priceCatalog();
        $before = WorkspacePlanCatalog::query()->orderBy('id')->get()->toArray();

        $this->artisan('marketing:verify-pricing')->assertExitCode(0);

        $this->assertSame($before, WorkspacePlanCatalog::query()->orderBy('id')->get()->toArray());
    }
}

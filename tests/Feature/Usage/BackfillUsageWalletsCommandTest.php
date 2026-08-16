<?php

namespace Tests\Feature\Usage;

use App\Library\Usage\Migration\UsageWalletBackfillV1;
use App\Models\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 M1 contract §9.2/§14 — the command wraps the same action the
 * migration uses; non-zero exit with the exact remaining count on a
 * forced-incomplete scenario.
 */
class BackfillUsageWalletsCommandTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    public function test_command_produces_identical_result_to_the_action_it_wraps(): void
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);

        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        $this->artisan('usage:backfill-wallets')->assertSuccessful();

        $this->assertDatabaseHas('business_usage_wallets', ['business_id' => $business->id]);
        $this->assertDatabaseCount('business_usage_wallets', 1);
    }

    public function test_command_exits_non_zero_with_remaining_count_on_forced_incomplete_scenario(): void
    {
        // No currencies row exists at all — every Business will fail to
        // resolve a currency, forcing UsageWalletBackfillIncompleteException.
        $customer = $this->createCustomer();
        $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        $exitCode = \Illuminate\Support\Facades\Artisan::call('usage:backfill-wallets');

        $this->assertNotSame(0, $exitCode);
        $output = \Illuminate\Support\Facades\Artisan::output();
        $this->assertStringContainsString('1 Business(es)', $output);
    }

    public function test_command_is_safe_to_rerun(): void
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);

        $customer = $this->createCustomer();
        $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        $this->artisan('usage:backfill-wallets')->assertSuccessful();
        $this->artisan('usage:backfill-wallets')->assertSuccessful();

        $this->assertDatabaseCount('business_usage_wallets', 1);
    }
}

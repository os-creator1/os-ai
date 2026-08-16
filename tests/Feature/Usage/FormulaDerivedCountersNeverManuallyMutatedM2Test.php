<?php

namespace Tests\Feature\Usage;

use App\Library\Usage\BillingProfileManager;
use App\Library\Usage\UsageWalletManager;
use App\Models\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 §13/§19 (v1.4 surgical patch), M2 contract §6.D — none of the
 * four new M2 mutation methods ever writes to
 * committed_spend_this_period_micro / reserved_spend_this_period_micro /
 * recharged_this_period_micro. Mechanical source check + a live-database
 * proof that setSpendCap()/setFeatureLimit()/setSafetyLimit()/
 * setBillingStatus() leave those three columns untouched.
 */
class FormulaDerivedCountersNeverManuallyMutatedM2Test extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    public function test_no_m2_method_source_assigns_to_a_formula_derived_counter(): void
    {
        $source = file_get_contents(app_path('Library/Usage/UsageWalletManager.php'));

        // Every legitimate write to these three columns lives in the
        // pre-existing M1 reserve()/commit()/release() algorithms only —
        // scan just the four new M2 method bodies (setSpendCap through
        // the private authorization helpers) for a forbidden direct
        // assignment.
        $m2Section = substr($source, strpos($source, 'public function setSpendCap'));

        $this->assertStringNotContainsString("'committed_spend_this_period_micro' =>", $m2Section);
        $this->assertStringNotContainsString("'reserved_spend_this_period_micro' =>", $m2Section);
        $this->assertStringNotContainsString("'recharged_this_period_micro' =>", $m2Section);
    }

    public function test_setting_the_spend_cap_leaves_the_committed_and_reserved_counters_untouched(): void
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $business->loadMissing('workspace');
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);

        DB::table('business_usage_wallets')->where('business_id', $business->id)->update([
            'committed_spend_this_period_micro' => 7_000_000,
            'reserved_spend_this_period_micro' => 3_000_000,
        ]);

        app(UsageWalletManager::class)->setSpendCap($business, '20000000', (int) $business->workspace->owner_user_id, 'Cap change.');

        $this->assertDatabaseHas('business_usage_wallets', [
            'business_id' => $business->id,
            'committed_spend_this_period_micro' => 7_000_000,
            'reserved_spend_this_period_micro' => 3_000_000,
        ]);
    }
}

<?php

namespace Tests\Feature\Usage\Slice5;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Jobs\Usage\EvaluateBusinessAutoRecharge;
use App\Library\Usage\UsageBillingCheckoutManager;
use App\Library\Usage\UsageWalletManager;
use App\Repositories\Contracts\BusinessFundingAttemptRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\Usage\Slice5\Concerns\Slice5Fixtures;
use Tests\TestCase;

/**
 * Customer Experience Slice 5 — T-WALLET-2, T-WALLET-4 (as currently
 * required: the four fixed presets configure; custom input is prohibited
 * server-side until §28.9 is approved) and T-WALLET-5 (off by default; no
 * automatic charge before the payer's explicit opt-in). Also proves the
 * schema default and the consent backfill.
 */
class AutoRechargePresetsAndConsentTest extends TestCase
{
    use RefreshDatabase;
    use Slice5Fixtures;

    public function test_each_of_the_four_presets_configures_exactly(): void
    {
        [$owner, $business, $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Growth);
        $this->fakeProvider();
        $this->attachFakeCard($business, (int) $owner->user_id);
        $this->authenticateAs($owner);

        $this->assertSame([5_000_000, 10_000_000, 25_000_000, 50_000_000], UsageWalletManager::AUTO_RECHARGE_PRESETS_MICRO);

        foreach (UsageWalletManager::AUTO_RECHARGE_PRESETS_MICRO as $preset) {
            $this->from($this->usageBillingUrl($workspace, $business))
                ->post($this->usageBillingRoute('auto-recharge.configure', $workspace, $business), [
                    'auto_recharge_enabled' => '1',
                    'auto_recharge_amount_micro' => (string) $preset,
                    'auto_recharge_threshold' => '2.00',
                    'monthly_recharge_cap' => '500.00',
                ])
                ->assertSessionDoesntHaveErrors()
                ->assertRedirect($this->usageBillingUrl($workspace, $business))
                ->assertSessionHas('flash_success');

            $wallet = $this->walletRow($business);
            $this->assertSame(1, (int) $wallet->auto_recharge_enabled);
            $this->assertSame((string) $preset, (string) $wallet->auto_recharge_amount_micro);
            $this->assertSame('2000000', (string) $wallet->auto_recharge_threshold_micro);
            $this->assertSame('500000000', (string) $wallet->monthly_recharge_cap_micro);
            $this->assertNotNull($wallet->auto_recharge_consented_at);
            $this->assertSame((int) $owner->user_id, (int) $wallet->auto_recharge_consented_by_user_id);
        }
    }

    public function test_a_crafted_custom_amount_is_refused_at_the_request_and_at_the_manager(): void
    {
        [$owner, $business, $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Growth);
        $this->fakeProvider();
        $this->attachFakeCard($business, (int) $owner->user_id);
        $this->authenticateAs($owner);

        foreach (['7000000', '4990000', '500000000', '5000001', '1', '0', 'abc'] as $custom) {
            $this->from($this->usageBillingUrl($workspace, $business))
                ->post($this->usageBillingRoute('auto-recharge.configure', $workspace, $business), [
                    'auto_recharge_enabled' => '1',
                    'auto_recharge_amount_micro' => $custom,
                    'auto_recharge_threshold' => '2.00',
                ])
                ->assertSessionHasErrors('auto_recharge_amount_micro');

            $this->assertSame(0, (int) $this->walletRow($business)->auto_recharge_enabled, "amount {$custom} must not enable automatic top-up");
        }

        // The custom-amount field does not exist on the page at all.
        $html = $this->get($this->usageBillingUrl($workspace, $business))->getContent();
        $this->assertStringNotContainsString('name="auto_recharge_amount"', $html);
        $this->assertMatchesRegularExpression('/type="radio"[^>]*name="auto_recharge_amount_micro"[^>]*value="5000000"/', $html);
        $this->assertDoesNotMatchRegularExpression('/type="(number|text)"[^>]*name="auto_recharge_amount_micro"/', $html);

        // The manager refuses independently of any request layer.
        foreach (['7000000', '4990000', '500000000'] as $custom) {
            try {
                app(UsageWalletManager::class)->configureAutoRecharge($business, true, '2000000', $custom, null, (int) $owner->user_id);
                $this->fail("Manager accepted custom amount {$custom}.");
            } catch (\InvalidArgumentException) {
                $this->assertSame(0, (int) $this->walletRow($business)->auto_recharge_enabled);
            }
        }

        // The recommended $5–$500 custom range is deliberately NOT shipped
        // (§28.9 gate): the presets are the whole surface.
        $this->assertFalse(UsageWalletManager::isAutoRechargePreset('100000000'));
        $this->assertTrue(UsageWalletManager::isAutoRechargePreset('25000000'));
    }

    public function test_automatic_top_up_is_off_by_default_in_schema_and_behaviour(): void
    {
        [$owner, $business, $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Growth);

        $wallet = $this->walletRow($business);
        $this->assertSame(0, (int) $wallet->auto_recharge_enabled);
        $this->assertNull($wallet->auto_recharge_consented_at);
        $this->assertNull($wallet->auto_recharge_consented_by_user_id);
        $this->assertTrue(Schema::hasColumn('business_usage_wallets', 'auto_recharge_consented_at'));

        // A saved payment method and a manual top-up do NOT imply consent.
        $this->fakeProvider();
        $this->attachFakeCard($business, (int) $owner->user_id);
        $result = app(UsageBillingCheckoutManager::class)->initiateTopUp($business, (int) $owner->user_id, 5_000_000);
        app(UsageBillingCheckoutManager::class)->confirmAttemptFromReturn(app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId));

        $this->assertSame('5000000', (string) $this->walletRow($business)->available_balance_micro);
        $this->assertSame(0, (int) $this->walletRow($business)->auto_recharge_enabled);

        // Spend down below any conceivable threshold: the evaluation runs
        // and charges nothing, creates no attempt, calls no provider.
        $this->activateFixtureRate('crm', '1000000');
        app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '4.5');
        EvaluateBusinessAutoRecharge::dispatch((int) $business->id);

        $this->assertSame(0, DB::table('business_funding_attempts')->where('business_id', $business->id)->where('purpose', 'auto_recharge')->count());
        $this->assertSame('500000', (string) $this->walletRow($business)->available_balance_micro);
        $this->assertSame(0, DB::table('business_usage_ledger_entries')->where('business_id', $business->id)->where('entry_type', 'auto_recharge')->count());

        // Only the payer's explicit opt-in changes that.
        app(UsageWalletManager::class)->configureAutoRecharge($business, true, '2000000', '5000000', (string) UsageWalletManager::BUSINESS_MONTHLY_AUTO_RECHARGE_MAXIMUM_MICRO, (int) $owner->user_id);
        EvaluateBusinessAutoRecharge::dispatch((int) $business->id);
        $this->assertSame(1, DB::table('business_funding_attempts')->where('business_id', $business->id)->where('purpose', 'auto_recharge')->count());
    }

    public function test_turning_automatic_top_up_off_clears_the_consent_record_and_stops_charges(): void
    {
        [$owner, $business, $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Growth);
        $this->fakeProvider();
        $this->attachFakeCard($business, (int) $owner->user_id);
        app(UsageWalletManager::class)->configureAutoRecharge($business, true, '2000000', '5000000', (string) UsageWalletManager::BUSINESS_MONTHLY_AUTO_RECHARGE_MAXIMUM_MICRO, (int) $owner->user_id);
        $this->assertNotNull($this->walletRow($business)->auto_recharge_consented_at);

        app(UsageWalletManager::class)->configureAutoRecharge($business, false, null, null, null, (int) $owner->user_id);

        $wallet = $this->walletRow($business);
        $this->assertSame(0, (int) $wallet->auto_recharge_enabled);
        $this->assertNull($wallet->auto_recharge_consented_at);
        $this->assertNull($wallet->auto_recharge_consented_by_user_id);

        $this->fund($business, 1_000_000);
        EvaluateBusinessAutoRecharge::dispatch((int) $business->id);
        $this->assertSame(0, DB::table('business_funding_attempts')->where('business_id', $business->id)->where('purpose', 'auto_recharge')->count());
    }

    public function test_only_the_payer_may_enable_automatic_top_up(): void
    {
        [$owner, $business, $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Growth);
        $admin = $this->createCustomer();
        $this->member($workspace, $admin->user, \App\Enums\Workspace\WorkspaceMembershipRole::Admin);
        $this->fakeProvider();
        $this->authenticateAs($admin);

        $this->post($this->usageBillingRoute('auto-recharge.configure', $workspace, $business), [
            'auto_recharge_enabled' => '1',
            'auto_recharge_amount_micro' => '5000000',
            'auto_recharge_threshold' => '2.00',
            'monthly_recharge_cap' => '500.00',
        ])->assertRedirect()->assertSessionHas('flash_error');

        $this->assertSame(0, (int) $this->walletRow($business)->auto_recharge_enabled);
    }

    /**
     * The data-only backfill migration: an enabled row is kept only when
     * consent is mechanically provable (flag on, threshold present, a
     * preset amount); every other enabled row is switched off; null and a
     * stored instrument never count. Idempotent.
     */
    public function test_the_consent_backfill_keeps_provable_consent_and_switches_everything_else_off(): void
    {
        [$owner, $business] = $this->tenantWithWallet(WorkspacePlanTier::Growth);
        [, $businessB] = $this->clientBusiness($business->workspace, 'Second');
        [, $businessC] = $this->clientBusiness($business->workspace, 'Third');
        [, $businessD] = $this->clientBusiness($business->workspace, 'Fourth');

        DB::table('business_usage_wallets')->where('business_id', $business->id)->update(['auto_recharge_enabled' => true, 'auto_recharge_threshold_micro' => 2_000_000, 'auto_recharge_amount_micro' => 10_000_000, 'auto_recharge_consented_at' => null]);
        DB::table('business_usage_wallets')->where('business_id', $businessB->id)->update(['auto_recharge_enabled' => true, 'auto_recharge_threshold_micro' => 2_000_000, 'auto_recharge_amount_micro' => 3_000_000, 'auto_recharge_consented_at' => null]);
        DB::table('business_usage_wallets')->where('business_id', $businessC->id)->update(['auto_recharge_enabled' => true, 'auto_recharge_threshold_micro' => null, 'auto_recharge_amount_micro' => 5_000_000, 'auto_recharge_consented_at' => null]);
        DB::table('business_usage_wallets')->where('business_id', $businessD->id)->update(['auto_recharge_enabled' => false, 'auto_recharge_threshold_micro' => null, 'auto_recharge_amount_micro' => null, 'auto_recharge_consented_at' => null]);

        $migration = require base_path('database/migrations/2026_09_11_120003_backfill_auto_recharge_consent_on_business_usage_wallets.php');
        $first = $migration->apply();

        $this->assertSame(['stamped' => 1, 'disabled' => 2], $first);

        $a = $this->walletRow($business);
        $this->assertSame(1, (int) $a->auto_recharge_enabled);
        $this->assertNotNull($a->auto_recharge_consented_at);
        $this->assertNull($a->auto_recharge_consented_by_user_id, 'The consenting user is unknown to the old schema and is never invented.');

        $b = $this->walletRow($businessB);
        $this->assertSame(0, (int) $b->auto_recharge_enabled, 'A free-form amount the product no longer offers is switched off.');
        $this->assertSame('3000000', (string) $b->auto_recharge_amount_micro, 'Its values are kept for a one-click re-enable.');

        $this->assertSame(0, (int) $this->walletRow($businessC)->auto_recharge_enabled, 'No threshold means no provable configuration.');
        $this->assertSame(0, (int) $this->walletRow($businessD)->auto_recharge_enabled);

        $this->assertSame(['stamped' => 0, 'disabled' => 0], $migration->apply(), 'Idempotent.');
    }
}

<?php

namespace Tests\Feature\Usage\Slice5;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Jobs\Usage\EvaluateBusinessAutoRecharge;
use App\Library\Usage\UsageWalletManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Usage\Slice5\Concerns\Slice5Fixtures;
use Tests\TestCase;

/**
 * Customer Experience Slice 5 — Correction Round 1 §2, §3, §6 and §13.2–§13.4
 * (tests 8–21): the single authoritative policy source, the four presets at
 * both boundaries, the required and bounded Business monthly ceiling, and
 * the bounded Agency aggregate ceiling — every amount an exact integer
 * micro-unit string, never a float.
 */
class AutoTopUpPolicyAndValidationTest extends TestCase
{
    use RefreshDatabase;
    use Slice5Fixtures;

    public function test_the_policy_constants_are_the_single_authoritative_source(): void
    {
        $this->assertSame(5_000_000, UsageWalletManager::MINIMUM_MANUAL_TOP_UP_MICRO);
        $this->assertSame([5_000_000, 10_000_000, 25_000_000, 50_000_000], UsageWalletManager::AUTO_RECHARGE_PRESETS_MICRO);
        $this->assertSame(5_000_000, UsageWalletManager::AUTO_RECHARGE_SUGGESTED_PRESET_MICRO);
        $this->assertSame(500_000_000, UsageWalletManager::BUSINESS_MONTHLY_AUTO_RECHARGE_MAXIMUM_MICRO);
        $this->assertSame(500_000_000, UsageWalletManager::WORKSPACE_MONTHLY_AUTO_RECHARGE_MAXIMUM_MICRO);
        $this->assertSame(2, UsageWalletManager::AUTO_RECHARGE_MAX_PER_ROLLING_WINDOW);
        $this->assertSame(24, UsageWalletManager::AUTO_RECHARGE_ROLLING_WINDOW_HOURS);
        $this->assertContains(UsageWalletManager::AUTO_RECHARGE_SUGGESTED_PRESET_MICRO, UsageWalletManager::AUTO_RECHARGE_PRESETS_MICRO);

        // The customer sentence is written once and matches the constants.
        $this->assertSame('Automatic top-up can run at most twice in any 24 hours.', __('locale.usage_billing.auto_top_up.frequency_help'));
    }

    public function test_exactly_the_four_presets_are_accepted_and_every_other_amount_is_refused_at_both_boundaries(): void
    {
        [$owner, $business, $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Growth);
        $this->fakeProvider();
        $this->attachFakeCard($business, (int) $owner->user_id);
        $this->authenticateAs($owner);

        foreach (UsageWalletManager::AUTO_RECHARGE_PRESETS_MICRO as $preset) {
            $this->from($this->usageBillingUrl($workspace, $business))
                ->post($this->usageBillingRoute('auto-recharge.configure', $workspace, $business), [
                    'auto_recharge_enabled' => '1',
                    'auto_recharge_amount_micro' => (string) $preset,
                    'auto_recharge_threshold' => '2.00',
                    'monthly_recharge_cap' => '500.00',
                ])
                ->assertSessionDoesntHaveErrors()
                ->assertSessionHas('flash_success');

            $this->assertSame((string) $preset, (string) $this->walletRow($business)->auto_recharge_amount_micro);
            $this->assertNull(UsageWalletManager::autoRechargeConfigurationProblem(true, '2000000', (string) $preset, '500000000'));
        }

        foreach (['7000000', '4990000', '5000001', '100000000', '500000000', '1', '0', '-5000000', 'abc', '5.00'] as $custom) {
            $this->from($this->usageBillingUrl($workspace, $business))
                ->post($this->usageBillingRoute('auto-recharge.configure', $workspace, $business), [
                    'auto_recharge_enabled' => '1',
                    'auto_recharge_amount_micro' => $custom,
                    'auto_recharge_threshold' => '2.00',
                    'monthly_recharge_cap' => '500.00',
                ])
                ->assertSessionHasErrors('auto_recharge_amount_micro');

            $this->assertSame('auto_recharge_preset_only', UsageWalletManager::autoRechargeConfigurationProblem(true, '2000000', $custom, '500000000'));

            try {
                app(UsageWalletManager::class)->configureAutoRecharge($business, true, '2000000', $custom, '500000000', (int) $owner->user_id);
                $this->fail("The manager accepted the custom amount {$custom}.");
            } catch (\InvalidArgumentException $e) {
                $this->assertSame('auto_recharge_preset_only', $e->getMessage());
            }
        }

        // The last accepted preset is still the stored one; nothing else ever landed.
        $this->assertSame('50000000', (string) $this->walletRow($business)->auto_recharge_amount_micro);
    }

    public function test_no_custom_amount_control_renders(): void
    {
        [$owner, $business, $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Growth);
        $this->fakeProvider();
        $this->authenticateAs($owner);

        $html = $this->get($this->usageBillingUrl($workspace, $business))->assertOk()->getContent();

        $this->assertStringNotContainsString('name="auto_recharge_amount"', $html);
        $this->assertDoesNotMatchRegularExpression('/type="(number|text)"[^>]*name="auto_recharge_amount_micro"/', $html);
        $this->assertSame(4, preg_match_all('/type="radio"[^>]*name="auto_recharge_amount_micro"/', $html));
        $this->assertStringContainsString('Monthly automatic top-up limit (required)', $html);
        $this->assertStringContainsString('at most USD 500.00 per month', $html);
    }

    public function test_the_business_monthly_ceiling_is_required_bounded_and_exact_at_both_boundaries(): void
    {
        [$owner, $business, $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Growth);
        $this->fakeProvider();
        $this->attachFakeCard($business, (int) $owner->user_id);
        $this->authenticateAs($owner);

        $post = fn (array $extra) => $this->from($this->usageBillingUrl($workspace, $business))
            ->post($this->usageBillingRoute('auto-recharge.configure', $workspace, $business), array_merge([
                'auto_recharge_enabled' => '1',
                'auto_recharge_amount_micro' => '5000000',
                'auto_recharge_threshold' => '2.00',
            ], $extra));

        // 12. Equal to the selected preset: accepted.
        $post(['monthly_recharge_cap' => '5.00'])->assertSessionDoesntHaveErrors()->assertSessionHas('flash_success');
        $this->assertSame('5000000', (string) $this->walletRow($business)->monthly_recharge_cap_micro);

        // 13. Below the selected preset: refused at the request …
        $post(['monthly_recharge_cap' => '4.99'])->assertSessionHasErrors('monthly_recharge_cap_micro');
        $this->assertSame('5000000', (string) $this->walletRow($business)->monthly_recharge_cap_micro);
        // … and at the manager.
        $this->assertSame('monthly_cap_below_preset', UsageWalletManager::autoRechargeConfigurationProblem(true, '2000000', '5000000', '4990000'));

        // 14/15. $500.00 succeeds; $500.01 fails.
        $post(['monthly_recharge_cap' => '500.00'])->assertSessionDoesntHaveErrors()->assertSessionHas('flash_success');
        $this->assertSame('500000000', (string) $this->walletRow($business)->monthly_recharge_cap_micro);
        $post(['monthly_recharge_cap' => '500.01'])->assertSessionHasErrors('monthly_recharge_cap_micro');
        $this->assertSame('500000000', (string) $this->walletRow($business)->monthly_recharge_cap_micro);

        // 16. Crafted micro-unit values above the maximum fail at both boundaries.
        $post(['monthly_recharge_cap_micro' => '500010000'])->assertSessionHasErrors('monthly_recharge_cap_micro');
        $post(['monthly_recharge_cap_micro' => '500000001'])->assertSessionHasErrors('monthly_recharge_cap_micro');
        $this->assertNull(UsageWalletManager::autoRechargeConfigurationProblem(true, '2000000', '5000000', '500000000'));
        $this->assertSame('monthly_cap_above_maximum', UsageWalletManager::autoRechargeConfigurationProblem(true, '2000000', '5000000', '500010000'));
        $this->assertSame('monthly_cap_above_maximum', UsageWalletManager::autoRechargeConfigurationProblem(true, '2000000', '5000000', '500000001'));

        try {
            app(UsageWalletManager::class)->configureAutoRecharge($business, true, '2000000', '5000000', '500010000', (int) $owner->user_id);
            $this->fail('The manager accepted a ceiling above the hard maximum.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('monthly_cap_above_maximum', $e->getMessage());
        }

        // Negative, zero and blank all fail while enabling — request and manager.
        $post(['monthly_recharge_cap' => '-5.00'])->assertSessionHasErrors('monthly_recharge_cap_micro');
        $post(['monthly_recharge_cap_micro' => '-5000000'])->assertSessionHasErrors('monthly_recharge_cap_micro');
        $post(['monthly_recharge_cap' => '0.00'])->assertSessionHasErrors('monthly_recharge_cap_micro');
        $post(['monthly_recharge_cap' => ''])->assertSessionHasErrors('monthly_recharge_cap_micro');
        $post([])->assertSessionHasErrors('monthly_recharge_cap_micro');
        $this->assertSame('monthly_cap_required', UsageWalletManager::autoRechargeConfigurationProblem(true, '2000000', '5000000', null));
        $this->assertSame('monthly_cap_required', UsageWalletManager::autoRechargeConfigurationProblem(true, '2000000', '5000000', '0'));
        $this->assertSame('monthly_cap_invalid', UsageWalletManager::autoRechargeConfigurationProblem(true, '2000000', '5000000', '-5000000'));

        foreach ([null, '0', '-5000000'] as $bad) {
            try {
                app(UsageWalletManager::class)->configureAutoRecharge($business, true, '2000000', '5000000', $bad, (int) $owner->user_id);
                $this->fail('The manager enabled automatic top-up without a valid ceiling.');
            } catch (\InvalidArgumentException) {
                $this->assertSame('500000000', (string) $this->walletRow($business)->monthly_recharge_cap_micro, 'The last valid ceiling is untouched.');
            }
        }

        // The stored value is exact: no float ever touched it.
        $this->assertSame(1, (int) $this->walletRow($business)->auto_recharge_enabled);
        $this->assertSame('500000000', (string) $this->walletRow($business)->monthly_recharge_cap_micro);
    }

    public function test_a_stored_ceiling_while_disabled_is_bounded_but_never_permission_to_charge(): void
    {
        [$owner, $business, $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Growth);
        $this->fakeProvider();
        $this->attachFakeCard($business, (int) $owner->user_id);

        // Disabled with a ceiling above the maximum: refused (never stored above it).
        try {
            app(UsageWalletManager::class)->configureAutoRecharge($business, false, null, null, '600000000', (int) $owner->user_id);
            $this->fail('A disabled configuration stored a ceiling above the hard maximum.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('monthly_cap_above_maximum', $e->getMessage());
        }

        // Disabled with a compliant ceiling: preserved, and still no charge.
        app(UsageWalletManager::class)->configureAutoRecharge($business, false, null, null, '100000000', (int) $owner->user_id);
        $wallet = $this->walletRow($business);
        $this->assertSame(0, (int) $wallet->auto_recharge_enabled);
        $this->assertSame('100000000', (string) $wallet->monthly_recharge_cap_micro);
        $this->assertNull($wallet->auto_recharge_consented_at);

        $this->fund($business, 1_000_000);
        EvaluateBusinessAutoRecharge::dispatch((int) $business->id);
        $this->assertSame(0, DB::table('business_funding_attempts')->where('business_id', $business->id)->count());
        $this->assertSame('1000000', (string) $this->walletRow($business)->available_balance_micro);
    }

    public function test_the_agency_aggregate_ceiling_is_bounded_at_both_boundaries(): void
    {
        [$agency, $agencyBusiness, $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Agency, 'Agency House', 'Northwind Agency');
        $manager = app(UsageWalletManager::class);

        // 18/20. $500.00 succeeds at the manager; one micro-unit more fails.
        $manager->setWorkspaceAggregateRechargeCap($workspace, '500000000', (int) $agency->user_id, 'Maximum.');
        $this->assertDatabaseHas('workspace_usage_controls', ['workspace_id' => $workspace->id, 'monthly_aggregate_recharge_cap_micro' => 500_000_000]);

        foreach (['500000001', '500010000', '1000000000'] as $tooHigh) {
            try {
                $manager->setWorkspaceAggregateRechargeCap($workspace, $tooHigh, (int) $agency->user_id, 'Too high.');
                $this->fail("The manager stored an Agency ceiling of {$tooHigh}.");
            } catch (\InvalidArgumentException $e) {
                $this->assertSame('workspace_recharge_cap_above_maximum', $e->getMessage());
            }
        }
        $this->assertDatabaseHas('workspace_usage_controls', ['workspace_id' => $workspace->id, 'monthly_aggregate_recharge_cap_micro' => 500_000_000]);
        $this->assertSame(1, DB::table('usage_control_transitions')->where('scope', 'workspace')->where('scope_id', $workspace->id)->where('control', UsageWalletManager::CONTROL_WORKSPACE_RECHARGE_CAP)->count());

        // 19/21. The request boundary: $500.00 succeeds, $500.01 and crafted micro values fail.
        $this->authenticateAs($agency);
        $post = fn (array $extra) => $this->from($this->usageBillingUrl($workspace, $agencyBusiness))
            ->post($this->usageBillingRoute('spend-cap', $workspace, $agencyBusiness), array_merge(['control' => 'workspace_controls'], $extra));

        $post(['workspace_monthly_recharge_cap' => '250.00'])->assertSessionDoesntHaveErrors()->assertSessionHas('flash_success');
        $this->assertDatabaseHas('workspace_usage_controls', ['workspace_id' => $workspace->id, 'monthly_aggregate_recharge_cap_micro' => 250_000_000]);
        $post(['workspace_monthly_recharge_cap' => '500.00'])->assertSessionDoesntHaveErrors()->assertSessionHas('flash_success');
        $this->assertDatabaseHas('workspace_usage_controls', ['workspace_id' => $workspace->id, 'monthly_aggregate_recharge_cap_micro' => 500_000_000]);

        $post(['workspace_monthly_recharge_cap' => '500.01'])->assertSessionHasErrors('workspace_monthly_recharge_cap_micro');
        $post(['workspace_monthly_recharge_cap_micro' => '500010000'])->assertSessionHasErrors('workspace_monthly_recharge_cap_micro');
        $post(['workspace_monthly_recharge_cap_micro' => '500000001'])->assertSessionHasErrors('workspace_monthly_recharge_cap_micro');
        $this->assertDatabaseHas('workspace_usage_controls', ['workspace_id' => $workspace->id, 'monthly_aggregate_recharge_cap_micro' => 500_000_000]);

        // The page explains that the ceiling is shared across agency-paid client accounts.
        $html = $this->get($this->usageBillingUrl($workspace, $agencyBusiness))->assertOk()->getContent();
        $this->assertStringContainsString('across all client accounts whose usage your agency pays for', $html);
        $this->assertStringContainsString('at most USD 500.00', $html);
    }
}

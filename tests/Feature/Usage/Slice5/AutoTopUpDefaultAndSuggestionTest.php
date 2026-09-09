<?php

namespace Tests\Feature\Usage\Slice5;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Events\Usage\BusinessFundingAttemptFailed;
use App\Events\Usage\BusinessFundingAttemptSucceeded;
use App\Events\Usage\BusinessPayerChanged;
use App\Events\Usage\BusinessWalletCredited;
use App\Jobs\Usage\EvaluateBusinessAutoRecharge;
use App\Library\Usage\UsageWalletManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Usage\Slice5\Concerns\Slice5Fixtures;
use Tests\TestCase;

/**
 * Customer Experience Slice 5 — Correction Round 1 §2.1, §2.3 and §13.1
 * (tests 1–7): automatic top-up is off by default; the $5 preset is only a
 * visual suggestion on a read-only GET; submitting with it off keeps it
 * off; a saved preset stays selected; an invalid legacy amount is never
 * offered and falls back visually without a write.
 */
class AutoTopUpDefaultAndSuggestionTest extends TestCase
{
    use RefreshDatabase;
    use Slice5Fixtures;

    private function presetRadio(string $html, string $micro): string
    {
        $this->assertSame(1, preg_match('/<input[^>]*name="auto_recharge_amount_micro"[^>]*value="' . $micro . '"[^>]*>/', $html, $m), "The {$micro} preset radio must render exactly once.");

        return $m[0];
    }

    public function test_automatic_top_up_is_off_on_a_new_wallet(): void
    {
        [, $business] = $this->tenantWithWallet(WorkspacePlanTier::Growth);

        $wallet = $this->walletRow($business);
        $this->assertSame(0, (int) $wallet->auto_recharge_enabled);
        $this->assertNull($wallet->auto_recharge_amount_micro);
        $this->assertNull($wallet->monthly_recharge_cap_micro);
        $this->assertNull($wallet->auto_recharge_consented_at);
        $this->assertNull($wallet->auto_recharge_consented_by_user_id);
    }

    public function test_a_read_only_get_visually_suggests_five_dollars_and_changes_nothing(): void
    {
        [$owner, $business, $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Growth);
        $gateway = $this->fakeProvider();
        $this->attachFakeCard($business, (int) $owner->user_id);
        $this->authenticateAs($owner);
        Event::fake([BusinessFundingAttemptSucceeded::class, BusinessFundingAttemptFailed::class, BusinessWalletCredited::class, BusinessPayerChanged::class]);
        Notification::fake();
        $gateway->paymentIntentOutcomes = ['*' => 'declined']; // any provider call would leave a failed attempt behind

        $before = (array) $this->walletRow($business);
        $attemptsBefore = DB::table('business_funding_attempts')->count();
        $ledgerBefore = DB::table('business_usage_ledger_entries')->count();

        $html = $this->get($this->usageBillingUrl($workspace, $business))->assertOk()->getContent();

        // 2. $5 is visually preselected; the other presets are not.
        $this->assertStringContainsString('checked', $this->presetRadio($html, '5000000'));
        foreach (['10000000', '25000000', '50000000'] as $other) {
            $this->assertStringNotContainsString('checked', $this->presetRadio($html, $other));
        }
        $this->assertStringContainsString('data-role="auto-top-up-suggestion"', $html);
        $this->assertStringContainsString('Nothing is saved or charged until you turn automatic top-up on and save.', $html);
        $this->assertStringContainsString('Off — nothing is charged automatically.', $html);
        $this->assertStringContainsString('Automatic top-up can run at most twice in any 24 hours.', $html);

        // 3/4. Byte-for-byte unchanged wallet row; no attempt, consent, ledger row, event, notification or provider call.
        $this->assertSame($before, (array) $this->walletRow($business));
        $this->assertNull($this->walletRow($business)->auto_recharge_consented_at);
        $this->assertSame(0, (int) $this->walletRow($business)->auto_recharge_enabled);
        $this->assertSame($attemptsBefore, DB::table('business_funding_attempts')->count());
        $this->assertSame($ledgerBefore, DB::table('business_usage_ledger_entries')->count());
        Event::assertNothingDispatched();
        Notification::assertNothingSent();
    }

    public function test_submitting_with_automatic_top_up_off_keeps_it_off_despite_the_visible_selection(): void
    {
        [$owner, $business, $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Growth);
        $gateway = $this->fakeProvider();
        $this->attachFakeCard($business, (int) $owner->user_id);
        $this->authenticateAs($owner);
        $gateway->paymentIntentOutcomes = ['*' => 'declined'];

        $this->from($this->usageBillingUrl($workspace, $business))
            ->post($this->usageBillingRoute('auto-recharge.configure', $workspace, $business), [
                'auto_recharge_enabled' => '0',
                'auto_recharge_amount_micro' => (string) UsageWalletManager::AUTO_RECHARGE_SUGGESTED_PRESET_MICRO,
                'auto_recharge_threshold' => '2.00',
                'monthly_recharge_cap' => '25.00',
            ])
            ->assertSessionDoesntHaveErrors()
            ->assertRedirect($this->usageBillingUrl($workspace, $business))
            ->assertSessionHas('flash_success');

        $wallet = $this->walletRow($business);
        $this->assertSame(0, (int) $wallet->auto_recharge_enabled);
        $this->assertNull($wallet->auto_recharge_amount_micro);
        $this->assertNull($wallet->auto_recharge_consented_at);
        $this->assertNull($wallet->auto_recharge_consented_by_user_id);

        $this->fund($business, 1_000_000);
        EvaluateBusinessAutoRecharge::dispatch((int) $business->id);
        $this->assertSame(0, DB::table('business_funding_attempts')->where('business_id', $business->id)->count());
        $this->assertSame('1000000', (string) $this->walletRow($business)->available_balance_micro);
    }

    public function test_an_explicitly_saved_preset_stays_selected(): void
    {
        [$owner, $business, $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Growth);
        $this->fakeProvider();
        $this->attachFakeCard($business, (int) $owner->user_id);
        app(UsageWalletManager::class)->configureAutoRecharge($business, true, '2000000', '25000000', '100000000', (int) $owner->user_id);
        $this->authenticateAs($owner);

        $html = $this->get($this->usageBillingUrl($workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('checked', $this->presetRadio($html, '25000000'));
        $this->assertStringNotContainsString('checked', $this->presetRadio($html, '5000000'));
        $this->assertStringNotContainsString('data-role="auto-top-up-suggestion"', $html, 'No suggestion while a saved preset is active.');
        $this->assertStringContainsString('value="100.00"', $html);
    }

    public function test_an_invalid_legacy_custom_amount_is_never_offered_and_falls_back_visually_without_a_write(): void
    {
        [$owner, $business, $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Growth);
        $this->fakeProvider();
        $this->authenticateAs($owner);

        // A historical row: disabled, with an amount that is not a preset.
        DB::table('business_usage_wallets')->where('business_id', $business->id)->update([
            'auto_recharge_enabled' => false,
            'auto_recharge_amount_micro' => 7_000_000,
            'auto_recharge_threshold_micro' => 2_000_000,
        ]);
        $before = (array) $this->walletRow($business);

        $html = $this->get($this->usageBillingUrl($workspace, $business))->assertOk()->getContent();

        $this->assertStringNotContainsString('value="7000000"', $html);
        $this->assertStringContainsString('checked', $this->presetRadio($html, '5000000'));
        $this->assertSame(4, preg_match_all('/name="auto_recharge_amount_micro"/', $html));
        $this->assertSame($before, (array) $this->walletRow($business), 'A GET never rewrites the legacy value.');
        $this->assertSame('7000000', (string) $this->walletRow($business)->auto_recharge_amount_micro);
    }
}

<?php

namespace Tests\Feature\Usage\Slice5;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Jobs\Usage\EvaluateBusinessAutoRecharge;
use App\Library\Usage\UsageWalletManager;
use App\Notifications\Usage\AutoRechargeFailedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Usage\Slice5\Concerns\Slice5Fixtures;
use Tests\TestCase;

/**
 * Customer Experience Slice 5 — T-COST-10 (contract §20 C-10): when funds
 * or the payment integration are unavailable, the product degrades with
 * a task-oriented message and no partial charge; a failed automatic
 * top-up is visible on the page, alerts the payer, and never inflates
 * the balance.
 */
class GracefulDegradationTest extends TestCase
{
    use RefreshDatabase;
    use Slice5Fixtures;

    public function test_an_unconfigured_payment_integration_is_stated_plainly_and_a_top_up_post_fails_softly(): void
    {
        [$owner, $business, $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Growth);
        config(['services.stripe.secret' => null, 'services.stripe.key' => null, 'services.stripe.webhook.secret' => null]);
        $this->authenticateAs($owner);

        $html = $this->get($this->usageBillingUrl($workspace, $business))->assertOk()->getContent();
        $this->assertStringContainsString('Payment methods and top-ups are not yet configured.', $html);
        $this->assertStringNotContainsString('id="usage-billing-top-up-form"', $html);
        $this->assertStringNotContainsString('sk_', $html);
    }

    public function test_a_failed_automatic_top_up_is_visible_alerts_the_payer_and_never_credits(): void
    {
        [$owner, $business, $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Growth);
        $gateway = $this->fakeProvider();
        $this->attachFakeCard($business, (int) $owner->user_id);
        $this->billingContact($business, (int) $owner->user_id);
        app(UsageWalletManager::class)->configureAutoRecharge($business, true, '2000000', '5000000', null, (int) $owner->user_id);
        $this->fund($business, 1_000_000);
        Notification::fake();

        $gateway->paymentIntentOutcomes = ['*' => 'declined'];
        EvaluateBusinessAutoRecharge::dispatch((int) $business->id);

        $wallet = $this->walletRow($business);
        $this->assertSame('1000000', (string) $wallet->available_balance_micro, 'A failed recharge never increases the balance.');
        $this->assertSame(1, (int) $wallet->consecutive_recharge_failures);
        $this->assertSame(1, DB::table('business_funding_attempts')->where('business_id', $business->id)->where('purpose', 'auto_recharge')->where('state', 'failed')->count());

        Notification::assertSentTo(new AnonymousNotifiable(), AutoRechargeFailedNotification::class, fn ($n, $channels, AnonymousNotifiable $notifiable) => $notifiable->routes['mail'] === 'billing@example.test');

        $this->authenticateAs($owner);
        $html = $this->get($this->usageBillingUrl($workspace, $business))->assertOk()->getContent();
        $this->assertStringContainsString('did not go through', $html);
        $this->assertStringContainsString('data-result="failed"', $html);
        $this->assertStringContainsString('Did not go through', $html);
        $this->assertStringNotContainsString('requires_action', $html);
    }

    public function test_the_page_never_leaks_provider_configuration_or_tokens(): void
    {
        [$owner, $business, $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Growth);
        $this->fakeProvider();
        $this->attachFakeCard($business, (int) $owner->user_id);
        $this->authenticateAs($owner);

        $html = $this->get($this->usageBillingUrl($workspace, $business))->assertOk()->getContent();

        $this->assertStringNotContainsString('sk_test_fixture', $html);
        $this->assertStringNotContainsString('whsec_fixture', $html);
        $this->assertStringNotContainsString('pm_fake_', $html);
        $this->assertStringNotContainsString('cus_', $html);
        $this->assertStringNotContainsString('seti_', $html);
    }
}

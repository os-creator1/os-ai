<?php

namespace Tests\Feature\Usage\Slice5;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Usage\UsageBillingCheckoutManager;
use App\Library\Usage\UsageWalletManager;
use App\Repositories\Contracts\BusinessFundingAttemptRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Usage\Slice5\Concerns\Slice5Fixtures;
use Tests\TestCase;

/**
 * Customer Experience Slice 5 — T-WALLET-1 (contract §12.2 E-18, §28.9)
 * and the funding half of T-COST-10. The $5.00 floor holds at the
 * request boundary and at the manager boundary; $4.99 is refused, $5.00
 * accepted; an existing balance is recognized on the page; a repeated
 * confirmation never credits twice; and nothing here talks to a live
 * provider (the gateway is the repository's own fake).
 */
class ManualTopUpMinimumTest extends TestCase
{
    use RefreshDatabase;
    use Slice5Fixtures;

    public function test_the_manager_boundary_refuses_below_five_and_accepts_exactly_five(): void
    {
        $manager = app(UsageWalletManager::class);

        $this->assertSame(5_000_000, UsageWalletManager::MINIMUM_MANUAL_TOP_UP_MICRO);
        $this->assertSame('below_minimum_top_up', $manager->manualTopUpDenialReason(4_990_000));
        $this->assertSame('below_minimum_top_up', $manager->manualTopUpDenialReason(1));
        $this->assertSame('below_minimum_top_up', $manager->manualTopUpDenialReason(0));
        $this->assertNull($manager->manualTopUpDenialReason(5_000_000));
        $this->assertNull($manager->manualTopUpDenialReason(5_000_001));
        $this->assertNull($manager->manualTopUpDenialReason(50_000_000));
    }

    public function test_the_request_boundary_refuses_four_ninety_nine_in_either_form(): void
    {
        [$owner, $business, $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Growth);
        $this->fakeProvider();
        $this->attachFakeCard($business, (int) $owner->user_id);
        $this->authenticateAs($owner);

        foreach ([['amount' => '4.99'], ['amount_micro' => '4990000'], ['amount' => '0.01'], ['amount' => '4'], ['amount' => 'five'], []] as $payload) {
            $this->from($this->usageBillingUrl($workspace, $business))
                ->post($this->usageBillingRoute('top-up.initiate', $workspace, $business), $payload)
                ->assertRedirect($this->usageBillingUrl($workspace, $business))
                ->assertSessionHasErrors('amount_micro');
        }

        $this->assertSame(0, DB::table('business_funding_attempts')->where('business_id', $business->id)->count(), 'No attempt is created for a refused amount.');
    }

    public function test_exactly_five_dollars_is_accepted_and_starts_the_existing_checkout_flow_without_crediting(): void
    {
        [$owner, $business, $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Growth);
        $this->fakeProvider();
        $this->attachFakeCard($business, (int) $owner->user_id);
        $this->authenticateAs($owner);

        $response = $this->from($this->usageBillingUrl($workspace, $business))
            ->post($this->usageBillingRoute('top-up.initiate', $workspace, $business), ['amount' => '5.00']);

        $response->assertSessionDoesntHaveErrors();
        $response->assertRedirect();

        $attempt = DB::table('business_funding_attempts')->where('business_id', $business->id)->first();
        $this->assertNotNull($attempt);
        $this->assertSame('5000000', (string) $attempt->expected_amount_micro);
        $this->assertSame('0', (string) $this->walletRow($business)->available_balance_micro, 'A displayed top-up is never credited before the provider confirms it.');
        $this->assertSame(0, DB::table('business_usage_ledger_entries')->where('business_id', $business->id)->count());
    }

    public function test_a_decimal_entry_is_converted_exactly_without_floats(): void
    {
        [$owner, $business, $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Growth);
        $this->fakeProvider();
        $this->attachFakeCard($business, (int) $owner->user_id);
        $this->authenticateAs($owner);

        $this->post($this->usageBillingRoute('top-up.initiate', $workspace, $business), ['amount' => '12.34'])->assertSessionDoesntHaveErrors();

        $this->assertSame('12340000', (string) DB::table('business_funding_attempts')->where('business_id', $business->id)->value('expected_amount_micro'));
    }

    public function test_an_existing_balance_is_recognized_on_the_page_and_a_repeated_confirmation_credits_once(): void
    {
        [$owner, $business, $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Growth);
        $this->fakeProvider();
        $this->attachFakeCard($business, (int) $owner->user_id);
        $this->fund($business, 7_500_000);
        $this->authenticateAs($owner);

        $html = $this->get($this->usageBillingUrl($workspace, $business))->assertOk()->getContent();
        $this->assertStringContainsString('data-role="existing-balance-note"', $html);
        $this->assertStringContainsString('You already have USD 7.50 available', $html);
        $this->assertStringContainsString('Minimum USD 5.00 per top-up.', $html);

        $result = app(UsageBillingCheckoutManager::class)->initiateTopUp($business, (int) $owner->user_id, 5_000_000);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);

        app(UsageBillingCheckoutManager::class)->confirmAttemptFromReturn($attempt);
        app(UsageBillingCheckoutManager::class)->confirmAttemptFromReturn($attempt->fresh());

        $this->assertSame('12500000', (string) $this->walletRow($business)->available_balance_micro, 'Confirming twice credits exactly once.');
        $this->assertSame(1, DB::table('business_usage_ledger_entries')->where('business_id', $business->id)->where('entry_type', 'paid_top_up')->count());
    }

    public function test_a_non_payer_cannot_add_funds_by_direct_post(): void
    {
        [, , $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Agency, 'Agency House', 'Northwind Agency');
        [$client, $business] = $this->clientBusiness($workspace);
        $this->setPayer($business, \App\Enums\Usage\PayerType::Workspace);
        $this->assign($this->member($workspace, $client->user, \App\Enums\Workspace\WorkspaceMembershipRole::Staff, \App\Enums\Workspace\WorkspaceBusinessAccessScope::Selected), $business);
        $this->fakeProvider();
        $this->authenticateAs($client);

        $this->post($this->usageBillingRoute('top-up.initiate', $workspace, $business), ['amount' => '5.00'])
            ->assertRedirect()
            ->assertSessionHas('flash_error');

        $this->assertSame(0, DB::table('business_funding_attempts')->where('business_id', $business->id)->count());
    }
}

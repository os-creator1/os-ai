<?php

namespace Tests\Feature\Dashboards;

use App\Enums\Dashboard\AttentionType;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Dashboard\AttentionItem;
use App\Library\Dashboard\DashboardSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Dashboards\Concerns\CreatesDashboardFixtures;
use Tests\TestCase;

/**
 * Unified Business Home §5 (Slice H-1) — billing left the Business Home as a
 * metric.
 *
 * Balance, spend, top-ups and invoices live in Settings → Billing, which this
 * slice does not touch. Home speaks about billing only when the customer has
 * something to do about it, and then as ONE compact strip in plain language:
 * what is wrong, what it means for the business, and where to fix it.
 *
 * A low balance while automatic top-up is working is NOT an exception — it is
 * the normal moment a top-up happens by itself — so Home stays quiet about it.
 */
class BusinessHomeBillingTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDashboardFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeClock();
    }

    // =================================================================
    // No spend metric anywhere (T-BILL-1)
    // =================================================================

    public function test_no_spend_band_or_funding_action_renders_for_the_payer_on_core_growth_or_an_agency_client(): void
    {
        foreach ([WorkspacePlanTier::Core, WorkspacePlanTier::Growth, WorkspacePlanTier::Agency] as $tier) {
            [$customer, $business] = $this->tenant($tier, 'Payer Venue ' . $tier->value, 'Payer Account ' . $tier->value);
            // A healthy, funded wallet with everything a spend card used to show.
            $this->wallet($business, [
                'available_balance_micro' => 25000000,
                'committed_spend_this_period_micro' => 4000000,
                'monthly_spend_cap_micro' => 50000000,
                'auto_recharge_enabled' => true,
                'auto_recharge_threshold_micro' => 5000000,
            ]);
            $this->authenticateAs($customer);

            $html = $this->home()->assertOk()->getContent();
            $main = $this->mainText($html);

            $this->assertNotContains('spend', $this->bandOrder($html), "{$tier->value}: no spend band.");
            $this->assertNotContains('billing_exception', $this->bandOrder($html), "{$tier->value}: a healthy wallet is not an exception.");
            $this->assertNotContains('add_funds', $this->quickActionKeys($html), "{$tier->value}: no funding quick action.");
            $this->assertDoesNotMatchRegularExpression('/\b(Spend and billing|Available balance|Spent this month|Monthly spending limit|Automatic top-up|Add funds)\b/i', $main, $tier->value);
            $this->assertDoesNotMatchRegularExpression('/USD\s?\d/', $main, "{$tier->value}: no money figure on Home at all.");
        }
    }

    public function test_a_business_with_no_wallet_says_nothing_about_billing(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth, 'Unfunded Venue', 'Unfunded Account');
        $this->authenticateAs($customer);

        $html = $this->home()->assertOk()->getContent();

        $this->assertNotContains('billing_exception', $this->bandOrder($html));
        $this->assertDoesNotMatchRegularExpression('/\bbilling\b/i', $this->mainText($html), 'Nothing invites a customer to think about billing when there is nothing to do.');
    }

    // =================================================================
    // Only a genuinely actionable exception (T-BILL-2)
    // =================================================================

    public function test_a_low_balance_is_not_an_exception_while_automatic_top_up_is_working(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Topped Venue', 'Topped Account');
        $this->wallet($business, [
            'available_balance_micro' => 900000,
            'auto_recharge_enabled' => true,
            'auto_recharge_threshold_micro' => 5000000,
            'consecutive_recharge_failures' => 0,
        ]);
        $this->authenticateAs($customer);

        $this->assertNull($this->exceptionFor($customer->user), 'Dipping under your own top-up threshold is what triggers the top-up.');

        // The same balance with automatic top-up OFF is a real exception:
        // nothing is going to fix it by itself.
        $this->wallet($business, ['auto_recharge_enabled' => false]);
        $exception = $this->exceptionFor($customer->user);

        $this->assertInstanceOf(AttentionItem::class, $exception);
        $this->assertSame(AttentionType::LowBalance, $exception->type);
    }

    public function test_a_failing_automatic_top_up_is_an_exception_even_though_the_top_up_is_on(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Failing Venue', 'Failing Account');
        $this->wallet($business, [
            'available_balance_micro' => 900000,
            'auto_recharge_enabled' => true,
            'auto_recharge_threshold_micro' => 5000000,
            'consecutive_recharge_failures' => 2,
        ]);
        $this->authenticateAs($customer);

        $exception = $this->exceptionFor($customer->user);

        $this->assertInstanceOf(AttentionItem::class, $exception);
        $this->assertSame(AttentionType::AutoRechargeFailing, $exception->type, 'The strip names the thing the customer can fix.');
    }

    /**
     * Each blocking or debt state is canonical wallet state, and each one
     * reaches the customer as the strip with its consequence spelled out.
     */
    public function test_each_actionable_billing_state_renders_one_strip_with_a_consequence_and_a_route(): void
    {
        $states = [
            AttentionType::WalletSuspended->value => ['billing_status' => 'suspended'],
            AttentionType::PaidActivityPaused->value => ['paid_activity_paused_at' => now()],
            AttentionType::OutstandingDebt->value => ['debt_balance_micro' => 250000],
            AttentionType::AutoRechargeFailing->value => ['consecutive_recharge_failures' => 3],
        ];

        foreach ($states as $type => $columns) {
            [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'State Venue ' . $type, 'State Account ' . $type);
            $this->wallet($business, array_merge(['available_balance_micro' => 10000000, 'auto_recharge_threshold_micro' => 1000000], $columns));
            $this->authenticateAs($customer);

            $exception = $this->exceptionFor($customer->user);
            $this->assertInstanceOf(AttentionItem::class, $exception, $type);
            $this->assertSame($type, $exception->type->value);

            // Plain customer language: no key, no provider, no internal noun.
            $this->assertDoesNotMatchRegularExpression('/_|stripe|provider|wallet_|micro|locale\./i', $exception->text, $type);
            $this->assertStringContainsString($exception->type->consequence(), $exception->text, 'The strip says what happens if it is left alone.');

            $html = $this->home()->assertOk()->getContent();
            $strip = $this->bandHtml($html, 'billing_exception');

            $this->assertStringContainsString('data-role="billing-exception-action"', $strip);
            $this->assertStringContainsString($exception->severity->word(), $strip, 'Severity is a word, not only a colour.');
            $this->assertSame(1, substr_count($html, 'data-role="billing-exception"'), 'One strip, never a list.');
            $this->get($exception->url)->assertOk();
        }
    }

    public function test_several_billing_problems_are_still_one_strip_and_never_enter_the_attention_band(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Many Venue', 'Many Account');
        $this->wallet($business, [
            'billing_status' => 'suspended',
            'debt_balance_micro' => 500000,
            'paid_activity_paused_at' => now(),
            'available_balance_micro' => 1,
            'auto_recharge_threshold_micro' => 1000000,
            'consecutive_recharge_failures' => 4,
        ]);
        $this->website($business, 'draft');
        $this->authenticateAs($customer);

        $snapshot = $this->dashboardFor($customer->user);
        $attention = $snapshot->band(DashboardSnapshot::BAND_ATTENTION);

        $this->assertInstanceOf(AttentionItem::class, $snapshot->band(DashboardSnapshot::BAND_BILLING_EXCEPTION));
        $this->assertSame(
            [AttentionType::WebsiteUnpublished->value],
            array_map(fn (AttentionItem $item) => $item->type->value, $attention),
            'Billing never appears twice: the attention band keeps only what is not billing.'
        );
    }

    public function test_an_actor_who_cannot_reach_billing_is_never_shown_the_exception(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Scoped Venue', 'Northwind Agency');
        $this->wallet($business, ['billing_status' => 'suspended']);

        $staff = $this->createCustomer();
        $membership = $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected);
        $this->assign($membership, $business);
        $this->authenticateAs($staff);

        $html = $this->home()->assertOk()->getContent();

        $this->assertNull($this->exceptionFor($staff->user), 'No reachable fix, so no dead-end alert.');
        $this->assertNotContains('billing_exception', $this->bandOrder($html));
        $this->assertDoesNotMatchRegularExpression('/\bbilling\b/i', $this->mainText($html));
    }

    // -----------------------------------------------------------------

    private function exceptionFor(\App\Models\User $user): ?AttentionItem
    {
        $band = $this->dashboardFor($user)->band(DashboardSnapshot::BAND_BILLING_EXCEPTION);

        return $band instanceof AttentionItem ? $band : null;
    }

    /** @return array<int, string> */
    private function bandOrder(string $html): array
    {
        preg_match_all('/data-band="([a-z_]+)"/', $this->mainHtml($html), $matches);

        return $matches[1];
    }

    private function bandHtml(string $html, string $band): string
    {
        $main = $this->mainHtml($html);
        $start = strpos($main, 'data-band="' . $band . '"');

        if ($start === false) {
            return '';
        }

        $end = strpos($main, '</section>', $start);

        return substr($main, $start, $end === false ? null : $end - $start);
    }

    /** @return array<int, string> */
    private function quickActionKeys(string $html): array
    {
        preg_match_all('/data-action="([a-z_]+)"/', $this->bandHtml($html, 'actions'), $matches);

        return $matches[1];
    }
}

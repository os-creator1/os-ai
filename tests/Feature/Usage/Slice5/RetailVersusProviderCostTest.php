<?php

namespace Tests\Feature\Usage\Slice5;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Usage\UsageWalletManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Usage\Slice5\Concerns\Slice5Fixtures;
use Tests\TestCase;

/**
 * Customer Experience Slice 5 — T-COST-9 (contract §20 C-9): the customer
 * sees retail amounts only; provider cost stays administrative. Proven
 * with explicit fixture rates ($1.00 retail / $0.60 provider cost per
 * unit) — gated (§28.1a): the same assertion against the real telecom
 * rate card awaits the owner's decision; no production telecom rate is
 * activated here.
 */
class RetailVersusProviderCostTest extends TestCase
{
    use RefreshDatabase;
    use Slice5Fixtures;

    public function test_the_customer_page_shows_retail_amounts_and_never_the_provider_cost(): void
    {
        [$owner, $business, $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Growth);
        $this->activateFixtureRate('crm', '1000000', '600000');
        $this->fund($business, 10_000_000);

        $reservation = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '3');
        app(UsageWalletManager::class)->commit($reservation->reservationId, '3');

        // The ledger keeps both figures for the operator; the customer sees only retail.
        $this->assertSame('600000', (string) DB::table('business_usage_ledger_entries')->where('business_id', $business->id)->where('entry_type', 'usage_charge')->value('provider_cost_micro'));

        $this->authenticateAs($owner);
        $html = $this->get($this->usageBillingUrl($workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('USD 3.00', $html, 'Retail charge for 3 units.');
        $this->assertStringContainsString('USD 7.00', $html, 'Retail balance after the charge.');
        $this->assertStringNotContainsString('1.80', $html, 'Provider cost for 3 units (3 × 0.60) is never rendered.');
        $this->assertStringNotContainsString('0.60', $html);
        $this->assertStringNotContainsString('600000', $html);
        $this->assertStringNotContainsStringIgnoringCase('provider cost', $html);
        $this->assertStringNotContainsStringIgnoringCase('provider_cost', $html);
        $this->assertStringNotContainsStringIgnoringCase('margin', $html);
    }

    public function test_the_ledger_row_reads_as_paid_activity_with_a_capability_label_not_a_key(): void
    {
        [$owner, $business, $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Growth);
        $this->activateFixtureRate('crm', '1000000', '600000');
        $this->fund($business, 10_000_000);
        $reservation = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '1');
        app(UsageWalletManager::class)->commit($reservation->reservationId, '1');

        $this->authenticateAs($owner);
        $html = $this->get($this->usageBillingUrl($workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('Paid activity', $html);
        $this->assertStringContainsString('Contacts &amp; CRM', $html);
        $this->assertStringNotContainsString('<td>crm</td>', $html);
        $this->assertStringNotContainsString('usage_charge', $html);
        $this->assertStringNotContainsString('<td>reservation</td>', $html);
    }
}

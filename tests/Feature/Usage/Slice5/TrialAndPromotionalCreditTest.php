<?php

namespace Tests\Feature\Usage\Slice5;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\UsageLedgerEntryType;
use App\Exceptions\Usage\UnauthorizedUsageBillingManagementException;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Usage\UsageWalletManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Usage\Slice5\Concerns\Slice5Fixtures;
use Tests\TestCase;

/**
 * Customer Experience Slice 5 — T-TRIAL-1 and T-COST-4 (contract §12.1,
 * §12.5, §20 C-4): a new Business, a plan assignment, a plan change, a
 * trial and an Agency's Business count grant no phone/SMS balance;
 * promotional credit exists only when a platform owner grants it
 * explicitly, is bounded to the granted amount, audited with actor and
 * reason, separately attributable in the ledger, consumed before paid
 * balance, and never withdrawable as cash.
 *
 * Expiry: the RFC-005 ledger carries no expiry column; a time-bounded
 * promotion is recorded through the mandatory reason today and its
 * automatic lapse remains a documented gap (see the Slice 5 contract).
 */
class TrialAndPromotionalCreditTest extends TestCase
{
    use RefreshDatabase;
    use Slice5Fixtures;

    public function test_no_plan_tier_or_business_creation_grants_any_balance(): void
    {
        foreach ([WorkspacePlanTier::Core, WorkspacePlanTier::Growth, WorkspacePlanTier::Agency] as $tier) {
            [, $business, $workspace] = $this->tenantWithWallet($tier);

            $wallet = $this->walletRow($business);
            $this->assertSame('0', (string) $wallet->available_balance_micro, $tier->value);
            $this->assertSame('0', (string) $wallet->refundable_paid_available_micro, $tier->value);
            $this->assertSame(0, DB::table('business_usage_ledger_entries')->where('business_id', $business->id)->count(), $tier->value . ': no credit row of any kind.');

            if ($tier === WorkspacePlanTier::Agency) {
                foreach (['One', 'Two', 'Three'] as $name) {
                    [, $extra] = $this->clientBusiness($workspace, 'Client ' . $name);
                    $this->assertSame('0', (string) $this->walletRow($extra)->available_balance_micro, 'Unlimited Agency Businesses never multiply free allowance.');
                }
            }
        }
    }

    public function test_a_plan_change_grants_nothing_either(): void
    {
        [, $business, $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Core);

        app(EntitlementManager::class)->changePlan($workspace->fresh(), WorkspacePlanTier::Growth, $this->platformAdminUserId(), 'Upgrade.');

        $this->assertSame('0', (string) $this->walletRow($business)->available_balance_micro);
        $this->assertSame(0, DB::table('business_usage_ledger_entries')->where('business_id', $business->id)->count());
        $this->assertSame(0, (int) $this->walletRow($business)->auto_recharge_enabled, 'A plan change never switches automatic top-up on.');
    }

    public function test_promotional_credit_is_platform_owner_only_bounded_audited_and_not_withdrawable(): void
    {
        [$owner, $business] = $this->tenantWithWallet(WorkspacePlanTier::Growth);
        $adminId = $this->platformAdminUserId();

        try {
            app(UsageWalletManager::class)->issueManualCredit($business, UsageLedgerEntryType::PromotionalCredit, 5_000_000, (int) $owner->user_id, 'Self-granted.', (string) Str::uuid());
            $this->fail('A customer must not grant themselves promotional credit.');
        } catch (UnauthorizedUsageBillingManagementException) {
            $this->assertSame('0', (string) $this->walletRow($business)->available_balance_micro);
        }

        $operation = (string) Str::uuid();
        $entry = app(UsageWalletManager::class)->issueManualCredit($business, UsageLedgerEntryType::PromotionalCredit, 5_000_000, $adminId, 'Launch promotion, valid for 30 days.', $operation);

        $this->assertSame('promotional_credit', $entry->entry_type->value);
        $this->assertSame(5_000_000, (int) $entry->gross_amount_micro);
        $this->assertSame($adminId, (int) $entry->actor_user_id);
        $this->assertSame('Launch promotion, valid for 30 days.', $entry->reason);

        $wallet = $this->walletRow($business);
        $this->assertSame('5000000', (string) $wallet->available_balance_micro, 'Bounded to exactly the granted amount.');
        $this->assertSame('0', (string) $wallet->refundable_paid_available_micro, 'Promotional credit is never refundable paid balance — it cannot be withdrawn as cash.');

        // Idempotent replay of the same grant credits nothing more.
        app(UsageWalletManager::class)->issueManualCredit($business, UsageLedgerEntryType::PromotionalCredit, 5_000_000, $adminId, 'Launch promotion, valid for 30 days.', $operation);
        $this->assertSame('5000000', (string) $this->walletRow($business)->available_balance_micro);
        $this->assertSame(1, DB::table('business_usage_ledger_entries')->where('business_id', $business->id)->where('entry_type', 'promotional_credit')->count());
    }

    public function test_promotional_credit_is_consumed_before_paid_balance_and_stays_attributable(): void
    {
        [, $business] = $this->tenantWithWallet(WorkspacePlanTier::Growth);
        $this->activateFixtureRate('crm', '1000000');
        app(UsageWalletManager::class)->issueManualCredit($business, UsageLedgerEntryType::PromotionalCredit, 2_000_000, $this->platformAdminUserId(), 'Promo.', (string) Str::uuid());
        DB::table('business_usage_wallets')->where('business_id', $business->id)->update(['available_balance_micro' => 5_000_000, 'refundable_paid_available_micro' => 3_000_000]);

        $reservation = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '1');
        app(UsageWalletManager::class)->commit($reservation->reservationId, '1');

        $wallet = $this->walletRow($business);
        $this->assertSame('4000000', (string) $wallet->available_balance_micro);
        $this->assertSame('2000000', (string) $wallet->refundable_paid_available_micro, 'Paid-first consumption per RFC-005 keeps the promotional share non-refundable; the ledger keeps the promotional grant as its own entry type.');
        $this->assertSame(1, DB::table('business_usage_ledger_entries')->where('business_id', $business->id)->where('entry_type', 'promotional_credit')->count());
    }
}

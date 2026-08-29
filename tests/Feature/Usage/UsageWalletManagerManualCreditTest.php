<?php

namespace Tests\Feature\Usage;

use App\Enums\Usage\UsageLedgerEntryType;
use App\Events\Usage\BusinessWalletCredited;
use App\Events\Usage\BusinessWalletDebtCleared;
use App\Exceptions\Usage\InvalidAdminCreditAmountException;
use App\Exceptions\Usage\InvalidAdminCreditEntryTypeException;
use App\Exceptions\Usage\InvalidAdminCreditOperationIdException;
use App\Exceptions\Usage\InvalidAdminCreditReasonException;
use App\Exceptions\Usage\ManualCreditOperationConflictException;
use App\Exceptions\Usage\UnauthorizedUsageBillingManagementException;
use App\Library\Usage\UsageWalletManager;
use App\Models\Business;
use App\Models\Currency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 Admin Usage Billing Surface Contract §5.2 — manager-layer
 * tests for UsageWalletManager::issueManualCredit() (§2.3), mirroring
 * SlotAgreementAdminAuthorityTest's own established manager-layer
 * pattern.
 */
class UsageWalletManagerManualCreditTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
    }

    private function adminUser(): User
    {
        return User::create([
            'first_name' => 'Fixture', 'last_name' => 'Admin', 'email' => 'admin'.uniqid('', true).'@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);
    }

    private function businessWithWallet(): Business
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);

        return $business;
    }

    private function setWalletBalances(Business $business, int $availableMicro, int $debtMicro): void
    {
        DB::table('business_usage_wallets')
            ->where('business_id', $business->id)
            ->update(['available_balance_micro' => $availableMicro, 'debt_balance_micro' => $debtMicro]);
    }

    private function walletRow(Business $business): object
    {
        return DB::table('business_usage_wallets')->where('business_id', $business->id)->first();
    }

    public function test_issuing_a_manual_credit_increases_available_balance_and_records_the_ledger_entry(): void
    {
        $business = $this->businessWithWallet();
        $admin = $this->adminUser();

        $entry = app(UsageWalletManager::class)->issueManualCredit(
            $business, UsageLedgerEntryType::ManualCredit, 10_000_000, $admin->id, 'Goodwill credit.', (string) Str::uuid(),
        );

        $wallet = $this->walletRow($business);

        $this->assertSame(10_000_000, (int) $wallet->available_balance_micro);
        $this->assertSame(UsageLedgerEntryType::ManualCredit, $entry->entry_type);
        $this->assertSame(10_000_000, (int) $entry->gross_amount_micro);
        $this->assertSame('Goodwill credit.', $entry->reason);
        $this->assertSame($admin->id, (int) $entry->actor_user_id);
    }

    public function test_issuing_a_manual_credit_clears_existing_debt_first(): void
    {
        $business = $this->businessWithWallet();
        $admin = $this->adminUser();
        $this->setWalletBalances($business, 0, 4_000_000);

        app(UsageWalletManager::class)->issueManualCredit(
            $business, UsageLedgerEntryType::ManualCredit, 10_000_000, $admin->id, 'Clears debt.', (string) Str::uuid(),
        );

        $wallet = $this->walletRow($business);

        $this->assertSame(0, (int) $wallet->debt_balance_micro);
        $this->assertSame(6_000_000, (int) $wallet->available_balance_micro);
    }

    public function test_issuing_a_promotional_credit_records_the_correct_entry_type(): void
    {
        $business = $this->businessWithWallet();
        $admin = $this->adminUser();

        $entry = app(UsageWalletManager::class)->issueManualCredit(
            $business, UsageLedgerEntryType::PromotionalCredit, 5_000_000, $admin->id, 'Promo.', (string) Str::uuid(),
        );

        $this->assertSame(UsageLedgerEntryType::PromotionalCredit, $entry->entry_type);
    }

    public function test_a_non_admin_actor_directly_invoking_issue_manual_credit_is_denied_even_bypassing_http_middleware(): void
    {
        $business = $this->businessWithWallet();
        $nonAdmin = User::create([
            'first_name' => 'Fixture', 'last_name' => 'NonAdmin', 'email' => 'nonadmin'.uniqid('', true).'@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);

        $this->expectException(UnauthorizedUsageBillingManagementException::class);
        app(UsageWalletManager::class)->issueManualCredit(
            $business, UsageLedgerEntryType::ManualCredit, 1_000_000, $nonAdmin->id, 'Attempted.', (string) Str::uuid(),
        );
    }

    public function test_issuing_a_credit_with_a_disallowed_entry_type_is_rejected(): void
    {
        $business = $this->businessWithWallet();
        $admin = $this->adminUser();

        $this->expectException(InvalidAdminCreditEntryTypeException::class);
        app(UsageWalletManager::class)->issueManualCredit(
            $business, UsageLedgerEntryType::UsageCharge, 1_000_000, $admin->id, 'Not allowed.', (string) Str::uuid(),
        );
    }

    public function test_issuing_a_credit_requires_a_mandatory_reason(): void
    {
        $business = $this->businessWithWallet();
        $admin = $this->adminUser();

        $this->expectException(InvalidAdminCreditReasonException::class);
        app(UsageWalletManager::class)->issueManualCredit(
            $business, UsageLedgerEntryType::ManualCredit, 1_000_000, $admin->id, '   ', (string) Str::uuid(),
        );
    }

    public function test_issuing_a_credit_requires_a_positive_amount(): void
    {
        $business = $this->businessWithWallet();
        $admin = $this->adminUser();

        $this->expectException(InvalidAdminCreditAmountException::class);
        app(UsageWalletManager::class)->issueManualCredit(
            $business, UsageLedgerEntryType::ManualCredit, 0, $admin->id, 'Zero.', (string) Str::uuid(),
        );
    }

    public function test_replaying_the_same_operation_id_with_an_identical_payload_returns_the_original_ledger_entry_without_a_second_credit(): void
    {
        $business = $this->businessWithWallet();
        $admin = $this->adminUser();
        $operationId = (string) Str::uuid();

        $first = app(UsageWalletManager::class)->issueManualCredit(
            $business, UsageLedgerEntryType::ManualCredit, 5_000_000, $admin->id, 'Same payload.', $operationId,
        );

        $second = app(UsageWalletManager::class)->issueManualCredit(
            $business, UsageLedgerEntryType::ManualCredit, 5_000_000, $admin->id, 'Same payload.', $operationId,
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, DB::table('business_usage_ledger_entries')->where('business_id', $business->id)->count());
        $this->assertSame(5_000_000, (int) $this->walletRow($business)->available_balance_micro);
    }

    public function test_reusing_the_same_operation_id_with_a_different_payload_is_rejected_and_changes_nothing(): void
    {
        $business = $this->businessWithWallet();
        $admin = $this->adminUser();
        $operationId = (string) Str::uuid();

        app(UsageWalletManager::class)->issueManualCredit(
            $business, UsageLedgerEntryType::ManualCredit, 5_000_000, $admin->id, 'Original.', $operationId,
        );

        try {
            app(UsageWalletManager::class)->issueManualCredit(
                $business, UsageLedgerEntryType::ManualCredit, 9_000_000, $admin->id, 'Different amount.', $operationId,
            );
            $this->fail('Expected ManualCreditOperationConflictException.');
        } catch (ManualCreditOperationConflictException) {
            // expected
        }

        $this->assertSame(1, DB::table('business_usage_ledger_entries')->where('business_id', $business->id)->count());
        $this->assertSame(5_000_000, (int) $this->walletRow($business)->available_balance_micro);
    }

    public function test_issuing_a_manual_credit_dispatches_business_wallet_credited_when_available_balance_increases(): void
    {
        Event::fake([BusinessWalletCredited::class, BusinessWalletDebtCleared::class]);

        $business = $this->businessWithWallet();
        $admin = $this->adminUser();

        app(UsageWalletManager::class)->issueManualCredit(
            $business, UsageLedgerEntryType::ManualCredit, 5_000_000, $admin->id, 'Credited.', (string) Str::uuid(),
        );

        Event::assertDispatched(BusinessWalletCredited::class);
        Event::assertNotDispatched(BusinessWalletDebtCleared::class);
    }

    public function test_issuing_a_manual_credit_dispatches_business_wallet_debt_cleared_when_debt_is_cleared(): void
    {
        Event::fake([BusinessWalletCredited::class, BusinessWalletDebtCleared::class]);

        $business = $this->businessWithWallet();
        $admin = $this->adminUser();
        $this->setWalletBalances($business, 0, 5_000_000);

        app(UsageWalletManager::class)->issueManualCredit(
            $business, UsageLedgerEntryType::ManualCredit, 5_000_000, $admin->id, 'Clears exactly.', (string) Str::uuid(),
        );

        Event::assertDispatched(BusinessWalletDebtCleared::class);
        Event::assertNotDispatched(BusinessWalletCredited::class);
    }

    public function test_an_idempotent_replay_dispatches_no_additional_events_and_causes_no_balance_change(): void
    {
        $business = $this->businessWithWallet();
        $admin = $this->adminUser();
        $operationId = (string) Str::uuid();

        app(UsageWalletManager::class)->issueManualCredit(
            $business, UsageLedgerEntryType::ManualCredit, 5_000_000, $admin->id, 'Once.', $operationId,
        );

        Event::fake([BusinessWalletCredited::class, BusinessWalletDebtCleared::class]);

        app(UsageWalletManager::class)->issueManualCredit(
            $business, UsageLedgerEntryType::ManualCredit, 5_000_000, $admin->id, 'Once.', $operationId,
        );

        Event::assertNotDispatched(BusinessWalletCredited::class);
        Event::assertNotDispatched(BusinessWalletDebtCleared::class);
        $this->assertSame(5_000_000, (int) $this->walletRow($business)->available_balance_micro);
    }

    public function test_a_credit_larger_than_existing_debt_dispatches_both_wallet_events_exactly_once_with_the_same_ledger_entry_id(): void
    {
        Event::fake([BusinessWalletCredited::class, BusinessWalletDebtCleared::class]);

        $business = $this->businessWithWallet();
        $admin = $this->adminUser();
        $this->setWalletBalances($business, 0, 3_000_000);

        $entry = app(UsageWalletManager::class)->issueManualCredit(
            $business, UsageLedgerEntryType::ManualCredit, 10_000_000, $admin->id, 'Larger than debt.', (string) Str::uuid(),
        );

        Event::assertDispatchedTimes(BusinessWalletCredited::class, 1);
        Event::assertDispatchedTimes(BusinessWalletDebtCleared::class, 1);

        Event::assertDispatched(BusinessWalletCredited::class, fn (BusinessWalletCredited $event) => $event->ledgerEntryId === $entry->id);
        Event::assertDispatched(BusinessWalletDebtCleared::class, fn (BusinessWalletDebtCleared $event) => $event->ledgerEntryId === $entry->id);
    }

    public function test_a_malformed_operation_id_is_rejected_with_no_ledger_or_wallet_mutation(): void
    {
        $business = $this->businessWithWallet();
        $admin = $this->adminUser();

        try {
            app(UsageWalletManager::class)->issueManualCredit(
                $business, UsageLedgerEntryType::ManualCredit, 5_000_000, $admin->id, 'Malformed.', 'not-a-uuid',
            );
            $this->fail('Expected InvalidAdminCreditOperationIdException.');
        } catch (InvalidAdminCreditOperationIdException) {
            // expected
        }

        $this->assertSame(0, DB::table('business_usage_ledger_entries')->where('business_id', $business->id)->count());
        $this->assertSame(0, (int) $this->walletRow($business)->available_balance_micro);
    }

    public function test_differently_cased_representations_of_the_same_uuid_resolve_to_one_idempotent_operation(): void
    {
        $business = $this->businessWithWallet();
        $admin = $this->adminUser();
        $operationId = (string) Str::uuid();

        $first = app(UsageWalletManager::class)->issueManualCredit(
            $business, UsageLedgerEntryType::ManualCredit, 5_000_000, $admin->id, 'Cased.', strtoupper($operationId),
        );

        $second = app(UsageWalletManager::class)->issueManualCredit(
            $business, UsageLedgerEntryType::ManualCredit, 5_000_000, $admin->id, 'Cased.', strtolower($operationId),
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, DB::table('business_usage_ledger_entries')->where('business_id', $business->id)->count());
    }
}

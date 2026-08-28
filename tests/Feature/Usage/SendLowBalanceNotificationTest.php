<?php

namespace Tests\Feature\Usage;

use App\Enums\Usage\UsageLedgerEntryType;
use App\Jobs\Usage\SendLowBalanceNotification;
use App\Library\Usage\BillingProfileManager;
use App\Library\Usage\UsageWalletManager;
use App\Models\Business;
use App\Models\Currency;
use App\Models\User;
use App\Notifications\Usage\LowBalanceNotification;
use App\Repositories\Contracts\UsageMeterRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 Job/Event Dispatch Completion Correction Contract §4 — the
 * low_balance_notified_at episode/marker rule, and SendLowBalanceNotification's
 * own recipient resolution (byte-for-byte the same algorithm
 * SendReceiptNotification already uses, per ReceiptBoundaryTest's own
 * established fixture pattern).
 */
class SendLowBalanceNotificationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
    }

    private function business(): Business
    {
        $customer = $this->createCustomer();

        return $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
    }

    private function createActorUserId(): int
    {
        return User::create([
            'first_name' => 'Test', 'last_name' => 'Actor', 'email' => 'actor'.uniqid().'@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ])->id;
    }

    private function activateRate(string $featureKey = 'crm', string $retailRateMicro = '1000000'): void
    {
        $actorId = $this->createActorUserId();
        $currencyId = Currency::query()->first()->id;

        app(UsageMeterRepository::class)->create([
            'meter_key' => $featureKey, 'feature_key' => $featureKey, 'business_id' => null,
            'currency_id' => $currencyId, 'description' => 'Low-balance fixture meter.', 'updated_by_user_id' => $actorId,
        ]);

        app(UsageWalletManager::class)->setActiveRate($featureKey, $retailRateMicro, '500000', 'per message', $currencyId, $actorId, 'Test rate activation.');
        app(UsageWalletManager::class)->activateMetering($featureKey, $actorId, 'Test metering activation.');
    }

    private function seedWallet(int $businessId, array $attributes): void
    {
        DB::table('business_usage_wallets')->where('business_id', $businessId)->update($attributes);
    }

    // --- Wallet-manager trigger/marker rule ---

    public function test_dispatches_when_a_reservation_drops_the_balance_to_or_below_threshold(): void
    {
        Queue::fake();

        $business = $this->business();
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        $this->activateRate();
        $this->seedWallet($business->id, [
            'available_balance_micro' => 3_000_000,
            'auto_recharge_enabled' => true,
            'auto_recharge_threshold_micro' => 2_000_000,
        ]);

        app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '2');

        Queue::assertPushed(SendLowBalanceNotification::class, 1);
        $wallet = DB::table('business_usage_wallets')->where('business_id', $business->id)->first();
        $this->assertNotNull($wallet->low_balance_notified_at);
    }

    public function test_dispatches_when_a_commit_overage_drops_the_balance_to_or_below_threshold(): void
    {
        Queue::fake();

        $business = $this->business();
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        $this->activateRate();
        $this->seedWallet($business->id, [
            'available_balance_micro' => 2_500_000,
            'auto_recharge_enabled' => true,
            'auto_recharge_threshold_micro' => 100_000,
        ]);

        // Reserve(2) leaves 500,000 available — above the 100,000
        // threshold, so reserve() itself must not yet dispatch.
        $reservation = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '2');
        Queue::assertNotPushed(SendLowBalanceNotification::class);

        // Commit(3) draws the remaining 500,000 available down to 0,
        // crossing the threshold from the overage branch.
        app(UsageWalletManager::class)->commit($reservation->reservationId, '3');

        Queue::assertPushed(SendLowBalanceNotification::class, 1);
    }

    public function test_does_not_redispatch_while_the_marker_is_already_set(): void
    {
        Queue::fake();

        $business = $this->business();
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        $this->activateRate();
        $this->seedWallet($business->id, [
            'available_balance_micro' => 3_000_000,
            'auto_recharge_enabled' => true,
            'auto_recharge_threshold_micro' => 2_500_000,
            'low_balance_notified_at' => now(),
        ]);

        app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '1');

        Queue::assertNotPushed(SendLowBalanceNotification::class);
    }

    public function test_does_not_dispatch_when_auto_recharge_is_disabled(): void
    {
        Queue::fake();

        $business = $this->business();
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        $this->activateRate();
        $this->seedWallet($business->id, [
            'available_balance_micro' => 3_000_000,
            'auto_recharge_enabled' => false,
            'auto_recharge_threshold_micro' => 2_000_000,
        ]);

        app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '2');

        Queue::assertNotPushed(SendLowBalanceNotification::class);
        $wallet = DB::table('business_usage_wallets')->where('business_id', $business->id)->first();
        $this->assertNull($wallet->low_balance_notified_at);
    }

    public function test_does_not_dispatch_when_no_threshold_is_configured(): void
    {
        Queue::fake();

        $business = $this->business();
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        $this->activateRate();
        $this->seedWallet($business->id, [
            'available_balance_micro' => 3_000_000,
            'auto_recharge_enabled' => true,
            'auto_recharge_threshold_micro' => null,
        ]);

        app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '2');

        Queue::assertNotPushed(SendLowBalanceNotification::class);
    }

    public function test_clears_the_marker_on_recovery_via_credit_from_funding(): void
    {
        Queue::fake();

        $business = $this->business();
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        $this->seedWallet($business->id, [
            'available_balance_micro' => 500_000,
            'auto_recharge_enabled' => true,
            'auto_recharge_threshold_micro' => 2_000_000,
            'low_balance_notified_at' => now(),
        ]);

        app(UsageWalletManager::class)->creditFromFunding($business->id, UsageLedgerEntryType::PaidTopUp, 5_000_000, 1, (string) Str::uuid());

        $wallet = DB::table('business_usage_wallets')->where('business_id', $business->id)->first();
        $this->assertNull($wallet->low_balance_notified_at);
    }

    public function test_clears_the_marker_on_recovery_via_commits_unused_reservation_release(): void
    {
        Queue::fake();

        $business = $this->business();
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        $this->activateRate();
        $this->seedWallet($business->id, ['available_balance_micro' => 5_000_000]);
        $reservation = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '2');
        $this->seedWallet($business->id, [
            'auto_recharge_enabled' => true,
            'auto_recharge_threshold_micro' => 2_900_000,
            'low_balance_notified_at' => now(),
        ]);

        // Committing final quantity 1 releases 1,000,000 unused back to
        // available (3,000,000 -> 4,000,000), crossing back above the
        // 2,900,000 threshold.
        app(UsageWalletManager::class)->commit($reservation->reservationId, '1');

        $wallet = DB::table('business_usage_wallets')->where('business_id', $business->id)->first();
        $this->assertNull($wallet->low_balance_notified_at);
    }

    public function test_clears_the_marker_on_recovery_via_reservation_release(): void
    {
        Queue::fake();

        $business = $this->business();
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        $this->activateRate();
        $this->seedWallet($business->id, ['available_balance_micro' => 5_000_000]);
        $reservation = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '2');
        $this->seedWallet($business->id, [
            'auto_recharge_enabled' => true,
            'auto_recharge_threshold_micro' => 2_900_000,
            'low_balance_notified_at' => now(),
        ]);

        // Releasing the full 2,000,000 reservation returns available to
        // 5,000,000, crossing back above the 2,900,000 threshold.
        app(UsageWalletManager::class)->release($reservation->reservationId);

        $wallet = DB::table('business_usage_wallets')->where('business_id', $business->id)->first();
        $this->assertNull($wallet->low_balance_notified_at);
    }

    public function test_re_enabling_auto_recharge_alone_does_not_clear_the_marker(): void
    {
        $business = $this->business();
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        $this->seedWallet($business->id, [
            'available_balance_micro' => 500_000,
            'auto_recharge_enabled' => false,
            'low_balance_notified_at' => now(),
        ]);
        $ownerUserId = (int) $business->workspace->owner_user_id;

        app(UsageWalletManager::class)->configureAutoRecharge($business, true, '2000000', '3000000', null, $ownerUserId);

        $wallet = DB::table('business_usage_wallets')->where('business_id', $business->id)->first();
        $this->assertNotNull($wallet->low_balance_notified_at, 'Re-enabling alone must not clear the marker — only an actual balance recovery does.');
    }

    // --- Job-level recipient resolution ---

    private function businessWithWallet(): Business
    {
        $business = $this->business();
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        $this->seedWallet($business->id, ['available_balance_micro' => 500_000, 'auto_recharge_threshold_micro' => 2_000_000]);

        return $business;
    }

    public function test_skips_when_no_billing_contact_is_configured(): void
    {
        Notification::fake();
        $business = $this->businessWithWallet();

        app()->call([new SendLowBalanceNotification((int) $business->id), 'handle']);

        Notification::assertNothingSent();
    }

    public function test_skips_when_the_contact_has_opted_out(): void
    {
        Notification::fake();
        $business = $this->businessWithWallet();
        app(BillingProfileManager::class)->updateBillingContact($business, null, 'Jane Doe', 'jane@example.test', false, (int) $business->customer_id);

        app()->call([new SendLowBalanceNotification((int) $business->id), 'handle']);

        Notification::assertNothingSent();
    }

    public function test_skips_when_the_resolved_email_is_blank(): void
    {
        Notification::fake();
        $business = $this->businessWithWallet();
        // updateBillingContact() itself validates against a blank email,
        // so a genuinely blank resolved email (a data-integrity edge case
        // the job must still defensively handle) is inserted directly.
        DB::table('business_billing_contacts')->insert([
            'business_id' => $business->id, 'contact_user_id' => null, 'contact_name' => 'Jane Doe',
            'contact_email' => '', 'notification_opt_in' => true, 'updated_by_user_id' => (int) $business->customer_id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        app()->call([new SendLowBalanceNotification((int) $business->id), 'handle']);

        Notification::assertNothingSent();
    }

    public function test_sends_to_the_opted_in_contact_email(): void
    {
        Notification::fake();
        $business = $this->businessWithWallet();
        $expectedEmail = 'jane'.uniqid().'@example.test';
        app(BillingProfileManager::class)->updateBillingContact($business, null, 'Jane Doe', $expectedEmail, true, (int) $business->customer_id);

        app()->call([new SendLowBalanceNotification((int) $business->id), 'handle']);

        Notification::assertSentOnDemand(
            LowBalanceNotification::class,
            fn (LowBalanceNotification $notification, array $channels, object $notifiable) => $notifiable->routes['mail'] === $expectedEmail,
        );
    }
}

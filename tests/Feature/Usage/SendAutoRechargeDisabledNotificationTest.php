<?php

namespace Tests\Feature\Usage;

use App\Jobs\Usage\SendAutoRechargeDisabledNotification;
use App\Library\Usage\BillingProfileManager;
use App\Models\Business;
use App\Models\User;
use App\Notifications\Usage\AutoRechargeDisabledNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 Job/Event Dispatch Completion Correction Contract §5 —
 * SendAutoRechargeDisabledNotification's own recipient resolution, byte-
 * for-byte the same algorithm SendReceiptNotification already uses
 * (ReceiptBoundaryTest's own established fixture pattern). The wallet-
 * manager triggering logic (the 2->3 system-disable edge, preserved
 * configuration, no re-notify while already disabled, re-enable resets
 * the episode, no notification on a deliberate owner disable) is proven
 * separately in AutoRechargeFailedPaymentRetryTest.
 */
class SendAutoRechargeDisabledNotificationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    private function business(): Business
    {
        $customer = $this->createCustomer();

        return $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
    }

    public function test_sends_the_notification_to_the_opted_in_billing_contact(): void
    {
        Notification::fake();
        $business = $this->business();
        $expectedEmail = 'jane'.uniqid().'@example.test';
        app(BillingProfileManager::class)->updateBillingContact($business, null, 'Jane Doe', $expectedEmail, true, (int) $business->customer_id);

        app()->call([new SendAutoRechargeDisabledNotification((int) $business->id), 'handle']);

        Notification::assertSentOnDemand(
            AutoRechargeDisabledNotification::class,
            fn (AutoRechargeDisabledNotification $notification, array $channels, object $notifiable) => $notifiable->routes['mail'] === $expectedEmail,
        );
    }

    public function test_skips_when_no_billing_contact_is_configured(): void
    {
        Notification::fake();
        $business = $this->business();

        app()->call([new SendAutoRechargeDisabledNotification((int) $business->id), 'handle']);

        Notification::assertNothingSent();
    }

    public function test_skips_when_the_contact_has_opted_out(): void
    {
        Notification::fake();
        $business = $this->business();
        app(BillingProfileManager::class)->updateBillingContact($business, null, 'Jane Doe', 'jane@example.test', false, (int) $business->customer_id);

        app()->call([new SendAutoRechargeDisabledNotification((int) $business->id), 'handle']);

        Notification::assertNothingSent();
    }

    public function test_skips_when_the_resolved_email_is_blank(): void
    {
        Notification::fake();
        $business = $this->business();
        DB::table('business_billing_contacts')->insert([
            'business_id' => $business->id, 'contact_user_id' => null, 'contact_name' => 'Jane Doe',
            'contact_email' => '', 'notification_opt_in' => true, 'updated_by_user_id' => (int) $business->customer_id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        app()->call([new SendAutoRechargeDisabledNotification((int) $business->id), 'handle']);

        Notification::assertNothingSent();
    }

    public function test_resolves_email_via_the_contact_user_when_contact_user_id_is_set(): void
    {
        Notification::fake();
        $business = $this->business();
        $contactUser = User::create([
            'first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane'.uniqid().'@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);
        app(BillingProfileManager::class)->updateBillingContact($business, (int) $contactUser->id, null, null, true, (int) $business->customer_id);

        app()->call([new SendAutoRechargeDisabledNotification((int) $business->id), 'handle']);

        Notification::assertSentOnDemand(
            AutoRechargeDisabledNotification::class,
            fn (AutoRechargeDisabledNotification $notification, array $channels, object $notifiable) => $notifiable->routes['mail'] === $contactUser->email,
        );
    }
}

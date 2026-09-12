<?php

namespace Tests\Feature\DesignSystem;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Models\Currency;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * The legacy /subscriptions destination (Pricing Plans, or the legacy
 * billing page when the customer holds an old Ultimate SMS subscription)
 * cannot bring back the inherited "Oops..!!" notifications: it renders in the
 * customer shell, so PR #253's Business OS toast answers its flash and its
 * page scripts' toastr calls, Toastr itself is never loaded, and no inherited
 * title is left in its markup. The Plan page itself is out of scope here.
 */
class LegacySubscriptionsNotificationsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    private const INHERITED = ['Oops', 'Opps', '..!!', 'Success!!', 'toastr.min.js', 'toastr.min.css', 'ext-component-toastr.css'];

    public function test_the_legacy_pricing_plans_page_uses_the_business_os_toast(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $page = $this->withSession(['status' => 'error', 'message' => 'We couldn\'t start that payment. Please try again.'])
            ->get(route('customer.subscriptions.index'))
            ->assertOk()
            ->assertViewIs('customer.Accounts.plan');

        $this->assertBusinessOsToastOnly($page, 'We couldn\'t start that payment. Please try again.');
    }

    public function test_the_legacy_subscription_billing_page_uses_the_business_os_toast_and_carries_no_inherited_titles(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth);
        $this->legacySubscription($customer->user_id);
        // The legacy billing page formats its dates in the user's own timezone.
        $customer->user->forceFill(['timezone' => 'UTC'])->save();
        $this->authenticateAs($customer);

        $page = $this->withSession(['status' => 'error', 'message' => 'That invoice could not be downloaded.'])
            ->get(route('customer.subscriptions.index'))
            ->assertOk()
            ->assertViewIs('customer.Accounts.index');

        $this->assertBusinessOsToastOnly($page, 'That invoice could not be downloaded.');

        $html = (string) $page->getContent();
        $this->assertStringContainsString("toastr['success'](data.message);", $html, 'Its own toasts pass a message only; no inherited title.');
        $this->assertStringContainsString("toastr['error'](data.message);", $html);
    }

    // -----------------------------------------------------------------

    private function assertBusinessOsToastOnly(TestResponse $page, string $message): void
    {
        $html = (string) $page->getContent();

        $this->assertStringContainsString('data-role="toast-region"', $html);
        $this->assertStringContainsString('window.toastr = {', $html, 'Legacy page scripts render through the Business OS toast.');
        $this->assertSame([['variant' => 'error', 'title' => null, 'message' => $message]], $this->pageToasts($html));

        foreach (self::INHERITED as $inherited) {
            $this->assertStringNotContainsString($inherited, $html);
        }
    }

    /**
     * @return list<array{variant: string, title: ?string, message: string}>
     */
    private function pageToasts(string $html): array
    {
        $this->assertSame(1, preg_match('/<script type="application\/json" data-role="toast-initial">(.*?)<\/script>/s', $html, $payload));

        return json_decode($payload[1], true, 512, JSON_THROW_ON_ERROR);
    }

    private function legacySubscription(int $userId): void
    {
        $currency = Currency::firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'format' => '$', 'status' => true]);

        $plan = Plan::create([
            'currency_id' => $currency->id,
            'name' => 'Legacy SMS Plan ' . uniqid(),
            'price' => 10,
            'billing_cycle' => 'monthly',
            'frequency_amount' => 1,
            'frequency_unit' => 'month',
            'options' => json_encode([]),
            'status' => true,
        ]);

        Subscription::create([
            'user_id' => $userId,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'paid' => true,
            'start_at' => now(),
            'end_at' => null,
        ]);
    }
}

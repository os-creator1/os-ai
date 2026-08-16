<?php

namespace Tests\Feature\Usage;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Entitlement\EntitlementManager;
use App\Models\AppConfig;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\Workspace\Concerns\CreatesWorkspaceTestData;
use Tests\TestCase;

/**
 * RFC-005 M2 contract §5 — direct regression test for the exclusion
 * list: the rendered dashboard never contains a functional-looking
 * control for adding a card, Stripe Checkout, top-ups, refunds,
 * auto-recharge execution, invoices/receipts, add-ons, or additional-slot
 * purchases. Honest informational text is used instead.
 */
class NoFakePaymentControlsRenderedTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;
    use CreatesWorkspaceTestData;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
    }

    private function actingAsHttpCustomer(): Customer
    {
        $this->ensureRequiredAppConfigRowsExist();

        $customer = $this->createCustomer();
        $customer->permissions = Customer::customerPermissions();
        $customer->save();

        $customer->user->email_verified_at = now();
        $customer->user->save();

        $this->actingAs($customer->user);

        return $customer;
    }

    private function ensureRequiredAppConfigRowsExist(): void
    {
        $existing = AppConfig::whereIn('setting', ['license', 'customer_permissions', 'custom_script'])->pluck('setting')->all();

        if (! in_array('license', $existing, true)) {
            AppConfig::create(['setting' => 'license', 'value' => 'test-license-key']);
        }

        if (! in_array('custom_script', $existing, true)) {
            AppConfig::create(['setting' => 'custom_script', 'value' => '']);
        }

        if (! in_array('customer_permissions', $existing, true)) {
            $default = collect((new AppConfig())->defaultSettings())->firstWhere('setting', 'customer_permissions');
            AppConfig::create($default);
        }
    }

    private function entitledWorkspace(User $owner): Workspace
    {
        $workspace = $this->createWorkspace($owner);
        $admin = User::create([
            'first_name' => 'M2Fixture', 'last_name' => 'Admin', 'email' => 'm2fixture' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);
        app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Core, $admin->id, 'Fixture.', true, 2);

        return $workspace->fresh();
    }

    public function test_dashboard_shows_the_honest_unavailable_message_and_no_fake_controls(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->entitledWorkspace($customer->user);
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes());

        $response = $this->get(route('customer.workspaces.businesses.usage-billing.show', [$workspace->uid, $business->uid]))
            ->assertOk();

        $response->assertSee('Payment methods and top-ups are not yet configured.');

        $html = $response->getContent();

        foreach (['Add card', 'Add Card', 'Stripe Checkout', 'Top up', 'Top-up', 'Refund', 'Auto-recharge', 'Enable auto-recharge', 'Invoice', 'Receipt', 'Buy add-on', 'Purchase add-on', 'Buy additional slot', 'Purchase slot'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html, "Dashboard must not render a fake payment control containing \"{$forbidden}\".");
        }
    }
}

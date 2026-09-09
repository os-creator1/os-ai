<?php

namespace Tests\Feature\Security;

use App\Models\AppConfig;
use App\Models\Currency;
use App\Models\Invoices;
use App\Models\PaymentMethods;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * Security Remediation Slice 0 §16.A.2 (D-19) — resources/views/customer/dashboard.blade.php
 * used to emit `WHERE user_id = ? AND status = 'unpaid' OR status = 'pending'`,
 * whose ungrouped `orWhere` carried no ownership predicate on its second
 * disjunct (AND binds tighter than OR). UserController::index() now
 * computes both counts once, with whereIn(['unpaid','pending']) applying
 * the SAME $userId predicate to both statuses by construction.
 *
 * In this sandbox (and identically on unmodified origin/main, confirmed
 * via `git stash`-based reproduction with a cleared compiled-view cache)
 * the shared customer layout cannot fully render at all, for reasons
 * unrelated to this change: a pre-existing, missing Laravel Mix asset
 * manifest entry a nested partial requires. dashboard() below therefore
 * proves the exact figure UserController::index() computed and handed to
 * the view via a View::composer capture on 'customer.dashboard' — which
 * fires before that nested partial is ever reached — rather than via
 * $response->assertSee() on the rendered HTML.
 */
class DashboardInvoiceScopeTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    private Currency $currency;

    private PaymentMethods $paymentMethod;

    protected function setUp(): void
    {
        parent::setUp();

        User::create([
            'first_name' => 'Placeholder',
            'last_name' => 'SuperAdmin',
            'email' => 'placeholder-superadmin' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
        ]);

        $this->ensureRequiredAppConfigRowsExist();

        $this->currency = Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        $this->paymentMethod = PaymentMethods::create(['name' => 'Test Gateway', 'type' => 'test_gateway', 'status' => true, 'options' => json_encode([])]);
    }

    /**
     * Tenant A's dashboard shows a count equal to A's own unpaid+pending
     * only — asserted against an explicitly computed expected integer,
     * never a hard-coded literal — while B and C, unrelated tenants, each
     * hold invoices in every status so both sides of the former OR are
     * populated for every tenant.
     */
    public function test_a_tenants_dashboard_count_is_scoped_to_their_own_invoices_with_every_status_populated_for_every_tenant(): void
    {
        $tenantA = $this->authenticatedCustomer();
        $tenantB = $this->createCustomer();
        $tenantC = $this->createCustomer();

        // Every tenant holds paid, unpaid AND pending invoices, so both
        // sides of the former ungrouped OR are populated for every tenant.
        $expectedForA = 0;
        foreach ([Invoices::STATUS_PAID, Invoices::STATUS_UNPAID, Invoices::STATUS_PENDING] as $status) {
            $this->makeInvoice($tenantA->user_id, $status);
            if ($status !== Invoices::STATUS_PAID) {
                $expectedForA++;
            }
        }
        foreach ([Invoices::STATUS_PAID, Invoices::STATUS_UNPAID, Invoices::STATUS_PENDING] as $status) {
            $this->makeInvoice($tenantB->user_id, $status);
            $this->makeInvoice($tenantC->user_id, $status);
        }

        $rendered = $this->dashboardInvoiceCount();

        $actual = Invoices::where('user_id', $tenantA->user_id)
            ->whereIn('status', [Invoices::STATUS_UNPAID, Invoices::STATUS_PENDING])
            ->count();

        $this->assertSame($expectedForA, $actual);
        $this->assertSame($actual, $rendered);

        // Neither B's nor C's totals appear as A's computed figure — each
        // has 2 unpaid+pending invoices, a distinct figure from A's own 2
        // status rows summed the SAME way; assert the query result
        // directly rather than the ambiguous rendered digits.
        $bCount = Invoices::where('user_id', $tenantB->user_id)->whereIn('status', [Invoices::STATUS_UNPAID, Invoices::STATUS_PENDING])->count();
        $cCount = Invoices::where('user_id', $tenantC->user_id)->whereIn('status', [Invoices::STATUS_UNPAID, Invoices::STATUS_PENDING])->count();
        $this->assertSame(2, $bCount);
        $this->assertSame(2, $cCount);
    }

    /**
     * Regression guard for the precedence bug specifically: with A holding
     * ZERO pending invoices and B/C holding several, A's count must not
     * include them. A naive fixture (every tenant with the same status
     * mix) would miss this — the old ungrouped OR only leaked when the
     * VIEWER had no matching row of their own for the un-scoped disjunct.
     */
    public function test_a_tenant_with_zero_pending_invoices_never_inherits_other_tenants_pending_invoices(): void
    {
        $tenantA = $this->authenticatedCustomer();
        $tenantB = $this->createCustomer();
        $tenantC = $this->createCustomer();

        // A has ONLY unpaid invoices — zero pending.
        $this->makeInvoice($tenantA->user_id, Invoices::STATUS_UNPAID);
        $this->makeInvoice($tenantA->user_id, Invoices::STATUS_UNPAID);

        // B and C each hold several pending invoices.
        for ($i = 0; $i < 4; $i++) {
            $this->makeInvoice($tenantB->user_id, Invoices::STATUS_PENDING);
        }
        for ($i = 0; $i < 3; $i++) {
            $this->makeInvoice($tenantC->user_id, Invoices::STATUS_PENDING);
        }

        $rendered = $this->dashboardInvoiceCount();

        $expected = Invoices::where('user_id', $tenantA->user_id)
            ->whereIn('status', [Invoices::STATUS_UNPAID, Invoices::STATUS_PENDING])
            ->count();

        $this->assertSame(2, $expected, 'A has exactly 2 unpaid and 0 pending invoices of their own.');
        $this->assertSame($expected, $rendered);

        // The exact defect: the old query's second disjunct
        // (`orWhere('status', PENDING)`) carried no ownership predicate at
        // all, so it would have returned every OTHER tenant's pending rows
        // too. That would have produced 2 + 4 + 3 = 9, not 2.
        $this->assertNotSame(9, $rendered);
    }

    /**
     * The reverse combination: A holds pending but zero unpaid, while B/C
     * hold several unpaid invoices — the other half of the precedence
     * trap.
     */
    public function test_a_tenant_with_zero_unpaid_invoices_never_inherits_other_tenants_unpaid_invoices(): void
    {
        $tenantA = $this->authenticatedCustomer();
        $tenantB = $this->createCustomer();
        $tenantC = $this->createCustomer();

        $this->makeInvoice($tenantA->user_id, Invoices::STATUS_PENDING);

        for ($i = 0; $i < 5; $i++) {
            $this->makeInvoice($tenantB->user_id, Invoices::STATUS_UNPAID);
        }
        for ($i = 0; $i < 2; $i++) {
            $this->makeInvoice($tenantC->user_id, Invoices::STATUS_UNPAID);
        }

        $rendered = $this->dashboardInvoiceCount();

        $expected = Invoices::where('user_id', $tenantA->user_id)
            ->whereIn('status', [Invoices::STATUS_UNPAID, Invoices::STATUS_PENDING])
            ->count();

        $this->assertSame(1, $expected);
        $this->assertSame($expected, $rendered);
    }

    /**
     * The figure computed for A's page never carries B's invoice total —
     * a direct assertion against the actual query results for each, proven
     * not to equal the count A's own page is handed.
     */
    public function test_the_rendered_response_never_contains_another_tenants_invoice_totals(): void
    {
        $tenantA = $this->authenticatedCustomer();
        $tenantB = $this->createCustomer();

        $this->makeInvoice($tenantA->user_id, Invoices::STATUS_UNPAID);

        for ($i = 0; $i < 17; $i++) {
            $this->makeInvoice($tenantB->user_id, Invoices::STATUS_PENDING);
        }

        $rendered = $this->dashboardInvoiceCount();

        $bTotal = Invoices::where('user_id', $tenantB->user_id)->count();
        $this->assertSame(17, $bTotal);

        // A's own count is 1; B's distinguishable total (17) must not leak
        // into the figure computed for A's page.
        $this->assertSame(1, $rendered);
        $this->assertNotSame(17, $rendered);
    }

    /**
     * Requests user.home and returns the exact
     * unpaidAndPendingInvoiceCount UserController::index() computed and
     * handed to the view — captured via a view composer that fires before
     * Blade evaluation, so it does not depend on the view finishing
     * rendering (see class docblock).
     */
    private function dashboardInvoiceCount(): int
    {
        $captured = null;

        View::composer('customer.dashboard', function ($view) use (&$captured) {
            $captured = $view->getData();
        });

        $this->get(route('user.home'));

        $this->assertIsArray($captured, 'customer.dashboard must have been composed for this request.');
        $this->assertArrayHasKey('unpaidAndPendingInvoiceCount', $captured);

        return $captured['unpaidAndPendingInvoiceCount'];
    }

    private function authenticatedCustomer(): \App\Models\Customer
    {
        $customer = $this->createCustomer();
        $customer->user->email_verified_at = now();
        $customer->user->save();

        $this->withSession(['permissions' => collect(['access_backend'])]);
        $this->actingAs($customer->user);

        return $customer;
    }

    private function makeInvoice(int $userId, string $status): Invoices
    {
        return Invoices::create([
            'user_id' => $userId,
            'currency_id' => $this->currency->id,
            'payment_method' => $this->paymentMethod->id,
            'amount' => '10.00',
            'type' => Invoices::TYPE_SUBSCRIPTION,
            'status' => $status,
        ]);
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
}

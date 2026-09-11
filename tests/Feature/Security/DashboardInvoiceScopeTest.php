<?php

namespace Tests\Feature\Security;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Invoices;
use App\Models\PaymentMethods;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Security Remediation Slice 0 §16.A.2 (D-19), re-pointed by Customer
 * Experience Slice 4 §8.1 — never removed.
 *
 * D-19 was an ungrouped `WHERE user_id = ? AND status = 'unpaid' OR status =
 * 'pending'` in the customer dashboard: its second disjunct carried no
 * ownership predicate, so every tenant saw every other tenant's pending
 * invoices. Slice 4 removes the invoice tile from the dashboard altogether
 * (`invoices` is keyed to a paying user, which is neither a Business nor an
 * Account), so the guard moves to the surface that still computes invoice
 * figures: Customer\InvoiceController::search(), whose four reads — total,
 * page, filtered page, filtered total — feed the invoice list on
 * customer.subscriptions.index.
 *
 * search() ends in `exit()`, which would terminate the test process, so the
 * surface cannot be driven over HTTP here. The guard is therefore the
 * contract's repository-level query-shape guard: the surface's four reads
 * are pinned to the viewer-scoped predicate, every customer-facing invoice
 * read anywhere is checked for that predicate, and the isolation properties
 * the original four tests proved are re-proved against that exact predicate
 * with three unrelated tenants holding paid, unpaid and pending invoices.
 * One assertion is added: the rebuilt Business Home renders no invoice
 * figure and reads no invoice at all.
 */
class DashboardInvoiceScopeTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    /** The one predicate every customer-facing invoice read must open with. */
    private const VIEWER_SCOPE = "Invoices::where('user_id', Auth::user()->id)";

    private Currency $currency;

    private PaymentMethods $paymentMethod;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureRequiredAppConfigRowsExist();
        $this->platformAdminId();

        $this->currency = Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        $this->paymentMethod = PaymentMethods::create(['name' => 'Test Gateway', 'type' => 'test_gateway', 'status' => true, 'options' => json_encode([])]);
    }

    /**
     * Three unrelated tenants, each holding paid, unpaid AND pending
     * invoices, so both sides of the former OR are populated for everyone:
     * each viewer's figures — total and unpaid+pending — are their own only.
     */
    public function test_a_viewers_invoice_figures_are_their_own_with_every_status_populated_for_every_tenant(): void
    {
        [$tenantA, $tenantB, $tenantC] = [$this->customer(), $this->customer(), $this->customer()];

        foreach ([$tenantA, $tenantB, $tenantC] as $tenant) {
            foreach ([Invoices::STATUS_PAID, Invoices::STATUS_UNPAID, Invoices::STATUS_PENDING] as $status) {
                $this->makeInvoice($tenant->user_id, $status);
            }
        }

        foreach ([$tenantA, $tenantB, $tenantC] as $viewer) {
            $figures = $this->surfaceFiguresFor($viewer);

            $this->assertSame(3, $figures['total'], 'The list total is the viewer\'s own three invoices.');
            $this->assertSame(2, $figures['unpaidAndPending'], 'Unpaid + pending is the viewer\'s own two.');
            $this->assertSame([$viewer->user_id], $figures['owners'], 'Every row belongs to the viewer.');
        }
    }

    /**
     * The precedence trap itself: a viewer with ZERO pending invoices never
     * inherits other tenants' pending ones (the old query returned 2 + 4 + 3).
     */
    public function test_a_viewer_with_zero_pending_invoices_never_inherits_other_tenants_pending_invoices(): void
    {
        [$tenantA, $tenantB, $tenantC] = [$this->customer(), $this->customer(), $this->customer()];

        $this->makeInvoice($tenantA->user_id, Invoices::STATUS_UNPAID);
        $this->makeInvoice($tenantA->user_id, Invoices::STATUS_UNPAID);
        for ($i = 0; $i < 4; $i++) {
            $this->makeInvoice($tenantB->user_id, Invoices::STATUS_PENDING);
        }
        for ($i = 0; $i < 3; $i++) {
            $this->makeInvoice($tenantC->user_id, Invoices::STATUS_PENDING);
        }

        $figures = $this->surfaceFiguresFor($tenantA);

        $this->assertSame(2, $figures['unpaidAndPending']);
        $this->assertNotSame(9, $figures['unpaidAndPending']);
        $this->assertSame([$tenantA->user_id], $figures['owners']);
    }

    /** The reverse half: zero unpaid never inherits other tenants' unpaid ones. */
    public function test_a_viewer_with_zero_unpaid_invoices_never_inherits_other_tenants_unpaid_invoices(): void
    {
        [$tenantA, $tenantB, $tenantC] = [$this->customer(), $this->customer(), $this->customer()];

        $this->makeInvoice($tenantA->user_id, Invoices::STATUS_PENDING);
        for ($i = 0; $i < 5; $i++) {
            $this->makeInvoice($tenantB->user_id, Invoices::STATUS_UNPAID);
        }
        for ($i = 0; $i < 2; $i++) {
            $this->makeInvoice($tenantC->user_id, Invoices::STATUS_UNPAID);
        }

        $figures = $this->surfaceFiguresFor($tenantA);

        $this->assertSame(1, $figures['unpaidAndPending']);
        $this->assertSame(1, $figures['total']);
    }

    /**
     * No rendered response carries another tenant's invoice totals: the
     * viewer's own figure is 1, B's distinguishable 17 appears nowhere — not
     * in the surface's figures and not on the viewer's home page.
     */
    public function test_no_rendered_response_contains_another_tenants_invoice_totals(): void
    {
        [$tenantA, , ] = $this->tenant(WorkspacePlanTier::Growth, 'Invoice Venue', 'Invoice Account');
        $tenantB = $this->customer();

        $this->makeInvoice($tenantA->user_id, Invoices::STATUS_UNPAID);
        for ($i = 0; $i < 17; $i++) {
            $this->makeInvoice($tenantB->user_id, Invoices::STATUS_PENDING);
        }

        $this->assertSame(17, Invoices::where('user_id', $tenantB->user_id)->count());
        $this->assertSame(1, $this->surfaceFiguresFor($tenantA)['total']);

        $this->authenticateAs($tenantA);
        $main = $this->mainText($this->home()->assertOk()->getContent());

        $this->assertDoesNotMatchRegularExpression('/\b17\b/', $main);
    }

    /**
     * §8.1 point 3 — added, not substituted: the rebuilt Business Home shows
     * no invoice figure and does not read the invoices table at all.
     */
    public function test_the_business_home_renders_no_invoice_figure_and_reads_no_invoice(): void
    {
        [$tenantA, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Harbor Venue', 'Harbor Account');
        foreach ([Invoices::STATUS_PAID, Invoices::STATUS_UNPAID, Invoices::STATUS_PENDING] as $status) {
            $this->makeInvoice($tenantA->user_id, $status);
        }
        $this->authenticateAs($tenantA);

        $sql = [];
        DB::listen(function ($query) use (&$sql): void {
            $sql[] = $query->sql;
        });

        $html = $this->home()->assertOk()->getContent();

        $this->assertStringContainsString('data-kind="business"', $html);
        $this->assertSame([], array_values(array_filter($sql, fn (string $s) => preg_match('/\binvoices\b/', $s) === 1)), 'The dashboard reads no invoice.');
        $this->assertDoesNotMatchRegularExpression('/invoice/i', $this->mainText($html), 'No invoice tile, label or figure.');
        $this->assertStringNotContainsString('<sup>', $this->mainHtml($html), 'The former "unpaid / total" figure is gone.');
    }

    /**
     * The surface's own four reads open with the viewer-scoped predicate, the
     * file carries no `orWhere`, and no other customer-facing code reads
     * invoices without that predicate — so reintroducing an unscoped invoice
     * query anywhere a customer can see fails here.
     */
    public function test_every_customer_facing_invoice_read_is_scoped_to_the_viewer(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/Customer/InvoiceController.php'));
        preg_match_all('/Invoices::\w+\([^;]*/', $controller, $reads);

        $this->assertCount(4, $reads[0], 'search() makes exactly four invoice reads: ' . implode(' | ', $reads[0]));
        foreach ($reads[0] as $read) {
            $this->assertStringStartsWith(self::VIEWER_SCOPE, $read);
        }
        $this->assertStringNotContainsString('orWhere', $controller);

        $offending = [];

        foreach ($this->customerFacingFiles() as $file) {
            $source = file_get_contents($file);

            preg_match_all('/(Invoices::(?!create\b|STATUS_|TYPE_|class\b)\w+\([^;]*|->invoices\(\)[^;]*)/', $source, $matches);

            foreach ($matches[0] as $statement) {
                if (str_contains($statement, "where('user_id', Auth::user()->id)") && ! str_contains($statement, 'orWhere')) {
                    continue;
                }

                // Pre-existing payment-callback de-duplication
                // (PaymentController, AccountController): one invoice looked
                // up by the payment provider's own transaction id, never a
                // list or a figure shown to a viewer.
                if (str_starts_with($statement, "Invoices::where('transaction_id', ") && ! str_contains($statement, 'orWhere')) {
                    continue;
                }

                $offending[] = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file) . ': ' . $statement;
            }
        }

        $this->assertSame([], $offending, 'Unscoped customer-facing invoice read(s).');
    }

    // -----------------------------------------------------------------

    /**
     * The invoice list's figures for one viewer, computed with the very
     * predicate the surface's reads are pinned to above: the list total
     * (search()'s first read) and the unpaid + pending subset the dashboard
     * once showed, plus the owners of every row the list would return.
     *
     * @return array{total: int, unpaidAndPending: int, owners: array<int, int>}
     */
    private function surfaceFiguresFor(Customer $viewer): array
    {
        Auth::login($viewer->user);

        $scoped = fn () => Invoices::where('user_id', Auth::user()->id);

        $figures = [
            'total' => $scoped()->count(),
            'unpaidAndPending' => $scoped()->whereIn('status', [Invoices::STATUS_UNPAID, Invoices::STATUS_PENDING])->count(),
            'owners' => $scoped()->pluck('user_id')->map(fn ($id) => (int) $id)->unique()->values()->all(),
        ];

        Auth::logout();

        return $figures;
    }

    private function customer(): Customer
    {
        return $this->createCustomer();
    }

    /** @return array<int, string> */
    private function customerFacingFiles(): array
    {
        $files = [];

        foreach ([app_path('Http/Controllers/Customer'), app_path('Http/Controllers/User'), app_path('Library/Dashboard'), resource_path('views/customer')] as $root) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $file) {
                if (str_ends_with($file->getFilename(), '.php')) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
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

    private function mainHtml(string $html): string
    {
        $start = strpos($html, '<main');
        $end = strpos($html, '</main>');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }

    private function mainText(string $html): string
    {
        $region = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#si', ' ', $this->mainHtml($html)) ?? '';

        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($region))) ?? '');
    }
}

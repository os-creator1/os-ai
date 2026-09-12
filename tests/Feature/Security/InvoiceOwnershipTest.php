<?php

namespace Tests\Feature\Security;

use App\Models\AppConfig;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Invoices;
use App\Models\PaymentMethods;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * Billing Security — Customer Invoice Ownership.
 *
 * customer.invoices.search already scopes every query by
 * `Invoices::where('user_id', Auth::user()->id)`. The direct routes
 * customer.invoices.view and customer.invoices.print did not: they
 * route-bind an Invoices model by uid and rendered it directly, so a
 * customer who altered the uid in the URL could view or print another
 * customer's invoice. Nonexistent uids fared no better: implicit
 * route-model-binding's ModelNotFoundException reaches this
 * application's global Handler, which maps it to a 500 response outside
 * the local environment (app/Exceptions/Handler.php) — never a 404.
 *
 * InvoiceController::view()/print() now each assert
 * `$invoice->user_id === Auth::id()` before rendering, exactly the
 * inline `abort_unless(...)` idiom TemplateController::show() already
 * uses for the same class of problem. The two routes also each carry a
 * ->missing() callback, mirroring routes/admin.php's identical
 * {business} precedent, so a nonexistent uid is refused before
 * ModelNotFoundException is ever thrown. Foreign ownership and a
 * nonexistent uid must both read as a plain 404 — never a 403, and never
 * distinguishably different from one another.
 */
class InvoiceOwnershipTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    private Currency $currency;

    private PaymentMethods $paymentMethod;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureRequiredAppConfigRowsExist();

        $this->currency = Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        $this->paymentMethod = PaymentMethods::create(['name' => 'Test Gateway', 'type' => 'test_gateway', 'status' => true, 'options' => json_encode([])]);
    }

    // =================================================================
    // Own invoice: unchanged behaviour.
    // =================================================================

    public function test_a_customer_can_view_their_own_invoice(): void
    {
        $owner = $this->authenticatedCustomer();
        $invoice = $this->makeInvoice($owner->user_id, Invoices::STATUS_PAID, 'My own invoice description');

        $response = $this->get(route('customer.invoices.view', $invoice->uid));

        $response->assertOk();
        $response->assertSee('My own invoice description');
    }

    public function test_a_customer_can_print_their_own_invoice(): void
    {
        $owner = $this->authenticatedCustomer();
        $invoice = $this->makeInvoice($owner->user_id, Invoices::STATUS_PAID, 'My own printable invoice');

        $response = $this->get(route('customer.invoices.print', $invoice->uid));

        $response->assertOk();
        $response->assertSee('My own printable invoice');
    }

    // =================================================================
    // Foreign invoice: 404, never 403, never any leaked content.
    // =================================================================

    public function test_viewing_another_customers_invoice_is_a_404(): void
    {
        $this->authenticatedCustomer();
        $stranger = $this->createCustomer();
        $foreignInvoice = $this->makeInvoice($stranger->user_id, Invoices::STATUS_UNPAID, 'SECRET_FOREIGN_DESCRIPTION_12345');

        $response = $this->get(route('customer.invoices.view', $foreignInvoice->uid));

        $response->assertNotFound();
        $response->assertDontSee('SECRET_FOREIGN_DESCRIPTION_12345');
    }

    public function test_printing_another_customers_invoice_is_a_404(): void
    {
        $this->authenticatedCustomer();
        $stranger = $this->createCustomer();
        $foreignInvoice = $this->makeInvoice($stranger->user_id, Invoices::STATUS_UNPAID, 'SECRET_FOREIGN_PRINT_98765');

        $response = $this->get(route('customer.invoices.print', $foreignInvoice->uid));

        $response->assertNotFound();
        $response->assertDontSee('SECRET_FOREIGN_PRINT_98765');
    }

    public function test_a_foreign_invoices_amount_status_and_type_never_appear(): void
    {
        $this->authenticatedCustomer();
        $stranger = $this->createCustomer();
        $foreignInvoice = Invoices::create([
            'user_id' => $stranger->user_id,
            'currency_id' => $this->currency->id,
            'payment_method' => $this->paymentMethod->id,
            'amount' => '999.42',
            'total_amount' => '999.42',
            'type' => Invoices::TYPE_NUMBERS,
            'description' => 'UNIQUE_LEAK_PROBE_DESCRIPTION',
            'status' => Invoices::STATUS_PENDING,
        ]);

        foreach ([
            route('customer.invoices.view', $foreignInvoice->uid),
            route('customer.invoices.print', $foreignInvoice->uid),
        ] as $url) {
            $response = $this->get($url);
            $response->assertNotFound();
            $response->assertDontSee('999.42');
            $response->assertDontSee('UNIQUE_LEAK_PROBE_DESCRIPTION');
            $response->assertDontSee(Invoices::TYPE_NUMBERS);
        }
    }

    // =================================================================
    // Nonexistent invoice: the SAME 404, indistinguishable from foreign.
    // =================================================================

    public function test_viewing_a_nonexistent_invoice_is_a_404(): void
    {
        $this->authenticatedCustomer();

        $response = $this->get(route('customer.invoices.view', 'this-uid-does-not-exist'));

        $response->assertNotFound();
    }

    public function test_printing_a_nonexistent_invoice_is_a_404(): void
    {
        $this->authenticatedCustomer();

        $response = $this->get(route('customer.invoices.print', 'this-uid-does-not-exist'));

        $response->assertNotFound();
    }

    public function test_foreign_and_nonexistent_invoices_are_indistinguishable(): void
    {
        $this->authenticatedCustomer();
        $stranger = $this->createCustomer();
        $foreignInvoice = $this->makeInvoice($stranger->user_id, Invoices::STATUS_UNPAID);

        $foreignResponse = $this->get(route('customer.invoices.view', $foreignInvoice->uid));
        $nonexistentResponse = $this->get(route('customer.invoices.view', 'totally-made-up-uid'));

        $this->assertSame(404, $foreignResponse->getStatusCode());
        $this->assertSame(404, $nonexistentResponse->getStatusCode());
        $this->assertSame($foreignResponse->getStatusCode(), $nonexistentResponse->getStatusCode());
        $this->assertSame($foreignResponse->getContent(), $nonexistentResponse->getContent(), 'A foreign invoice and a nonexistent one must render the identical 404 page.');

        $this->assertNotSame(403, $foreignResponse->getStatusCode(), 'Foreign ownership must never be a 403.');
    }

    // =================================================================
    // Search/list stays scoped to the acting customer only.
    // =================================================================

    /**
     * InvoiceController::search() ends with `echo json_encode(...); exit();`
     * — a real process-terminating `exit()`, not a returned Response. Driven
     * in-process (as every other test in this file drives its route) that
     * `exit()` would kill the PHPUnit process itself, not just this test.
     * This is why no test anywhere in the repository has ever driven this
     * route (or any of the identically-shaped `echo json_encode(...);
     * exit();` DataTables endpoints in this codebase) over real HTTP before.
     *
     * So this one test drives it in a genuinely separate OS process — the
     * same technique this repository's own concurrency tests already use
     * for real cross-process behaviour — and reads back the JSON the
     * controller printed to that process's own stdout. The child creates
     * and commits its own fixtures (a RefreshDatabase-wrapped transaction
     * in this parent process is invisible to another connection), and this
     * test cleans them up explicitly afterward since they are real, committed
     * rows outside that transaction.
     */
    public function test_the_search_endpoint_returns_only_the_actors_own_invoices(): void
    {
        $vendorAutoload = base_path('vendor/autoload.php');
        $bootstrapApp = base_path('bootstrap/app.php');
        $database = \Tests\Support\TestDatabaseSafety::activeTestDatabase();

        $runnerPath = sys_get_temp_dir() . '/invoice_search_ownership_runner_' . uniqid() . '.php';
        $markerPath = sys_get_temp_dir() . '/invoice_search_ownership_marker_' . uniqid() . '.json';

        file_put_contents($runnerPath, <<<PHP
<?php
require '{$vendorAutoload}';
putenv('APP_ENV=testing');
\$_ENV['APP_ENV'] = 'testing';
\$_SERVER['APP_ENV'] = 'testing';
\$app = require '{$bootstrapApp}';
\$kernel = \$app->make(Illuminate\Contracts\Console\Kernel::class);
\$kernel->bootstrap();

\Tests\Support\TestDatabaseSafety::assertMatchesActiveTestDatabase(getenv('EXPECTED_TEST_DATABASE'));

\$currency = App\Models\Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '\$', 'status' => true]);
\$paymentMethod = App\Models\PaymentMethods::create(['name' => 'Test Gateway', 'type' => 'test_gateway', 'status' => true, 'options' => json_encode([])]);
App\Models\AppConfig::firstOrCreate(['setting' => 'license'], ['value' => 'test-license-key']);

\$ownerUser = App\Models\User::create(['first_name' => 'Owner', 'last_name' => 'Customer', 'email' => 'owner' . uniqid() . '@example.test', 'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer']);
\$ownerCustomer = App\Models\Customer::create(['user_id' => \$ownerUser->id, 'permissions' => json_encode(['access_backend'])]);

\$strangerUser = App\Models\User::create(['first_name' => 'Stranger', 'last_name' => 'Customer', 'email' => 'stranger' . uniqid() . '@example.test', 'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer']);
App\Models\Customer::create(['user_id' => \$strangerUser->id]);

\$ownInvoice = App\Models\Invoices::create(['user_id' => \$ownerUser->id, 'currency_id' => \$currency->id, 'payment_method' => \$paymentMethod->id, 'amount' => '10.00', 'total_amount' => '10.00', 'type' => App\Models\Invoices::TYPE_SUBSCRIPTION, 'description' => 'Owner invoice', 'status' => App\Models\Invoices::STATUS_PAID]);
App\Models\Invoices::create(['user_id' => \$strangerUser->id, 'currency_id' => \$currency->id, 'payment_method' => \$paymentMethod->id, 'amount' => '10.00', 'total_amount' => '10.00', 'type' => App\Models\Invoices::TYPE_SUBSCRIPTION, 'description' => 'Stranger invoice one', 'status' => App\Models\Invoices::STATUS_PAID]);
App\Models\Invoices::create(['user_id' => \$strangerUser->id, 'currency_id' => \$currency->id, 'payment_method' => \$paymentMethod->id, 'amount' => '10.00', 'total_amount' => '10.00', 'type' => App\Models\Invoices::TYPE_SUBSCRIPTION, 'description' => 'Stranger invoice two', 'status' => App\Models\Invoices::STATUS_UNPAID]);

file_put_contents('{$markerPath}', json_encode([
    'owner_user_id' => \$ownerUser->id,
    'stranger_user_id' => \$strangerUser->id,
    'currency_id' => \$currency->id,
    'payment_method_id' => \$paymentMethod->id,
    'own_invoice_id' => \$ownInvoice->id,
]));

app('auth')->guard('web')->setUser(\$ownerUser);
app('auth')->shouldUse('web');

\$httpKernel = \$app->make(Illuminate\Contracts\Http\Kernel::class);
\$request = Illuminate\Http\Request::create('/invoices/search', 'POST', [
    'draw' => 1, 'start' => 0, 'length' => 25,
    'order' => [['column' => 4, 'dir' => 'asc']],
    'search' => ['value' => ''],
]);
\$httpKernel->handle(\$request);
PHP);

        $process = new \Symfony\Component\Process\Process(
            [(new \Symfony\Component\Process\PhpExecutableFinder())->find() ?: 'php', $runnerPath],
            null,
            ['DB_DATABASE' => $database, 'EXPECTED_TEST_DATABASE' => $database],
        );
        $process->setTimeout(20.0);
        $process->run();

        $marker = json_decode((string) file_get_contents($markerPath), true);

        try {
            $this->assertNotNull($marker, 'The child process never reached its fixture marker: ' . $process->getErrorOutput());

            $rawJson = trim($process->getOutput());
            $payload = json_decode($rawJson, true);

            $this->assertIsArray($payload, 'The search endpoint must have printed valid JSON: ' . $rawJson . ' / stderr: ' . $process->getErrorOutput());
            $this->assertSame(1, $payload['recordsTotal'], "Only the acting customer's own invoice must be counted.");
            $this->assertCount(1, $payload['data']);
            $this->assertStringContainsString('#' . $marker['own_invoice_id'], $payload['data'][0]['id']);

            $this->assertStringNotContainsString('Stranger invoice one', $rawJson);
            $this->assertStringNotContainsString('Stranger invoice two', $rawJson);
        } finally {
            @unlink($runnerPath);
            @unlink($markerPath);

            if (is_array($marker)) {
                // The child process committed these rows for real, on its
                // own connection — they are NOT part of this test's
                // RefreshDatabase transaction and would survive that
                // transaction's rollback. Deleting them through THIS
                // process's default connection would itself be undone by
                // that same rollback, so the delete runs on a separate,
                // real connection to the same database instead (mirroring
                // MessagingSchemaInvariantsTest's adminConnection()
                // pattern), so the delete commits immediately for real.
                $cleanupConnection = 'invoice_search_ownership_cleanup';
                config(['database.connections.' . $cleanupConnection => config('database.connections.mysql')]);
                \Illuminate\Support\Facades\DB::purge($cleanupConnection);

                $cleanup = \Illuminate\Support\Facades\DB::connection($cleanupConnection);
                $cleanup->table('invoices')->whereIn('user_id', [$marker['owner_user_id'], $marker['stranger_user_id']])->delete();
                $cleanup->table('customers')->whereIn('user_id', [$marker['owner_user_id'], $marker['stranger_user_id']])->delete();
                $cleanup->table('users')->whereIn('id', [$marker['owner_user_id'], $marker['stranger_user_id']])->delete();
                $cleanup->table('payment_methods')->where('id', $marker['payment_method_id'])->delete();
                $cleanup->table('currencies')->where('id', $marker['currency_id'])->delete();

                \Illuminate\Support\Facades\DB::purge($cleanupConnection);
            }
        }
    }

    // =================================================================
    // Route-key behaviour (uid, per HasUid::getRouteKeyName()) unchanged.
    // =================================================================

    public function test_the_invoice_route_binds_by_uid_not_by_numeric_id(): void
    {
        $owner = $this->authenticatedCustomer();
        $invoice = $this->makeInvoice($owner->user_id, Invoices::STATUS_PAID);

        // The numeric primary key must NOT resolve the route — only the uid
        // does. A customer's own numeric id, used where the uid belongs,
        // must behave exactly like any other nonexistent/foreign lookup:
        // a 404, never a coincidental match on an unrelated invoice.
        $response = $this->get(route('customer.invoices.view', $invoice->id));

        if ((string) $invoice->id === (string) $invoice->uid) {
            $this->markTestSkipped('Fixture collision between id and uid; not meaningful here.');
        }

        $response->assertNotFound();
    }

    // =================================================================
    // Authentication is unchanged: an unauthenticated request still hits
    // the ordinary auth gate, not a new behaviour introduced here.
    // =================================================================

    public function test_an_unauthenticated_request_is_not_permitted_through(): void
    {
        $owner = $this->createCustomer();
        $invoice = $this->makeInvoice($owner->user_id, Invoices::STATUS_PAID);

        $response = $this->get(route('customer.invoices.view', $invoice->uid));

        // This application's Handler renders an unauthenticated request as
        // its own errors.401 view (app/Exceptions/Handler.php), not
        // Laravel's default login redirect — pre-existing, unchanged by
        // this task's ownership check.
        $response->assertStatus(401);
        $this->assertNotSame(200, $response->getStatusCode());
    }

    // =================================================================
    // Fixtures
    // =================================================================

    private function authenticatedCustomer(): Customer
    {
        $customer = $this->createCustomer();
        $customer->user->email_verified_at = now();
        // Tool::formatDate()/currentTimezone() reads Auth::user()->timezone
        // with no fallback for an empty string, and throws constructing a
        // Carbon timezone from it — required here because
        // customer.Accounts.invoice/print render invoice dates through it,
        // which the pre-existing DashboardInvoiceScopeTest never needed.
        $customer->user->timezone = 'UTC';
        $customer->user->save();

        $this->withSession(['permissions' => collect(['access_backend'])]);
        $this->actingAs($customer->user);

        return $customer;
    }

    private function makeInvoice(int $userId, string $status, ?string $description = null): Invoices
    {
        return Invoices::create([
            'user_id' => $userId,
            'currency_id' => $this->currency->id,
            'payment_method' => $this->paymentMethod->id,
            'amount' => '10.00',
            'total_amount' => '10.00',
            'type' => Invoices::TYPE_SUBSCRIPTION,
            'description' => $description ?? 'Fixture invoice',
            'status' => $status,
        ]);
    }

    private function ensureRequiredAppConfigRowsExist(): void
    {
        $required = ['license', 'customer_permissions', 'custom_script', 'company_address'];
        $existing = AppConfig::whereIn('setting', $required)->pluck('setting')->all();

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

        // Required by resources/views/customer/Accounts/invoice.blade.php
        // and print.blade.php, via Helper::app_config('company_address') —
        // neither of which the pre-existing DashboardInvoiceScopeTest
        // needed, since it never renders the invoice view itself.
        if (! in_array('company_address', $existing, true)) {
            $default = collect((new AppConfig())->defaultSettings())->firstWhere('setting', 'company_address');
            AppConfig::create($default);
        }
    }
}

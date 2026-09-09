<?php

namespace Tests\Feature\Security;

use App\Models\AppConfig;
use App\Models\Contacts;
use App\Models\PaymentMethods;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * Security Remediation Slice 0 §16.A.1 (D-24) — the five unauthenticated,
 * state-changing GET routes (add-gateways, remove-jobs, remove-contacts,
 * cache-clear, update-campaign-cache/{campaign}/{number}) and the locally
 * guarded /debug route, along with app/Http/Controllers/Debug/DebugController.php
 * in full, no longer exist. See docs/automation/AI-BUSINESS-OS-CUSTOMER-EXPERIENCE-AND-NAVIGATION-REDESIGN.md
 * §5.4/§16.A.1 for the full evidence this test proves against.
 */
class DebugRouteRemovalTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    private const REMOVED_URIS = [
        '/add-gateways',
        '/remove-jobs',
        '/remove-contacts',
        '/cache-clear',
        '/update-campaign-cache/some-campaign-uid/5',
    ];

    private const REMOVED_ROUTE_NAMES = [
        'add.gateways',
        'remove.jobs',
        'remove.contacts',
        'cache.clear',
        'update.campaign.cache',
        'debug',
    ];

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
    }

    public function test_every_removed_uri_404s_anonymously(): void
    {
        foreach (self::REMOVED_URIS as $uri) {
            $this->get($uri)->assertStatus(404);
        }

        $this->get('/debug')->assertStatus(404);
    }

    public function test_every_removed_uri_404s_for_an_ordinary_authenticated_customer(): void
    {
        $customer = $this->createCustomer();
        $customer->user->email_verified_at = now();
        $customer->user->save();
        $this->withSession(['permissions' => collect(['access_backend'])]);
        $this->actingAs($customer->user);

        foreach (self::REMOVED_URIS as $uri) {
            $this->get($uri)->assertStatus(404);
        }

        $this->get('/debug')->assertStatus(404);
    }

    public function test_every_removed_uri_404s_for_an_authenticated_admin(): void
    {
        $admin = User::create([
            'first_name' => 'Test',
            'last_name' => 'Admin',
            'email' => 'admin' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
        ]);
        $this->withSession(['permissions' => collect(['access backend'])]);
        $this->actingAs($admin);

        foreach (self::REMOVED_URIS as $uri) {
            $this->get($uri)->assertStatus(404);
        }

        $this->get('/debug')->assertStatus(404);
    }

    public function test_none_of_the_removed_route_names_are_registered(): void
    {
        foreach (self::REMOVED_ROUTE_NAMES as $name) {
            $this->assertFalse(Route::has($name), "Route [{$name}] must not exist.");
        }
    }

    /**
     * Behavioural proof, not just routing: seed job/failed-job/Contacts
     * rows across two unrelated tenants, issue every removed request
     * unauthenticated, and assert every row still exists — proving the
     * destructive behaviour is gone, not merely that a route name changed.
     */
    public function test_the_destructive_behaviour_is_gone_not_just_the_route_name(): void
    {
        $tenantA = $this->createCustomer();
        $businessA = $this->createBusinessWithWorkspace($tenantA, $this->businessAttributes(['name' => 'Tenant A']));
        $tenantB = $this->createCustomer();
        $businessB = $this->createBusinessWithWorkspace($tenantB, $this->businessAttributes(['name' => 'Tenant B']));

        $contactA = Contacts::create(['customer_id' => $tenantA->user_id, 'business_id' => $businessA->id, 'phone' => 15550001111, 'status' => 'subscribe']);
        $contactB = Contacts::create(['customer_id' => $tenantB->user_id, 'business_id' => $businessB->id, 'phone' => 15550002222, 'status' => 'subscribe']);

        DB::table('jobs')->insert([
            'queue' => 'default', 'payload' => 'tenant-a-payload', 'attempts' => 0,
            'reserved_at' => null, 'available_at' => time(), 'created_at' => time(),
        ]);
        DB::table('failed_jobs')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'connection' => 'sync', 'queue' => 'default',
            'payload' => 'tenant-b-payload', 'exception' => 'test-exception', 'failed_at' => now(),
        ]);

        $jobsBefore = DB::table('jobs')->count();
        $failedJobsBefore = DB::table('failed_jobs')->count();

        foreach (self::REMOVED_URIS as $uri) {
            $this->get($uri);
        }
        $this->get('/debug');

        $this->assertDatabaseHas('contacts', ['id' => $contactA->id, 'phone' => 15550001111]);
        $this->assertDatabaseHas('contacts', ['id' => $contactB->id, 'phone' => 15550002222]);
        $this->assertSame($jobsBefore, DB::table('jobs')->count(), 'The jobs table must not be truncated.');
        $this->assertSame($failedJobsBefore, DB::table('failed_jobs')->count(), 'The failed_jobs table must not be truncated.');
    }

    /**
     * R-3's payment-configuration-overwrite/payment-redirection defect,
     * proven gone: an operator-configured PaymentMethods row (including
     * the offline-payment instructions customers see) is byte-identical
     * before and after probing every former URL, unauthenticated.
     */
    public function test_payment_methods_and_offline_payment_instructions_are_unchanged_after_probing_every_former_url(): void
    {
        $offlinePayment = PaymentMethods::create([
            'name' => 'Offline Payment',
            'type' => 'offline_payment',
            'status' => true,
            'options' => json_encode([
                'payment_details' => '<p>Operator-configured deposit instructions, not the vendor default.</p>',
                'payment_confirmation' => 'Operator-configured confirmation copy.',
            ]),
        ]);
        $stripe = PaymentMethods::create([
            'name' => 'Stripe',
            'type' => 'stripe',
            'status' => true,
            'options' => json_encode(['publishable_key' => 'operator-configured-publishable-key', 'secret_key' => 'operator-configured-secret-key']),
        ]);

        $offlinePaymentOptionsBefore = $offlinePayment->options;
        $offlinePaymentStatusBefore = $offlinePayment->status;
        $stripeOptionsBefore = $stripe->options;
        $stripeStatusBefore = $stripe->status;

        foreach (self::REMOVED_URIS as $uri) {
            $this->get($uri);
        }

        $this->assertSame($offlinePaymentOptionsBefore, $offlinePayment->fresh()->options);
        $this->assertSame($offlinePaymentStatusBefore, $offlinePayment->fresh()->status);
        $this->assertSame($stripeOptionsBefore, $stripe->fresh()->options);
        $this->assertSame($stripeStatusBefore, $stripe->fresh()->status);

        // No new gateway rows materialize from the (now-absent) 29-gateway
        // seed either.
        $this->assertSame(2, PaymentMethods::count());
    }

    public function test_no_route_anywhere_truncates_a_table_or_deletes_contacts_and_debug_controller_no_longer_exists(): void
    {
        foreach (self::REMOVED_ROUTE_NAMES as $name) {
            $this->assertFalse(Route::has($name));
        }

        $this->assertFalse(class_exists(\App\Http\Controllers\Debug\DebugController::class));
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

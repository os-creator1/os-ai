<?php

namespace Tests\Feature\Documents;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Http\Middleware\VerifyCsrfToken;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Entitlement\PlatformFeatureRegistry;
use App\Library\Navigation\CustomerMenuBuilder;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessRepository;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Implementation Contract 17 §6.4, §12.A — Sub-slice A ships SCHEMA AND INERT
 * IDENTITY ONLY. PlatformFeature::PaymentsContracts stays `Planned` and is
 * flipped to `Available` only as Sub-slice G's final step, once A-F are merged
 * and verified end to end.
 *
 * These are the guard that a schema-only sub-slice did not quietly make the
 * feature reachable. They are expected to be UPDATED — not deleted — by the
 * later sub-slices as each surface legitimately appears (the Calendar and
 * Catalog sub-slice-A tests set the same precedent).
 */
class DocumentsRemainInertTest extends TestCase
{
    use RefreshDatabase;

    private const FEATURE_KEY = 'payments_contracts';

    // ------------------------------------------------------------------
    // Entitlement identity
    // ------------------------------------------------------------------

    public function test_payments_contracts_is_a_known_business_scoped_platform_feature(): void
    {
        $this->assertSame('payments_contracts', PlatformFeature::PaymentsContracts->value);
        $this->assertTrue(PlatformFeatureRegistry::isKnown(self::FEATURE_KEY));
        $this->assertTrue(PlatformFeatureRegistry::isBusinessScoped(self::FEATURE_KEY));
        $this->assertFalse(PlatformFeatureRegistry::isWorkspaceScoped(self::FEATURE_KEY));
    }

    public function test_payments_contracts_stays_planned_not_available(): void
    {
        $this->assertFalse(
            PlatformFeatureRegistry::isAvailable(self::FEATURE_KEY),
            'Sub-slice A must not flip PlatformFeature::PaymentsContracts to Available.'
        );
    }

    public function test_payments_contracts_is_packaged_for_core_growth_and_agency(): void
    {
        $catalogIds = DB::table('workspace_plan_catalog')->pluck('id', 'tier');

        foreach (['core', 'growth', 'agency'] as $tier) {
            $catalogId = $catalogIds[$tier] ?? null;
            $this->assertNotNull($catalogId, "Expected a workspace_plan_catalog row for tier [{$tier}].");

            $this->assertDatabaseHas('workspace_plan_features', [
                'workspace_plan_catalog_id' => $catalogId,
                'feature_key' => self::FEATURE_KEY,
            ]);
        }
    }

    public function test_payments_contracts_has_an_unmetered_usage_classification_row(): void
    {
        $this->assertDatabaseHas('platform_feature_usage_classifications', [
            'feature_key' => self::FEATURE_KEY,
            'is_metered' => false,
            'active_rate_id' => null,
        ]);

        $this->assertSame(
            count(PlatformFeature::cases()),
            DB::table('platform_feature_usage_classifications')->count(),
            'Every PlatformFeature case must have exactly one classification row.'
        );
    }

    public function test_the_plan_packaging_migration_is_idempotent(): void
    {
        $migration = $this->loadMigration('2026_09_25_100012_seed_payments_contracts_plan_packaging.php');

        $before = DB::table('workspace_plan_features')->where('feature_key', self::FEATURE_KEY)->count();
        $this->assertSame(3, $before);

        $migration->up();

        $this->assertSame($before, DB::table('workspace_plan_features')->where('feature_key', self::FEATURE_KEY)->count());
    }

    /**
     * §6.4 — a Planned feature already fails closed at the decision layer.
     * That is what makes it safe for later sub-slices to build authenticated
     * routes behind the ordinary entitlement gate while the flip is pending.
     */
    public function test_entitlement_manager_refuses_payments_contracts_for_a_fully_entitled_business(): void
    {
        $owner = User::create([
            'first_name' => 'Owner',
            'last_name' => 'User',
            'email' => 'owner' . uniqid() . '@example.test',
            'status' => true,
            'is_admin' => false,
            'is_customer' => true,
            'active_portal' => 'customer',
        ]);
        $admin = User::create([
            'first_name' => 'Admin',
            'last_name' => 'User',
            'email' => 'admin' . uniqid() . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
        ]);

        $customer = Customer::create(['user_id' => $owner->id]);
        $workspace = Workspace::create([
            'name' => 'Test Workspace',
            'owner_user_id' => $owner->id,
            'is_active' => true,
        ]);
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, [
            'name' => 'Test Business',
            'industry' => 'photo_booth_service',
            'country_code' => 'US',
            'timezone' => 'America/New_York',
            'currency_code' => 'USD',
        ]);

        // Packaged in core already, so the plan is not what refuses this —
        // availability is.
        app(EntitlementManager::class)->assignFirstPlan(
            $workspace->fresh(),
            WorkspacePlanTier::Core,
            $admin->id,
            'Fixture assignment.',
            true,
            0
        );

        $decision = app(EntitlementManager::class)->decide(
            $workspace->fresh(),
            $business->fresh(),
            self::FEATURE_KEY,
            $admin->id
        );

        $this->assertFalse($decision->allowed);
        $this->assertSame('platform_feature_unavailable', $decision->reason);
    }

    // ------------------------------------------------------------------
    // Capability identity
    // ------------------------------------------------------------------

    public function test_payments_contracts_capability_is_declared_in_customer_permissions(): void
    {
        $permissions = config('customer-permissions');

        $this->assertArrayHasKey('payments_contracts', $permissions);
        $this->assertSame('Payments & Contracts', $permissions['payments_contracts']['category']);
        $this->assertTrue($permissions['payments_contracts']['default']);
        $this->assertSame('payments_contracts', $permissions['payments_contracts']['display_name']);
    }

    public function test_only_the_single_module_capability_exists_not_a_crud_matrix(): void
    {
        $keys = array_filter(
            array_keys(config('customer-permissions')),
            fn (string $key) => str_contains($key, 'payments') || str_contains($key, 'contracts') || str_contains($key, 'proposal') || str_contains($key, 'invoice')
        );

        $this->assertSame(['payments_contracts'], array_values($keys));
    }

    public function test_the_capability_is_registered_as_a_gate_by_the_generic_loop(): void
    {
        $this->assertTrue(Gate::has('payments_contracts'));
    }

    // ------------------------------------------------------------------
    // The capability backfill (persisted per-customer permission lists)
    // ------------------------------------------------------------------

    private function loadMigration(string $file): object
    {
        $method = new ReflectionMethod(app('migrator'), 'resolvePath');
        $method->setAccessible(true);

        return $method->invoke(app('migrator'), database_path('migrations/' . $file));
    }

    private function customerWithPermissions(?string $raw): int
    {
        $user = User::create([
            'first_name' => 'Cust',
            'last_name' => 'Omer',
            'email' => 'cust' . uniqid() . '@example.test',
            'status' => true,
            'is_admin' => false,
            'is_customer' => true,
            'active_portal' => 'customer',
        ]);

        $customer = Customer::create(['user_id' => $user->id]);

        DB::table('customers')->where('id', $customer->id)->update(['permissions' => $raw]);

        return (int) $customer->id;
    }

    public function test_the_backfill_appends_the_capability_to_existing_customers(): void
    {
        $missing = $this->customerWithPermissions(json_encode(['view_contact', 'website']));
        $present = $this->customerWithPermissions(json_encode(['view_contact', 'payments_contracts']));
        $null = $this->customerWithPermissions(null);
        $empty = $this->customerWithPermissions('[]');
        $notAList = $this->customerWithPermissions('{"a":1}');
        $garbage = $this->customerWithPermissions('not json');

        $this->loadMigration('2026_09_25_100013_backfill_payments_contracts_customer_permission.php')->up();

        $perm = fn (int $id) => DB::table('customers')->where('id', $id)->value('permissions');

        $this->assertSame(['view_contact', 'website', 'payments_contracts'], json_decode($perm($missing), true));
        $this->assertSame(['view_contact', 'payments_contracts'], json_decode($perm($present), true), 'An already-granted list must be left untouched.');
        $this->assertNull($perm($null), 'A null list must never be invented into one.');
        $this->assertSame('[]', $perm($empty));
        $this->assertSame('{"a":1}', $perm($notAList));
        $this->assertSame('not json', $perm($garbage));
    }

    public function test_the_backfill_is_idempotent(): void
    {
        $id = $this->customerWithPermissions(json_encode(['view_contact']));
        $migration = $this->loadMigration('2026_09_25_100013_backfill_payments_contracts_customer_permission.php');

        $migration->up();
        $migration->up();

        $this->assertSame(
            ['view_contact', 'payments_contracts'],
            json_decode(DB::table('customers')->where('id', $id)->value('permissions'), true)
        );
    }

    public function test_the_backfill_also_updates_the_operator_editable_default_list(): void
    {
        DB::table('app_config')->updateOrInsert(
            ['setting' => 'customer_permissions'],
            ['value' => json_encode(['view_contact'])]
        );

        $this->loadMigration('2026_09_25_100013_backfill_payments_contracts_customer_permission.php')->up();

        $this->assertSame(
            ['view_contact', 'payments_contracts'],
            json_decode(DB::table('app_config')->where('setting', 'customer_permissions')->value('value'), true)
        );
    }

    public function test_a_backfilled_customer_passes_the_capability_gate_but_it_opens_nothing_yet(): void
    {
        $id = $this->customerWithPermissions(json_encode(['view_contact']));
        $this->loadMigration('2026_09_25_100013_backfill_payments_contracts_customer_permission.php')->up();

        $user = Customer::find($id)->user;
        $this->assertTrue(Gate::forUser($user)->allows('payments_contracts'));

        // ...but the capability is only ONE link in the §6.1 chain; the
        // feature it fronts is still Planned, so nothing is reachable.
        $this->assertFalse(PlatformFeatureRegistry::isAvailable(self::FEATURE_KEY));
    }

    // ------------------------------------------------------------------
    // Nothing customer- or public-reachable exists yet
    // ------------------------------------------------------------------

    /**
     * §12.A — no controllers, no routes. A schema sub-slice that accidentally
     * registered a reachable surface would be a real defect, so the route
     * table is asserted directly. Covers BOTH the authenticated surface and
     * the public link/webhook surface Sub-slices B-E will add.
     */
    public function test_only_authenticated_authoring_routes_are_registered(): void
    {
        $needles = [
            'document', 'proposal', 'contract', 'payments-contracts', 'payments_contracts',
            'business-payments', 'business_payments', 'stripe-connect', 'stripe_connect',
        ];

        $offending = [];

        foreach (Route::getRoutes() as $route) {
            $uri = strtolower($route->uri());
            $name = strtolower((string) $route->getName());

            foreach ($needles as $needle) {
                if ((str_contains($uri, $needle) || str_contains($name, $needle))
                    && ! str_contains($name, 'businesses.documents.')
                    // Sub-slice D's owner-only Stripe Connect onboarding.
                    && ! str_contains($name, 'businesses.payments.connect.')
                    // Sub-slice C's secure link plus Sub-slice E's payment
                    // start; the next assertion pins their exact shape.
                    && ! str_starts_with($name, 'public.documents.')
                    // Sub-slice E's lane-B Connect webhook.
                    && $name !== 'public.business-payments.webhook') {
                    $offending[] = ($name !== '' ? $name : $uri) . " [{$needle}]";
                }
            }
        }

        $this->assertSame([], array_values(array_unique($offending)), 'Only Sub-slice B authoring routes may exist.');
    }

    public function test_the_public_document_surface_is_exactly_the_link_and_no_webhook_exists(): void
    {
        // Sub-slice C added the secure link; Sub-slice E added §6.3.2's
        // payment-start POST and the lane-B Connect webhook. That is the
        // COMPLETE public surface — the set is pinned so a later sub-slice
        // cannot add one without deliberately amending this list.
        $publicDocumentUris = [];
        $webhookUris = [];

        foreach (Route::getRoutes() as $route) {
            $uri = strtolower($route->uri());

            if (str_starts_with($uri, 'documents/')) {
                $publicDocumentUris[] = $uri;
            }

            if (str_contains($uri, 'stripe/webhook/')) {
                $webhookUris[] = $uri;
            }
        }

        sort($publicDocumentUris);
        sort($webhookUris);

        $this->assertSame(
            ['documents/{uid}/{token}', 'documents/{uid}/{token}/pay', 'documents/{uid}/{token}/sign'],
            array_values(array_unique($publicDocumentUris)),
            'Only the view, pay and sign routes may be publicly reachable.'
        );

        // Lane B's webhook is its OWN path, and lane D's is untouched.
        $this->assertContains('stripe/webhook/business-payments', $webhookUris);
        $this->assertContains('stripe/webhook/usage-billing', $webhookUris,
            'Lane D\'s webhook must still exist, unmodified.');

        $except = (new ReflectionClass(VerifyCsrfToken::class))->getDefaultProperties()['except'] ?? [];

        // Sub-slice E: exempt, because Stripe signs the raw body instead and
        // that signature is verified before anything is inserted (§8.2).
        $this->assertContains('stripe/webhook/business-payments', $except);
    }

    /**
     * The domain managers, gateway, finalizer, jobs and commands the later
     * sub-slices own must not exist yet. Each of these assertions is expected
     * to be flipped by the sub-slice that legitimately creates the class.
     */
    public function test_no_manager_gateway_job_or_command_exists_yet(): void
    {
        foreach ([
            'App\\Library\\Timeline\\Sources\\DocumentActivitySource',           // Sub-slice G
            // Sub-slice F deliberately did NOT introduce a parallel refund
            // manager: refunds extend PaymentManager and the shared finalizer
            // pattern instead of inventing a second money flow (§7.4).
            'App\\Library\\Payments\\RefundManager',
        ] as $class) {
            $this->assertFalse(class_exists($class), "[{$class}] belongs to a later sub-slice and must not exist yet.");
        }

        // Sub-slices C and D legitimately create these; asserted positively
        // so the boundary above stays a real inventory rather than a stale
        // list.
        foreach ([
            'App\\Events\\DocumentSent',
            'App\\Events\\DocumentSigned',
            'App\\Library\\Documents\\PublicDocumentGuard',
            'App\\Library\\Documents\\DocumentContentHasher',
            'App\\Library\\Payments\\StripeConnectManager',
            'App\\Library\\Payments\\StripeApiConnectGateway',
            // Sub-slice E.
            'App\\Library\\Payments\\PaymentManager',
            'App\\Library\\Payments\\PaymentFinalizer',
            'App\\Jobs\\BusinessPayments\\ProcessBusinessPaymentEvent',
            'App\\Events\\DocumentPaymentSucceeded',
            'App\\Events\\DocumentFullyPaid',
            // Sub-slice F.
            'App\\Events\\DocumentExpired',
            'App\\Events\\DocumentRefunded',
            'App\\Library\\Payments\\RefundFinalizer',
            'App\\Console\\Commands\\ExpireDueDocuments',
            'App\\Console\\Commands\\DispatchDueDocumentReminders',
            'App\\Console\\Commands\\ReconcileStaleDocumentPayments',
        ] as $class) {
            $this->assertTrue(class_exists($class), "[{$class}] is Sub-slice C's, D's or E's own.");
        }

        $this->assertTrue(
            interface_exists('App\\Library\\Payments\\StripeConnectGateway'),
            "Sub-slice D's lane-B provider boundary is an interface, so the SDK stays swappable and testable."
        );
    }

    /**
     * Sub-slice F added exactly three `documents:*` commands and no more. The
     * assertion stayed rather than being deleted, so it keeps working as an
     * inventory: a fourth scheduled documents command would fail here.
     */
    public function test_only_the_three_contracted_documents_commands_are_scheduled(): void
    {
        $schedule = new Schedule();
        $kernel = app(\App\Console\Kernel::class);
        $method = new ReflectionMethod($kernel, 'schedule');
        $method->setAccessible(true);
        $method->invoke($kernel, $schedule);

        $scheduled = [];

        foreach ($schedule->events() as $event) {
            if (preg_match('/(documents:[a-z-]+)/', (string) $event->command, $matches) === 1) {
                $scheduled[] = $matches[1];
            }
        }

        sort($scheduled);

        $this->assertSame(
            ['documents:dispatch-due-reminders', 'documents:expire-due', 'documents:reconcile-stale-payments'],
            $scheduled,
        );
    }

    public function test_payments_contracts_is_not_yet_a_nav_gated_feature(): void
    {
        $this->assertNotContains(
            self::FEATURE_KEY,
            CustomerMenuBuilder::ENTITLEMENT_GATED_FEATURES,
            "'payments_contracts' joins ENTITLEMENT_GATED_FEATURES in Sub-slice G, with the nav entry it gates."
        );
    }

    public function test_documents_config_exists_but_is_disabled_by_default(): void
    {
        $this->assertFalse((bool) config('documents.enabled'));
        $this->assertSame('default', config('documents.queue'));
        $this->assertIsInt((int) config('documents.link_ttl_days'));
        $this->assertGreaterThan(0, (int) config('documents.link_ttl_days'));
        $this->assertSame([3, 1], config('documents.reminder_offsets_days'));
    }
}

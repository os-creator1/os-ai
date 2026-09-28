<?php

namespace Tests\Feature\NicheBlueprint\Admin;

use App\Library\NicheBlueprint\Adapters\BlueprintComponentAdapter;
use App\Library\NicheBlueprint\Adapters\BlueprintComponentAdapterRegistry;
use App\Library\NicheBlueprint\Adapters\InstalledComponentReference;
use App\Library\NicheBlueprint\NicheBlueprintPublisher;
use App\Models\AppConfig;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * Implementation Contract 20 §12.F/§18.F — the Platform Owner's "Template
 * Library" surface (Blueprint §30): a read-only catalog view over the same
 * niche_blueprint_* rows NicheBlueprintController authors.
 *
 * No write route exists here at all — every assertion in this file is about
 * what can be READ and by whom, never about a mutation.
 */
class BlueprintTemplateLibraryControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureRequiredAppConfigRowsExist();
    }

    /**
     * The default type is a distinct, test-only placeholder — NOT
     * 'crm_pipeline'. Contract 20 Sub-slice D registered the real
     * CrmPipelineComponentAdapter for that type, and its
     * validateDescriptor() genuinely rejects an arbitrary dummy payload;
     * these fixtures are about the read-only catalog surface, not the CRM
     * adapter's own descriptor shape.
     */
    private function registerFakeAdapter(string $type = 'test_generic_component'): void
    {
        $registry = app(BlueprintComponentAdapterRegistry::class);

        if ($registry->has($type)) {
            return;
        }

        $registry->register(new class($type) implements BlueprintComponentAdapter
        {
            public function __construct(private readonly string $type)
            {
            }

            public function componentType(): string
            {
                return $this->type;
            }

            public function validateDescriptor(array $payload): void
            {
            }

            public function install(Business $business, array $payload, ?int $actorUserId): InstalledComponentReference
            {
                return new InstalledComponentReference('crm_pipeline', 1);
            }
        });
    }

    private function rawAdminId(): int
    {
        return DB::table('users')->insertGetId([
            'uid' => (string) Str::uuid(),
            'first_name' => 'Raw',
            'last_name' => 'Admin',
            'email' => 'raw-admin' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function actingAsAdmin(array $permissions = ['access backend']): User
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

        $this->withSession(['permissions' => collect($permissions)]);
        $this->actingAs($admin);

        return $admin;
    }

    private function ensureRequiredAppConfigRowsExist(): void
    {
        $existing = AppConfig::whereIn('setting', ['license', 'customer_permissions', 'custom_script'])
            ->pluck('setting')
            ->all();

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

    public function test_routes_are_get_only(): void
    {
        foreach (['admin.template-library.index', 'admin.template-library.show', 'admin.template-library.versions.show'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertContains('GET', $route->methods());
            $this->assertNotContains('POST', $route->methods());
            $this->assertNotContains('PUT', $route->methods());
            $this->assertNotContains('PATCH', $route->methods());
            $this->assertNotContains('DELETE', $route->methods());
        }
    }

    public function test_guest_is_refused(): void
    {
        $adminId = $this->rawAdminId();
        $publisher = app(NicheBlueprintPublisher::class);
        $blueprint = $publisher->createBlueprint($adminId, 'photo_booth', 'Photo Booth');
        $draft = $publisher->createDraftVersion($adminId, $blueprint);

        $this->get(route('admin.template-library.index'))->assertUnauthorized();
        $this->get(route('admin.template-library.show', $blueprint))->assertUnauthorized();
        $this->get(route('admin.template-library.versions.show', [$blueprint, $draft]))->assertUnauthorized();
    }

    public function test_ordinary_customer_is_refused(): void
    {
        $customer = $this->createCustomer();
        $this->actingAs($customer->user);
        $this->withSession(['permissions' => collect(['access backend'])]);

        $this->get(route('admin.template-library.index'))->assertUnauthorized();
    }

    public function test_admin_can_inspect_catalog_and_a_published_version(): void
    {
        $this->registerFakeAdapter();
        $admin = $this->actingAsAdmin();
        $publisher = app(NicheBlueprintPublisher::class);

        $blueprint = $publisher->createBlueprint($admin->id, 'photo_booth', 'Photo Booth', null, 'photo_booth_service');
        $draft = $publisher->createDraftVersion($admin->id, $blueprint);
        $publisher->addDraftComponent($admin->id, $draft, 'photo_booth_default_pipeline', 'test_generic_component', 'crm', ['pipeline_key' => 'sales']);
        $published = $publisher->publishVersion($admin->id, $draft);

        $this->get(route('admin.template-library.index'))->assertOk()->assertSee('Photo Booth');

        $this->get(route('admin.template-library.show', $blueprint))
            ->assertOk()
            ->assertSee('photo_booth')
            ->assertSee('v1');

        $this->get(route('admin.template-library.versions.show', [$blueprint, $published]))
            ->assertOk()
            ->assertSee('photo_booth_default_pipeline')
            ->assertSee('crm')
            ->assertSee('Registered');
    }

    public function test_unregistered_component_type_is_shown_honestly(): void
    {
        $admin = $this->actingAsAdmin();
        $publisher = app(NicheBlueprintPublisher::class);

        $blueprint = $publisher->createBlueprint($admin->id, 'photo_booth', 'Photo Booth');
        $draft = $publisher->createDraftVersion($admin->id, $blueprint);
        $publisher->addDraftComponent($admin->id, $draft, 'k1', 'no_adapter_exists_for_this', 'crm', []);

        $this->get(route('admin.template-library.versions.show', [$blueprint, $draft]))
            ->assertOk()
            ->assertSee('no_adapter_exists_for_this')
            ->assertSee('Unregistered');
    }

    public function test_a_version_belonging_to_a_different_blueprint_is_not_reachable(): void
    {
        $admin = $this->actingAsAdmin();
        $publisher = app(NicheBlueprintPublisher::class);

        $blueprintA = $publisher->createBlueprint($admin->id, 'blueprint_a', 'Blueprint A');
        $blueprintB = $publisher->createBlueprint($admin->id, 'blueprint_b', 'Blueprint B');
        $draftB = $publisher->createDraftVersion($admin->id, $blueprintB);

        $this->get(route('admin.template-library.versions.show', [$blueprintA, $draftB]))->assertNotFound();
    }

    public function test_this_surface_writes_nothing(): void
    {
        $this->registerFakeAdapter();
        $admin = $this->actingAsAdmin();
        $publisher = app(NicheBlueprintPublisher::class);
        $blueprint = $publisher->createBlueprint($admin->id, 'photo_booth', 'Photo Booth');
        $draft = $publisher->createDraftVersion($admin->id, $blueprint);
        $publisher->addDraftComponent($admin->id, $draft, 'k1', 'test_generic_component', 'crm', []);
        $published = $publisher->publishVersion($admin->id, $draft);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get(route('admin.template-library.index'));
        $this->get(route('admin.template-library.show', $blueprint));
        $this->get(route('admin.template-library.versions.show', [$blueprint, $published]));

        $writes = array_filter(DB::getQueryLog(), function (array $entry): bool {
            return (bool) preg_match('/^\s*(insert|update|delete|replace|truncate)\b/i', $entry['query']);
        });

        DB::disableQueryLog();

        $this->assertSame([], array_values($writes), 'The Template Library surface must issue no write query.');
    }
}

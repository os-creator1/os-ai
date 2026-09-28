<?php

namespace Tests\Feature\NicheBlueprint\Admin;

use App\Enums\NicheBlueprint\NicheBlueprintVersionState;
use App\Library\NicheBlueprint\Adapters\BlueprintComponentAdapter;
use App\Library\NicheBlueprint\Adapters\BlueprintComponentAdapterRegistry;
use App\Library\NicheBlueprint\Adapters\InstalledComponentReference;
use App\Library\NicheBlueprint\NicheBlueprintPublisher;
use App\Models\AppConfig;
use App\Models\Business;
use App\Models\NicheBlueprint;
use App\Models\NicheBlueprintComponent;
use App\Models\NicheBlueprintVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * Implementation Contract 20 §6.1/§12.F/§18.F — the Platform Owner's "Niche
 * Blueprints" authoring HTTP surface, built over Sub-slice B's
 * NicheBlueprintPublisher.
 *
 * Every mutation this controller offers must delegate to the publisher and
 * refuse exactly what the publisher itself refuses — this file proves the
 * HTTP layer adds authentication/authorization and request shaping only, and
 * no second domain authority. tests/Feature/NicheBlueprint/
 * NicheBlueprintPublishBoundaryTest::test_the_publisher_is_the_only_production_writer_of_blueprint_authoring_state
 * is the structural proof that this controller (and every other production
 * class) contains no direct Blueprint-table write.
 */
class NicheBlueprintAdminControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureRequiredAppConfigRowsExist();
    }

    // ------------------------------------------------------------- fixtures

    private function registerFakeAdapter(string $type = 'crm_pipeline', ?callable $validator = null): void
    {
        $registry = app(BlueprintComponentAdapterRegistry::class);

        if ($registry->has($type)) {
            return;
        }

        $registry->register(new class($type, $validator) implements BlueprintComponentAdapter
        {
            /** @param callable(array): void|null $validator */
            public function __construct(private readonly string $type, private $validator)
            {
            }

            public function componentType(): string
            {
                return $this->type;
            }

            public function validateDescriptor(array $payload): void
            {
                if ($this->validator !== null) {
                    ($this->validator)($payload);
                }
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

    private function publisher(): NicheBlueprintPublisher
    {
        return app(NicheBlueprintPublisher::class);
    }

    /** @return array{0: NicheBlueprint, 1: NicheBlueprintVersion} */
    private function blueprintWithDraft(int $adminId, string $key = 'photo_booth'): array
    {
        $publisher = $this->publisher();
        $blueprint = $publisher->createBlueprint($adminId, $key, 'Photo Booth', null, 'photo_booth_service');
        $draft = $publisher->createDraftVersion($adminId, $blueprint);

        return [$blueprint, $draft];
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

    // ---------------------------------------------------------------- routes

    public function test_routes_use_the_expected_http_verbs(): void
    {
        $index = Route::getRoutes()->getByName('admin.niche-blueprints.index');
        $this->assertContains('GET', $index->methods());
        $this->assertNotContains('POST', $index->methods());

        $store = Route::getRoutes()->getByName('admin.niche-blueprints.store');
        $this->assertContains('POST', $store->methods());
        $this->assertNotContains('GET', $store->methods());

        $update = Route::getRoutes()->getByName('admin.niche-blueprints.update');
        $this->assertContains('PATCH', $update->methods());
        $this->assertNotContains('POST', $update->methods());
        $this->assertNotContains('DELETE', $update->methods());

        $destroyVersion = Route::getRoutes()->getByName('admin.niche-blueprints.versions.destroy');
        $this->assertContains('DELETE', $destroyVersion->methods());

        $publish = Route::getRoutes()->getByName('admin.niche-blueprints.versions.publish');
        $this->assertContains('POST', $publish->methods());
        $this->assertNotContains('GET', $publish->methods());
    }

    public function test_blueprint_and_version_routes_bind_by_uid_component_by_id(): void
    {
        [$blueprint, $draft] = $this->blueprintWithDraft($this->rawAdminId());
        $component = $this->publisher()->addDraftComponent($this->rawAdminId(), $draft, 'k', 'crm_pipeline', 'crm', []);

        $this->assertSame('uid', $blueprint->getRouteKeyName());
        $this->assertSame('uid', $draft->getRouteKeyName());
        $this->assertSame('id', (new NicheBlueprintComponent())->getRouteKeyName());
        $this->assertIsInt($component->id);
    }

    // -------------------------------------------------------------- non-admin

    public function test_guest_is_refused_on_every_route(): void
    {
        $adminId = $this->rawAdminId();
        [$blueprint, $draft] = $this->blueprintWithDraft($adminId);
        $component = $this->publisher()->addDraftComponent($adminId, $draft, 'k1', 'crm_pipeline', 'crm', []);

        $this->get(route('admin.niche-blueprints.index'))->assertUnauthorized();
        $this->get(route('admin.niche-blueprints.create'))->assertUnauthorized();
        $this->post(route('admin.niche-blueprints.store'), ['key' => 'ghost', 'display_name' => 'Ghost'])->assertUnauthorized();
        $this->get(route('admin.niche-blueprints.show', $blueprint))->assertUnauthorized();
        $this->patch(route('admin.niche-blueprints.update', $blueprint), ['display_name' => 'Renamed'])->assertUnauthorized();
        $this->post(route('admin.niche-blueprints.activate', $blueprint))->assertUnauthorized();
        $this->post(route('admin.niche-blueprints.deactivate', $blueprint))->assertUnauthorized();
        $this->post(route('admin.niche-blueprints.versions.store', $blueprint))->assertUnauthorized();
        $this->patch(route('admin.niche-blueprints.versions.update', [$blueprint, $draft]), ['notes' => 'n'])->assertUnauthorized();
        $this->post(route('admin.niche-blueprints.components.store', [$blueprint, $draft]), [
            'component_key' => 'ghost_key', 'component_type' => 'crm_pipeline', 'required_feature_key' => 'crm', 'payload_json' => '{}',
        ])->assertUnauthorized();
        $this->patch(route('admin.niche-blueprints.components.update', [$blueprint, $draft, $component]), ['position' => 2])->assertUnauthorized();
        $this->delete(route('admin.niche-blueprints.components.destroy', [$blueprint, $draft, $component]))->assertUnauthorized();
        $this->post(route('admin.niche-blueprints.versions.publish', [$blueprint, $draft]))->assertUnauthorized();
        $this->post(route('admin.niche-blueprints.versions.supersede', [$blueprint, $draft]))->assertUnauthorized();
        $this->delete(route('admin.niche-blueprints.versions.destroy', [$blueprint, $draft]))->assertUnauthorized();

        // Nothing was written.
        $this->assertSame(1, NicheBlueprint::count());
        $this->assertSame('draft', $draft->fresh()->state->value);
    }

    public function test_ordinary_customer_is_refused_on_every_route(): void
    {
        $adminId = $this->rawAdminId();
        [$blueprint, $draft] = $this->blueprintWithDraft($adminId);
        $component = $this->publisher()->addDraftComponent($adminId, $draft, 'k1', 'crm_pipeline', 'crm', []);

        $customer = $this->createCustomer();
        $this->actingAs($customer->user);
        $this->withSession(['permissions' => collect(['access backend'])]);

        $this->get(route('admin.niche-blueprints.index'))->assertUnauthorized();
        $this->post(route('admin.niche-blueprints.store'), ['key' => 'ghost', 'display_name' => 'Ghost'])->assertUnauthorized();
        $this->patch(route('admin.niche-blueprints.update', $blueprint), ['display_name' => 'Renamed'])->assertUnauthorized();
        $this->post(route('admin.niche-blueprints.versions.publish', [$blueprint, $draft]))->assertUnauthorized();
        $this->patch(route('admin.niche-blueprints.components.update', [$blueprint, $draft, $component]), ['position' => 2])->assertUnauthorized();
        $this->delete(route('admin.niche-blueprints.components.destroy', [$blueprint, $draft, $component]))->assertUnauthorized();

        $this->assertSame('draft', $draft->fresh()->state->value);
        $this->assertSame('Photo Booth', $blueprint->fresh()->display_name);
    }

    public function test_admin_without_access_backend_permission_is_refused(): void
    {
        $this->actingAsAdmin([]);

        $this->get(route('admin.niche-blueprints.index'))->assertUnauthorized();
    }

    // ------------------------------------------------------------- authoring

    public function test_admin_can_create_a_blueprint(): void
    {
        $admin = $this->actingAsAdmin();

        $response = $this->post(route('admin.niche-blueprints.store'), [
            'key' => 'photo_booth',
            'display_name' => 'Photo Booth',
            'broad_industry' => 'photo_booth_service',
        ]);

        $blueprint = NicheBlueprint::where('key', 'photo_booth')->firstOrFail();
        $response->assertRedirect(route('admin.niche-blueprints.show', $blueprint));
        $this->assertSame('Photo Booth', $blueprint->display_name);
        $this->assertTrue($blueprint->is_active);
    }

    public function test_admin_can_open_edit_and_discard_a_draft(): void
    {
        $admin = $this->actingAsAdmin();
        $blueprint = $this->publisher()->createBlueprint($admin->id, 'photo_booth', 'Photo Booth');

        $this->post(route('admin.niche-blueprints.versions.store', $blueprint), ['notes' => 'first cut'])
            ->assertRedirect(route('admin.niche-blueprints.show', $blueprint));

        $draft = $blueprint->versions()->first();
        $this->assertSame('first cut', $draft->notes);
        $this->assertSame('draft', $draft->state->value);

        $this->patch(route('admin.niche-blueprints.versions.update', [$blueprint, $draft]), ['notes' => 'revised'])
            ->assertRedirect(route('admin.niche-blueprints.show', $blueprint));
        $this->assertSame('revised', $draft->fresh()->notes);

        $this->delete(route('admin.niche-blueprints.versions.destroy', [$blueprint, $draft]))
            ->assertRedirect(route('admin.niche-blueprints.show', $blueprint));
        $this->assertNull($blueprint->versions()->first());
    }

    public function test_admin_can_add_edit_and_remove_a_draft_component(): void
    {
        $admin = $this->actingAsAdmin();
        [$blueprint, $draft] = $this->blueprintWithDraft($admin->id);

        $this->post(route('admin.niche-blueprints.components.store', [$blueprint, $draft]), [
            'component_key' => 'photo_booth_default_pipeline',
            'component_type' => 'crm_pipeline',
            'required_feature_key' => 'crm',
            'payload_json' => json_encode(['pipeline_key' => 'sales']),
            'position' => 0,
        ])->assertRedirect(route('admin.niche-blueprints.show', $blueprint));

        $component = NicheBlueprintComponent::where('blueprint_version_id', $draft->id)->firstOrFail();
        $this->assertSame('crm', $component->required_feature_key);
        $this->assertSame(['pipeline_key' => 'sales'], $component->payload);

        $this->patch(route('admin.niche-blueprints.components.update', [$blueprint, $draft, $component]), [
            'position' => 5,
            'payload_json' => json_encode(['pipeline_key' => 'sales', 'name' => 'Sales pipeline']),
        ])->assertRedirect(route('admin.niche-blueprints.show', $blueprint));

        $component->refresh();
        $this->assertSame(5, $component->position);
        $this->assertSame('Sales pipeline', $component->payload['name']);

        $this->delete(route('admin.niche-blueprints.components.destroy', [$blueprint, $draft, $component]))
            ->assertRedirect(route('admin.niche-blueprints.show', $blueprint));

        $this->assertNull(NicheBlueprintComponent::find($component->id));
    }

    public function test_publish_delegates_to_publisher_and_succeeds(): void
    {
        $this->registerFakeAdapter();
        $admin = $this->actingAsAdmin();
        [$blueprint, $draft] = $this->blueprintWithDraft($admin->id);
        $this->publisher()->addDraftComponent($admin->id, $draft, 'k1', 'crm_pipeline', 'crm', ['x' => 1]);

        $this->post(route('admin.niche-blueprints.versions.publish', [$blueprint, $draft]))
            ->assertRedirect(route('admin.niche-blueprints.show', $blueprint))
            ->assertSessionHas('flash_success');

        $fresh = $draft->fresh();
        $this->assertSame(NicheBlueprintVersionState::Published, $fresh->state);
        $this->assertSame($admin->id, $fresh->published_by_user_id);
        $this->assertNotNull($fresh->published_at);
    }

    public function test_publish_refuses_unregistered_component_type(): void
    {
        $admin = $this->actingAsAdmin();
        [$blueprint, $draft] = $this->blueprintWithDraft($admin->id);
        $this->publisher()->addDraftComponent($admin->id, $draft, 'k1', 'totally_unregistered_type', 'crm', []);

        $this->post(route('admin.niche-blueprints.versions.publish', [$blueprint, $draft]))
            ->assertRedirect(route('admin.niche-blueprints.show', $blueprint))
            ->assertSessionHas('flash_error');

        $this->assertSame('draft', $draft->fresh()->state->value);
    }

    public function test_publish_refuses_when_adapter_rejects_payload(): void
    {
        $this->registerFakeAdapter('strict_type', function (array $payload): void {
            if (! isset($payload['must_have'])) {
                throw new \InvalidArgumentException('must_have is required');
            }
        });
        $admin = $this->actingAsAdmin();
        [$blueprint, $draft] = $this->blueprintWithDraft($admin->id);
        $this->publisher()->addDraftComponent($admin->id, $draft, 'k1', 'strict_type', 'crm', ['nope' => true]);

        $this->post(route('admin.niche-blueprints.versions.publish', [$blueprint, $draft]))
            ->assertRedirect(route('admin.niche-blueprints.show', $blueprint))
            ->assertSessionHas('flash_error');

        $this->assertSame('draft', $draft->fresh()->state->value);
    }

    public function test_published_version_cannot_be_mutated_through_http(): void
    {
        $this->registerFakeAdapter();
        $admin = $this->actingAsAdmin();
        [$blueprint, $draft] = $this->blueprintWithDraft($admin->id);
        $component = $this->publisher()->addDraftComponent($admin->id, $draft, 'k1', 'crm_pipeline', 'crm', []);
        $published = $this->publisher()->publishVersion($admin->id, $draft);

        $this->patch(route('admin.niche-blueprints.versions.update', [$blueprint, $published]), ['notes' => 'sneaky'])
            ->assertSessionHas('flash_error');
        $this->assertNull($published->fresh()->notes);

        $this->post(route('admin.niche-blueprints.components.store', [$blueprint, $published]), [
            'component_key' => 'sneaked_in', 'component_type' => 'crm_pipeline', 'required_feature_key' => 'crm', 'payload_json' => '{}',
        ])->assertSessionHas('flash_error');
        $this->assertNull(NicheBlueprintComponent::where('component_key', 'sneaked_in')->first());

        $this->patch(route('admin.niche-blueprints.components.update', [$blueprint, $published, $component]), ['position' => 99])
            ->assertSessionHas('flash_error');
        $this->assertSame(0, $component->fresh()->position);

        $this->delete(route('admin.niche-blueprints.components.destroy', [$blueprint, $published, $component]))
            ->assertSessionHas('flash_error');
        $this->assertNotNull(NicheBlueprintComponent::find($component->id));

        $this->delete(route('admin.niche-blueprints.versions.destroy', [$blueprint, $published]))
            ->assertSessionHas('flash_error');
        $this->assertSame('published', $published->fresh()->state->value);
    }

    public function test_publishing_does_not_mutate_any_business(): void
    {
        $this->registerFakeAdapter();
        $business = $this->createBusinessWithWorkspace($this->createCustomer(), $this->businessAttributes());
        $admin = $this->actingAsAdmin();
        [$blueprint, $draft] = $this->blueprintWithDraft($admin->id);
        $this->publisher()->addDraftComponent($admin->id, $draft, 'k1', 'crm_pipeline', 'crm', []);

        $this->post(route('admin.niche-blueprints.versions.publish', [$blueprint, $draft]))
            ->assertSessionHas('flash_success');

        $this->assertSame(0, DB::table('business_blueprint_component_installations')->where('business_id', $business->id)->count());
        $this->assertSame(0, DB::table('crm_pipelines')->where('business_id', $business->id)->count());
    }

    public function test_second_publication_preserves_first_versions_immutability(): void
    {
        $this->registerFakeAdapter();
        $admin = $this->actingAsAdmin();
        [$blueprint, $draftV1] = $this->blueprintWithDraft($admin->id);
        $componentV1 = $this->publisher()->addDraftComponent($admin->id, $draftV1, 'stays_the_same', 'crm_pipeline', 'crm', ['a' => 1]);
        $this->post(route('admin.niche-blueprints.versions.publish', [$blueprint, $draftV1]))->assertSessionHas('flash_success');

        $v1Snapshot = $componentV1->fresh();

        $this->post(route('admin.niche-blueprints.versions.store', $blueprint), ['notes' => 'v2'])->assertSessionHas('flash_success');
        $draftV2 = $blueprint->versions()->where('state', 'draft')->firstOrFail();
        $this->publisher()->addDraftComponent($admin->id, $draftV2, 'a_new_component', 'crm_pipeline', 'crm', []);
        $this->post(route('admin.niche-blueprints.versions.publish', [$blueprint, $draftV2]))->assertSessionHas('flash_success');

        $this->assertSame('superseded', $draftV1->fresh()->state->value);
        $this->assertSame('published', $draftV2->fresh()->state->value);

        $unchanged = $componentV1->fresh();
        $this->assertSame($v1Snapshot->component_key, $unchanged->component_key);
        $this->assertSame($v1Snapshot->component_type, $unchanged->component_type);
        $this->assertSame($v1Snapshot->required_feature_key, $unchanged->required_feature_key);
        $this->assertSame($v1Snapshot->payload, $unchanged->payload);
        $this->assertSame($v1Snapshot->position, $unchanged->position);
    }

    public function test_supersede_delegates_to_publisher(): void
    {
        $this->registerFakeAdapter();
        $admin = $this->actingAsAdmin();
        [$blueprint, $draft] = $this->blueprintWithDraft($admin->id);
        $this->publisher()->addDraftComponent($admin->id, $draft, 'k1', 'crm_pipeline', 'crm', []);
        $published = $this->publisher()->publishVersion($admin->id, $draft);

        $this->post(route('admin.niche-blueprints.versions.supersede', [$blueprint, $published]))
            ->assertRedirect(route('admin.niche-blueprints.show', $blueprint))
            ->assertSessionHas('flash_success');

        $this->assertSame('superseded', $published->fresh()->state->value);
    }

    // ----------------------------------------------------------------- pages

    public function test_index_and_show_render_for_admin(): void
    {
        $admin = $this->actingAsAdmin();
        [$blueprint, $draft] = $this->blueprintWithDraft($admin->id, 'photo_booth');

        $this->get(route('admin.niche-blueprints.index'))->assertOk()->assertSee('Photo Booth');
        $this->get(route('admin.niche-blueprints.show', $blueprint))->assertOk()->assertSee('photo_booth');
    }

    public function test_no_customer_route_exists_for_niche_blueprints(): void
    {
        $customerRoutesFile = base_path('routes/customer.php');
        $contents = (string) file_get_contents($customerRoutesFile);

        $this->assertStringNotContainsString('NicheBlueprintController', $contents);
        $this->assertStringNotContainsString('BlueprintTemplateLibraryController', $contents);

        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();

            if (str_starts_with($name, 'customer.')) {
                $this->assertStringNotContainsString('niche-blueprint', strtolower($name));
                $this->assertStringNotContainsString('template-library', strtolower($name));
            }
        }
    }
}

<?php

namespace Tests\Feature\NicheBlueprint\Admin;

use App\Enums\NicheBlueprint\NicheBlueprintVersionState;
use App\Library\NicheBlueprint\Adapters\BlueprintComponentAdapter;
use App\Library\NicheBlueprint\Adapters\BlueprintComponentAdapterRegistry;
use App\Library\NicheBlueprint\Adapters\InstalledComponentReference;
use App\Library\NicheBlueprint\NicheBlueprintPublisher;
use App\Models\AppConfig;
use App\Models\Business;
use App\Models\BusinessVertical;
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

    /**
     * The default type is a distinct, test-only placeholder — NOT
     * 'crm_pipeline'. Contract 20 Sub-slice D registered the real
     * CrmPipelineComponentAdapter for that type in AppServiceProvider, and
     * its validateDescriptor() genuinely rejects an arbitrary dummy payload.
     * These generic "publish delegates to the publisher and succeeds"
     * fixtures are about the HTTP/authorization layer, not the CRM adapter's
     * own descriptor shape, so they register their own no-op adapter under a
     * type the real registry does not claim.
     */
    private function registerFakeAdapter(string $type = 'test_generic_component', ?callable $validator = null): void
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
        $this->publisher()->addDraftComponent($admin->id, $draft, 'k1', 'test_generic_component', 'crm', ['x' => 1]);

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
        $component = $this->publisher()->addDraftComponent($admin->id, $draft, 'k1', 'test_generic_component', 'crm', []);
        $published = $this->publisher()->publishVersion($admin->id, $draft);

        $this->patch(route('admin.niche-blueprints.versions.update', [$blueprint, $published]), ['notes' => 'sneaky'])
            ->assertSessionHas('flash_error');
        $this->assertNull($published->fresh()->notes);

        $this->post(route('admin.niche-blueprints.components.store', [$blueprint, $published]), [
            'component_key' => 'sneaked_in', 'component_type' => 'test_generic_component', 'required_feature_key' => 'crm', 'payload_json' => '{}',
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
        $this->publisher()->addDraftComponent($admin->id, $draft, 'k1', 'test_generic_component', 'crm', []);

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
        $componentV1 = $this->publisher()->addDraftComponent($admin->id, $draftV1, 'stays_the_same', 'test_generic_component', 'crm', ['a' => 1]);
        $this->post(route('admin.niche-blueprints.versions.publish', [$blueprint, $draftV1]))->assertSessionHas('flash_success');

        $v1Snapshot = $componentV1->fresh();

        $this->post(route('admin.niche-blueprints.versions.store', $blueprint), ['notes' => 'v2'])->assertSessionHas('flash_success');
        $draftV2 = $blueprint->versions()->where('state', 'draft')->firstOrFail();
        $this->publisher()->addDraftComponent($admin->id, $draftV2, 'a_new_component', 'test_generic_component', 'crm', []);
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
        $this->publisher()->addDraftComponent($admin->id, $draft, 'k1', 'test_generic_component', 'crm', []);
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

    // ------------------------------------------------- route-binding misses
    //
    // Review finding 1. A syntactically valid identifier that resolves to no
    // row lets ModelNotFoundException reach App\Exceptions\Handler, which
    // renders that as a literal 500 in every non-local environment
    // (config('app.env') !== 'local'), never a 404. These exercise the
    // REAL HTTP pipeline (no withoutExceptionHandling()), so a regression
    // here is caught as a 500, not merely as an uncaught exception.

    public function test_nonexistent_blueprint_uuid_returns_404_not_500(): void
    {
        $this->actingAsAdmin();

        $this->get(route('admin.niche-blueprints.show', ['blueprint' => (string) Str::uuid()]))
            ->assertNotFound();
    }

    public function test_existing_blueprint_with_nonexistent_version_uuid_returns_404_not_500(): void
    {
        $admin = $this->actingAsAdmin();
        $blueprint = $this->publisher()->createBlueprint($admin->id, 'photo_booth', 'Photo Booth');

        $this->patch(
            route('admin.niche-blueprints.versions.update', ['blueprint' => $blueprint, 'version' => (string) Str::uuid()]),
            ['notes' => 'x']
        )->assertNotFound();

        $this->post(
            route('admin.niche-blueprints.versions.publish', ['blueprint' => $blueprint, 'version' => (string) Str::uuid()])
        )->assertNotFound();
    }

    public function test_existing_version_with_nonexistent_numeric_component_returns_404_not_500(): void
    {
        $admin = $this->actingAsAdmin();
        [$blueprint, $draft] = $this->blueprintWithDraft($admin->id);

        $this->patch(
            route('admin.niche-blueprints.components.update', ['blueprint' => $blueprint, 'version' => $draft, 'component' => 999999999]),
            ['position' => 1]
        )->assertNotFound();

        $this->delete(
            route('admin.niche-blueprints.components.destroy', ['blueprint' => $blueprint, 'version' => $draft, 'component' => 999999999])
        )->assertNotFound();
    }

    /**
     * The existing belongs-to checks (assertVersionBelongsToBlueprint() /
     * assertComponentBelongsToVersion()) are a DIFFERENT failure than a
     * route-binding miss — both resolve genuinely, just mismatched — and
     * must keep working exactly as before this fix.
     */
    public function test_a_resolved_but_mismatched_version_still_404s_via_the_belongs_to_check(): void
    {
        $admin = $this->actingAsAdmin();
        $blueprintA = $this->publisher()->createBlueprint($admin->id, 'blueprint_a', 'Blueprint A');
        $blueprintB = $this->publisher()->createBlueprint($admin->id, 'blueprint_b', 'Blueprint B');
        $draftB = $this->publisher()->createDraftVersion($admin->id, $blueprintB);

        $this->patch(
            route('admin.niche-blueprints.versions.update', ['blueprint' => $blueprintA, 'version' => $draftB]),
            ['notes' => 'x']
        )->assertNotFound();
    }

    // ---------------------------------------- duplicate identity (finding 2)

    public function test_duplicate_blueprint_key_via_http_is_refused_without_a_500(): void
    {
        $admin = $this->actingAsAdmin();
        $this->publisher()->createBlueprint($admin->id, 'photo_booth', 'Photo Booth');

        $this->post(route('admin.niche-blueprints.store'), [
            'key' => 'photo_booth',
            'display_name' => 'A Second Photo Booth',
        ])
            ->assertRedirect(route('admin.niche-blueprints.create'))
            ->assertSessionHas('flash_error');

        $this->assertSame(1, NicheBlueprint::where('key', 'photo_booth')->count());
    }

    public function test_duplicate_vertical_via_http_is_refused_without_a_500(): void
    {
        $admin = $this->actingAsAdmin();
        $vertical = BusinessVertical::create([
            'key' => 'photo_booth_vertical', 'display_name' => 'Photo Booth Vertical', 'is_active' => true,
        ]);
        $this->publisher()->createBlueprint($admin->id, 'photo_booth', 'Photo Booth', $vertical->key);

        $this->post(route('admin.niche-blueprints.store'), [
            'key' => 'weddings',
            'display_name' => 'Weddings',
            'vertical_key' => $vertical->key,
        ])
            ->assertRedirect(route('admin.niche-blueprints.create'))
            ->assertSessionHas('flash_error');

        $this->assertSame(1, NicheBlueprint::where('vertical_key', $vertical->key)->count());
        $this->assertNull(NicheBlueprint::where('key', 'weddings')->first());
    }

    public function test_updating_blueprint_onto_another_blueprints_vertical_via_http_is_refused_without_a_500(): void
    {
        $admin = $this->actingAsAdmin();
        $verticalA = BusinessVertical::create(['key' => 'vertical_a', 'display_name' => 'Vertical A', 'is_active' => true]);
        $verticalB = BusinessVertical::create(['key' => 'vertical_b', 'display_name' => 'Vertical B', 'is_active' => true]);
        $blueprintA = $this->publisher()->createBlueprint($admin->id, 'blueprint_a', 'Blueprint A', $verticalA->key);
        $blueprintB = $this->publisher()->createBlueprint($admin->id, 'blueprint_b', 'Blueprint B', $verticalB->key);

        $this->patch(route('admin.niche-blueprints.update', $blueprintB), [
            'display_name' => 'Blueprint B',
            'vertical_key' => $verticalA->key,
        ])
            ->assertRedirect(route('admin.niche-blueprints.show', $blueprintB))
            ->assertSessionHas('flash_error');

        $this->assertSame($verticalA->key, $blueprintA->fresh()->vertical_key);
        $this->assertSame($verticalB->key, $blueprintB->fresh()->vertical_key, 'The refused update must leave the row unchanged.');
    }

    // ------------------------------------ inactive current vertical (finding 3)

    public function test_show_page_displays_and_retains_a_now_inactive_current_vertical(): void
    {
        $admin = $this->actingAsAdmin();
        $vertical = BusinessVertical::create([
            'key' => 'legacy_vertical', 'display_name' => 'Legacy Vertical', 'is_active' => true,
        ]);
        $blueprint = $this->publisher()->createBlueprint($admin->id, 'photo_booth', 'Photo Booth', $vertical->key);

        $vertical->update(['is_active' => false]);

        // The deactivated vertical is still rendered as a selectable, selected option.
        $html = $this->get(route('admin.niche-blueprints.show', $blueprint))->assertOk()->getContent();
        $this->assertStringContainsString('value="legacy_vertical"', $html);
        $this->assertMatchesRegularExpression('/<option value="legacy_vertical"[^>]*selected/', $html);

        // Saving an unrelated field must not detach it.
        $this->patch(route('admin.niche-blueprints.update', $blueprint), [
            'display_name' => 'Renamed Photo Booth',
            'vertical_key' => $vertical->key,
        ])
            ->assertRedirect(route('admin.niche-blueprints.show', $blueprint))
            ->assertSessionHas('flash_success');

        $fresh = $blueprint->fresh();
        $this->assertSame('Renamed Photo Booth', $fresh->display_name);
        $this->assertSame('legacy_vertical', $fresh->vertical_key);

        // A DIFFERENT Blueprint still cannot be pointed at that inactive vertical.
        $other = $this->publisher()->createBlueprint($admin->id, 'other_blueprint', 'Other Blueprint');
        $this->patch(route('admin.niche-blueprints.update', $other), [
            'display_name' => 'Other Blueprint',
            'vertical_key' => $vertical->key,
        ])->assertSessionHas('flash_error');

        $this->assertNull($other->fresh()->vertical_key);
    }

    // ---------------------------- out-of-vocabulary feature key (finding 4)

    public function test_editing_a_draft_component_preserves_an_out_of_vocabulary_feature_key(): void
    {
        $admin = $this->actingAsAdmin();
        [$blueprint, $draft] = $this->blueprintWithDraft($admin->id);

        // The draft-authoring seam itself deliberately permits an unknown
        // required_feature_key (Contract 20 §6.2/§15) — only publish refuses it.
        $component = $this->publisher()->addDraftComponent(
            $admin->id, $draft, 'k1', 'test_generic_component', 'not_a_real_feature_key', []
        );

        $html = $this->get(route('admin.niche-blueprints.show', $blueprint))->assertOk()->getContent();
        $this->assertStringContainsString('value="not_a_real_feature_key"', $html);
        $this->assertMatchesRegularExpression('/<option value="not_a_real_feature_key"[^>]*selected/', $html);

        // Editing only the payload/position must leave the feature key untouched.
        $this->patch(route('admin.niche-blueprints.components.update', [$blueprint, $draft, $component]), [
            'position' => 3,
            'payload_json' => json_encode(['x' => 1]),
        ])->assertRedirect(route('admin.niche-blueprints.show', $blueprint));

        $fresh = $component->fresh();
        $this->assertSame('not_a_real_feature_key', $fresh->required_feature_key);
        $this->assertSame(3, $fresh->position);

        // Publish's own gate (§6.2 gate 3, unknown PlatformFeature) is still
        // the one place this is ever finally rejected — never draft authoring.
        $this->post(route('admin.niche-blueprints.versions.publish', [$blueprint, $draft]))
            ->assertSessionHas('flash_error');
        $this->assertSame('draft', $draft->fresh()->state->value);
    }
}

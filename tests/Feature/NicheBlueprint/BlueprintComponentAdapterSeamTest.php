<?php

namespace Tests\Feature\NicheBlueprint;

use App\Enums\NicheBlueprint\BlueprintComponentInstallationState;
use App\Enums\NicheBlueprint\NicheBlueprintVersionState;
use App\Exceptions\NicheBlueprint\UnknownBlueprintComponentTypeException;
use App\Library\Crm\Templates\BusinessTemplateRegistry;
use App\Library\NicheBlueprint\Adapters\BlueprintComponentAdapter;
use App\Library\NicheBlueprint\Adapters\BlueprintComponentAdapterRegistry;
use App\Library\NicheBlueprint\Adapters\InstalledComponentReference;
use App\Models\Business;
use App\Models\NicheBlueprint;
use App\Models\NicheBlueprintComponent;
use App\Models\NicheBlueprintVersion;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Implementation Contract 20 §10, §12.A — the adapter seam, the enums, and the
 * scope boundary of Sub-slice A.
 *
 * Sub-slice A ships the seam EMPTY: no real adapter, no publisher, no
 * installer, no HTTP surface. Several assertions here exist specifically to
 * fail if a later edit smuggles any of that in early.
 */
class BlueprintComponentAdapterSeamTest extends TestCase
{
    private function fakeAdapter(string $componentType = 'crm_pipeline'): BlueprintComponentAdapter
    {
        return new class($componentType) implements BlueprintComponentAdapter
        {
            public function __construct(private readonly string $componentType)
            {
            }

            public function componentType(): string
            {
                return $this->componentType;
            }

            public function validateDescriptor(array $payload): void
            {
            }

            public function install(Business $business, array $payload, ?int $actorUserId): InstalledComponentReference
            {
                return new InstalledComponentReference('crm_pipeline', 1);
            }
        };
    }

    // ------------------------------------------------------------- registry

    public function test_the_registry_ships_empty(): void
    {
        $registry = new BlueprintComponentAdapterRegistry();

        $this->assertSame([], $registry->registeredComponentTypes());
        $this->assertFalse($registry->has('crm_pipeline'));
        $this->assertNull($registry->find('crm_pipeline'));
    }

    public function test_the_container_binding_is_a_singleton_and_also_ships_empty(): void
    {
        $first = app(BlueprintComponentAdapterRegistry::class);
        $second = app(BlueprintComponentAdapterRegistry::class);

        $this->assertSame($first, $second, 'The adapter registry must be a container singleton.');
        $this->assertSame([], $first->registeredComponentTypes(), 'Sub-slice A registers no adapter.');

        // Registering through one reference must be visible through the other:
        // that is the whole point of the singleton (mirrors BusinessTemplateRegistry).
        $first->register($this->fakeAdapter());
        $this->assertTrue($second->has('crm_pipeline'));
    }

    /**
     * §6.2 check 1 / §10 — an unknown component type refuses CLEANLY, with a
     * typed exception, rather than returning a null the publisher might ignore.
     */
    public function test_an_unknown_component_type_refuses_cleanly(): void
    {
        $registry = new BlueprintComponentAdapterRegistry();

        try {
            $registry->adapterFor('calendar_booking_defaults');
            $this->fail('adapterFor() must throw for an unregistered component type.');
        } catch (UnknownBlueprintComponentTypeException $e) {
            $this->assertSame('calendar_booking_defaults', $e->componentType);
            $this->assertStringContainsString('calendar_booking_defaults', $e->getMessage());
        }

        // find() is the explicit "is one registered?" question and stays nullable.
        $this->assertNull($registry->find('calendar_booking_defaults'));
    }

    public function test_a_registered_adapter_is_returned_by_type(): void
    {
        $registry = new BlueprintComponentAdapterRegistry();
        $adapter = $this->fakeAdapter();
        $registry->register($adapter);

        $this->assertSame($adapter, $registry->adapterFor('crm_pipeline'));
        $this->assertSame($adapter, $registry->find('crm_pipeline'));
        $this->assertTrue($registry->has('crm_pipeline'));
        $this->assertSame(['crm_pipeline'], $registry->registeredComponentTypes());
    }

    public function test_two_adapters_cannot_claim_the_same_component_type(): void
    {
        $registry = new BlueprintComponentAdapterRegistry();
        $registry->register($this->fakeAdapter());

        $this->expectException(InvalidArgumentException::class);
        $registry->register($this->fakeAdapter());
    }

    public function test_an_adapter_must_declare_a_storable_component_type(): void
    {
        $registry = new BlueprintComponentAdapterRegistry();

        // Empty, and longer than the column, are both refused at registration
        // rather than becoming a truncation surprise at publish time.
        $this->expectException(InvalidArgumentException::class);
        $registry->register($this->fakeAdapter(str_repeat('a', 41)));
    }

    // -------------------------------------------- installed reference

    public function test_an_installed_component_reference_validates_itself(): void
    {
        $reference = new InstalledComponentReference('crm_pipeline', 7);
        $this->assertSame('crm_pipeline', $reference->recordType);
        $this->assertSame(7, $reference->recordId);

        $this->expectException(InvalidArgumentException::class);
        new InstalledComponentReference('crm_pipeline', 0);
    }

    public function test_an_installed_component_reference_rejects_a_blank_record_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new InstalledComponentReference('   ', 7);
    }

    // ------------------------------------------------------------- enums

    public function test_version_states_are_exactly_the_three_contracted_cases(): void
    {
        $this->assertSame(
            ['draft', 'published', 'superseded'],
            array_column(NicheBlueprintVersionState::cases(), 'value')
        );

        $this->assertTrue(NicheBlueprintVersionState::Draft->isEditable());
        $this->assertFalse(NicheBlueprintVersionState::Draft->isImmutable());

        foreach ([NicheBlueprintVersionState::Published, NicheBlueprintVersionState::Superseded] as $state) {
            $this->assertFalse($state->isEditable());
            $this->assertTrue($state->isImmutable(), 'A version is immutable once it leaves draft.');
        }
    }

    public function test_installation_states_are_exactly_the_four_contracted_cases(): void
    {
        $this->assertSame(
            ['installed', 'skipped_unentitled', 'skipped_unavailable', 'failed'],
            array_column(BlueprintComponentInstallationState::cases(), 'value')
        );
    }

    /**
     * §5.4 / §8.1 — skip states are provenance, never authority. Only
     * `installed` permanently removes a component from the addable query, and
     * only `failed` is retryable by an automated run (a skip is reversed only
     * by the owner's explicit action).
     */
    public function test_only_installed_is_terminal_and_only_failed_is_auto_retryable(): void
    {
        $this->assertTrue(BlueprintComponentInstallationState::Installed->isTerminalForSurfacing());

        foreach ([
            BlueprintComponentInstallationState::SkippedUnentitled,
            BlueprintComponentInstallationState::SkippedUnavailable,
            BlueprintComponentInstallationState::Failed,
        ] as $state) {
            $this->assertFalse(
                $state->isTerminalForSurfacing(),
                'A non-installed state must never permanently suppress a component.'
            );
        }

        $this->assertTrue(BlueprintComponentInstallationState::Failed->isRetryableByAutomatedRun());

        foreach ([
            BlueprintComponentInstallationState::Installed,
            BlueprintComponentInstallationState::SkippedUnentitled,
            BlueprintComponentInstallationState::SkippedUnavailable,
        ] as $state) {
            $this->assertFalse(
                $state->isRetryableByAutomatedRun(),
                'An automated run must never reverse a skip or re-run an install.'
            );
        }
    }

    // ------------------------------------------------- model write posture

    /**
     * §5.2 — the publisher (Sub-slice B) owns the lifecycle fields. They must
     * not be reachable by casual mass assignment, or any later caller could
     * publish a version, renumber one, or forge its publication metadata.
     */
    public function test_version_lifecycle_fields_are_not_mass_assignable(): void
    {
        $version = new NicheBlueprintVersion();

        foreach (['state', 'version_number', 'published_at', 'published_by_user_id', 'draft_guard', 'published_guard'] as $field) {
            $this->assertFalse(
                $version->isFillable($field),
                $field . ' must not be mass-assignable on NicheBlueprintVersion.'
            );
        }

        $this->assertTrue($version->isFillable('blueprint_id'));
        $this->assertTrue($version->isFillable('notes'));
    }

    public function test_models_cast_their_state_columns_to_the_contracted_enums(): void
    {
        $this->assertSame(
            NicheBlueprintVersionState::class,
            (new NicheBlueprintVersion())->getCasts()['state'] ?? null
        );

        $this->assertSame(
            BlueprintComponentInstallationState::class,
            (new \App\Models\BusinessBlueprintComponentInstallation())->getCasts()['state'] ?? null
        );

        $this->assertSame('array', (new NicheBlueprintComponent())->getCasts()['payload'] ?? null);
        $this->assertSame('boolean', (new NicheBlueprint())->getCasts()['is_active'] ?? null);
    }

    // ---------------------------------------- Sub-slice A scope boundary

    /**
     * Sub-slice A builds the seam only. If any of these exist, a later
     * sub-slice has been smuggled in early and its own gates are not yet in
     * place — which is exactly the ordering failure §12 exists to prevent.
     */
    public function test_no_publisher_installer_or_http_surface_exists_yet(): void
    {
        // NicheBlueprintPublisher was on this list until Sub-slice B, which is
        // the sub-slice that introduces it; it moved to the assertion below.
        // Everything still listed belongs to C, D, E or F, and must not appear
        // before the gates that protect it do.
        foreach ([
            'App\\Library\\NicheBlueprint\\NicheBlueprintInstaller',
            'App\\Jobs\\NicheBlueprint\\InstallNicheBlueprintForBusiness',
            'App\\Http\\Controllers\\Customer\\Business\\NicheBlueprintController',
            'App\\Http\\Controllers\\Admin\\NicheBlueprintController',
            'App\\Library\\NicheBlueprint\\Adapters\\CrmPipelineComponentAdapter',
        ] as $class) {
            $this->assertFalse(class_exists($class), $class . ' belongs to a later sub-slice.');
        }

        // Sub-slice B's own deliverable: present, and the only Blueprint
        // service that may exist at this point.
        $this->assertTrue(class_exists('App\\Library\\NicheBlueprint\\NicheBlueprintPublisher'));
    }

    public function test_no_niche_blueprint_route_is_registered(): void
    {
        foreach (app('router')->getRoutes() as $route) {
            $this->assertStringNotContainsString(
                'blueprint',
                strtolower($route->uri()),
                'Sub-slice A registers no route: ' . $route->uri()
            );
        }
    }

    /**
     * §6.5 / §15 — this slice adds no customer capability key, and does not
     * touch config/customer-permissions.php.
     */
    public function test_no_blueprint_customer_permission_key_was_added(): void
    {
        $permissions = array_keys(config('customer-permissions', []));

        foreach ($permissions as $key) {
            $this->assertStringNotContainsString('blueprint', strtolower((string) $key));
            $this->assertStringNotContainsString('niche', strtolower((string) $key));
        }
    }

    /**
     * The existing CRM Business Template mechanism is reused UNMODIFIED by a
     * later sub-slice, and is untouched by this one.
     */
    public function test_the_existing_business_template_registry_is_unchanged(): void
    {
        $templates = app(BusinessTemplateRegistry::class);
        $generic = $templates->generic();

        $this->assertSame('generic', $generic->key);
        $this->assertSame(1, $generic->version);
        $this->assertCount(1, $generic->pipelines);
        $this->assertSame('sales', $generic->pipelines[0]->key);

        // The two registries are separate objects with separate purposes.
        $this->assertNotSame(
            $templates,
            app(BlueprintComponentAdapterRegistry::class)
        );
    }
}

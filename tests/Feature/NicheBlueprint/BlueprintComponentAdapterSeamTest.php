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
 * scope boundary Sub-slice A originally shipped empty.
 *
 * Sub-slices B (publisher), C (installer), D (the real CRM pipeline adapter)
 * and F (the Platform Owner admin surfaces) have since landed their own
 * authorized deliverables, asserted below as a positive control. Only
 * Sub-slice E's customer-reachable HTTP surface remains out of scope; several
 * assertions here exist specifically to fail if a later edit smuggles that in
 * early.
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

    public function test_the_container_binding_is_a_singleton_and_registers_the_real_adapters(): void
    {
        $first = app(BlueprintComponentAdapterRegistry::class);
        $second = app(BlueprintComponentAdapterRegistry::class);

        $this->assertSame($first, $second, 'The adapter registry must be a container singleton.');

        // Sub-slice A shipped this registry empty; Sub-slice D registers the
        // first real adapter (§11 rule 3). Each later Calendar/Packages/
        // Proposal/Forms adapter adds one more component type to this list
        // and nothing else in this test's assertions changes.
        $this->assertSame(['crm_pipeline', 'document_template', 'crm_tag_set', 'crm_custom_field', 'automation_workflow', 'form', 'booking_type', 'package_template', 'website_config', 'seo_strategy', 'citation_recommendations'], $first->registeredComponentTypes());

        // Registering through one reference must be visible through the other:
        // that is the whole point of the singleton (mirrors BusinessTemplateRegistry).
        $first->register($this->fakeAdapter('seam_test_fake_component'));
        $this->assertTrue($second->has('seam_test_fake_component'));
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

    // ---------------------------------------- remaining scope boundary

    /**
     * Sub-slice A built the seam empty; B, C, D and F have since landed
     * their own authorized deliverables (checked below as a positive
     * control, so this test cannot pass vacuously on a misspelled
     * namespace). Only Sub-slice E's customer HTTP surface remains absent —
     * if it exists, it has been smuggled in early and its own gates
     * (ownership-only add, §6.5) are not yet in place, which is exactly the
     * ordering failure §12 exists to prevent.
     */
    public function test_no_customer_surface_exists_yet(): void
    {
        foreach ([
            'App\\Http\\Controllers\\Customer\\Business\\NicheBlueprintController',
        ] as $class) {
            $this->assertFalse(class_exists($class), $class . ' belongs to a later sub-slice.');
        }

        // Sub-slice B's, C's, D's and F's own deliverables: present, and
        // still the only Blueprint services/surfaces that may exist at this
        // point. Neither admin controller writes a niche_blueprint_* row
        // directly — both delegate to NicheBlueprintPublisher (proven by
        // NicheBlueprintPublishBoundaryTest's structural tripwire, which
        // scans the whole app/ tree including these two files) — and no
        // customer-reachable surface exists yet, which is what keeps the
        // feature unreachable by anyone but a platform administrator.
        $this->assertTrue(class_exists('App\\Library\\NicheBlueprint\\NicheBlueprintPublisher'));
        $this->assertTrue(class_exists('App\\Library\\NicheBlueprint\\NicheBlueprintInstaller'));
        $this->assertTrue(class_exists('App\\Jobs\\NicheBlueprint\\InstallNicheBlueprintForBusiness'));
        $this->assertTrue(class_exists('App\\Library\\NicheBlueprint\\Adapters\\CrmPipelineComponentAdapter'));
        $this->assertTrue(class_exists('App\\Http\\Controllers\\Admin\\NicheBlueprintController'));
        $this->assertTrue(class_exists('App\\Http\\Controllers\\Admin\\BlueprintTemplateLibraryController'));
    }

    /**
     * Contract 20 §12.F — Sub-slice F registers the Platform Owner's two
     * admin-only surfaces, so "no blueprint route at all" (Sub-slice A's
     * original assertion) is no longer the right boundary. What must still
     * be true, and is asserted here, is that no CUSTOMER-facing route names
     * a Blueprint — that remains Sub-slice E's, not yet built.
     */
    public function test_no_customer_facing_niche_blueprint_route_is_registered(): void
    {
        foreach (app('router')->getRoutes() as $route) {
            $name = (string) $route->getName();

            if (! str_starts_with($name, 'customer.') && ! str_starts_with($name, 'user.')) {
                continue;
            }

            $this->assertStringNotContainsString(
                'blueprint',
                strtolower($route->uri()),
                'Sub-slice E is not yet built: ' . $route->uri()
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

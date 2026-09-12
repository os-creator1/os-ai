<?php

namespace Tests\Feature\Workspace;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Entitlement\EntitlementManager;
use App\Models\AppConfig;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\Entitlement\Concerns\PinsBoundedBusinessSlotCatalog;
use Tests\Feature\Workspace\Concerns\CreatesWorkspaceTestData;
use Tests\TestCase;

/**
 * Customer Experience Slice 1A, correction round 2 (RFC-004 §33.2): the admin
 * Workspace page must never OFFER an additional-Business-slot value the
 * corrected catalog cannot hold. Every chooser is rendered from
 * EntitlementManager::additionalBusinessSlotOptionsByTier(), so Core and
 * Growth (1 Business, no additional slot) and Agency (unlimited Businesses,
 * no additional-slot concept) present 0 alone, while a catalog row that does
 * offer capacity still presents the full range. The server-side guards stay
 * in place; this is about what the form shows in the first place.
 */
class AdminAdditionalBusinessSlotOptionsTest extends TestCase
{
    use CreatesBusinessTestData;
    use CreatesWorkspaceTestData;
    use PinsBoundedBusinessSlotCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureRequiredAppConfigRowsExist();
    }

    public function test_the_assign_form_offers_no_additional_business_slot_on_the_corrected_catalog(): void
    {
        $this->actingAsAdmin();
        $workspace = $this->createWorkspace($this->createOwner());

        $selects = $this->slotSelects($workspace);

        $this->assertCount(1, $selects, 'An unassigned Workspace shows only the assign form.');
        $this->assertSame(['0'], $selects[0], 'No tier can take an additional Business slot, so only 0 is offered.');
    }

    #[DataProvider('boundedTiers')]
    public function test_the_change_and_update_forms_offer_no_additional_business_slot_on_the_corrected_catalog(WorkspacePlanTier $tier): void
    {
        $this->actingAsAdmin();
        $workspace = $this->assignedWorkspace($tier);

        $selects = $this->slotSelects($workspace);

        $this->assertCount(2, $selects, 'An assigned Workspace shows the change form and the update form.');
        $this->assertSame(['', '0'], $selects[0], 'Change plan may preserve the current value or set 0 — nothing else.');
        $this->assertSame(['0'], $selects[1], 'Update additional Business slots offers 0 alone.');
    }

    public function test_agency_keeps_its_unlimited_semantics_and_offers_no_additional_slot(): void
    {
        $this->actingAsAdmin();
        $workspace = $this->assignedWorkspace(WorkspacePlanTier::Agency);

        $response = $this->get(route('admin.workspaces.show', $workspace))->assertOk();
        $selects = $this->slotSelects($workspace);

        $this->assertSame(['', '0'], $selects[0]);
        $this->assertSame(['0'], $selects[1], 'Agency has unlimited Businesses, so an additional slot never applies.');
        $response->assertSee('unlimited', false);
    }

    public function test_a_catalog_row_with_capacity_still_offers_its_full_range(): void
    {
        $this->pinBoundedBusinessSlotCatalog();
        $this->actingAsAdmin();
        $workspace = $this->assignedWorkspace(WorkspacePlanTier::Core);

        $selects = $this->slotSelects($workspace);

        $this->assertSame(['0', '1', '2'], $selects[1], 'A 3-included / 5-max row offers this Workspace two additional slots.');
        // Change plan picks its destination tier in the same request, so it
        // stays at what EVERY tier allows — Agency never takes an additional
        // slot. The allocation is then made in the update form above, where
        // the tier is known.
        $this->assertSame(['', '0'], $selects[0]);
    }

    public function test_the_options_come_from_the_catalog_rows_themselves(): void
    {
        $this->assertSame(
            ['core' => [0], 'growth' => [0], 'agency' => [0]],
            app(EntitlementManager::class)->additionalBusinessSlotOptionsByTier(),
        );

        $this->pinBoundedBusinessSlotCatalog(['core']);

        $this->assertSame(
            ['core' => [0, 1, 2], 'growth' => [0], 'agency' => [0]],
            app(EntitlementManager::class)->additionalBusinessSlotOptionsByTier(),
        );
    }

    public static function boundedTiers(): array
    {
        return ['core' => [WorkspacePlanTier::Core], 'growth' => [WorkspacePlanTier::Growth]];
    }

    /**
     * Every additional_business_slots chooser the page renders, in document
     * order, as its list of option values.
     *
     * @return array<int, array<int, string>>
     */
    private function slotSelects(Workspace $workspace): array
    {
        $html = $this->get(route('admin.workspaces.show', $workspace))->assertOk()->getContent();

        preg_match_all('/<select name="additional_business_slots".*?<\/select>/s', $html, $matches);

        return array_map(static function (string $select): array {
            preg_match_all('/<option value="([^"]*)"/', $select, $options);

            return $options[1];
        }, $matches[0]);
    }

    private function assignedWorkspace(WorkspacePlanTier $tier): Workspace
    {
        $workspace = $this->createWorkspace($this->createOwner());

        app(EntitlementManager::class)->assignFirstPlan($workspace, $tier, $this->platformAdmin()->id, 'Fixture assignment.', true, 0);

        return $workspace->fresh();
    }

    private function createOwner(): User
    {
        return User::create([
            'first_name' => 'Owner', 'last_name' => 'User',
            'email' => 'owner' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);
    }

    private function platformAdmin(): User
    {
        return User::create([
            'first_name' => 'Platform', 'last_name' => 'Admin',
            'email' => 'platform' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);
    }

    /** The admin shell renders only with these rows present. */
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
            AppConfig::create(collect((new AppConfig())->defaultSettings())->firstWhere('setting', 'customer_permissions'));
        }
    }

    private function actingAsAdmin(): User
    {
        $admin = $this->platformAdmin();

        $this->withSession(['permissions' => collect(['access backend', 'view workspace', 'view workspace plans', 'manage workspace plans'])]);
        $this->actingAs($admin);

        return $admin;
    }
}

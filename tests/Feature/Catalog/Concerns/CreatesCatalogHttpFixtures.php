<?php

namespace Tests\Feature\Catalog\Concerns;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspaceEntitlementOverrideState;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\LocationAccessScope;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Catalog\CatalogItemManager;
use App\Library\Entitlement\EntitlementManager;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\CatalogItem;
use App\Models\Customer;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceMembershipLocationRepository;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;

/**
 * Implementation Contract 16 §6, §12.E — fixtures for the catalog's real-HTTP
 * tests.
 *
 * Everything is built through the same seams production reads: catalog items
 * through `CatalogItemManager`, memberships through the real membership
 * repositories, entitlement changes through `EntitlementManager`. Nothing
 * here can accidentally prove a rule the production guards would not agree
 * with.
 */
trait CreatesCatalogHttpFixtures
{
    use CreatesCustomerContextFixtures;

    /**
     * An owner, Workspace and Business on the given tier. The owner is also the
     * Business's customer, so every owner branch of the Location guard holds.
     *
     * @return array{0: Customer, 1: Business, 2: Workspace}
     */
    protected function catalogTenant(WorkspacePlanTier $tier = WorkspacePlanTier::Core, string $name = 'Harbor Lane Studios'): array
    {
        return $this->tenant($tier, $name, $name . ' Workspace');
    }

    protected function catalogLocation(Business $business, string $name): BusinessLocation
    {
        return BusinessLocation::create([
            'business_id' => $business->id,
            'name' => $name,
            'service_mode' => 'storefront',
            'country_code' => 'US',
        ]);
    }

    /**
     * Created through the REAL manager, so a fixture item is exactly what
     * production would have written.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function catalogItem(Business $business, string $name = 'Wedding Package', array $attributes = []): CatalogItem
    {
        return app(CatalogItemManager::class)->create($business, array_merge([
            'type' => 'package',
            'name' => $name,
            'description' => null,
            'price_minor' => 100000,
            'currency_code' => 'USD',
        ], $attributes));
    }

    /**
     * An active Staff member of the Workspace who reaches EVERY Business and
     * EVERY Location.
     */
    protected function staffWithFullReach(Workspace $workspace): Customer
    {
        $customer = $this->createCustomer();

        $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Staff,
            'business_access_scope' => WorkspaceBusinessAccessScope::All,
            'location_access_scope' => LocationAccessScope::All,
        ]);

        return $customer;
    }

    /**
     * An active Staff member who reaches the Business but holds an explicit
     * grant for ONE Location only — the boundary that proves a sibling
     * Location is genuinely out of reach rather than incidentally
     * unreachable.
     */
    protected function staffGrantedOnly(Workspace $workspace, BusinessLocation $location): Customer
    {
        $customer = $this->createCustomer();

        $membership = $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Staff,
            'business_access_scope' => WorkspaceBusinessAccessScope::All,
            'location_access_scope' => LocationAccessScope::Selected,
        ]);

        app(WorkspaceMembershipLocationRepository::class)->assign($membership, $location);

        return $customer;
    }

    /** A customer with no membership anywhere: the "ungranted" actor. */
    protected function outsider(): Customer
    {
        return $this->createCustomer();
    }

    /**
     * The catalog capability is the ONLY thing removed: every other permission
     * a customer ordinarily holds stays. This is what "tenancy without
     * capability" means.
     */
    protected function authenticateWithoutCatalogCapability(Customer $customer): void
    {
        $this->authenticateAs(
            $customer,
            array_values(array_diff($this->allCustomerPermissions(), ['packages_products']))
        );
    }

    /**
     * Explicitly deny the catalog feature to a Workspace through the real
     * entitlement authority — "capability and tenancy present, but unentitled".
     */
    protected function denyCatalogEntitlement(Workspace $workspace): void
    {
        app(EntitlementManager::class)->createOrChangeOverride(
            $workspace,
            PlatformFeature::PackagesProducts,
            WorkspaceEntitlementOverrideState::Deny,
            $this->platformAdminId(),
            'Catalog HTTP test: deny the feature.'
        );
    }

    protected function catalogRoute(string $name, Workspace $workspace, Business $business, array $parameters = []): string
    {
        return route('customer.workspaces.businesses.catalog.' . $name, [$workspace->uid, $business->uid, ...$parameters]);
    }

    /**
     * EVERY customer-reachable catalog route, in one place, so the
     * authorization matrix and the pre-flip proof cannot silently miss one.
     * A route added to routes/customer.php without an entry here is caught by
     * CatalogRouteInventoryTest.
     *
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}> label => [method, url, payload]
     */
    protected function catalogEveryRoute(Workspace $workspace, Business $business, BusinessLocation $location, CatalogItem $item): array
    {
        $w = $workspace;
        $b = $business;

        return [
            'index' => ['GET', $this->catalogRoute('index', $w, $b), []],
            'create' => ['GET', $this->catalogRoute('create', $w, $b), []],
            'store' => ['POST', $this->catalogRoute('store', $w, $b), [
                'type' => 'package', 'name' => 'Probe', 'price' => '10.00', 'currency_code' => 'USD',
            ]],
            'reorder' => ['POST', $this->catalogRoute('reorder', $w, $b), ['order' => [$item->uid]]],
            'edit' => ['GET', $this->catalogRoute('edit', $w, $b, [$item->uid]), []],
            'update' => ['POST', $this->catalogRoute('update', $w, $b, [$item->uid]), [
                'type' => 'package', 'name' => 'Probe', 'price' => '10.00', 'currency_code' => 'USD',
            ]],
            'archive' => ['POST', $this->catalogRoute('archive', $w, $b, [$item->uid]), []],
            'reactivate' => ['POST', $this->catalogRoute('reactivate', $w, $b, [$item->uid]), []],
            'locations.index' => ['GET', $this->catalogRoute('locations.index', $w, $b), []],
            'locations.show' => ['GET', $this->catalogRoute('locations.show', $w, $b, [$location->uid]), []],
            'locations.enabled' => ['POST', $this->catalogRoute('locations.enabled', $w, $b, [$location->uid, $item->uid]), ['is_enabled' => 0]],
            'locations.price' => ['POST', $this->catalogRoute('locations.price', $w, $b, [$location->uid, $item->uid]), ['price' => '10.00']],
        ];
    }
}

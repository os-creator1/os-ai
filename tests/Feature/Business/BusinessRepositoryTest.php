<?php

namespace Tests\Feature\Business;

use App\Enums\Business\BusinessIndustry;
use App\Enums\Business\BusinessStatus;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Eloquent\EloquentBusinessRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use ReflectionMethod;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

class BusinessRepositoryTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    private function createWorkspaceOwnedBy(User $owner, array $overrides = []): Workspace
    {
        return Workspace::create(array_merge([
            'name' => 'Test Workspace',
            'owner_user_id' => $owner->id,
            'is_active' => true,
        ], $overrides));
    }

    public function test_first_business_becomes_primary(): void
    {
        $customer = $this->createCustomer();
        $repository = app(BusinessRepository::class);

        $business = $repository->createForCustomer($customer, $this->businessAttributes());

        $this->assertTrue($business->is_primary);
        $this->assertSame(BusinessStatus::Draft, $business->status);
    }

    public function test_second_business_is_not_primary_by_default(): void
    {
        $customer = $this->createCustomer();
        $repository = app(BusinessRepository::class);

        $repository->createForCustomer($customer, $this->businessAttributes());
        $second = $repository->createForCustomer($customer, $this->businessAttributes(['name' => 'Second Business']));

        $this->assertFalse($second->is_primary);
    }

    public function test_setting_a_business_primary_unsets_its_sibling(): void
    {
        $customer = $this->createCustomer();
        $repository = app(BusinessRepository::class);

        $first = $repository->createForCustomer($customer, $this->businessAttributes());
        $second = $repository->createForCustomer($customer, $this->businessAttributes(['name' => 'Second Business']));

        $repository->setPrimary($second);

        $this->assertFalse($first->fresh()->is_primary);
        $this->assertTrue($second->fresh()->is_primary);
    }

    public function test_find_primary_by_customer_returns_the_primary_business(): void
    {
        $customer = $this->createCustomer();
        $repository = app(BusinessRepository::class);

        $repository->createForCustomer($customer, $this->businessAttributes());

        $primary = $repository->findPrimaryByCustomer($customer->user_id);

        $this->assertNotNull($primary);
        $this->assertTrue($primary->is_primary);
    }

    public function test_find_owned_by_customer_rejects_other_customers(): void
    {
        $owner = $this->createCustomer();
        $stranger = $this->createCustomer();
        $repository = app(BusinessRepository::class);

        $business = $repository->createForCustomer($owner, $this->businessAttributes());

        $this->assertNull($repository->findOwnedByCustomer($business->id, $stranger->user_id));
        $this->assertNotNull($repository->findOwnedByCustomer($business->id, $owner->user_id));
    }

    public function test_update_ignores_protected_fields(): void
    {
        $customer = $this->createCustomer();
        $repository = app(BusinessRepository::class);

        $business = $repository->createForCustomer($customer, $this->businessAttributes());
        $otherCustomer = $this->createCustomer();

        $repository->update($business, [
            'name' => 'Renamed Booth Co',
            'customer_id' => $otherCustomer->user_id,
            'is_primary' => false,
            'status' => BusinessStatus::Active->value,
            'canonical_domain' => 'attacker.test',
            'activated_at' => now(),
        ]);

        $business->refresh();

        $this->assertSame('Renamed Booth Co', $business->name);
        $this->assertSame($customer->user_id, $business->customer_id);
        $this->assertTrue($business->is_primary);
        $this->assertSame(BusinessStatus::Draft, $business->status);
        $this->assertNull($business->canonical_domain);
        $this->assertNull($business->activated_at);
    }

    public function test_update_status_sets_activated_at_when_becoming_active(): void
    {
        $customer = $this->createCustomer();
        $repository = app(BusinessRepository::class);

        $business = $repository->createForCustomer($customer, $this->businessAttributes());
        $this->assertNull($business->activated_at);

        $repository->updateStatus($business, BusinessStatus::Active);

        $this->assertSame(BusinessStatus::Active, $business->status);
        $this->assertNotNull($business->activated_at);
    }

    public function test_find_for_update_returns_the_correct_business(): void
    {
        $customer = $this->createCustomer();
        $repository = app(BusinessRepository::class);

        $business = $repository->createForCustomer($customer, $this->businessAttributes());
        $other = $repository->createForCustomer($customer, $this->businessAttributes(['name' => 'Second Business']));

        $found = $repository->findForUpdate($business->id);

        $this->assertNotNull($found);
        $this->assertSame($business->id, $found->id);
        $this->assertNotSame($other->id, $found->id);
    }

    public function test_find_for_update_returns_null_for_unknown_id(): void
    {
        $repository = app(BusinessRepository::class);

        $this->assertNull($repository->findForUpdate(999999));
    }

    public function test_uid_is_automatically_generated_and_unique(): void
    {
        $customer = $this->createCustomer();
        $repository = app(BusinessRepository::class);

        $first = $repository->createForCustomer($customer, $this->businessAttributes());
        $second = $repository->createForCustomer($customer, $this->businessAttributes(['name' => 'Second Business']));

        $this->assertNotNull($first->uid);
        $this->assertNotNull($second->uid);
        $this->assertNotSame($first->uid, $second->uid);
    }

    /**
     * paginateForAdmin() is the one deliberately cross-tenant method on this
     * contract (RFC-001 §19 admin index — Milestone 6); every other method
     * above is tenant-scoped by design.
     */
    public function test_paginate_for_admin_returns_businesses_across_tenants(): void
    {
        $repository = app(BusinessRepository::class);
        $customerA = $this->createCustomer();
        $customerB = $this->createCustomer();
        $repository->createForCustomer($customerA, $this->businessAttributes(['name' => 'Booth Co A']));
        $repository->createForCustomer($customerB, $this->businessAttributes(['name' => 'Booth Co B']));

        $page = $repository->paginateForAdmin([], 25);

        $this->assertSame(2, $page->total());
    }

    public function test_paginate_for_admin_filters_by_search(): void
    {
        $repository = app(BusinessRepository::class);
        $customer = $this->createCustomer();
        $repository->createForCustomer($customer, $this->businessAttributes(['name' => 'Snap Booth Co']));
        $repository->createForCustomer($customer, $this->businessAttributes(['name' => 'Totally Different']));

        $page = $repository->paginateForAdmin(['search' => 'Snap'], 25);

        $this->assertSame(1, $page->total());
        $this->assertSame('Snap Booth Co', $page->first()->name);
    }

    public function test_paginate_for_admin_filters_by_status(): void
    {
        $repository = app(BusinessRepository::class);
        $customer = $this->createCustomer();
        $active = $repository->createForCustomer($customer, $this->businessAttributes(['name' => 'Active Co']));
        $repository->updateStatus($active, BusinessStatus::Active);
        $repository->createForCustomer($customer, $this->businessAttributes(['name' => 'Draft Co']));

        $page = $repository->paginateForAdmin(['status' => BusinessStatus::Active->value], 25);

        $this->assertSame(1, $page->total());
        $this->assertSame('Active Co', $page->first()->name);
    }

    public function test_paginate_for_admin_filters_by_industry(): void
    {
        $repository = app(BusinessRepository::class);
        $customer = $this->createCustomer();
        $repository->createForCustomer($customer, $this->businessAttributes([
            'name' => 'Photographer Co',
            'industry' => BusinessIndustry::Photographer->value,
        ]));
        $repository->createForCustomer($customer, $this->businessAttributes([
            'name' => 'Booth Co',
            'industry' => BusinessIndustry::PhotoBoothService->value,
        ]));

        $page = $repository->paginateForAdmin(['industry' => BusinessIndustry::Photographer->value], 25);

        $this->assertSame(1, $page->total());
        $this->assertSame('Photographer Co', $page->first()->name);
    }

    public function test_paginate_for_admin_ignores_unknown_filter_keys(): void
    {
        $repository = app(BusinessRepository::class);
        $customer = $this->createCustomer();
        $repository->createForCustomer($customer, $this->businessAttributes());

        // 'customer_id' is not an allowed filter — passing it must not
        // narrow (or otherwise change) the query.
        $page = $repository->paginateForAdmin(['customer_id' => $customer->user_id + 999], 25);

        $this->assertSame(1, $page->total());
    }

    public function test_paginate_for_admin_caps_per_page_to_a_safe_maximum(): void
    {
        $repository = app(BusinessRepository::class);
        $customer = $this->createCustomer();
        $repository->createForCustomer($customer, $this->businessAttributes());

        $page = $repository->paginateForAdmin([], 9999);

        $this->assertLessThanOrEqual(100, $page->perPage());
    }

    public function test_paginate_for_admin_orders_deterministically_newest_first(): void
    {
        $repository = app(BusinessRepository::class);
        $customer = $this->createCustomer();
        $first = $repository->createForCustomer($customer, $this->businessAttributes(['name' => 'First Co']));
        $second = $repository->createForCustomer($customer, $this->businessAttributes(['name' => 'Second Co']));

        $page = $repository->paginateForAdmin([], 25);

        $this->assertSame([$second->id, $first->id], $page->pluck('id')->all());
    }

    // 1. BusinessRepository declares createForCustomerInWorkspace().
    public function test_business_repository_contract_declares_create_for_customer_in_workspace(): void
    {
        $this->assertTrue(
            (new ReflectionClass(BusinessRepository::class))->hasMethod('createForCustomerInWorkspace')
        );
    }

    // 2. The Workspace parameter is required, non-nullable, typed as Workspace, and has no default.
    public function test_workspace_parameter_is_required_non_nullable_and_typed(): void
    {
        $method = new ReflectionMethod(EloquentBusinessRepository::class, 'createForCustomerInWorkspace');
        $workspaceParam = collect($method->getParameters())->firstWhere('name', 'workspace');

        $this->assertNotNull($workspaceParam);
        $this->assertFalse($workspaceParam->isOptional());
        $this->assertFalse($workspaceParam->allowsNull());
        $this->assertSame(Workspace::class, $workspaceParam->getType()?->getName());
    }

    // 3. EloquentBusinessRepository implements the method.
    public function test_eloquent_business_repository_implements_create_for_customer_in_workspace(): void
    {
        $repository = app(BusinessRepository::class);

        $this->assertInstanceOf(EloquentBusinessRepository::class, $repository);
        $this->assertTrue(method_exists($repository, 'createForCustomerInWorkspace'));
    }

    // 4 & 5. customer_id/workspace_id are set from the supplied Customer/Workspace.
    public function test_created_business_stores_the_supplied_customer_and_workspace(): void
    {
        $customer = $this->createCustomer();
        $workspace = $this->createWorkspaceOwnedBy($customer->user);
        $repository = app(BusinessRepository::class);

        $business = $repository->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes());

        $this->assertSame($customer->user_id, $business->customer_id);
        $this->assertSame($workspace->id, $business->workspace_id);
    }

    // 6. Conflicting customer_id/workspace_id attributes cannot override the explicit arguments.
    public function test_attributes_cannot_override_customer_id_or_workspace_id(): void
    {
        $customer = $this->createCustomer();
        $workspace = $this->createWorkspaceOwnedBy($customer->user);
        $stranger = $this->createCustomer();
        $otherWorkspace = $this->createWorkspaceOwnedBy($stranger->user);
        $repository = app(BusinessRepository::class);

        $business = $repository->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes([
            'customer_id' => $stranger->user_id,
            'workspace_id' => $otherWorkspace->id,
        ]));

        $this->assertSame($customer->user_id, $business->customer_id);
        $this->assertSame($workspace->id, $business->workspace_id);
    }

    // 7. A Workspace owned by a different User from the Business Customer is accepted.
    public function test_workspace_owned_by_a_different_user_than_the_customer_is_accepted(): void
    {
        $customer = $this->createCustomer();
        $workspaceOwner = $this->createCustomer();
        $workspace = $this->createWorkspaceOwnedBy($workspaceOwner->user);
        $repository = app(BusinessRepository::class);

        $business = $repository->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes());

        $this->assertSame($customer->user_id, $business->customer_id);
        $this->assertSame($workspace->id, $business->workspace_id);
        $this->assertNotSame($workspace->owner_user_id, $business->customer_id);
    }

    // 8. An inactive explicitly supplied Workspace is accepted without authorization/lifecycle checks.
    public function test_inactive_workspace_is_accepted(): void
    {
        $customer = $this->createCustomer();
        $workspace = $this->createWorkspaceOwnedBy($customer->user, ['is_active' => false]);
        $repository = app(BusinessRepository::class);

        $business = $repository->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes());

        $this->assertSame($workspace->id, $business->workspace_id);
    }

    // 9. First-Business primary/default behavior remains identical to createForCustomer().
    public function test_first_business_becomes_primary_in_workspace_variant(): void
    {
        $customer = $this->createCustomer();
        $workspace = $this->createWorkspaceOwnedBy($customer->user);
        $repository = app(BusinessRepository::class);

        $business = $repository->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes());

        $this->assertTrue($business->is_primary);
        $this->assertSame(BusinessStatus::Draft, $business->status);
    }

    // 10. A subsequent Business for the same Customer receives the existing non-primary behavior.
    public function test_second_business_in_workspace_variant_is_not_primary_by_default(): void
    {
        $customer = $this->createCustomer();
        $workspace = $this->createWorkspaceOwnedBy($customer->user);
        $repository = app(BusinessRepository::class);

        $repository->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes());
        $second = $repository->createForCustomerInWorkspace(
            $customer,
            $workspace,
            $this->businessAttributes(['name' => 'Second Business'])
        );

        $this->assertFalse($second->is_primary);
    }

    // 11. Existing createForCustomer() still exists and still creates workspace_id = null under the
    // current M1A schema.
    public function test_create_for_customer_still_exists_and_still_produces_a_null_workspace_id(): void
    {
        $customer = $this->createCustomer();
        $repository = app(BusinessRepository::class);

        $this->assertTrue(method_exists($repository, 'createForCustomer'));

        $business = $repository->createForCustomer($customer, $this->businessAttributes());

        $this->assertNull(DB::table('businesses')->where('id', $business->id)->value('workspace_id'));
    }

    // 12. No Workspace is inferred or created by the new method.
    public function test_create_for_customer_in_workspace_creates_no_new_workspace(): void
    {
        $customer = $this->createCustomer();
        $workspace = $this->createWorkspaceOwnedBy($customer->user);
        $repository = app(BusinessRepository::class);

        $workspaceCountBefore = DB::table('workspaces')->count();

        $repository->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes());

        $this->assertSame($workspaceCountBefore, DB::table('workspaces')->count());
    }
}

<?php

namespace Tests\Feature\Business;

use App\Enums\Business\BusinessIndustry;
use App\Enums\Business\BusinessStatus;
use App\Models\Business;
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

        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        $this->assertTrue($business->is_primary);
        $this->assertSame(BusinessStatus::Draft, $business->status);
    }

    public function test_second_business_is_not_primary_by_default(): void
    {
        $customer = $this->createCustomer();
        $repository = app(BusinessRepository::class);

        $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $second = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => 'Second Business']));

        $this->assertFalse($second->is_primary);
    }

    public function test_setting_a_business_primary_unsets_its_sibling(): void
    {
        $customer = $this->createCustomer();
        $repository = app(BusinessRepository::class);

        $first = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $second = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => 'Second Business']));

        $repository->setPrimary($second);

        $this->assertFalse($first->fresh()->is_primary);
        $this->assertTrue($second->fresh()->is_primary);
    }

    public function test_find_primary_by_customer_returns_the_primary_business(): void
    {
        $customer = $this->createCustomer();
        $repository = app(BusinessRepository::class);

        $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        $primary = $repository->findPrimaryByCustomer($customer->user_id);

        $this->assertNotNull($primary);
        $this->assertTrue($primary->is_primary);
    }

    public function test_find_owned_by_customer_rejects_other_customers(): void
    {
        $owner = $this->createCustomer();
        $stranger = $this->createCustomer();
        $repository = app(BusinessRepository::class);

        $business = $this->createBusinessWithWorkspace($owner, $this->businessAttributes());

        $this->assertNull($repository->findOwnedByCustomer($business->id, $stranger->user_id));
        $this->assertNotNull($repository->findOwnedByCustomer($business->id, $owner->user_id));
    }

    public function test_update_ignores_protected_fields(): void
    {
        $customer = $this->createCustomer();
        $repository = app(BusinessRepository::class);

        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
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

        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $this->assertNull($business->activated_at);

        $repository->updateStatus($business, BusinessStatus::Active);

        $this->assertSame(BusinessStatus::Active, $business->status);
        $this->assertNotNull($business->activated_at);
    }

    public function test_find_for_update_returns_the_correct_business(): void
    {
        $customer = $this->createCustomer();
        $repository = app(BusinessRepository::class);

        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $other = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => 'Second Business']));

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

        $first = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $second = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => 'Second Business']));

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
        $this->createBusinessWithWorkspace($customerA, $this->businessAttributes(['name' => 'Booth Co A']));
        $this->createBusinessWithWorkspace($customerB, $this->businessAttributes(['name' => 'Booth Co B']));

        $page = $repository->paginateForAdmin([], 25);

        $this->assertSame(2, $page->total());
    }

    public function test_paginate_for_admin_filters_by_search(): void
    {
        $repository = app(BusinessRepository::class);
        $customer = $this->createCustomer();
        $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => 'Snap Booth Co']));
        $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => 'Totally Different']));

        $page = $repository->paginateForAdmin(['search' => 'Snap'], 25);

        $this->assertSame(1, $page->total());
        $this->assertSame('Snap Booth Co', $page->first()->name);
    }

    public function test_paginate_for_admin_filters_by_status(): void
    {
        $repository = app(BusinessRepository::class);
        $customer = $this->createCustomer();
        $active = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => 'Active Co']));
        $repository->updateStatus($active, BusinessStatus::Active);
        $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => 'Draft Co']));

        $page = $repository->paginateForAdmin(['status' => BusinessStatus::Active->value], 25);

        $this->assertSame(1, $page->total());
        $this->assertSame('Active Co', $page->first()->name);
    }

    public function test_paginate_for_admin_filters_by_industry(): void
    {
        $repository = app(BusinessRepository::class);
        $customer = $this->createCustomer();
        $this->createBusinessWithWorkspace($customer, $this->businessAttributes([
            'name' => 'Photographer Co',
            'industry' => BusinessIndustry::Photographer->value,
        ]));
        $this->createBusinessWithWorkspace($customer, $this->businessAttributes([
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
        $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        // 'customer_id' is not an allowed filter — passing it must not
        // narrow (or otherwise change) the query.
        $page = $repository->paginateForAdmin(['customer_id' => $customer->user_id + 999], 25);

        $this->assertSame(1, $page->total());
    }

    public function test_paginate_for_admin_caps_per_page_to_a_safe_maximum(): void
    {
        $repository = app(BusinessRepository::class);
        $customer = $this->createCustomer();
        $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        $page = $repository->paginateForAdmin([], 9999);

        $this->assertLessThanOrEqual(100, $page->perPage());
    }

    public function test_paginate_for_admin_orders_deterministically_newest_first(): void
    {
        $repository = app(BusinessRepository::class);
        $customer = $this->createCustomer();
        $first = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => 'First Co']));
        $second = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => 'Second Co']));

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

    // 11 (updated for Slice 3B). createForCustomer() has been removed entirely — it can no
    // longer be used to produce a workspace_id = null Business (RFC-003 §10.6 step 2, §12.4).
    public function test_create_for_customer_no_longer_exists(): void
    {
        $this->assertFalse(
            (new ReflectionClass(BusinessRepository::class))->hasMethod('createForCustomer')
        );
        $this->assertFalse(
            (new ReflectionClass(EloquentBusinessRepository::class))->hasMethod('createForCustomer')
        );
        $this->assertFalse(method_exists(app(BusinessRepository::class), 'createForCustomer'));
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

    // 7. findFirstByCustomer returns the lowest-ID Business for that customer.
    public function test_find_first_by_customer_returns_the_lowest_id_business(): void
    {
        $customer = $this->createCustomer();
        $repository = app(BusinessRepository::class);
        $first = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => 'First']));
        $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => 'Second']));

        $found = $repository->findFirstByCustomer($customer->user_id);

        $this->assertNotNull($found);
        $this->assertSame($first->id, $found->id);
    }

    // 8. It excludes another customer's Businesses.
    public function test_find_first_by_customer_excludes_other_customers(): void
    {
        $customer = $this->createCustomer();
        $stranger = $this->createCustomer();
        $repository = app(BusinessRepository::class);
        $this->createBusinessWithWorkspace($stranger, $this->businessAttributes());

        $this->assertNull($repository->findFirstByCustomer($customer->user_id));
    }

    // 9. It returns null when none exist.
    public function test_find_first_by_customer_returns_null_when_none_exist(): void
    {
        $repository = app(BusinessRepository::class);

        $this->assertNull($repository->findFirstByCustomer(999999));
    }

    // 10. primaryBusinessesForCustomer returns all primary rows in ascending ID order.
    public function test_primary_businesses_for_customer_returns_all_primary_rows_in_ascending_order(): void
    {
        $customer = $this->createCustomer();
        $repository = app(BusinessRepository::class);
        $first = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => 'First']));
        $second = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => 'Second']));

        // The schema places no unique constraint on (customer_id, is_primary);
        // force the second row primary too, outside the normal single-primary
        // discipline, to prove the repository surfaces both rather than one.
        DB::table('businesses')->where('id', $second->id)->update(['is_primary' => true]);

        $result = $repository->primaryBusinessesForCustomer($customer->user_id);

        $this->assertSame([$first->id, $second->id], $result->pluck('id')->all());
    }

    // 11. It does not hide multiple primary Businesses.
    public function test_primary_businesses_for_customer_does_not_hide_multiple_primary_businesses(): void
    {
        $customer = $this->createCustomer();
        $repository = app(BusinessRepository::class);
        $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => 'First']));
        $second = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => 'Second']));
        DB::table('businesses')->where('id', $second->id)->update(['is_primary' => true]);

        $result = $repository->primaryBusinessesForCustomer($customer->user_id);

        $this->assertCount(2, $result);
    }

    // 12. It excludes non-primary and other-customer rows.
    public function test_primary_businesses_for_customer_excludes_non_primary_and_other_customer_rows(): void
    {
        $customer = $this->createCustomer();
        $stranger = $this->createCustomer();
        $repository = app(BusinessRepository::class);
        $primary = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => 'Primary']));
        $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => 'Non-Primary']));
        $this->createBusinessWithWorkspace($stranger, $this->businessAttributes(['name' => 'Stranger']));

        $result = $repository->primaryBusinessesForCustomer($customer->user_id);

        $this->assertSame([$primary->id], $result->pluck('id')->all());
    }

    // 13. workspaceIdsForCustomer excludes null workspace_id values. createForCustomer()
    // is removed (Slice 3B), so the null-workspace_id row is constructed directly —
    // this is not an ordinary fixture, it deliberately needs the legacy-shape null
    // value this method's exclusion logic is being proven against.
    public function test_workspace_ids_for_customer_excludes_null_workspace_id_values(): void
    {
        $customer = $this->createCustomer();
        $workspace = $this->createWorkspaceOwnedBy($customer->user);
        $repository = app(BusinessRepository::class);
        $nullWorkspaceBusiness = new Business($this->businessAttributes(['name' => 'Null WS']));
        $nullWorkspaceBusiness->customer_id = $customer->user_id;
        $nullWorkspaceBusiness->status = BusinessStatus::Draft;
        $nullWorkspaceBusiness->save();
        $repository->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes(['name' => 'Has WS']));

        $ids = $repository->workspaceIdsForCustomer($customer->user_id);

        $this->assertCount(1, $ids);
        $this->assertTrue($ids->contains($workspace->id));
    }

    // 14. It returns distinct integer IDs in deterministic first-seen order.
    public function test_workspace_ids_for_customer_returns_distinct_ids_in_first_seen_order(): void
    {
        $customer = $this->createCustomer();
        $workspaceA = $this->createWorkspaceOwnedBy($customer->user, ['name' => 'WS A']);
        $workspaceB = $this->createWorkspaceOwnedBy($customer->user, ['name' => 'WS B']);
        $repository = app(BusinessRepository::class);
        $repository->createForCustomerInWorkspace($customer, $workspaceA, $this->businessAttributes(['name' => 'One']));
        $repository->createForCustomerInWorkspace($customer, $workspaceB, $this->businessAttributes(['name' => 'Two']));
        // Same Workspace as the first Business, seen again — must not duplicate.
        $repository->createForCustomerInWorkspace($customer, $workspaceA, $this->businessAttributes(['name' => 'Three']));

        $ids = $repository->workspaceIdsForCustomer($customer->user_id);

        $this->assertSame([$workspaceA->id, $workspaceB->id], $ids->values()->all());
    }

    // 15. It does not filter differently-owned or inactive Workspaces.
    public function test_workspace_ids_for_customer_does_not_filter_by_ownership_or_activity(): void
    {
        $customer = $this->createCustomer();
        $otherOwner = $this->createCustomer();
        $inactiveWorkspace = $this->createWorkspaceOwnedBy($otherOwner->user, ['is_active' => false]);
        $repository = app(BusinessRepository::class);
        $repository->createForCustomerInWorkspace($customer, $inactiveWorkspace, $this->businessAttributes());

        $ids = $repository->workspaceIdsForCustomer($customer->user_id);

        $this->assertTrue($ids->contains($inactiveWorkspace->id));
    }
}

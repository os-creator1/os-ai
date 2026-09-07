<?php

namespace Tests\Feature\Security;

use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Business\LegacyBusinessResolver;
use App\Repositories\Contracts\BusinessRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Analytics\Concerns\CreatesAnalyticsFixtures;
use Tests\TestCase;

/**
 * B5 Business Analytics — contract §2, §12, §21 "Tenancy chain",
 * "Workspace membership", "Foreign campaign exclusion" (route shape),
 * "Cache isolation" (key shape) and the bare-entry chooser.
 *
 * Every mismatch answers 404 — never 403 — so no route can be used to
 * probe existence. Auth::id() is only the capability/actor argument.
 */
class AnalyticsSecurityTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAnalyticsFixtures;

    // -----------------------------------------------------------------
    // Tenancy chain
    // -----------------------------------------------------------------

    public function test_direct_owner_can_open_business_analytics(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $this->authenticateAsCustomer($customer);

        $this->overview($workspace, $business)->assertOk()->assertSee($business->name)->assertSee('America/New_York');
        $this->campaignsPage($workspace, $business)->assertOk();
        $this->series($workspace, $business)->assertOk()->assertJsonStructure(['range', 'contact_growth', 'message_volume']);
    }

    public function test_unknown_workspace_uid_is_404(): void
    {
        [$customer, $business] = $this->tenant();
        $this->authenticateAsCustomer($customer);

        $this->get(route('customer.workspaces.businesses.analytics.overview', ['no-such-workspace', $business->uid]))->assertNotFound();
    }

    public function test_unknown_business_uid_is_404(): void
    {
        [$customer, , $workspace] = $this->tenant();
        $this->authenticateAsCustomer($customer);

        $this->get(route('customer.workspaces.businesses.analytics.overview', [$workspace->uid, 'no-such-business']))->assertNotFound();
    }

    public function test_business_from_another_workspace_is_404_not_403(): void
    {
        [$customer, , $workspace] = $this->tenant();
        [, $foreignBusiness] = $this->tenant();
        $this->authenticateAsCustomer($customer);

        foreach (['overview', 'campaigns'] as $route) {
            $this->get(route('customer.workspaces.businesses.analytics.' . $route, [$workspace->uid, $foreignBusiness->uid]))->assertNotFound();
        }

        $this->getJson(route('customer.workspaces.businesses.analytics.series', [$workspace->uid, $foreignBusiness->uid]))->assertNotFound();
    }

    public function test_foreign_workspace_and_business_pair_is_404_for_an_outsider(): void
    {
        [, $business, $workspace] = $this->tenant();
        [$outsider] = $this->tenant();
        $this->authenticateAsCustomer($outsider);

        $this->overview($workspace, $business)->assertNotFound();
        $this->campaignsPage($workspace, $business)->assertNotFound();
        $this->series($workspace, $business)->assertNotFound();
    }

    public function test_tenancy_is_resolved_before_range_validation(): void
    {
        // A foreign Business with an invalid range must still be a plain
        // 404, never a validation answer that reveals it exists.
        [, $business, $workspace] = $this->tenant();
        [$outsider] = $this->tenant();
        $this->authenticateAsCustomer($outsider);

        $this->overview($workspace, $business, ['range' => 'custom', 'start' => 'bad', 'end' => 'bad'])->assertNotFound();
        $this->series($workspace, $business, ['range' => 'nope'])->assertNotFound();
    }

    public function test_missing_view_reports_capability_is_denied(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $this->authenticateAsCustomer($customer, []);

        // The customer portal answers a Gate denial with 401 (its existing
        // convention); the point is that nothing renders.
        $this->overview($workspace, $business)->assertStatus(401)->assertDontSee('Provider-accepted rate');
    }

    public function test_legacy_resolvers_are_never_invoked(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $this->authenticateAsCustomer($customer);

        $resolver = \Mockery::mock(LegacyBusinessResolver::class);
        $resolver->shouldNotReceive('resolveForCustomer');
        $this->app->instance(LegacyBusinessResolver::class, $resolver);

        $repository = \Mockery::mock(app(BusinessRepository::class))->makePartial();
        $repository->shouldNotReceive('findPrimaryByCustomer');
        $this->app->instance(BusinessRepository::class, $repository);

        $this->overview($workspace, $business)->assertOk();
        $this->series($workspace, $business)->assertOk();
    }

    // -----------------------------------------------------------------
    // Workspace membership
    // -----------------------------------------------------------------

    public function test_inactive_workspace_is_404_even_for_the_owner(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        DB::table('workspaces')->where('id', $workspace->id)->update(['is_active' => false]);
        $this->authenticateAsCustomer($customer);

        $this->overview($workspace, $business)->assertNotFound();
    }

    public function test_workspace_owner_reaches_a_business_they_do_not_own(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $owner = $this->createCustomer()->user;
        DB::table('workspaces')->where('id', $workspace->id)->update(['owner_user_id' => $owner->id]);
        $this->authenticateAsUser($owner);

        $this->overview($workspace, $business)->assertOk();
    }

    public function test_admin_member_can_open_business_analytics(): void
    {
        [, $business, $workspace] = $this->tenant();
        $admin = $this->createCustomer()->user;
        $this->member($workspace, $admin, WorkspaceMembershipRole::Admin);
        $this->authenticateAsUser($admin);

        $this->overview($workspace, $business)->assertOk();
    }

    public function test_staff_with_all_scope_is_allowed(): void
    {
        [, $business, $workspace] = $this->tenant();
        $staff = $this->createCustomer()->user;
        $this->member($workspace, $staff, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);
        $this->authenticateAsUser($staff);

        $this->overview($workspace, $business)->assertOk();
    }

    public function test_selected_staff_with_assignment_is_allowed(): void
    {
        [, $business, $workspace] = $this->tenant();
        $staff = $this->createCustomer()->user;
        $membership = $this->member($workspace, $staff, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected);
        $this->assign($membership, $business);
        $this->authenticateAsUser($staff);

        $this->overview($workspace, $business)->assertOk();
    }

    public function test_selected_staff_without_assignment_is_404(): void
    {
        [, $business, $workspace] = $this->tenant();
        $staff = $this->createCustomer()->user;
        $this->member($workspace, $staff, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected);
        $this->authenticateAsUser($staff);

        $this->overview($workspace, $business)->assertNotFound();
    }

    public function test_inactive_membership_is_404(): void
    {
        [, $business, $workspace] = $this->tenant();
        $former = $this->createCustomer()->user;
        $this->member($workspace, $former, WorkspaceMembershipRole::Admin, WorkspaceBusinessAccessScope::All, false);
        $this->authenticateAsUser($former);

        $this->overview($workspace, $business)->assertNotFound();
    }

    // -----------------------------------------------------------------
    // Bare entry chooser (§2.4)
    // -----------------------------------------------------------------

    public function test_entry_with_no_business_renders_empty_state(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $customer = $this->createCustomer();
        $this->authenticateAsCustomer($customer);

        $this->get(route('customer.analytics.entry'))->assertOk()->assertSee('No Business available yet');
    }

    public function test_entry_with_one_business_redirects_to_it(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $this->authenticateAsCustomer($customer);

        $this->get(route('customer.analytics.entry'))
            ->assertRedirect(route('customer.workspaces.businesses.analytics.overview', [$workspace->uid, $business->uid]));
    }

    public function test_entry_with_several_businesses_renders_a_chooser_and_never_guesses(): void
    {
        [$customer, $first, $workspace] = $this->tenant();
        $second = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes(['name' => 'Second Venue']));
        $this->authenticateAsCustomer($customer);

        $this->get(route('customer.analytics.entry'))->assertOk()->assertSee($first->name)->assertSee('Second Venue');
        $this->assertNotNull($second);
    }

    // -----------------------------------------------------------------
    // Route shape (§12.1, §21 "Foreign campaign exclusion")
    // -----------------------------------------------------------------

    public function test_no_b5_route_declares_a_campaign_parameter_or_an_export(): void
    {
        $names = [];

        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();

            if (str_starts_with($name, 'customer.workspaces.businesses.analytics.') || $name === 'customer.analytics.entry') {
                $names[] = $name;
                $this->assertStringNotContainsString('{campaign', $route->uri(), $name . ' must not accept a campaign id.');
                $this->assertStringNotContainsString('export', $route->uri(), $name . ' must not be an export.');
                $this->assertSame(['GET', 'HEAD'], $route->methods(), $name . ' must be read-only.');
            }
        }

        sort($names);
        $this->assertSame([
            'customer.analytics.entry',
            'customer.workspaces.businesses.analytics.campaigns',
            'customer.workspaces.businesses.analytics.overview',
            'customer.workspaces.businesses.analytics.series',
        ], $names);
    }

    public function test_series_route_is_throttled(): void
    {
        $route = Route::getRoutes()->getByName('customer.workspaces.businesses.analytics.series');

        $this->assertContains('throttle:60,1', $route->middleware());
    }
}

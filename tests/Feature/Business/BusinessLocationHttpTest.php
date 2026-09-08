<?php

namespace Tests\Feature\Business;

use App\Enums\Business\BusinessLocationLifecycleState;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Http\Controllers\Customer\Business\BusinessLocationsController;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Business\Concerns\CreatesLocationCapacityFixtures;
use Tests\TestCase;

/**
 * Customer Experience Slice 1A — the HTTP surface: tenancy, authorization,
 * true no-ops, and the plain-language interface.
 *
 * Covers contracted tests 23, 24 and 25 of the Slice 1A brief, plus the
 * §8 customer-experience requirements.
 */
class BusinessLocationHttpTest extends TestCase
{
    use CreatesLocationCapacityFixtures;
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // Cross-tenant isolation (test 23)
    // -----------------------------------------------------------------

    /** A foreign Business uid is 404, never another Business's data. */
    public function test_a_foreign_business_identifier_cannot_reach_or_mutate_locations(): void
    {
        [$customer, , $workspace] = $this->locationTenant();
        [, $otherBusiness, $otherWorkspace] = $this->locationTenant();
        $otherLocation = $this->seedLocation($otherBusiness, 'Their Branch', true);

        $this->authenticateAsOwner($customer);

        // Own Workspace uid + foreign Business uid.
        $this->get(route('customer.workspaces.businesses.locations.index', [$workspace->uid, $otherBusiness->uid]))
            ->assertNotFound();

        // Foreign Workspace uid + foreign Business uid.
        $this->get(route('customer.workspaces.businesses.locations.index', [$otherWorkspace->uid, $otherBusiness->uid]))
            ->assertNotFound();

        // A mutation aimed at the foreign Business is refused and changes nothing.
        $this->post(
            route('customer.workspaces.businesses.locations.store', [$otherWorkspace->uid, $otherBusiness->uid]),
            $this->locationPayload()
        )->assertNotFound();

        $this->assertSame(1, $this->activeLocationCount($otherBusiness));

        $this->post(
            route('customer.workspaces.businesses.locations.archive', [$otherWorkspace->uid, $otherBusiness->uid]),
            ['location_uid' => $otherLocation->uid]
        )->assertNotFound();

        $this->assertSame(BusinessLocationLifecycleState::Active, $otherLocation->refresh()->lifecycle_state);
    }

    /** A foreign LOCATION uid inside my own Business is 404, not a cross-tenant write. */
    public function test_a_foreign_location_identifier_cannot_be_archived_through_my_own_business(): void
    {
        [$customer, $business, $workspace] = $this->locationTenant();
        $this->seedActiveLocations($business, 2);

        [, $otherBusiness] = $this->locationTenant();
        $foreignLocation = $this->seedLocation($otherBusiness, 'Their Branch', true);

        $this->authenticateAsOwner($customer);

        $this->post(
            route('customer.workspaces.businesses.locations.archive', [$workspace->uid, $business->uid]),
            ['location_uid' => $foreignLocation->uid]
        )->assertNotFound();

        $this->assertSame(BusinessLocationLifecycleState::Active, $foreignLocation->refresh()->lifecycle_state);
    }

    /** A foreign Business's locations cannot be edited across the tenancy line. */
    public function test_a_foreign_business_location_cannot_be_edited(): void
    {
        [$customer, $business, $workspace] = $this->locationTenant();
        $this->seedActiveLocations($business, 1);

        [, $otherBusiness, $otherWorkspace] = $this->locationTenant();
        $foreignLocation = $this->seedLocation($otherBusiness, 'Their Branch', true);

        $this->authenticateAsOwner($customer);

        $this->post(
            route('customer.workspaces.businesses.locations.update', [$otherWorkspace->uid, $otherBusiness->uid, $foreignLocation->uid]),
            $this->locationPayload(['name' => 'Hijacked'])
        )->assertNotFound();

        // Mixing my Workspace uid with their Business uid is equally refused.
        $this->post(
            route('customer.workspaces.businesses.locations.update', [$workspace->uid, $otherBusiness->uid, $foreignLocation->uid]),
            $this->locationPayload(['name' => 'Hijacked'])
        )->assertNotFound();

        $this->assertSame('Their Branch', $foreignLocation->refresh()->name);
    }

    // -----------------------------------------------------------------
    // Authorization (test 24) and true no-ops (test 25)
    // -----------------------------------------------------------------

    /**
     * An ordinary active member with Business access may VIEW locations but
     * may not create, archive, reactivate or edit. Every denial
     * is a true no-op with no success message and no audit row.
     */
    public function test_an_ordinary_member_can_view_but_cannot_mutate(): void
    {
        [, $business, $workspace] = $this->locationTenant();
        $locations = $this->seedActiveLocations($business, 2);
        $archived = $this->seedLocation($business, 'Closed', false, BusinessLocationLifecycleState::Archived);
        $this->setAdditionalLocationSlots($business, 1);

        $staff = $this->createCustomer()->user;
        WorkspaceMembership::create([
            'workspace_id' => $workspace->id,
            'user_id' => $staff->id,
            'role' => WorkspaceMembershipRole::Staff->value,
            'business_access_scope' => WorkspaceBusinessAccessScope::All->value,
            'is_active' => true,
        ]);

        $this->authenticateAsUser($staff);

        // Reading is permitted.
        $this->get(route('customer.workspaces.businesses.locations.index', [$workspace->uid, $business->uid]))
            ->assertOk();

        $args = [$workspace->uid, $business->uid];

        $this->post(route('customer.workspaces.businesses.locations.store', $args), $this->locationPayload())
            ->assertStatus(401);
        $this->post(route('customer.workspaces.businesses.locations.archive', $args), ['location_uid' => $locations[1]->uid])
            ->assertStatus(401);
        $this->post(route('customer.workspaces.businesses.locations.reactivate', $args), ['location_uid' => $archived->uid])
            ->assertStatus(401);
        $this->post(
            route('customer.workspaces.businesses.locations.update', [$workspace->uid, $business->uid, $locations[0]->uid]),
            $this->locationPayload(['name' => 'Renamed by staff'])
        )->assertStatus(401);

        // Every denial is a COMPLETE no-op.
        $this->assertSame(2, $this->activeLocationCount($business));
        $this->assertNotSame('Renamed by staff', $locations[0]->refresh()->name);
        $this->assertSame(BusinessLocationLifecycleState::Active, $locations[1]->refresh()->lifecycle_state);
        $this->assertSame(BusinessLocationLifecycleState::Archived, $archived->refresh()->lifecycle_state);
        $this->assertSame(1, (int) $business->refresh()->additional_location_slots);
        $this->assertDatabaseMissing('workspace_entitlement_transitions', [
            'workspace_id' => $business->workspace_id,
            'transition_type' => 'additional_location_slots_changed',
        ]);
    }

    /** An inactive membership grants nothing at all. */
    public function test_an_inactive_member_cannot_even_view(): void
    {
        [, $business, $workspace] = $this->locationTenant();
        $this->seedActiveLocations($business, 1);

        $staff = $this->createCustomer()->user;
        WorkspaceMembership::create([
            'workspace_id' => $workspace->id,
            'user_id' => $staff->id,
            'role' => WorkspaceMembershipRole::Admin->value,
            'business_access_scope' => WorkspaceBusinessAccessScope::All->value,
            'is_active' => false,
        ]);

        $this->authenticateAsUser($staff);

        $this->get(route('customer.workspaces.businesses.locations.index', [$workspace->uid, $business->uid]))
            ->assertNotFound();
    }

    /** An active Workspace Admin holds the management authority. */
    public function test_an_active_workspace_admin_may_mutate(): void
    {
        [, $business, $workspace] = $this->locationTenant();
        $this->seedActiveLocations($business, 1);

        $admin = $this->createCustomer()->user;
        WorkspaceMembership::create([
            'workspace_id' => $workspace->id,
            'user_id' => $admin->id,
            'role' => WorkspaceMembershipRole::Admin->value,
            'business_access_scope' => WorkspaceBusinessAccessScope::All->value,
            'is_active' => true,
        ]);

        $this->authenticateAsUser($admin);

        $this->post(
            route('customer.workspaces.businesses.locations.store', [$workspace->uid, $business->uid]),
            $this->locationPayload(['name' => 'Admin Branch'])
        )->assertRedirect();

        $this->assertSame(2, $this->activeLocationCount($business));
    }

    /** An inactive Workspace blocks the whole surface. */
    public function test_an_inactive_workspace_blocks_every_route(): void
    {
        [$customer, $business, $workspace] = $this->locationTenant();
        $this->seedActiveLocations($business, 1);
        $this->authenticateAsOwner($customer);

        DB::table('workspaces')->where('id', $workspace->id)->update(['is_active' => false]);

        $args = [$workspace->uid, $business->uid];

        $this->get(route('customer.workspaces.businesses.locations.index', $args))->assertNotFound();
        $this->post(route('customer.workspaces.businesses.locations.store', $args), $this->locationPayload())->assertNotFound();
        $this->post(route('customer.workspaces.businesses.locations.reactivate', $args), ['location_uid' => 'no-such-location'])->assertNotFound();

        $this->assertSame(1, $this->activeLocationCount($business));
    }

    // -----------------------------------------------------------------
    // Customer experience (§8)
    // -----------------------------------------------------------------

    /** The page speaks plain language and leaks no internal terminology. */
    public function test_the_page_uses_plain_language_and_leaks_no_internal_terminology(): void
    {
        [$customer, $business, $workspace] = $this->locationTenant();
        $this->seedActiveLocations($business, 2);
        $this->seedLocation($business, 'Old Shop', false, BusinessLocationLifecycleState::Archived);
        $this->authenticateAsOwner($customer);

        $response = $this->get(route('customer.workspaces.businesses.locations.index', [$workspace->uid, $business->uid]));

        $response->assertOk();
        $response->assertSee('Locations', false);
        $response->assertSee('Open locations', false);
        $response->assertSee('Closed locations', false);
        $response->assertSee('Your plan includes 3 open locations', false);

        $body = (string) $response->getContent();

        foreach ([
            'location_slot_included',
            'location_slot_max',
            'unlimited_location_slots',
            'additional_location_slots',
            'grandfathered_location_slots',
            'additional_location_slot_price_ratio',
            'lifecycle_state',
            'workspace_plan_catalog',
            'business_locations',
            'location_capacity_grandfathered',
            'additional_location_slots_changed',
            'location_slot_allocation_required',
            'location_slot_limit_exceeded',
        ] as $internal) {
            $this->assertStringNotContainsString($internal, $body, "The page must never expose [{$internal}].");
        }

        // Account vocabulary is asserted against SLICE 1A's OWN VIEW, not
        // the whole response. The inherited global shell still renders
        // "Workspaces" and "Sub Accounts" navigation entries; replacing
        // that shell is Slice 1B's scope, and this slice deliberately does
        // not touch it (§8: "Do not redesign the global shell...").
        // What Slice 1A owns is this page, and this page must never
        // describe a physical location as another account.
        $view = (string) file_get_contents(resource_path('views/customer/business/locations/index.blade.php'));

        // Strip Blade comments (developer notes, never rendered) and
        // route() helper calls (the URL shape deliberately keeps
        // /workspaces/... per contract §8.5 — that is an address, not
        // customer-facing copy). What remains is the text a customer can
        // actually read.
        $copy = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $view);
        $copy = (string) preg_replace("/route\([^)]*\)/s", '', $copy);

        foreach (['Workspace', 'workspace', 'sub-account', 'another account', 'tenant'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $copy,
                "The locations page must never describe a physical location using [{$forbidden}]."
            );
        }

        // ...and it must say, in plain words, what a location actually is.
        $response->assertSee('A location is a physical place this business operates from', false);
    }

    /** At the ceiling the page gives an honest upgrade next step. */
    public function test_the_page_gives_an_honest_next_step_when_capacity_is_exhausted(): void
    {
        [$customer, $business, $workspace] = $this->locationTenant();
        $this->seedActiveLocations($business, 5);
        $this->setAdditionalLocationSlots($business, 2);
        $this->authenticateAsOwner($customer);

        $response = $this->get(route('customer.workspaces.businesses.locations.index', [$workspace->uid, $business->uid]));

        $response->assertOk();
        $response->assertSee('reached the most locations this plan can open', false);
        $response->assertSee('move to the Agency plan', false);
    }

    /** An Agency Business is told its locations are unlimited, plainly. */
    public function test_an_agency_business_is_told_its_locations_are_unlimited(): void
    {
        [$customer, $business, $workspace] = $this->agencyTenant();
        $this->seedActiveLocations($business, 7);
        $this->authenticateAsOwner($customer);

        $response = $this->get(route('customer.workspaces.businesses.locations.index', [$workspace->uid, $business->uid]));

        $response->assertOk();
        $response->assertSee('Your plan covers as many as you need', false);
    }

    /** The happy path works end to end through the HTTP surface. */
    public function test_the_owner_can_add_close_and_reopen_a_location(): void
    {
        [$customer, $business, $workspace] = $this->locationTenant();
        $this->seedActiveLocations($business, 1);
        $this->authenticateAsOwner($customer);

        $args = [$workspace->uid, $business->uid];

        $this->post(route('customer.workspaces.businesses.locations.store', $args), $this->locationPayload(['name' => 'Second Shop']))
            ->assertRedirect();

        $this->assertSame(2, $this->activeLocationCount($business));

        $second = DB::table('business_locations')->where('business_id', $business->id)->where('name', 'Second Shop')->first();

        $this->post(route('customer.workspaces.businesses.locations.archive', $args), ['location_uid' => $second->uid])
            ->assertRedirect();

        $this->assertSame(1, $this->activeLocationCount($business));
        $this->assertDatabaseHas('business_locations', [
            'id' => $second->id,
            'lifecycle_state' => BusinessLocationLifecycleState::Archived->value,
        ]);

        $this->post(route('customer.workspaces.businesses.locations.reactivate', $args), ['location_uid' => $second->uid])
            ->assertRedirect();

        $this->assertSame(2, $this->activeLocationCount($business));
    }

    // -----------------------------------------------------------------
    // CORRECTION ROUND 1 — no customer surface may grant unpaid capacity
    // -----------------------------------------------------------------

    /**
     * The registered route table itself carries the guarantee: no customer
     * route allocates or cancels a paid additional location slot.
     */
    public function test_no_customer_route_allocates_or_cancels_a_paid_location_slot(): void
    {
        $names = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route) => (string) $route->getName())
            ->filter()
            ->filter(fn (string $name) => str_starts_with($name, 'customer.'))
            ->values();

        foreach (['allocations.store', 'allocations.cancel'] as $removed) {
            $this->assertEmpty(
                $names->filter(fn (string $name) => str_contains($name, 'locations.' . $removed))->all(),
                'No customer route may allocate or cancel a paid location slot while pricing and billing do not exist.'
            );
        }

        // The controller must not carry the actions either.
        foreach (['allocate', 'cancelAllocation'] as $removedAction) {
            $this->assertFalse(
                method_exists(BusinessLocationsController::class, $removedAction),
                'BusinessLocationsController::' . $removedAction . '() must not exist.'
            );
        }
    }

    /**
     * A direct POST to the removed URLs — exactly what an attacker or a
     * stale bookmark would send — changes nothing and claims nothing.
     */
    public function test_direct_posts_to_the_removed_allocation_urls_grant_no_capacity(): void
    {
        [$customer, $business, $workspace] = $this->locationTenant();
        $this->seedActiveLocations($business, 3);
        $this->setAdditionalLocationSlots($business, 1);
        $this->authenticateAsOwner($customer);

        $base = rtrim(route('customer.workspaces.businesses.locations.index', [$workspace->uid, $business->uid]), '/');

        foreach ([$base . '/allocations', $base . '/allocations/cancel'] as $url) {
            $response = $this->post($url);

            // 404 (no such route) or 405 (method not allowed) — never a
            // redirect carrying a success message.
            $this->assertContains(
                $response->getStatusCode(),
                [404, 405],
                'POST ' . $url . ' must not be handled.'
            );

            $this->assertNull(session('message'), 'A removed allocation URL must never claim success.');
        }

        // The counter is untouched and no transition was written.
        $this->assertSame(1, (int) $business->refresh()->additional_location_slots);
        $this->assertDatabaseMissing('workspace_entitlement_transitions', [
            'workspace_id' => $business->workspace_id,
            'transition_type' => 'additional_location_slots_changed',
        ]);
    }

    /**
     * Every customer POST route on this surface is exercised as the owner,
     * with nothing but a CSRF-valid empty body, and none of them can raise
     * the paid allocation counter.
     */
    public function test_no_customer_post_on_this_surface_can_raise_the_paid_counter(): void
    {
        [$customer, $business, $workspace] = $this->locationTenant();
        $this->seedActiveLocations($business, 3);
        $this->authenticateAsOwner($customer);

        $before = (int) $business->refresh()->additional_location_slots;

        $namePrefix = 'customer.workspaces.businesses.locations.';

        $posted = 0;

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! in_array('POST', $route->methods(), true)) {
                continue;
            }

            if (! str_starts_with((string) $route->getName(), $namePrefix)) {
                continue;
            }

            $url = str_replace(
                ['{workspaceUid}', '{businessUid}', '{locationUid}'],
                [$workspace->uid, $business->uid, 'no-such-location'],
                $route->uri()
            );

            $this->post('/' . $url);
            $posted++;
        }

        $this->assertGreaterThan(0, $posted, 'The surface must actually have POST routes to exercise.');
        $this->assertSame(
            $before,
            (int) $business->refresh()->additional_location_slots,
            'No customer POST on this surface may raise the paid location counter.'
        );
        $this->assertDatabaseMissing('workspace_entitlement_transitions', [
            'workspace_id' => $business->workspace_id,
            'transition_type' => 'additional_location_slots_changed',
        ]);
    }

    /**
     * The page explains the situation honestly and renders NO actionable
     * control, and makes no promise about a bill, an invoice or a payment.
     */
    public function test_the_page_explains_extra_locations_without_offering_an_unpaid_one(): void
    {
        [$customer, $business, $workspace] = $this->locationTenant();
        $this->seedActiveLocations($business, 3);
        $this->authenticateAsOwner($customer);

        $response = $this->get(route('customer.workspaces.businesses.locations.index', [$workspace->uid, $business->uid]));

        $response->assertOk();
        $response->assertSee('Extra locations cannot be added yet.', false);

        $body = (string) $response->getContent();

        // No actionable allocation control of any kind.
        foreach ([
            'Add an extra location to my plan',
            'Remove an extra location',
            '/allocations',
        ] as $control) {
            $this->assertStringNotContainsString($control, $body, 'The page must render no allocation control; found [' . $control . '].');
        }

        // And no unsupported billing promise.
        foreach ([
            'next invoice',
            'does not take a payment now',
            'will be charged',
            'added to your bill',
        ] as $promise) {
            $this->assertStringNotContainsString($promise, $body, 'The page must promise no charge; found [' . $promise . '].');
        }
    }
}

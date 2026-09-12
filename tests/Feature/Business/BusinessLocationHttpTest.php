<?php

namespace Tests\Feature\Business;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Models\BusinessLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Business\Concerns\CreatesLocationCapacityFixtures;
use Tests\TestCase;

/**
 * Customer Experience Slice 1A — Settings → Business → Locations over HTTP.
 *
 * Tenancy is 404, never 403 (contract §5.4). Reading needs access to the
 * Business; changing locations needs Workspace owner-or-active-Admin
 * authority. Copy speaks in outcomes: it never names a denial key, a slot,
 * an allocation, a price, an entitlement or the word Workspace. T-LOC-10
 * (route by route), T-CTX-4.
 */
class BusinessLocationHttpTest extends TestCase
{
    use CreatesLocationCapacityFixtures;
    use RefreshDatabase;

    private const FORBIDDEN_COPY = ['Workspace', 'workspace_plan', 'entitlement', 'allocation', 'slot', 'location_slot', 'locale.'];

    public function test_the_owner_sees_capacity_in_plain_language_and_can_add_a_location(): void
    {
        [$customer, $business, $workspace] = $this->locationTenant(WorkspacePlanTier::Core, 1);
        $this->authenticateAs($customer);

        $response = $this->get($this->url($workspace, $business))->assertOk();

        $response->assertSee('1 of 3 active locations in use', false);
        $response->assertSee('3 locations included in your plan', false);
        $response->assertSee('You can add 2 more locations.', false);
        $response->assertSee('data-role="add-location"', false);
        $response->assertSee('it isn\'t a separate account', false);
        $this->assertNoForbiddenCopy($response->getContent());
    }

    public function test_the_owner_adds_a_location(): void
    {
        [$customer, $business, $workspace] = $this->locationTenant(WorkspacePlanTier::Growth, 1);
        $this->authenticateAs($customer);

        $this->post($this->url($workspace, $business), $this->locationAttributes('Harbor Point'))
            ->assertRedirect($this->url($workspace, $business))
            ->assertSessionHas('flash_success', 'Added Harbor Point.');

        $location = $this->locationNamed($business, 'Harbor Point');
        $this->assertTrue($location->isActive());
        $this->assertFalse($location->is_primary, 'The existing primary stays primary.');
    }

    public function test_a_new_location_needs_a_name_and_a_physical_service_mode(): void
    {
        [$customer, $business, $workspace] = $this->locationTenant(WorkspacePlanTier::Core, 1);
        $this->authenticateAs($customer);

        $this->post($this->url($workspace, $business), array_merge($this->locationAttributes(), ['name' => '']))
            ->assertSessionHasErrors('name');
        $this->post($this->url($workspace, $business), array_merge($this->locationAttributes('Online'), ['service_mode' => 'online']))
            ->assertSessionHasErrors('service_mode');

        $this->assertSame(1, $this->activeCount($business));
    }

    // T-LOC-10 — the create route, at the 4th location
    public function test_the_fourth_location_is_refused_with_an_honest_add_on_message_and_no_purchase(): void
    {
        [$customer, $business, $workspace] = $this->locationTenant(WorkspacePlanTier::Core, 3);
        $this->authenticateAs($customer);

        $index = $this->get($this->url($workspace, $business))->assertOk();
        $index->assertSee('3 of 3 active locations in use', false);
        $index->assertSee("can't be bought online yet", false);
        $index->assertDontSee('data-role="add-location"', false);
        $this->assertNoForbiddenCopy($index->getContent());

        $this->get($this->url($workspace, $business) . '/create')
            ->assertRedirect($this->url($workspace, $business));

        $this->post($this->url($workspace, $business), $this->locationAttributes('Fourth'))
            ->assertRedirect($this->url($workspace, $business))
            ->assertSessionHas('flash_error');

        $this->assertStringContainsString("can't be bought online yet", session('flash_error'));
        $this->assertSame(3, $this->activeCount($business), 'The canonical boundary refused the write.');
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('customer.workspaces.businesses.locations.allocations.store'), 'There is no purchase route.');
    }

    public function test_the_sixth_location_is_refused_and_points_to_the_agency_plan(): void
    {
        [$customer, $business, $workspace] = $this->locationTenant(WorkspacePlanTier::Core, 3);
        $this->grantComplimentaryLocations($business, 2);
        $this->locations()->createLocation($business, $this->locationAttributes('Fourth'), (int) $customer->user_id);
        $this->locations()->createLocation($business, $this->locationAttributes('Fifth'), (int) $customer->user_id);
        $this->authenticateAs($customer);

        $index = $this->get($this->url($workspace, $business))->assertOk();
        $index->assertSee('up to 5 active locations per business', false);
        $index->assertSee('Agency plan', false);
        $index->assertSee('data-role="plan-link"', false);
        $index->assertSee('2 add-on locations', false);

        $this->post($this->url($workspace, $business), $this->locationAttributes('Sixth'))->assertSessionHas('flash_error');
        $this->assertStringContainsString('Agency plan', session('flash_error'));
        $this->assertSame(5, $this->activeCount($business));
    }

    public function test_an_agency_business_is_told_it_has_unlimited_locations(): void
    {
        [$customer, $business, $workspace] = $this->locationTenant(WorkspacePlanTier::Agency, 6);
        $this->authenticateAs($customer);

        $this->get($this->url($workspace, $business))->assertOk()
            ->assertSee('Your plan includes unlimited locations.', false)
            ->assertSee('data-role="add-location"', false);
    }

    public function test_archive_reactivate_and_make_primary_round_trip(): void
    {
        [$customer, $business, $workspace] = $this->locationTenant(WorkspacePlanTier::Core, 3);
        $this->authenticateAs($customer);
        $third = $this->locationNamed($business, 'Branch 3');
        $first = $this->locationNamed($business, 'Branch 1');
        $second = $this->locationNamed($business, 'Branch 2');

        $this->post($this->url($workspace, $business, $third->uid, 'archive'))->assertSessionHas('flash_success');
        $this->assertTrue($third->fresh()->isArchived());
        $this->get($this->url($workspace, $business))->assertSee('Archived locations', false)->assertSee('Branch 3', false);

        $this->post($this->url($workspace, $business, $third->uid, 'archive'))->assertSessionHas('flash_info');

        // T-LOC-10 — the reactivate route, refused when capacity is exhausted.
        $this->locations()->createLocation($business, $this->locationAttributes('Branch 4'), (int) $customer->user_id);
        $this->post($this->url($workspace, $business, $third->uid, 'reactivate'))->assertSessionHas('flash_error');
        $this->assertTrue($third->fresh()->isArchived());

        $this->post($this->url($workspace, $business, $this->locationNamed($business, 'Branch 4')->uid, 'archive'))->assertSessionHas('flash_success');
        $this->post($this->url($workspace, $business, $third->uid, 'reactivate'))->assertSessionHas('flash_success');
        $this->assertTrue($third->fresh()->isActive());

        // The primary is archived only together with a replacement.
        $this->post($this->url($workspace, $business, $first->uid, 'archive'))->assertSessionHas('flash_error');
        $this->assertTrue($first->fresh()->isActive());
        $this->post($this->url($workspace, $business, $first->uid, 'archive'), ['new_primary_location_uid' => $second->uid])->assertSessionHas('flash_success');
        $this->assertTrue($first->fresh()->isArchived());
        $this->assertTrue($second->fresh()->is_primary);

        $this->post($this->url($workspace, $business, $third->uid, 'primary'))->assertSessionHas('flash_success');
        $this->assertTrue($third->fresh()->is_primary);
        $this->assertFalse($second->fresh()->is_primary);
        $this->post($this->url($workspace, $business, $third->uid, 'primary'))->assertSessionHas('flash_info');
    }

    public function test_editing_details_never_moves_ownership_primary_or_lifecycle(): void
    {
        [$customer, $business, $workspace] = $this->locationTenant(WorkspacePlanTier::Core, 2);
        [, $otherBusiness] = $this->locationTenant(WorkspacePlanTier::Core, 0);
        $this->authenticateAs($customer);
        $second = $this->locationNamed($business, 'Branch 2');

        $this->get($this->url($workspace, $business, $second->uid, 'edit'))->assertOk()->assertSee('Edit Branch 2', false);

        $this->post($this->url($workspace, $business, $second->uid, 'details'), array_merge($this->locationAttributes('Renamed'), [
            'business_id' => $otherBusiness->id,
            'is_primary' => 1,
            'lifecycle_state' => 'archived',
        ]))->assertSessionHas('flash_success', 'Saved Renamed.');

        $fresh = $second->fresh();
        $this->assertSame('Renamed', $fresh->name);
        $this->assertSame($business->id, (int) $fresh->business_id);
        $this->assertFalse($fresh->is_primary);
        $this->assertTrue($fresh->isActive());
    }

    public function test_clearing_the_name_while_editing_keeps_the_existing_name(): void
    {
        [$customer, $business, $workspace] = $this->locationTenant(WorkspacePlanTier::Core, 1);
        $this->authenticateAs($customer);
        $first = $this->locationNamed($business, 'Branch 1');

        $this->post($this->url($workspace, $business, $first->uid, 'details'), array_merge($this->locationAttributes('Ignored'), ['name' => '', 'city' => 'Shelbyville']))
            ->assertSessionHas('flash_success', 'Saved Branch 1.');

        $this->assertSame('Branch 1', $first->fresh()->name);
        $this->assertSame('Shelbyville', $first->fresh()->city);
    }

    public function test_staff_read_the_locations_but_cannot_change_them(): void
    {
        [$owner, $business, $workspace] = $this->locationTenant(WorkspacePlanTier::Core, 2);
        $staff = $this->createCustomer();
        $membership = $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected);
        $this->assign($membership, $business);
        $this->authenticateAs($staff);

        $index = $this->get($this->url($workspace, $business))->assertOk();
        $index->assertSee('Branch 2', false);
        $index->assertSee('data-role="read-only-note"', false);
        $index->assertDontSee('data-role="add-location"', false);
        $index->assertDontSee('/archive', false);

        $this->post($this->url($workspace, $business), $this->locationAttributes('Staff Branch'))->assertStatus(401);
        $this->post($this->url($workspace, $business, $this->locationNamed($business, 'Branch 2')->uid, 'archive'))->assertStatus(401);

        $this->assertSame(2, $this->activeCount($business), 'A refused actor writes nothing.');
    }

    public function test_another_tenant_gets_404_everywhere(): void
    {
        [, $business, $workspace] = $this->locationTenant(WorkspacePlanTier::Core, 1);
        [$outsider] = $this->locationTenant(WorkspacePlanTier::Core, 1);
        $this->authenticateAs($outsider);
        $location = $this->locationNamed($business, 'Branch 1');

        $this->get($this->url($workspace, $business))->assertNotFound();
        $this->post($this->url($workspace, $business), $this->locationAttributes('Intruder'))->assertNotFound();
        $this->post($this->url($workspace, $business, $location->uid, 'archive'))->assertNotFound();
        $this->post($this->url($workspace, $business, $location->uid, 'reactivate'))->assertNotFound();

        $this->assertSame(1, $this->activeCount($business));
    }

    public function test_a_location_of_another_business_is_404_even_inside_an_accessible_business(): void
    {
        [$customer, $business, $workspace] = $this->locationTenant(WorkspacePlanTier::Agency, 1);
        $clientTwo = $this->addBusiness($customer, $workspace, 'Client Two');
        $this->locations()->createLocation($clientTwo, $this->locationAttributes('Elsewhere'), (int) $customer->user_id);
        $this->authenticateAs($customer);

        $foreign = $this->locationNamed($clientTwo, 'Elsewhere');
        $this->post($this->url($workspace, $business, $foreign->uid, 'archive'))->assertNotFound();
        $this->assertTrue($foreign->fresh()->isActive());
    }

    // T-CTX-4 — a location is never a switcher entry and never gates access
    public function test_locations_never_appear_as_an_account_level_or_gate_access(): void
    {
        [$customer, $business, $workspace] = $this->locationTenant(WorkspacePlanTier::Agency, 0);
        [$outsider] = $this->locationTenant(WorkspacePlanTier::Core, 0);

        $this->authenticateAs($customer);
        $this->get($this->url($workspace, $business))->assertOk();

        foreach (['Switcher Probe', 'Second Probe', 'Third Probe'] as $name) {
            $this->locations()->createLocation($business, $this->locationAttributes($name), (int) $customer->user_id);
        }

        $this->get($this->url($workspace, $business))->assertOk();
        $home = $this->home()->assertOk()->getContent();
        $this->assertStringNotContainsString('Switcher Probe', $this->shellHtml($home), 'A location is never a switcher or account entry.');

        foreach (['business_usage_wallets', 'business_payer_assignments', 'workspace_memberships', 'workspace_membership_businesses', 'workspace_plan_assignments'] as $table) {
            $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn($table, 'business_location_id'), "{$table} never keys off a location.");
        }

        $this->authenticateAs($outsider);
        $this->get($this->url($workspace, $business))->assertNotFound();
    }

    // Slice 1A navigation — Settings → Business → Locations
    public function test_the_business_frame_offers_locations_under_settings(): void
    {
        [$customer, $business, $workspace] = $this->locationTenant(WorkspacePlanTier::Core, 1);
        $this->authenticateAs($customer);

        $html = $this->get($this->url($workspace, $business))->assertOk()->getContent();

        $this->assertContains('locations', $this->menuKeys($html));
        $this->assertContains('locations', $this->activeMenuKeys($html), 'The Locations entry is active on its own page.');
        $this->assertStringContainsString($this->url($workspace, $business), implode(' ', $this->menuLinks($html)));
    }

    public function test_view_as_client_reaches_only_the_viewed_business_locations(): void
    {
        [$agency, $viewed, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Viewed Client', 'Northwind Agency');
        $sibling = $this->addBusiness($agency, $workspace, 'Sibling Client');
        $this->authenticateAs($agency);
        $this->startViewAs($workspace, $viewed)->assertRedirect(route('user.home'));

        $html = $this->get($this->url($workspace, $viewed))->assertOk()->getContent();
        $this->assertContains('locations', $this->menuKeys($html), 'The existing view-as classification keeps the entry for the viewed Business.');
        $this->get($this->url($workspace, $sibling))->assertNotFound();
    }

    private function url($workspace, $business, ?string $locationUid = null, ?string $action = null): string
    {
        $base = "/workspaces/{$workspace->uid}/businesses/{$business->uid}/locations";

        if ($locationUid === null) {
            return $base;
        }

        return "{$base}/{$locationUid}/{$action}";
    }

    private function assertNoForbiddenCopy(string $html): void
    {
        $text = html_entity_decode(strip_tags(preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $this->mainContent($html))));

        foreach (self::FORBIDDEN_COPY as $term) {
            $this->assertStringNotContainsStringIgnoringCase($term, $text, "Customer copy must not say \"{$term}\".");
        }
    }

    private function mainContent(string $html): string
    {
        return preg_match('/<main\b[^>]*>(.*)<\/main>/is', $html, $m) === 1 ? $m[1] : $html;
    }
}

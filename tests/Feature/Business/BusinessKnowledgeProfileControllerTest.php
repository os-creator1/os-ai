<?php

namespace Tests\Feature\Business;

use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Business\BusinessKnowledgeProfileManager;
use App\Models\BusinessKnowledgeProfileChange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesBusinessKnowledgeProfileFixtures;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Website Guided Generation contract §3.3 (Option A, locked), §16 Slice
 * 2 -- the Business Knowledge Profile customer-portal surface. Mirrors
 * WebsiteTenancyTest's shape exactly: owner/admin/staff access,
 * inactive-member denial, missing-permission denial, foreign
 * Workspace/Business 404s, and a cross-tenant IDOR proof.
 */
class BusinessKnowledgeProfileControllerTest extends TestCase
{
    use CreatesWebsiteFixtures;
    use CreatesBusinessKnowledgeProfileFixtures;
    use RefreshDatabase;

    // ---------------------------------------------------------------
    // Authorization: owner / admin / staff / inactive / permission
    // ---------------------------------------------------------------

    public function test_owner_can_access_the_completeness_page(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $this->get(route('customer.workspaces.businesses.knowledge-profile.show', [$workspace->uid, $business->uid]))
            ->assertOk()
            ->assertSee('Business Details');
    }

    public function test_active_admin_member_can_access_the_completeness_page(): void
    {
        [, $business, $workspace] = $this->entitledTenant();

        $admin = $this->createCustomer()->user;
        $this->addMember($workspace, $admin, WorkspaceMembershipRole::Admin);
        $this->authenticateAsUser($admin);

        $this->get(route('customer.workspaces.businesses.knowledge-profile.show', [$workspace->uid, $business->uid]))
            ->assertOk();
    }

    public function test_active_staff_member_can_access_the_completeness_page(): void
    {
        [, $business, $workspace] = $this->entitledTenant();

        $staff = $this->createCustomer()->user;
        $this->addMember($workspace, $staff, WorkspaceMembershipRole::Staff);
        $this->authenticateAsUser($staff);

        $this->get(route('customer.workspaces.businesses.knowledge-profile.show', [$workspace->uid, $business->uid]))
            ->assertOk();
    }

    public function test_inactive_member_is_denied(): void
    {
        [, $business, $workspace] = $this->entitledTenant();

        $former = $this->createCustomer()->user;
        $this->addMember($workspace, $former, WorkspaceMembershipRole::Admin, false);
        $this->authenticateAsUser($former);

        $this->get(route('customer.workspaces.businesses.knowledge-profile.show', [$workspace->uid, $business->uid]))
            ->assertNotFound();
    }

    public function test_missing_website_permission_is_denied(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer, []);

        $this->get(route('customer.workspaces.businesses.knowledge-profile.show', [$workspace->uid, $business->uid]))
            ->assertStatus(401);
    }

    // ---------------------------------------------------------------
    // Foreign identifiers / cross-tenant protection
    // ---------------------------------------------------------------

    public function test_foreign_workspace_uid_is_not_found(): void
    {
        [$customer, $business] = $this->entitledTenant();
        [, , $otherWorkspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $this->get(route('customer.workspaces.businesses.knowledge-profile.show', [$otherWorkspace->uid, $business->uid]))
            ->assertNotFound();
    }

    public function test_foreign_business_uid_inside_own_workspace_is_not_found(): void
    {
        [$customer, , $workspace] = $this->entitledTenant();
        [, $otherBusiness] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $this->get(route('customer.workspaces.businesses.knowledge-profile.show', [$workspace->uid, $otherBusiness->uid]))
            ->assertNotFound();
    }

    public function test_a_foreign_tenant_cannot_write_another_businesses_profile(): void
    {
        [$customerA, , $workspaceA] = $this->entitledTenant();
        $this->authenticateAsCustomer($customerA);

        [, $businessB, $workspaceB] = $this->entitledTenant();

        $this->put(route('customer.workspaces.businesses.knowledge-profile.update', [$workspaceB->uid, $businessB->uid]), [
            'ideal_customers' => 'Attacker-supplied value',
        ])->assertNotFound();

        $this->assertSame(0, \App\Models\BusinessKnowledgeProfile::where('business_id', $businessB->id)->count());
    }

    public function test_a_foreign_tenant_cannot_write_another_businesses_location_hours(): void
    {
        [$customerA] = $this->entitledTenant();
        $this->authenticateAsCustomer($customerA);

        [, $businessB, $workspaceB] = $this->entitledTenant();
        $locationB = $this->addLocation($businessB, ['is_primary' => true]);

        $this->put(route('customer.workspaces.businesses.knowledge-profile.locations.hours', [$workspaceB->uid, $businessB->uid, $locationB->uid]), [
            'monday' => [['open' => '09:00', 'close' => '17:00']],
        ])->assertNotFound();

        $this->assertNull($locationB->fresh()->hours);
    }

    // ---------------------------------------------------------------
    // Read-only completeness display
    // ---------------------------------------------------------------

    public function test_missing_stale_and_present_fields_are_all_displayed(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        app(BusinessKnowledgeProfileManager::class)->updateFields($business, ['brand_voice' => 'Friendly'], 'manual_edit', $customer->user_id, markVerified: true);
        $this->authenticateAsCustomer($customer);

        $response = $this->get(route('customer.workspaces.businesses.knowledge-profile.show', [$workspace->uid, $business->uid]));

        $response->assertOk();
        $response->assertSee("How would you describe your brand's tone?", false);
        $response->assertSee('Who are your ideal customers?', false);
    }

    public function test_viewing_the_completeness_page_creates_no_profile_row(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $this->get(route('customer.workspaces.businesses.knowledge-profile.show', [$workspace->uid, $business->uid]))->assertOk();
        $this->get(route('customer.workspaces.businesses.knowledge-profile.show', [$workspace->uid, $business->uid]))->assertOk();

        $this->assertSame(0, \App\Models\BusinessKnowledgeProfile::where('business_id', $business->id)->count());
    }

    // ---------------------------------------------------------------
    // Writes: atomicity, no-op, hours
    // ---------------------------------------------------------------

    public function test_updating_fields_persists_through_the_manager_seam(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $this->put(route('customer.workspaces.businesses.knowledge-profile.update', [$workspace->uid, $business->uid]), [
            'ideal_customers' => 'Homeowners in the local area',
            'years_operating' => '10',
        ])->assertRedirect();

        $profile = \App\Models\BusinessKnowledgeProfile::where('business_id', $business->id)->first();
        $this->assertSame('Homeowners in the local area', $profile->ideal_customers);
        $this->assertSame(10, $profile->years_operating);
    }

    public function test_an_invalid_field_rejects_the_whole_submission_and_saves_nothing(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $this->put(route('customer.workspaces.businesses.knowledge-profile.update', [$workspace->uid, $business->uid]), [
            'ideal_customers' => 'Homeowners',
            'years_operating' => '-5',
        ])->assertSessionHasErrors();

        $this->assertNull(\App\Models\BusinessKnowledgeProfile::where('business_id', $business->id)->first()?->ideal_customers);
    }

    public function test_resubmitting_identical_answers_creates_no_new_change_row(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $payload = ['ideal_customers' => 'Homeowners'];

        $this->put(route('customer.workspaces.businesses.knowledge-profile.update', [$workspace->uid, $business->uid]), $payload)->assertRedirect();
        $this->assertSame(1, BusinessKnowledgeProfileChange::where('business_id', $business->id)->count());

        $this->put(route('customer.workspaces.businesses.knowledge-profile.update', [$workspace->uid, $business->uid]), $payload)->assertRedirect();
        $this->assertSame(1, BusinessKnowledgeProfileChange::where('business_id', $business->id)->count());
    }

    public function test_updating_hours_for_one_location_never_touches_another(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $primary = $this->addLocation($business, ['is_primary' => true]);
        $secondary = $this->addLocation($business, ['is_primary' => false, 'name' => 'Second Shop']);
        $this->authenticateAsCustomer($customer);

        $this->put(route('customer.workspaces.businesses.knowledge-profile.locations.hours', [$workspace->uid, $business->uid, $primary->uid]), [
            'monday' => [['open' => '09:00', 'close' => '17:00']],
        ])->assertRedirect();

        $this->assertNotNull($primary->fresh()->hours);
        $this->assertNull($secondary->fresh()->hours);
    }

    // -----------------------------------------------------------------
    // Correction (§6): clearable priority selections via an explicit
    // "presented" marker
    // -----------------------------------------------------------------

    public function test_presenting_the_service_priority_select_with_nothing_checked_clears_it_to_empty_array(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $service = $this->addService($business);
        app(BusinessKnowledgeProfileManager::class)->updateFields($business, ['growth_priority_service_ids' => [$service->id]], 'manual_edit', $customer->user_id, markVerified: true);
        $this->authenticateAsCustomer($customer);

        $this->put(route('customer.workspaces.businesses.knowledge-profile.update', [$workspace->uid, $business->uid]), [
            'growth_priority_service_ids_presented' => '1',
        ])->assertRedirect();

        $profile = \App\Models\BusinessKnowledgeProfile::where('business_id', $business->id)->first();
        $this->assertSame([], $profile->growth_priority_service_ids);
    }

    public function test_omitting_the_presented_marker_leaves_existing_service_priorities_untouched(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $service = $this->addService($business);
        app(BusinessKnowledgeProfileManager::class)->updateFields($business, ['growth_priority_service_ids' => [$service->id]], 'manual_edit', $customer->user_id, markVerified: true);
        $this->authenticateAsCustomer($customer);

        $this->put(route('customer.workspaces.businesses.knowledge-profile.update', [$workspace->uid, $business->uid]), [
            'ideal_customers' => 'Something else entirely',
        ])->assertRedirect();

        $profile = \App\Models\BusinessKnowledgeProfile::where('business_id', $business->id)->first();
        $this->assertSame([$service->id], $profile->growth_priority_service_ids);
    }

    public function test_presenting_the_location_priority_select_with_nothing_checked_clears_it_to_empty_array(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $location = $this->addLocation($business);
        app(BusinessKnowledgeProfileManager::class)->updateFields($business, ['growth_priority_location_ids' => [$location->id]], 'manual_edit', $customer->user_id, markVerified: true);
        $this->authenticateAsCustomer($customer);

        $this->put(route('customer.workspaces.businesses.knowledge-profile.update', [$workspace->uid, $business->uid]), [
            'growth_priority_location_ids_presented' => '1',
        ])->assertRedirect();

        $profile = \App\Models\BusinessKnowledgeProfile::where('business_id', $business->id)->first();
        $this->assertSame([], $profile->growth_priority_location_ids);
    }

    public function test_omitting_the_presented_marker_leaves_existing_location_priorities_untouched(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $location = $this->addLocation($business);
        app(BusinessKnowledgeProfileManager::class)->updateFields($business, ['growth_priority_location_ids' => [$location->id]], 'manual_edit', $customer->user_id, markVerified: true);
        $this->authenticateAsCustomer($customer);

        $this->put(route('customer.workspaces.businesses.knowledge-profile.update', [$workspace->uid, $business->uid]), [
            'ideal_customers' => 'Something else entirely',
        ])->assertRedirect();

        $profile = \App\Models\BusinessKnowledgeProfile::where('business_id', $business->id)->first();
        $this->assertSame([$location->id], $profile->growth_priority_location_ids);
    }

    // -----------------------------------------------------------------
    // Correction (§7): stale-value reconfirmation, HTTP layer
    // -----------------------------------------------------------------

    public function test_checking_confirm_still_correct_on_an_unchanged_stale_field_clears_staleness_via_http(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        app(BusinessKnowledgeProfileManager::class)->updateFields($business, ['ideal_customers' => 'Homeowners'], 'manual_edit', $customer->user_id, markVerified: true);
        \App\Models\BusinessKnowledgeProfileFieldState::where('business_id', $business->id)
            ->where('field_key', 'ideal_customers')
            ->update(['verified_at' => now()->subDays(400)]);
        $this->authenticateAsCustomer($customer);

        $this->put(route('customer.workspaces.businesses.knowledge-profile.update', [$workspace->uid, $business->uid]), [
            'ideal_customers' => 'Homeowners',
            'reconfirm' => ['ideal_customers' => '1'],
        ])->assertRedirect();

        $response = $this->get(route('customer.workspaces.businesses.knowledge-profile.show', [$workspace->uid, $business->uid]));
        $response->assertDontSee('Please confirm these are still correct');
    }

    public function test_the_completeness_page_lists_a_stale_field_until_reconfirmed(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        app(BusinessKnowledgeProfileManager::class)->updateFields($business, ['ideal_customers' => 'Homeowners'], 'manual_edit', $customer->user_id, markVerified: true);
        \App\Models\BusinessKnowledgeProfileFieldState::where('business_id', $business->id)
            ->where('field_key', 'ideal_customers')
            ->update(['verified_at' => now()->subDays(400)]);
        $this->authenticateAsCustomer($customer);

        $response = $this->get(route('customer.workspaces.businesses.knowledge-profile.show', [$workspace->uid, $business->uid]));

        $response->assertSee('Please confirm these are still correct');
        $response->assertSee('Who are your ideal customers?', false);
    }

    public function test_reconfirming_one_field_does_not_touch_other_untouched_fields_via_http(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $manager = app(BusinessKnowledgeProfileManager::class);
        $manager->updateFields($business, ['ideal_customers' => 'Homeowners', 'brand_voice' => 'Friendly'], 'manual_edit', $customer->user_id, markVerified: true);
        \App\Models\BusinessKnowledgeProfileFieldState::where('business_id', $business->id)
            ->where('field_key', 'ideal_customers')
            ->update(['verified_at' => now()->subDays(400)]);
        $this->authenticateAsCustomer($customer);

        $this->put(route('customer.workspaces.businesses.knowledge-profile.update', [$workspace->uid, $business->uid]), [
            'ideal_customers' => 'Homeowners',
            'reconfirm' => ['ideal_customers' => '1'],
        ])->assertRedirect();

        $profile = \App\Models\BusinessKnowledgeProfile::where('business_id', $business->id)->first();
        $this->assertSame('Friendly', $profile->brand_voice);
        $this->assertSame(1, BusinessKnowledgeProfileChange::where('business_id', $business->id)->where('field_key', 'brand_voice')->count());
    }

    // -----------------------------------------------------------------
    // Correction (§8): all four contracted hours periods
    // -----------------------------------------------------------------

    public function test_editing_a_location_with_four_stored_periods_renders_all_four(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $location = $this->addLocation($business, ['is_primary' => true]);
        app(BusinessKnowledgeProfileManager::class)->updateLocationHours($business, $location, [
            'monday' => [
                ['open' => '06:00', 'close' => '08:00'],
                ['open' => '09:00', 'close' => '11:00'],
                ['open' => '13:00', 'close' => '15:00'],
                ['open' => '16:00', 'close' => '18:00'],
            ],
            'tuesday' => [], 'wednesday' => [], 'thursday' => [], 'friday' => [], 'saturday' => [], 'sunday' => [],
            'notes' => null,
        ], 'manual_edit', $customer->user_id, markVerified: true);
        $this->authenticateAsCustomer($customer);

        $response = $this->get(route('customer.workspaces.businesses.knowledge-profile.edit', [$workspace->uid, $business->uid]));

        $response->assertOk();
        foreach (['06:00', '08:00', '09:00', '11:00', '13:00', '15:00', '16:00', '18:00'] as $time) {
            $response->assertSee("value=\"{$time}\"", false);
        }
    }

    public function test_resubmitting_the_hours_form_as_rendered_preserves_all_four_periods(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $location = $this->addLocation($business, ['is_primary' => true]);
        $fourPeriods = [
            ['open' => '06:00', 'close' => '08:00'],
            ['open' => '09:00', 'close' => '11:00'],
            ['open' => '13:00', 'close' => '15:00'],
            ['open' => '16:00', 'close' => '18:00'],
        ];
        app(BusinessKnowledgeProfileManager::class)->updateLocationHours($business, $location, [
            'monday' => $fourPeriods,
            'tuesday' => [], 'wednesday' => [], 'thursday' => [], 'friday' => [], 'saturday' => [], 'sunday' => [],
            'notes' => null,
        ], 'manual_edit', $customer->user_id, markVerified: true);
        $this->authenticateAsCustomer($customer);

        $this->put(route('customer.workspaces.businesses.knowledge-profile.locations.hours', [$workspace->uid, $business->uid, $location->uid]), [
            'monday' => $fourPeriods,
            'tuesday' => [], 'wednesday' => [], 'thursday' => [], 'friday' => [], 'saturday' => [], 'sunday' => [],
        ])->assertRedirect();

        $this->assertCount(4, $location->fresh()->hours['monday']);
    }

    public function test_a_fifth_period_in_one_day_is_rejected_at_the_http_layer(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $location = $this->addLocation($business, ['is_primary' => true]);
        $this->authenticateAsCustomer($customer);

        $this->put(route('customer.workspaces.businesses.knowledge-profile.locations.hours', [$workspace->uid, $business->uid, $location->uid]), [
            'monday' => [
                ['open' => '00:00', 'close' => '02:00'],
                ['open' => '02:00', 'close' => '04:00'],
                ['open' => '04:00', 'close' => '06:00'],
                ['open' => '06:00', 'close' => '08:00'],
                ['open' => '08:00', 'close' => '10:00'],
            ],
        ])->assertSessionHasErrors();

        $this->assertNull($location->fresh()->hours);
    }

    public function test_hours_input_is_preserved_on_validation_failure(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $location = $this->addLocation($business, ['is_primary' => true]);
        $this->authenticateAsCustomer($customer);

        $this->put(route('customer.workspaces.businesses.knowledge-profile.locations.hours', [$workspace->uid, $business->uid, $location->uid]), [
            'monday' => [['open' => '17:00', 'close' => '09:00']],
            'tuesday' => [['open' => '09:00', 'close' => '17:00']],
        ])->assertSessionHasErrors();

        $edit = $this->get(route('customer.workspaces.businesses.knowledge-profile.edit', [$workspace->uid, $business->uid]));

        $edit->assertOk();
        $edit->assertSee('value="17:00"', false);
        $edit->assertSee('value="09:00"', false);
    }

    public function test_a_closed_day_with_zero_periods_saves_successfully(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $location = $this->addLocation($business, ['is_primary' => true]);
        $this->authenticateAsCustomer($customer);

        $this->put(route('customer.workspaces.businesses.knowledge-profile.locations.hours', [$workspace->uid, $business->uid, $location->uid]), [
            'monday' => [['open' => '09:00', 'close' => '17:00']],
            'sunday' => [],
        ])->assertRedirect();

        $this->assertSame([], $location->fresh()->hours['sunday']);
    }

    // -----------------------------------------------------------------
    // Correction (§9): null-safe edit view
    // -----------------------------------------------------------------

    public function test_the_edit_page_renders_with_no_profile_row(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $response = $this->get(route('customer.workspaces.businesses.knowledge-profile.edit', [$workspace->uid, $business->uid]));

        $response->assertOk();
        $this->assertSame(0, \App\Models\BusinessKnowledgeProfile::where('business_id', $business->id)->count());
    }

    // -----------------------------------------------------------------
    // Correction (§10): non-destructive partial updates
    // -----------------------------------------------------------------

    public function test_submitting_only_one_field_leaves_every_other_field_byte_identical(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $service = $this->addService($business);
        $location = $this->addLocation($business);
        $this->createVertical(['key' => 'roofing']);
        $manager = app(BusinessKnowledgeProfileManager::class);

        $manager->updateFields($business, [
            'vertical_key' => 'roofing',
            'pricing_method' => 'fixed',
            'financing_available' => true,
            'offers' => [['name' => 'Free estimate', 'description' => null, 'price_label' => null, 'pricing_method_override' => null]],
            'differentiators' => ['Family owned'],
            'ideal_customers' => 'Homeowners',
            'customer_problems' => ['Leaky roofs'],
            'credentials' => [['label' => 'Licensed', 'verified' => true]],
            'years_operating' => 12,
            'warranties_guarantees' => '10-year warranty',
            'primary_conversion_goal' => 'call',
            'conversion_target' => 'tel:+15551234567',
            'brand_voice' => 'Friendly',
            'prohibited_claims' => ['Never say cheapest'],
            'growth_priority_service_ids' => [$service->id],
            'growth_priority_location_ids' => [$location->id],
            'testimonials' => [['quote' => 'Great work', 'author_name' => 'Jane', 'author_title' => null]],
        ], 'manual_edit', $customer->user_id, markVerified: true);

        $before = \App\Models\BusinessKnowledgeProfile::where('business_id', $business->id)->first()->getAttributes();
        $this->authenticateAsCustomer($customer);

        $this->put(route('customer.workspaces.businesses.knowledge-profile.update', [$workspace->uid, $business->uid]), [
            'ideal_customers' => 'Renters',
        ])->assertRedirect();

        $after = \App\Models\BusinessKnowledgeProfile::where('business_id', $business->id)->first()->getAttributes();

        $this->assertSame('Renters', $after['ideal_customers']);
        unset($before['ideal_customers'], $after['ideal_customers'], $before['updated_at'], $after['updated_at']);
        $this->assertSame($before, $after);
    }
}

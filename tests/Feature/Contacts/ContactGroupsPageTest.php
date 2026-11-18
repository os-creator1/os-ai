<?php

namespace Tests\Feature\Contacts;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Models\Business;
use App\Models\ContactGroupFields;
use App\Models\ContactGroups;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Contacts → Groups, the MotionGrove "ContactGroups" layout: a server-rendered
 * list whose every control posts to an existing Contacts route inside the
 * selected Business.
 */
class ContactGroupsPageTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    public function test_the_groups_page_renders_the_designed_layout_with_real_data(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->group($business, 'Leads & Clients');
        $this->group($business, 'Past clients');
        $this->authenticateAs($owner);

        $page = $this->get($this->groupsUrl($workspace, $business))->assertOk();

        $page->assertSee('Groups keep your contacts organised for messages and automations.');
        $page->assertSee('data-role="groups-export"', false);
        $page->assertSee('data-role="groups-new"', false);
        $page->assertSee('data-role="groups-create-another"', false);
        $page->assertSee('Create another group');
        $page->assertSee('Search groups', false);
        $page->assertSee('Leads &amp; Clients', false);
        $page->assertSee('Past clients');
        $page->assertSee('0 contacts');
        $page->assertSee('role="switch"', false);
        $page->assertSee('aria-label="Group options: Past clients"', false);
        $page->assertSee('Add contacts');
        $page->assertSee('Rename');
        $page->assertSee('Duplicate');
        $page->assertSee('Export contacts');
        $page->assertSee('Delete group');
        $page->assertDontSee('datatables-basic', false);
        $this->assertSame(2, substr_count($page->getContent(), 'data-role="group-row" data-uid'));
    }

    public function test_the_tabs_mark_groups_current_with_a_live_count_and_link_back_to_all_contacts(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->group($business, 'One');
        $this->group($business, 'Two');
        $this->group($business, 'Three');
        $this->authenticateAs($owner);

        $page = $this->get($this->groupsUrl($workspace, $business))->assertOk();

        $page->assertSee('href="' . route('customer.workspaces.businesses.people.index', [$workspace->uid, $business->uid]) . '"', false);
        $page->assertSee('aria-current="page" href="' . $this->groupsUrl($workspace, $business) . '"', false);
        $page->assertSee('<span class="mgx-count" data-role="groups-count">3</span>', false);
    }

    public function test_the_list_and_the_count_hold_only_the_selected_businesss_groups(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        $other = $this->createIndependentWorkspaceBusiness($owner, 'Client Two', 'Client Two Workspace');
        $this->group($business, 'Visible Group');
        $this->group($other['business'], 'Elsewhere Group');
        $this->authenticateAs($owner);

        $page = $this->get($this->groupsUrl($workspace, $business))->assertOk();

        $page->assertSee('Visible Group');
        $page->assertDontSee('Elsewhere Group');
        $page->assertSee('<span class="mgx-count" data-role="groups-count">1</span>', false);
    }

    public function test_search_filters_by_name_treats_wildcards_literally_and_explains_no_match(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->group($business, 'Bridal list');
        $this->group($business, 'Supplier list');
        $this->authenticateAs($owner);
        $url = $this->groupsUrl($workspace, $business);

        $hit = $this->get($url . '?q=bridal')->assertOk();
        $hit->assertSee('Bridal list');
        $hit->assertDontSee('Supplier list');
        $hit->assertSee('value="bridal"', false);
        // The badge stays the Business's total, not the filtered count.
        $hit->assertSee('<span class="mgx-count" data-role="groups-count">2</span>', false);

        foreach (['%', '_'] as $wildcard) {
            $this->get($url . '?q=' . urlencode($wildcard))->assertOk()
                ->assertSee('data-role="groups-empty"', false)
                ->assertDontSee('Supplier list');
        }

        $this->get($url . '?q=nothing-like-this')->assertOk()
            ->assertSee('No groups match', false)
            ->assertDontSee('Bridal list');
    }

    public function test_the_empty_state_still_offers_to_create_a_group(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($owner);

        $this->get($this->groupsUrl($workspace, $business))->assertOk()
            ->assertSee('No groups yet.')
            ->assertSee('data-role="groups-new"', false);
    }

    public function test_controls_follow_the_actors_permissions(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->group($business, 'Leads');
        $url = $this->groupsUrl($workspace, $business);

        $this->authenticateAs($owner, ['view_contact_group']);
        $viewOnly = $this->get($url)->assertOk();
        $viewOnly->assertSee('Leads');
        $viewOnly->assertDontSee('data-role="groups-export"', false);
        $viewOnly->assertDontSee('data-role="groups-new"', false);
        $viewOnly->assertDontSee('data-role="groups-create-another"', false);
        $viewOnly->assertDontSee('data-action="delete"', false);
        $viewOnly->assertDontSee('data-action="rename"', false);
        $viewOnly->assertDontSee('data-action="duplicate"', false);
        $viewOnly->assertDontSee('aria-haspopup="menu"', false);
        $viewOnly->assertSee('role="switch"', false);
        $this->assertMatchesRegularExpression('/role="switch"[^>]*\sdisabled/', $viewOnly->getContent());

        $this->authenticateAs($owner, ['view_contact_group', 'update_contact_group', 'delete_contact_group']);
        $editor = $this->get($url)->assertOk();
        $editor->assertSee('data-action="rename"', false);
        $editor->assertSee('data-action="delete"', false);
        $editor->assertDontSee('data-action="duplicate"', false);
        $editor->assertDontSee('data-action="add"', false);
        $this->assertDoesNotMatchRegularExpression('/role="switch"[^>]*\sdisabled/', $editor->getContent());

        $this->authenticateAs($owner, []);
        $this->get($url)->assertUnauthorized();
    }

    public function test_row_actions_point_at_the_selected_businesss_existing_routes(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $group = $this->group($business, 'Leads');
        $this->authenticateAs($owner);

        $page = $this->get($this->groupsUrl($workspace, $business))->assertOk();
        $named = fn (string $name, array $extra = []) => route('customer.workspaces.businesses.' . $name, array_merge([$workspace->uid, $business->uid], $extra));
        $token = '__UID__';

        $page->assertSee('data-active-url="' . $named('contacts.active', [$token]) . '"', false);
        $page->assertSee('data-copy-url="' . $named('contacts.copy', [$token]) . '"', false);
        $page->assertSee('data-update-url="' . $named('contacts.update', [$token]) . '"', false);
        $page->assertSee('data-destroy-url="' . $named('contacts.destroy', [$token]) . '"', false);
        $page->assertSee('data-export-url="' . $named('contact.export', [$token]) . '"', false);
        $page->assertSee('href="' . $named('contact.create', [$group->uid]) . '"', false);
        $page->assertSee('href="' . $named('contacts.show', [$group->uid]) . '"', false);
        $page->assertSee('href="' . $named('contacts.create') . '"', false);
        $page->assertSee('href="' . $named('contacts.export') . '"', false);
    }

    public function test_the_export_action_carries_the_groups_own_field_tags(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $group = $this->group($business, 'Leads');
        $tags = ContactGroupFields::where('contact_group_id', $group->id)->pluck('tag')->all();
        $this->assertNotEmpty($tags);
        $this->authenticateAs($owner);

        $page = $this->get($this->groupsUrl($workspace, $business))->assertOk();

        $page->assertSee('data-fields="' . e(json_encode($tags)) . '"', false);
    }

    public function test_exporting_a_group_downloads_its_contacts_and_is_refused_across_businesses(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        $other = $this->createIndependentWorkspaceBusiness($owner, 'Client Two', 'Client Two Workspace');
        $own = $this->group($business, 'Own');
        $foreign = $this->group($other['business'], 'Foreign');
        $tags = ContactGroupFields::where('contact_group_id', $own->id)->pluck('tag')->all();
        $this->authenticateAs($owner);

        $this->post($this->actionUrl($workspace, $business, $own, '/export'), ['contact_fields' => $tags, 'include_phone' => 'true'])
            ->assertOk()
            ->assertDownload();

        $this->post($this->actionUrl($workspace, $business, $foreign, '/export'), ['contact_fields' => $tags])
            ->assertNotFound();
    }

    public function test_active_toggle_flips_the_group_and_is_refused_across_businesses(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        $other = $this->createIndependentWorkspaceBusiness($owner, 'Client Two', 'Client Two Workspace');
        $own = $this->group($business, 'Own');
        $foreign = $this->group($other['business'], 'Foreign');
        $this->authenticateAs($owner);

        $this->postJson($this->actionUrl($workspace, $business, $own, '/active'))
            ->assertOk()->assertJson(['status' => 'success']);
        $this->assertFalse($own->fresh()->status);

        $this->postJson($this->actionUrl($workspace, $business, $foreign, '/active'))->assertNotFound();
        $this->assertTrue($foreign->fresh()->status);

        $page = $this->get($this->groupsUrl($workspace, $business))->assertOk();
        $page->assertSee('aria-checked="false"', false);
        $page->assertSee('<small>Inactive</small>', false);
    }

    public function test_rename_from_the_list_returns_to_the_list_and_elsewhere_still_opens_the_group(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $group = $this->group($business, 'Old name');
        $this->authenticateAs($owner);

        $this->patch($this->actionUrl($workspace, $business, $group, ''), ['name' => 'New name', 'return' => 'index'])
            ->assertRedirect($this->groupsUrl($workspace, $business));
        $this->assertSame('New name', $group->fresh()->name);

        $this->patch($this->actionUrl($workspace, $business, $group, ''), ['name' => 'Third name'])
            ->assertRedirect($this->actionUrl($workspace, $business, $group, '/show'));
        $this->assertSame('Third name', $group->fresh()->name);

        // "return" is a navigation hint only, never persisted on the group.
        $this->assertArrayNotHasKey('return', $group->fresh()->getAttributes());
    }

    public function test_rename_rejects_a_blank_name_and_a_foreign_group(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        $other = $this->createIndependentWorkspaceBusiness($owner, 'Client Two', 'Client Two Workspace');
        $own = $this->group($business, 'Own');
        $foreign = $this->group($other['business'], 'Foreign');
        $this->authenticateAs($owner);

        $this->patch($this->actionUrl($workspace, $business, $own, ''), ['name' => '', 'return' => 'index'])
            ->assertSessionHasErrors('name');
        $this->assertSame('Own', $own->fresh()->name);

        $this->patch($this->actionUrl($workspace, $business, $foreign, ''), ['name' => 'Hijacked', 'return' => 'index'])
            ->assertNotFound();
        $this->assertSame('Foreign', $foreign->fresh()->name);
    }

    public function test_duplicate_creates_a_copy_in_the_same_business_and_is_refused_across_businesses(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        $other = $this->createIndependentWorkspaceBusiness($owner, 'Client Two', 'Client Two Workspace');
        $own = $this->group($business, 'Own');
        $foreign = $this->group($other['business'], 'Foreign');
        $this->authenticateAs($owner);

        $this->postJson($this->actionUrl($workspace, $business, $own, '/copy'), ['group_name' => 'Copy of Own'])
            ->assertOk()->assertJson(['status' => 'success']);
        $copy = ContactGroups::where('name', 'Copy of Own')->first();
        $this->assertNotNull($copy);
        $this->assertSame($business->id, (int) $copy->business_id);

        $this->postJson($this->actionUrl($workspace, $business, $foreign, '/copy'), ['group_name' => 'Stolen'])->assertNotFound();
        $this->assertNull(ContactGroups::where('name', 'Stolen')->first());
    }

    public function test_delete_removes_the_group_and_is_refused_across_businesses(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        $other = $this->createIndependentWorkspaceBusiness($owner, 'Client Two', 'Client Two Workspace');
        $own = $this->group($business, 'Own');
        $foreign = $this->group($other['business'], 'Foreign');
        $this->authenticateAs($owner);

        $this->deleteJson($this->actionUrl($workspace, $business, $foreign, ''))->assertNotFound();
        $this->assertNotNull(ContactGroups::find($foreign->id));

        $this->deleteJson($this->actionUrl($workspace, $business, $own, ''))
            ->assertOk()->assertJson(['status' => 'success']);
        $this->assertNull(ContactGroups::find($own->id));

        $this->get($this->groupsUrl($workspace, $business))->assertOk()->assertDontSee('data-uid="' . $own->uid . '"', false);
    }

    public function test_the_page_stays_free_of_inline_colour_literals_and_font_declarations(): void
    {
        $blade = file_get_contents(base_path('resources/views/customer/contactGroups/index.blade.php'));

        $this->assertSame(0, preg_match('/#[0-9A-Fa-f]{3,8}\b/', $blade));
        $this->assertSame(0, preg_match('/font-family\s*:/', $blade));
        $this->assertFileExists(public_path('css/crm/mgx-groups.css'));
    }

    private function groupsUrl(Workspace $workspace, Business $business): string
    {
        return route('customer.workspaces.businesses.contacts.index', [$workspace->uid, $business->uid]);
    }

    private function actionUrl(Workspace $workspace, Business $business, ContactGroups $group, string $suffix): string
    {
        $name = match ($suffix) {
            '' => 'contacts.update',
            '/show' => 'contacts.show',
            '/active' => 'contacts.active',
            '/copy' => 'contacts.copy',
            '/export' => 'contact.export',
        };

        return route('customer.workspaces.businesses.' . $name, [$workspace->uid, $business->uid, $group->uid]);
    }

    private function group(Business $business, string $name): ContactGroups
    {
        return ContactGroups::create([
            'customer_id' => $business->customer_id,
            'business_id' => $business->id,
            'name' => $name,
            'status' => true,
        ]);
    }
}

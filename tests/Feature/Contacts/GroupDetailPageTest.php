<?php

namespace Tests\Feature\Contacts;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Jobs\ImportContacts;
use App\Models\Business;
use App\Models\ContactGroupFields;
use App\Models\ContactGroups;
use App\Models\ContactGroupsOptinKeywords;
use App\Models\JobMonitor;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Group Details (Contacts → Groups → one group): the shared header and six
 * tabs of the MotionGrove design, over the page's existing behaviour.
 */
class GroupDetailPageTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    public function test_the_shared_header_and_all_six_tabs_render_for_the_group(): void
    {
        [$owner, $business, $workspace, $group] = $this->scene();
        $this->authenticateAs($owner);

        $page = $this->get($this->showUrl($workspace, $business, $group))->assertOk();

        // Render SVG controls through Feather, never inline Blade components
        // inside JavaScript string literals (their multiline SVG breaks parsing).
        $this->assertSame(2, substr_count($page->getContent(), "selectAllButtons.html(feather.icons["));
        $this->assertSame(2, substr_count($page->getContent(), "$(buttonElement).html(feather.icons["));

        $page->assertSee('data-role="group-header"', false);
        // Bootstrap's tab plugin only finds its tab group through `.nav` / `.list-group`.
        $page->assertSee('class="nav mgx-tabs mgx-gd-tabs"', false);
        $page->assertSee('href="' . route('customer.workspaces.businesses.contacts.index', [$workspace->uid, $business->uid]) . '"', false);
        $page->assertSee('<h1 class="mgx-title">Leads &amp; Clients</h1>', false);
        $page->assertSee('0 contacts');
        $page->assertSee('data-role="group-message"', false);
        $page->assertSee('data-role="group-add-contact"', false);
        $page->assertSee(route('customer.workspaces.businesses.contact.create', [$workspace->uid, $business->uid, $group->uid]), false);

        foreach (['contact' => 'Contacts', 'message' => 'Message', 'fields' => 'Fields', 'keywords' => 'Keywords', 'import-history' => 'Import history', 'settings' => 'Settings'] as $pane => $label) {
            $page->assertSee('id="' . $pane . '-tab-justified"', false);
            $page->assertSee('role="tabpanel"', false);
            $page->assertSee('aria-labelledby="' . $pane . '-tab-justified"', false);
            $page->assertSee('aria-controls="' . $pane . '"', false);
            $page->assertSee($label);
        }

        // Contacts is the landing tab.
        $this->assertMatchesRegularExpression('/class="mgx-tab active"\s+id="contact-tab-justified"/', $page->getContent());
        $this->assertDoesNotMatchRegularExpression('/class="mgx-tab active"\s+id="message-tab-justified"/', $page->getContent());
        $this->assertSame(6, substr_count($page->getContent(), 'data-bs-toggle="tab"'));
    }

    public function test_a_redirect_that_names_a_tab_opens_that_tab_and_keywords_cover_join_and_leave(): void
    {
        [$owner, $business, $workspace, $group] = $this->scene();
        $this->authenticateAs($owner);
        $url = $this->showUrl($workspace, $business, $group);

        $this->withSession(['_old_input' => ['tab' => 'message']])->get($url)->assertOk()
            ->assertSeeInOrder(['class="mgx-tab active"', 'id="message-tab-justified"'], false);

        $leave = $this->withSession(['_old_input' => ['tab' => 'opt_out_keywords']])->get($url)->assertOk();
        $this->assertMatchesRegularExpression('/class="mgx-tab active"\s+id="keywords-tab-justified"/', $leave->getContent());
        $this->assertMatchesRegularExpression('/data-kw-pane="opt_out_keywords"\s*>/', $leave->getContent());
        $this->assertMatchesRegularExpression('/data-kw-pane="opt_in_keywords"\s+hidden/', $leave->getContent());
    }

    public function test_the_header_shows_the_real_contact_count_and_last_import(): void
    {
        [$owner, $business, $workspace, $group] = $this->scene();
        $this->person($group, '+15555550101');
        $this->person($group, '+15555550102');
        $group->updateCache();
        $this->monitor($group);
        $this->authenticateAs($owner);

        $page = $this->get($this->showUrl($workspace, $business, $group))->assertOk();

        $page->assertSee('2 contacts · last import', false);
        $page->assertSee('<span class="mgx-count" data-role="group-contacts-count">2</span>', false);
    }

    public function test_the_message_tab_uses_the_real_form_variables_and_links(): void
    {
        [$owner, $business, $workspace, $group] = $this->scene();
        $group->update(['name' => 'Leads']);
        $this->authenticateAs($owner);

        $page = $this->get($this->showUrl($workspace, $business, $group))->assertOk();

        $page->assertSee('action="' . route('customer.workspaces.businesses.contacts.message', [$workspace->uid, $business->uid, $group->uid]) . '"', false);
        foreach (['signup_sms', 'welcome_sms', 'unsubscribe_sms'] as $form) {
            $page->assertSee('value="' . $form . '"', false);
        }
        foreach (ContactGroupFields::where('contact_group_id', $group->id)->get() as $field) {
            $page->assertSee('data-insert-tag="' . $field->tag . '"', false);
        }
        $page->assertSee('value="' . route('contacts.subscribe_url', $group->uid) . '"', false);
        $page->assertSee('value="' . route('contacts.unsubscribe_url', $group->uid) . '"', false);
        $page->assertSee('data-copy-target="#join-link"', false);
        $page->assertSee('data-role="message-count"', false);
        $page->assertDontSee('getmotiongrove.com/contacts/6adcc3e3d0069');
    }

    public function test_saving_a_message_persists_it_and_returns_to_the_message_tab(): void
    {
        [$owner, $business, $workspace, $group] = $this->scene();
        $this->authenticateAs($owner);
        $post = route('customer.workspaces.businesses.contacts.message', [$workspace->uid, $business->uid, $group->uid]);

        $this->post($post, ['message_form' => 'welcome_sms', 'message' => 'Hi {FIRST_NAME}, reply STOP to leave.'])
            ->assertRedirect($this->showUrl($workspace, $business, $group));
        $this->assertSame('Hi {FIRST_NAME}, reply STOP to leave.', $group->fresh()->welcome_sms);

        $this->post($post, ['message_form' => 'welcome_sms', 'message' => ''])->assertSessionHasErrors('message');
        $this->post($post, ['message_form' => 'not_a_form', 'message' => 'x'])->assertRedirect();
        $this->assertSame('Hi {FIRST_NAME}, reply STOP to leave.', $group->fresh()->welcome_sms);
    }

    public function test_the_fields_tab_lists_real_fields_saves_edits_and_serves_new_field_rows(): void
    {
        [$owner, $business, $workspace, $group] = $this->scene();
        $this->authenticateAs($owner);

        $page = $this->get($this->showUrl($workspace, $business, $group))->assertOk();
        $page->assertSee('Contact fields');
        $page->assertSee('class="table field-list"', false);
        $page->assertSee('{SUBSCRIBER_', false);
        foreach (['text', 'number', 'date', 'datetime', 'textarea'] as $type) {
            $page->assertSee('type_name="' . $type . '"', false);
        }
        $page->assertSee('action-delete-fields', false);

        $field = ContactGroupFields::where('contact_group_id', $group->id)->where('tag', 'FIRST_NAME')->firstOrFail();
        $payload = ['fields' => [$field->uid => [
            'uid' => $field->uid, 'label' => 'Given name', 'type' => $field->type, 'tag' => $field->tag, 'required' => '1', 'visible' => '1',
        ]]];
        $this->post(route('customer.workspaces.businesses.contact.store-contact-field', [$workspace->uid, $business->uid, $group->uid]), $payload)
            ->assertRedirect($this->showUrl($workspace, $business, $group));
        $this->assertSame('Given name', $field->fresh()->label);

        $this->get(route('customer.workspaces.businesses.contact.contact-sample-field', [$workspace->uid, $business->uid, $group->uid, 'text']) . '?type=text')
            ->assertOk()->assertSee('__index__', false);
    }

    public function test_the_keywords_tab_shows_the_designed_empty_state_and_then_the_real_listing(): void
    {
        [$owner, $business, $workspace, $group] = $this->scene();
        $this->authenticateAs($owner);
        $url = $this->showUrl($workspace, $business, $group);

        $empty = $this->get($url)->assertOk();
        $empty->assertSee('data-role="keywords-empty-in"', false);
        $empty->assertSee('data-role="keywords-empty-out"', false);
        $empty->assertSee('No join keywords yet');
        $empty->assertSee('STOP always works for leaving');
        $this->assertGreaterThanOrEqual(2, substr_count($empty->getContent(), 'add_opt_in_keyword'));
        $this->assertSame(0, preg_match('/\+1 \(\d{3}\) \d{3}-\d{4}/', $empty->getContent()));

        ContactGroupsOptinKeywords::create([
            'uid' => uniqid(), 'contact_group' => $group->id, 'user_id' => $group->customer_id,
            'keyword' => 'BOOTH', 'status' => 'assigned', 'reply_text' => 'x',
        ]);
        $listed = $this->get($url)->assertOk();
        $listed->assertSee('BOOTH');
        $listed->assertSee('action-delete-optin-keyword', false);
        $listed->assertDontSee('data-role="keywords-empty-in"', false);
    }

    public function test_the_import_history_tab_shows_the_empty_state_then_the_real_runs(): void
    {
        [$owner, $business, $workspace, $group] = $this->scene();
        $this->authenticateAs($owner);
        $url = $this->showUrl($workspace, $business, $group);

        $empty = $this->get($url)->assertOk();
        $empty->assertSee('data-role="imports-empty"', false);
        $empty->assertSee('No imports yet');
        $empty->assertSee('href="' . route('customer.workspaces.businesses.contact.import', [$workspace->uid, $business->uid, $group->uid]) . '"', false);
        $empty->assertSee('data-role="imports-template"', false);
        $empty->assertSee('data-template-headers="', false);

        $this->monitor($group);
        $populated = $this->get($url)->assertOk();
        $populated->assertDontSee('data-role="imports-empty"', false);
        $populated->assertSee('class="table mb-0"', false);
        $populated->assertSee('data-role="imports-import"', false);
    }

    public function test_settings_persist_and_the_destructive_action_is_separate_and_confirmed(): void
    {
        [$owner, $business, $workspace, $group] = $this->scene();
        $this->authenticateAs($owner);

        $page = $this->get($this->showUrl($workspace, $business, $group))->assertOk();
        $page->assertSee('name="send_welcome_sms"', false);
        $page->assertSee('name="unsubscribe_notification"', false);
        $page->assertSee('data-role="group-delete"', false);
        $page->assertSee('data-delete-url="' . route('customer.workspaces.businesses.contacts.destroy', [$workspace->uid, $business->uid, $group->uid]) . '"', false);
        // Delete is its own control, never a submit button inside the settings form.
        $this->assertDoesNotMatchRegularExpression('/<button type="submit"[^>]*data-role="group-delete"/', $page->getContent());
        // The click handler always asks first.
        $page->assertSee('Swal.fire', false);

        $this->put(route('customer.workspaces.businesses.contacts.update', [$workspace->uid, $business->uid, $group->uid]), [
            'name' => 'Renamed', 'sender_id' => 'Studio', 'send_welcome_sms' => '1',
        ])->assertRedirect();
        $fresh = $group->fresh();
        $this->assertSame('Renamed', $fresh->name);
        $this->assertSame('Studio', $fresh->sender_id);
        $this->assertTrue($fresh->send_welcome_sms);
        $this->assertFalse($fresh->unsubscribe_notification);

        $this->put(route('customer.workspaces.businesses.contacts.update', [$workspace->uid, $business->uid, $group->uid]), ['name' => ''])
            ->assertSessionHasErrors('name');
        $this->assertSame('Renamed', $group->fresh()->name);
    }

    public function test_destructive_and_management_controls_follow_permissions(): void
    {
        [$owner, $business, $workspace, $group] = $this->scene();
        $url = $this->showUrl($workspace, $business, $group);

        $this->authenticateAs($owner, ['view_contact_group', 'view_contact', 'update_contact_group']);
        $editor = $this->get($url)->assertOk();
        $editor->assertDontSee('data-delete-url=', false);
        $editor->assertDontSee('data-role="group-add-contact"', false);
        $editor->assertDontSee('id="import-history-tab-justified"', false);
        $editor->assertSee('id="settings-tab-justified"', false);

        $this->authenticateAs($owner, ['view_contact_group', 'view_contact']);
        $viewer = $this->get($url)->assertOk();
        foreach (['message', 'fields', 'keywords', 'settings'] as $pane) {
            $viewer->assertDontSee('id="' . $pane . '-tab-justified"', false);
        }
        $viewer->assertSee('id="contact-tab-justified"', false);
    }

    public function test_another_businesss_group_never_renders(): void
    {
        [$owner, $business, $workspace] = $this->scene(false);
        $other = $this->createIndependentWorkspaceBusiness($owner, 'Client Two', 'Client Two Workspace');
        $foreign = $this->group($other['business'], 'Foreign');
        $this->authenticateAs($owner);

        $this->get($this->showUrl($workspace, $business, $foreign))->assertNotFound();
    }

    public function test_the_page_is_scoped_free_of_inline_colour_literals(): void
    {
        foreach (['show', '_group_header', '_message', '_fields', '_keywords_list', '_import_history', '_settings', '_contacts'] as $view) {
            $blade = file_get_contents(base_path("resources/views/customer/contactGroups/{$view}.blade.php"));
            $this->assertSame(0, preg_match('/#[0-9A-Fa-f]{3,8}\b/', $blade), $view);
            $this->assertSame(0, preg_match('/font-family\s*:/', $blade), $view);
        }
        $css = preg_replace('#/\*.*?\*/#s', '', file_get_contents(public_path('css/crm/mgx-group-detail.css')));
        $css = preg_replace('/@media[^{]*\{/', '', $css);
        foreach (explode('}', $css) as $block) {
            if (! str_contains($block, '{')) {
                continue;
            }
            foreach (explode(',', trim(explode('{', $block)[0])) as $selector) {
                $this->assertMatchesRegularExpression('/^(\.mgx-|\[data-layout="dark-layout"\] \.mgx-gd)/', trim($selector), "Unscoped selector: {$selector}");
            }
        }
    }

    /** @return array{0: \App\Models\Customer, 1: Business, 2: Workspace, 3: ContactGroups} */
    private function scene(bool $withGroup = true): array
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');

        return [$owner, $business, $workspace, $this->group($business, 'Leads & Clients')];
    }

    private function showUrl(Workspace $workspace, Business $business, ContactGroups $group): string
    {
        return route('customer.workspaces.businesses.contacts.show', [$workspace->uid, $business->uid, $group->uid]);
    }

    private function group(Business $business, string $name): ContactGroups
    {
        return ContactGroups::create(['customer_id' => $business->customer_id, 'business_id' => $business->id, 'name' => $name, 'status' => true]);
    }

    private function person(ContactGroups $group, string $phone): \App\Models\Contacts
    {
        return \App\Models\Contacts::create(['uid' => uniqid(), 'group_id' => $group->id, 'customer_id' => $group->customer_id, 'phone' => $phone, 'status' => 'subscribe']);
    }

    private function monitor(ContactGroups $group): void
    {
        $monitor = JobMonitor::makeInstance($group, ImportContacts::class);
        $monitor->save();
        $monitor->setJsonData(['total' => 3, 'processed' => 3, 'failed' => 0, 'message' => 'done']);
    }
}

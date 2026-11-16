<?php

namespace Tests\Feature\Conversations;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Timeline\ContactActivityTimeline;
use App\Library\Timeline\TimelineItem;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\ContactGroups;
use App\Models\Contacts;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Conversations\Concerns\CreatesTimelineFixtures;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Manual contact edits (name, canonical email, phone, group) are recorded in the append-only
 * `contact_edit_events` table and shown, dated, in the lead timeline — only real changes, never history
 * that was not recorded, and only for the one contact the conversation resolves to.
 */
class ContactEditActivityTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;
    use CreatesTimelineFixtures;

    private function route(string $name, Workspace $workspace, Business $business, string $uid): string
    {
        return route('customer.workspaces.businesses.people.' . $name, [$workspace->uid, $business->uid, $uid]);
    }

    private function group(Business $business, string $name = 'Leads'): ContactGroups
    {
        if (! BusinessLocation::query()->where('business_id', $business->id)->exists()) {
            BusinessLocation::create(['business_id' => $business->id, 'name' => 'Main', 'service_mode' => 'storefront', 'country_code' => 'US']);
        }

        return ContactGroups::create(['customer_id' => $business->customer_id, 'business_id' => $business->id, 'name' => $name, 'status' => true]);
    }

    private function person(Workspace $workspace, Business $business, ContactGroups $group): Contacts
    {
        $response = $this->postJson(route('customer.workspaces.businesses.people.store', [$workspace->uid, $business->uid]), [
            'country' => 'US', 'phone' => '(601) 692-6705', 'first_name' => 'John', 'last_name' => 'Sheppard',
            'email' => 'john@example.com', 'group' => $group->uid, 'notes' => '',
        ])->assertOk();

        return Contacts::query()->where('uid', $response->json('contact.uid'))->firstOrFail();
    }

    private function payload(array $override = []): array
    {
        return $override + ['country' => 'US', 'phone' => '(601) 692-6705', 'first_name' => 'John', 'last_name' => 'Sheppard', 'email' => 'john@example.com', 'company' => '', 'notes' => ''];
    }

    /** @return list<string> */
    private function editLines(Business $business, Contacts $contact): array
    {
        $box = $this->conversationWith($business, (string) $contact->fresh()->phone);
        $page = app(ContactActivityTimeline::class)->forConversation($business, $box, $box->resolveDisplayContact($business));

        return array_values(array_map(
            static fn (TimelineItem $i): string => $i->title . ' — ' . $i->detail,
            array_filter($page->items, static fn (TimelineItem $i): bool => str_starts_with($i->key, 'contact_edit:')),
        ));
    }

    public function test_a_manual_edit_records_each_changed_field_and_the_timeline_shows_it(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $leads = $this->group($business);
        $clients = $this->group($business, 'Clients');
        $this->authenticateAs($owner);
        $contact = $this->person($workspace, $business, $leads);

        $this->assertSame(0, DB::table('contact_edit_events')->count(), 'creating a contact is not an edit and nothing is backfilled');
        $this->assertSame([], $this->editLines($business, $contact));

        $this->postJson($this->route('update', $workspace, $business, $contact->uid), $this->payload([
            'first_name' => 'Jon', 'email' => 'Jon@Example.com', 'phone' => '(720) 664-0969', 'group' => $clients->uid,
        ]))->assertOk();

        $rows = DB::table('contact_edit_events')->where('contact_id', $contact->id)->orderBy('id')->get();
        $this->assertSame(['name', 'email', 'phone', 'group'], $rows->pluck('field')->all());
        $this->assertSame('John Sheppard', $rows[0]->old_value);
        $this->assertSame('Jon Sheppard', $rows[0]->new_value);
        $this->assertSame((int) $business->id, (int) $rows[0]->business_id);
        $this->assertSame((int) \Illuminate\Support\Facades\Auth::id(), (int) $rows[0]->actor_user_id, 'the acting user is recorded');

        $lines = $this->editLines($business, $contact);
        $this->assertEqualsCanonicalizing([
            'Name changed — John Sheppard → Jon Sheppard',
            'Email changed — john@example.com → jon@example.com',
            'Phone changed — +16016926705 → +17206640969',
            'Group changed — Leads → Clients',
        ], $lines);
    }

    public function test_saving_without_changes_and_failed_saves_record_nothing(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $group = $this->group($business);
        $this->authenticateAs($owner);
        $contact = $this->person($workspace, $business, $group);

        $this->postJson($this->route('update', $workspace, $business, $contact->uid), $this->payload())->assertOk();
        $this->postJson($this->route('update', $workspace, $business, $contact->uid), $this->payload(['email' => 'nope', 'first_name' => 'X']))->assertStatus(422);

        $this->assertSame(0, DB::table('contact_edit_events')->count());
    }

    public function test_the_profile_email_form_records_added_changed_and_removed(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $group = $this->group($business);
        $this->authenticateAs($owner);
        $contact = $this->person($workspace, $business, $group);

        $this->post($this->route('email.update', $workspace, $business, $contact->uid), ['email' => 'new@example.com'])->assertRedirect();
        $this->post($this->route('email.update', $workspace, $business, $contact->uid), ['email' => 'new@example.com'])->assertRedirect();
        $this->post($this->route('email.update', $workspace, $business, $contact->uid), ['email' => ''])->assertRedirect();

        $this->assertSame([
            'Email changed — john@example.com → new@example.com',
            'Email removed — Was new@example.com',
        ], $this->editLines($business, $contact));
    }


    public function test_profile_email_and_audit_event_roll_back_together_if_recording_fails(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $group = $this->group($business);
        $this->authenticateAs($owner);
        $contact = $this->person($workspace, $business, $group);

        // Simulate a database failure immediately after INSERT; neither the
        // canonical email value nor its audit event may survive the transaction.
        $failOnce = true;
        DB::listen(function (\Illuminate\Database\Events\QueryExecuted $query) use (&$failOnce): void {
            if ($failOnce && preg_match('/\\binsert\\s+into\\s+[`"]?contact_edit_events/i', $query->sql)) {
                $failOnce = false;
                throw new \RuntimeException('Simulated contact audit failure');
            }
        });

        $this->post($this->route('email.update', $workspace, $business, $contact->uid), ['email' => 'changed@example.com'])
            ->assertStatus(500);

        $this->assertSame('john@example.com', app(\App\Library\Contacts\ContactEmailIdentity::class)->status($contact)['email']);
        $this->assertSame(0, DB::table('contact_edit_events')->count());
    }

    public function test_without_permission_nothing_is_recorded_and_other_contacts_never_leak(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $group = $this->group($business);
        $this->authenticateAs($owner);
        $contact = $this->person($workspace, $business, $group);

        $this->authenticateAs($owner, ['view_contact']);
        $this->postJson($this->route('update', $workspace, $business, $contact->uid), $this->payload(['first_name' => 'Nope']))->assertStatus(401);
        $this->assertSame(0, DB::table('contact_edit_events')->count());

        // An event on a different contact (or Business) is never shown on this contact's timeline.
        $other = $this->namedContact($business, '12025550999', 'Other', 'Person');
        DB::table('contact_edit_events')->insert(['business_id' => $business->id, 'contact_id' => $other->id, 'field' => 'name', 'old_value' => 'A', 'new_value' => 'B', 'created_at' => now()]);
        $this->assertSame([], $this->editLines($business, $contact));
    }
}

<?php

namespace Tests\Feature\Conversations;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Timeline\ContactActivityTimeline;
use App\Library\Timeline\TimelineItem;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\ChatBox;
use App\Models\ContactGroups;
use App\Models\Contacts;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Conversations\Concerns\CreatesTimelineFixtures;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Changing a lead's phone must not orphan their SMS conversation: the Contact link is written only
 * when it is verified (the old number belongs to exactly this one Contact of the Business), historical
 * numbers are never rewritten, and nothing is ever matched by name or across Businesses.
 */
class ContactPhoneChangeTimelineTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;
    use CreatesTimelineFixtures;

    private const OLD = '16016926705';

    private function route(string $name, Workspace $workspace, Business $business, string $uid): string
    {
        return route('customer.workspaces.businesses.people.' . $name, [$workspace->uid, $business->uid, $uid]);
    }

    private function group(Business $business): ContactGroups
    {
        if (! BusinessLocation::query()->where('business_id', $business->id)->exists()) {
            BusinessLocation::create(['business_id' => $business->id, 'name' => 'Main', 'service_mode' => 'storefront', 'country_code' => 'US']);
        }

        return ContactGroups::create(['customer_id' => $business->customer_id, 'business_id' => $business->id, 'name' => 'Leads', 'status' => true]);
    }

    private function person(Workspace $workspace, Business $business, ContactGroups $group): Contacts
    {
        $response = $this->postJson(route('customer.workspaces.businesses.people.store', [$workspace->uid, $business->uid]), [
            'country' => 'US', 'phone' => '(601) 692-6705', 'first_name' => 'John', 'last_name' => 'Sheppard',
            'email' => 'john@example.com', 'group' => $group->uid, 'notes' => '',
        ])->assertOk();

        return Contacts::query()->where('uid', $response->json('contact.uid'))->firstOrFail();
    }

    private function changePhone(Workspace $workspace, Business $business, Contacts $contact, string $national = '(720) 664-0969')
    {
        return $this->postJson($this->route('update', $workspace, $business, $contact->uid), [
            'country' => 'US', 'phone' => $national, 'first_name' => 'John', 'last_name' => 'Sheppard',
            'email' => 'john@example.com', 'company' => '', 'notes' => '',
        ]);
    }

    private function emailFor(Business $business, Contacts $contact): void
    {
        DB::table('business_email_messages')->insert([
            'uid' => (string) Str::uuid(), 'business_id' => $business->id, 'contact_id' => $contact->id, 'direction' => 'outbound',
            'location_id' => BusinessLocation::query()->where('business_id', $business->id)->value('id'),
            'operation_key' => 'k1', 'provider' => 'google', 'from_email' => 'owner@business.test', 'to_email' => 'john@example.com',
            'subject' => 'Your quote', 'body_text' => 'Hi John', 'status' => 'accepted', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return list<string> */
    private function timelineKeys(Business $business, ChatBox $box): array
    {
        $box = $box->fresh();
        $page = app(ContactActivityTimeline::class)->forConversation($business, $box, $box->resolveDisplayContact($business));

        return array_map(static fn (TimelineItem $i): string => $i->key . '|' . $i->title . '|' . $i->body, $page->items);
    }

    public function test_a_phone_change_keeps_the_conversation_attached_with_its_history(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $group = $this->group($business);
        $this->authenticateAs($owner);
        $contact = $this->person($workspace, $business, $group);
        $box = $this->conversationWith($business, self::OLD);
        $this->message($box, 'incoming', 'Do you have Saturday slots?', Carbon::parse('2026-09-02 10:00:00'));
        $this->emailFor($business, $contact);

        $this->assertSame((int) $contact->id, (int) $box->resolveDisplayContact($business)?->id);

        $this->changePhone($workspace, $business, $contact)->assertOk();

        $this->assertSame('17206640969', (string) Contacts::query()->find($contact->id)->phone);
        $fresh = $box->fresh();
        $this->assertSame(self::OLD, (string) $fresh->to, 'the historical recipient number is never rewritten');
        $this->assertSame((int) $contact->id, (int) $fresh->contact_id);
        $this->assertSame((int) $contact->id, (int) $fresh->resolveDisplayContact($business)?->id, 'still the same lead after the number changed');

        $joined = implode("\n", $this->timelineKeys($business, $box));
        $this->assertStringContainsString('Do you have Saturday slots?', $joined, 'the conversation messages remain');
        $this->assertStringContainsString('Your quote', $joined, 'the linked email remains');
        $this->assertStringContainsString('Phone changed', $joined, 'the manual edit is shown');
        $this->assertStringContainsString('Added to contacts', $joined);
    }

    public function test_the_new_holder_of_the_old_number_is_never_mistaken_for_the_lead(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $group = $this->group($business);
        $this->authenticateAs($owner);
        $contact = $this->person($workspace, $business, $group);
        $box = $this->conversationWith($business, self::OLD);

        $this->changePhone($workspace, $business, $contact)->assertOk();

        $other = $this->namedContact($business, self::OLD, 'New', 'Owner');
        $this->assertSame((int) $contact->id, (int) $box->fresh()->resolveDisplayContact($business)?->id);

        // A conversation that was never linked is not guessed onto the new holder by name or history.
        $unlinked = $this->conversationWith($business, '12025550100');
        $this->assertNull($unlinked->resolveDisplayContact($business));
        $this->assertNotSame((int) $other->id, (int) $box->fresh()->resolveDisplayContact($business)?->id);
    }

    public function test_ambiguous_numbers_are_never_linked(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $group = $this->group($business);
        $this->authenticateAs($owner);
        $contact = $this->person($workspace, $business, $group);
        $this->namedContact($business, self::OLD, 'Twin', 'Number');
        $box = $this->conversationWith($business, self::OLD);

        $this->assertNull($box->resolveDisplayContact($business), 'two same-number contacts: no name is shown');

        $this->changePhone($workspace, $business, $contact)->assertOk();

        $this->assertNull($box->fresh()->contact_id, 'ambiguous number: nothing is linked');
        $this->assertNotSame((int) $contact->id, (int) $box->fresh()->resolveDisplayContact($business)?->id);
    }

    public function test_another_business_is_never_linked_or_resolved(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $group = $this->group($business);
        $this->authenticateAs($owner);
        $contact = $this->person($workspace, $business, $group);

        [, $foreignBusiness] = $this->tenant(WorkspacePlanTier::Growth);
        $foreignBox = $this->conversationWith($foreignBusiness, self::OLD);

        $this->changePhone($workspace, $business, $contact)->assertOk();
        $this->assertNull($foreignBox->fresh()->contact_id, 'a sibling Business conversation on the same number is untouched');

        // Even a forged link to another Business's contact never resolves.
        DB::table('chat_boxes')->where('id', $foreignBox->id)->update(['contact_id' => $contact->id]);
        $this->assertNull($foreignBox->fresh()->resolveDisplayContact($foreignBusiness));
    }

    public function test_without_permission_or_with_an_invalid_save_nothing_is_linked(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $group = $this->group($business);
        $this->authenticateAs($owner);
        $contact = $this->person($workspace, $business, $group);
        $box = $this->conversationWith($business, self::OLD);

        $this->changePhone($workspace, $business, $contact, '720 664')->assertStatus(422);
        $this->assertNull($box->fresh()->contact_id);

        $this->authenticateAs($owner, ['view_contact']);
        $this->changePhone($workspace, $business, $contact)->assertStatus(401);
        $this->assertNull($box->fresh()->contact_id);
        $this->assertSame(self::OLD, (string) Contacts::query()->find($contact->id)->phone);
    }
}

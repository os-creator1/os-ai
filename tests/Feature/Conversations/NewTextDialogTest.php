<?php

namespace Tests\Feature\Conversations;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Messaging\ManagedMessageDispatcher;
use App\Models\ChatBox;
use App\Models\BusinessLocation;
use App\Models\ChatBoxMessage;
use App\Models\ContactGroups;
use App\Models\Contacts;
use App\Models\Country;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\Feature\PlatformBilling\Concerns\CreatesPlatformSubscriptions;
use Tests\TestCase;

/**
 * Conversations -> "New text" dialog. The dialog reads the existing compose state as JSON, suggests this Business's
 * contacts, and posts to the existing `conversations.sent` action with Accept: application/json. Every provider call
 * here goes through the fake messaging adapter: no live SMS, no paid API.
 */
class NewTextDialogTest extends TestCase
{
    use CreatesPlatformSubscriptions;
    use CreatesMessagingFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdminId();
        $this->ensureRequiredAppConfigRowsExist();
        $this->bindFakeStripe();
        $this->bindFakeAdapter();
    }

    /** @return array{customer: \App\Models\Customer, workspace: \App\Models\Workspace, business: \App\Models\Business, number: \App\Models\BusinessMessagingNumber} */
    private function tenant(string $name = 'Dialog Business'): array
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $business = $this->addBusiness($fixture['customer'], $fixture['workspace'], $name, BusinessStatus::Active);
        $identity = $this->attachIdentity($business);
        $number = $this->attachNumber($identity, $this->uniqueNumber(), true);

        return ['customer' => $fixture['customer'], 'workspace' => $fixture['workspace'], 'business' => $business, 'number' => $number];
    }

    private function us(): Country
    {
        return Country::firstOrCreate(['country_code' => '1', 'iso_code' => 'US'], ['name' => 'United States', 'status' => 1]);
    }

    private function url(string $name, array $t): string
    {
        return route('customer.workspaces.businesses.conversations.' . $name, [$t['workspace']->uid, $t['business']->uid]);
    }

    private function contact(array $t, string $first, string $last, string $phone, string $groupName = 'Leads & Clients'): Contacts
    {
        if (! BusinessLocation::query()->where('business_id', $t['business']->id)->exists()) {
            BusinessLocation::create(['business_id' => $t['business']->id, 'name' => 'Main', 'service_mode' => 'storefront', 'country_code' => 'US']);
        }

        $group = ContactGroups::query()->where('business_id', $t['business']->id)->where('name', $groupName)->first()
            ?? ContactGroups::create(['customer_id' => $t['business']->customer_id, 'business_id' => $t['business']->id, 'name' => $groupName, 'status' => true]);

        $response = $this->postJson(route('customer.workspaces.businesses.people.store', [$t['workspace']->uid, $t['business']->uid]), [
            'country' => 'US', 'phone' => $phone, 'first_name' => $first, 'last_name' => $last, 'email' => '', 'group' => $group->uid, 'notes' => '',
        ])->assertOk();

        return Contacts::query()->where('uid', $response->json('contact.uid'))->firstOrFail();
    }

    private function send(array $t, array $override = [], ?string $token = null)
    {
        return $this->postJson($this->url('sent', $t), $override + [
            'sms_type' => 'plain',
            'country_code' => (string) $this->us()->id,
            'recipient' => '4155559999',
            'message' => 'Hello from the dialog',
            'idempotency_token' => $token ?? (string) Str::uuid(),
        ]);
    }

    public function test_the_compose_state_is_served_as_json_with_the_active_business_texting_number(): void
    {
        $t = $this->tenant();
        $this->authenticateAs($t['customer']);
        $us = $this->us();

        $json = $this->getJson($this->url('new', $t))->assertOk()->json();

        $this->assertSame('success', $json['status']);
        $this->assertTrue($json['managed']);
        $this->assertSame($t['number']->phone_number, $json['from']);
        $this->assertContains((int) $us->id, array_column($json['countries'], 'id'));
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $json['idempotency_token']);
    }

    public function test_a_business_that_is_not_ready_to_text_gets_the_reason_as_json_not_a_redirect(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $business = $this->addBusiness($fixture['customer'], $fixture['workspace'], 'Not Yet Set Up', BusinessStatus::Active);
        $this->authenticateAs($fixture['customer']);

        $this->getJson(route('customer.workspaces.businesses.conversations.new', [$fixture['workspace']->uid, $business->uid]))
            ->assertStatus(422)
            ->assertJsonPath('message', __('locale.conversations.send_failure.messaging_not_ready'));
    }

    public function test_the_inbox_renders_the_dialog_and_its_launch_controls(): void
    {
        $t = $this->tenant();
        $this->authenticateAs($t['customer']);

        $html = $this->get($this->url('index', $t))->assertOk()->getContent();

        $this->assertStringContainsString('id="new-text-dialog"', $html);
        $this->assertStringContainsString('data-role="new-text-launch"', $html);
        $this->assertStringContainsString('Use a template', $html);
        $this->assertStringContainsString('Opens in Conversations after sending.', $html);
        $this->assertStringContainsString('data-role="nt-send" disabled', $html);
        $this->assertStringContainsString('sms-counter.js', $html);
        // The launch control is still a working link without JavaScript.
        $this->assertMatchesRegularExpression('/data-role="new-text-launch"[^>]*href="[^"]*conversations\/new"/', $html);
    }

    public function test_contact_suggestions_tell_same_named_people_apart_and_stay_inside_the_business(): void
    {
        $t = $this->tenant();
        $other = $this->tenant('Other Business');
        $this->authenticateAs($t['customer']);
        $first = $this->contact($t, 'Amira', 'Misurati', '(970) 825-9551');
        $second = $this->contact($t, 'Amira', 'Misurati', '(720) 664-0969', 'Wedding Leads');

        $rows = $this->getJson($this->url('recipients', $t) . '?q=Amir')->assertOk()->json('contacts');

        $this->assertCount(2, $rows);
        $byUid = array_column($rows, null, 'uid');
        $this->assertSame('+19708259551', $byUid[$first->uid]['phone']);
        $this->assertSame('Leads & Clients', $byUid[$first->uid]['group']);
        $this->assertSame('+17206640969', $byUid[$second->uid]['phone']);
        $this->assertSame('Wedding Leads', $byUid[$second->uid]['group']);
        $this->assertSame('1', $byUid[$first->uid]['country_code']);
        $this->assertSame('9708259551', $byUid[$first->uid]['national']);
        $this->assertFalse($byUid[$first->uid]['opted_out']);

        // Too short a query suggests nothing; another Business's lookup never sees these people.
        $this->getJson($this->url('recipients', $t) . '?q=A')->assertOk()->assertExactJson(['contacts' => []]);
        $this->authenticateAs($other['customer']);
        $this->getJson($this->url('recipients', $other) . '?q=Amir')->assertOk()->assertExactJson(['contacts' => []]);
    }

    public function test_sending_to_a_suggested_contact_opens_that_conversation_and_stores_the_message_once(): void
    {
        $t = $this->tenant();
        $this->authenticateAs($t['customer']);
        $contact = $this->contact($t, 'Amira', 'Misurati', '(970) 825-9551');
        $suggestion = $this->getJson($this->url('recipients', $t) . '?q=Amir')->json('contacts.0');
        $this->assertSame($contact->uid, $suggestion['uid']);

        $json = $this->send($t, ['recipient' => $suggestion['national'], 'message' => 'Updated quote tonight.'])->assertOk()->json();

        $this->assertSame('success', $json['status']);
        $box = ChatBox::where('business_id', $t['business']->id)->firstOrFail();
        $this->assertSame($box->uid, $json['conversation_uid']);
        $this->assertCount(1, $this->fakeAdapter->sentRequests);
        $this->assertSame('+19708259551', $this->fakeAdapter->sentRequests[0]->toNumber);
        $this->assertSame($t['number']->phone_number, $this->fakeAdapter->sentRequests[0]->fromNumber);
        $this->assertSame(1, ChatBoxMessage::where('box_id', $box->id)->where('message', 'Updated quote tonight.')->count());
    }

    public function test_sending_to_a_typed_number_uses_the_same_path_and_normalization(): void
    {
        $t = $this->tenant();
        $this->authenticateAs($t['customer']);

        $json = $this->send($t, ['recipient' => '415 555 9999'])->assertOk()->json();

        $this->assertSame('success', $json['status']);
        $this->assertNotNull($json['conversation_uid']);
        $this->assertSame('+14155559999', $this->fakeAdapter->sentRequests[0]->toNumber);
        $this->assertSame(1, ChatBox::where('business_id', $t['business']->id)->count());
    }

    public function test_a_duplicate_submit_with_the_same_token_dispatches_once(): void
    {
        $t = $this->tenant();
        $this->authenticateAs($t['customer']);
        $token = (string) Str::uuid();

        $this->send($t, [], $token)->assertOk();
        $this->send($t, [], $token);

        $this->assertCount(1, $this->fakeAdapter->sentRequests);
        $this->assertSame(1, DB::table(ManagedMessageDispatcher::TABLE)->where('business_id', (int) $t['business']->id)->count());
        $this->assertSame(1, ChatBoxMessage::query()->count());
    }

    public function test_a_failed_send_is_a_json_error_with_an_actionable_message_and_no_conversation_message(): void
    {
        $t = $this->tenant();
        $this->authenticateAs($t['customer']);
        $this->fakeAdapter->rejections['*'] = \App\Enums\Messaging\ProviderErrorCategory::Terminal;

        $res = $this->send($t, ['message' => 'This draft must survive'])->assertStatus(422);

        $this->assertSame('error', $res->json('status'));
        $this->assertSame(\App\Library\Conversations\ConversationSendFailureReason::SendFailed->customerMessage(), $res->json('message'));
        $this->assertNull($res->json('conversation_uid'));
        $this->assertStringNotContainsString('locale.', (string) $res->json('message'));
    }

    public function test_a_blank_message_or_recipient_is_refused_as_json_validation_errors(): void
    {
        $t = $this->tenant();
        $this->authenticateAs($t['customer']);

        // The app answers a failed validation with {status: error, message: <first error>} (422).
        $this->send($t, ['message' => ''])->assertStatus(422)->assertJsonPath('status', 'error')->assertJsonPath('message', 'The message field is required.');
        $this->send($t, ['recipient' => ''])->assertStatus(422)->assertJsonPath('status', 'error')->assertJsonPath('message', 'The recipient field is required.');
        $this->assertCount(0, $this->fakeAdapter->sentRequests);
    }

    public function test_an_unsubscribed_contact_is_marked_in_suggestions_and_refused_on_send(): void
    {
        $t = $this->tenant();
        $this->authenticateAs($t['customer']);
        $contact = $this->contact($t, 'Opal', 'Outed', '(601) 692-6705');
        $contact->forceFill(['status' => Contacts::STATUS_UNSUBSCRIBE])->save();

        $this->assertTrue($this->getJson($this->url('recipients', $t) . '?q=Opal')->json('contacts.0.opted_out'));

        $res = $this->send($t, ['recipient' => '6016926705'])->assertStatus(422);
        $this->assertSame(__('locale.conversations.contact_opted_out'), $res->json('message'));
        $this->assertCount(0, $this->fakeAdapter->sentRequests);
        $this->assertSame(0, ChatBox::where('business_id', $t['business']->id)->count());
    }

    public function test_the_full_page_form_still_redirects_for_non_json_requests(): void
    {
        $t = $this->tenant();
        $this->authenticateAs($t['customer']);

        $this->post($this->url('sent', $t), [
            'sms_type' => 'plain', 'country_code' => (string) $this->us()->id, 'recipient' => '4155559999',
            'message' => 'Plain form post', 'idempotency_token' => (string) Str::uuid(),
        ])->assertRedirect(route('customer.workspaces.businesses.conversations.index', [$t['workspace']->uid, $t['business']->uid]));

        $this->assertCount(1, $this->fakeAdapter->sentRequests);
    }
}

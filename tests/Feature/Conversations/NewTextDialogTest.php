<?php

namespace Tests\Feature\Conversations;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Messaging\ManagedMessageDispatcher;
use App\Models\Blacklists;
use App\Models\ChatBox;
use App\Models\ChatBoxMessage;
use App\Models\ContactGroupFields;
use App\Models\ContactGroups;
use App\Models\Contacts;
use App\Models\ContactsCustomField;
use App\Models\Country;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\Feature\PlatformBilling\Concerns\CreatesPlatformSubscriptions;
use Tests\TestCase;

/**
 * The Conversations "New text" dialog is the existing compose + send path with a different face: the same
 * `conversations.new` context, `conversations.sent` action, recipient normalisation, opt-out check and
 * idempotency, asked for as JSON so the dialog can keep its draft on screen.
 *
 * Every send goes through the real managed dispatcher; only the provider is the deterministic fake adapter, so no
 * live SMS can leave this test.
 */
class NewTextDialogTest extends TestCase
{
    use CreatesPlatformSubscriptions;
    use CreatesMessagingFixtures;
    use RefreshDatabase;

    private int $phoneSequence = 2000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdminId();
        $this->ensureRequiredAppConfigRowsExist();
        $this->bindFakeStripe();
        $this->bindFakeAdapter();
    }

    /** @return array{customer: \App\Models\Customer, workspace: \App\Models\Workspace, business: \App\Models\Business, number: \App\Models\BusinessMessagingNumber} */
    private function tenant(): array
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $business = $this->addBusiness($fixture['customer'], $fixture['workspace'], 'Dialog Business', BusinessStatus::Active);
        $identity = $this->attachIdentity($business);
        $number = $this->attachNumber($identity, $this->uniqueNumber(), true);

        return ['customer' => $fixture['customer'], 'workspace' => $fixture['workspace'], 'business' => $business, 'number' => $number];
    }

    private function us(): Country
    {
        return Country::firstOrCreate(['country_code' => '1', 'iso_code' => 'US'], ['name' => 'United States', 'status' => 1]);
    }

    private function url(string $name, array $t, array $extra = []): string
    {
        return route('customer.workspaces.businesses.conversations.' . $name, [$t['workspace']->uid, $t['business']->uid, ...$extra]);
    }

    /** @param array<string, string> $values custom-field tag => value */
    private function person(\App\Models\Business $business, ContactGroups $group, array $values, string $phone): Contacts
    {
        $contact = Contacts::create([
            'customer_id' => $business->customer_id,
            'business_id' => $business->id,
            'group_id' => $group->id,
            'phone' => $phone,
            'status' => Contacts::STATUS_SUBSCRIBE,
        ]);

        foreach ($values as $tag => $value) {
            $field = ContactGroupFields::query()->firstOrCreate(
                ['contact_group_id' => $group->id, 'tag' => $tag],
                ['label' => ucwords(strtolower($tag)), 'type' => 'text', 'visible' => true, 'required' => false],
            );
            ContactsCustomField::create(['contact_id' => $contact->id, 'field_id' => $field->id, 'value' => $value]);
        }

        return $contact->fresh();
    }

    private function group(\App\Models\Business $business, string $name): ContactGroups
    {
        return ContactGroups::create(['customer_id' => $business->customer_id, 'business_id' => $business->id, 'name' => $name, 'status' => true]);
    }

    private function payload(Country $country, string $recipient, string $message = 'Hello from the dialog'): array
    {
        return [
            'sms_type' => 'plain',
            'country_code' => (string) $country->id,
            'recipient' => $recipient,
            'message' => $message,
            'idempotency_token' => (string) Str::uuid(),
        ];
    }

    public function test_the_compose_context_names_the_businesss_own_texting_number_countries_and_templates(): void
    {
        $t = $this->tenant();
        $us = $this->us();
        \App\Models\Templates::create(['user_id' => $t['business']->customer_id, 'business_id' => $t['business']->id, 'name' => 'Quote follow-up', 'message' => 'Hi there', 'status' => true]);
        $this->authenticateAs($t['customer']);

        $json = $this->getJson($this->url('new', $t))->assertOk()->json();

        $this->assertTrue($json['dialog_supported']);
        $this->assertSame($t['number']->phone_number, $json['from']);
        $this->assertNotEmpty($json['from_display']);
        $this->assertContains($us->id, array_column($json['countries'], 'id'));
        $this->assertSame(['Quote follow-up'], array_column($json['templates'], 'name'));
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $json['idempotency_token']);

        // The full page is untouched: a plain GET still renders the existing compose form.
        $this->get($this->url('new', $t))->assertOk()->assertSee($t['number']->phone_number);
    }

    public function test_recipient_suggestions_tell_same_named_people_apart_and_flag_contacts_who_cannot_be_texted(): void
    {
        $t = $this->tenant();
        $this->us();
        $leads = $this->group($t['business'], 'Leads & Clients');
        $vendors = $this->group($t['business'], 'Vendors');
        $this->person($t['business'], $leads, ['FIRST_NAME' => 'Amira', 'LAST_NAME' => 'Misurati', 'EMAIL' => 'amira@example.test'], '19708259551');
        $this->person($t['business'], $vendors, ['FIRST_NAME' => 'Amira', 'LAST_NAME' => 'Misurati', 'COMPANY' => 'Harbor Print'], '17205028306');
        $optedOut = $this->person($t['business'], $leads, ['FIRST_NAME' => 'Amir', 'LAST_NAME' => 'Stopped'], '12025550142');
        $optedOut->update(['status' => Contacts::STATUS_UNSUBSCRIBE]);
        $blocked = $this->person($t['business'], $leads, ['FIRST_NAME' => 'Amir', 'LAST_NAME' => 'Blocked'], '12025550143');
        Blacklists::create(['user_id' => $t['business']->customer_id, 'business_id' => $t['business']->id, 'number' => '12025550143', 'reason' => 'Optout by User']);

        // Another Business's contact never appears.
        $other = $this->tenant()['business'];
        $this->person($other, $this->group($other, 'Elsewhere'), ['FIRST_NAME' => 'Amir', 'LAST_NAME' => 'Foreign'], '12025550144');

        $this->authenticateAs($t['customer']);
        $rows = collect($this->getJson($this->url('recipients', $t) . '?q=Amir')->assertOk()->json('contacts'));

        $this->assertSame(['Amir Blocked', 'Amir Stopped', 'Amira Misurati', 'Amira Misurati'], $rows->pluck('name')->sort()->values()->all());
        $amiras = $rows->where('name', 'Amira Misurati')->values();
        $this->assertSame(['Leads & Clients', 'Vendors'], $amiras->pluck('group')->sort()->values()->all());
        $this->assertNotSame($amiras[0]['phone'], $amiras[1]['phone']);
        $first = $rows->firstWhere('email', 'amira@example.test');
        $this->assertSame('9708259551', $first['national']);
        $this->assertNotNull($first['country_id']);
        $this->assertTrue($first['can_text']);
        $this->assertFalse($rows->firstWhere('name', 'Amir Stopped')['can_text']);
        $this->assertFalse($rows->firstWhere('name', 'Amir Blocked')['can_text']);
        $this->assertFalse($rows->contains(fn ($r) => $r['name'] === 'Amir Foreign'));

        // Nothing typed, nothing listed.
        $this->assertSame([], $this->getJson($this->url('recipients', $t) . '?q=')->json('contacts'));
    }

    public function test_a_direct_number_sends_once_and_returns_the_conversation_with_one_outbound_message(): void
    {
        $t = $this->tenant();
        $us = $this->us();
        $this->authenticateAs($t['customer']);

        $response = $this->postJson($this->url('sent', $t), $this->payload($us, '4155559999', 'Hi from the dialog'))->assertOk();

        $this->assertSame('success', $response->json('status'));
        $box = ChatBox::where('business_id', $t['business']->id)->sole();
        $this->assertSame($box->uid, $response->json('conversation_uid'));
        $this->assertCount(1, $this->fakeAdapter->sentRequests);
        $this->assertSame('+14155559999', $this->fakeAdapter->sentRequests[0]->toNumber);
        $this->assertSame($t['number']->phone_number, $this->fakeAdapter->sentRequests[0]->fromNumber);
        $this->assertSame(1, ChatBoxMessage::where('box_id', $box->id)->where('direction', 'outgoing')->count());
        $this->assertSame('Hi from the dialog', ChatBoxMessage::where('box_id', $box->id)->value('message'));
    }

    public function test_a_picked_contact_sends_through_the_country_and_national_number_the_suggestion_supplied(): void
    {
        $t = $this->tenant();
        $us = $this->us();
        $leads = $this->group($t['business'], 'Leads');
        $this->person($t['business'], $leads, ['FIRST_NAME' => 'Cleo', 'LAST_NAME' => 'Park'], '14155550123');
        $this->authenticateAs($t['customer']);

        $pick = $this->getJson($this->url('recipients', $t) . '?q=Cleo')->assertOk()->json('contacts.0');
        $this->assertSame($us->id, $pick['country_id']);

        $response = $this->postJson($this->url('sent', $t), [...$this->payload($us, $pick['national'], 'Hi Cleo'), 'country_code' => (string) $pick['country_id']])->assertOk();

        $this->assertSame('+14155550123', $this->fakeAdapter->sentRequests[0]->toNumber);
        $this->assertSame(ChatBox::where('business_id', $t['business']->id)->value('uid'), $response->json('conversation_uid'));
    }

    public function test_a_provider_rejection_keeps_the_dialog_open_with_an_actionable_message_and_no_conversation(): void
    {
        $t = $this->tenant();
        $us = $this->us();
        $this->authenticateAs($t['customer']);
        $this->fakeAdapter->rejections['*'] = \App\Enums\Messaging\ProviderErrorCategory::Terminal;

        $response = $this->postJson($this->url('sent', $t), $this->payload($us, '4155559998', 'Draft that must survive'))->assertStatus(422);

        $this->assertSame('error', $response->json('status'));
        $this->assertSame(\App\Library\Conversations\ConversationSendFailureReason::SendFailed->customerMessage(), $response->json('message'));
        $this->assertNull($response->json('conversation_uid'));
        $this->assertStringNotContainsString('locale.', (string) $response->json('message'));
    }

    public function test_an_invalid_number_and_a_missing_message_are_inline_errors_not_redirects(): void
    {
        $t = $this->tenant();
        $us = $this->us();
        $this->authenticateAs($t['customer']);

        $bad = $this->postJson($this->url('sent', $t), $this->payload($us, '12'))->assertStatus(422);
        $this->assertSame('error', $bad->json('status'));
        $this->assertNotEmpty($bad->json('message'));

        $r = $this->postJson($this->url('sent', $t), [...$this->payload($us, '4155559997'), 'message' => ''])->assertStatus(422);
        $this->assertSame('error', $r->json('status'));
        $this->assertSame('The message field is required.', $r->json('message'));
        $this->assertCount(0, $this->fakeAdapter->sentRequests);
    }

    public function test_an_opted_out_number_is_refused_with_the_reason_and_nothing_is_sent(): void
    {
        $t = $this->tenant();
        $us = $this->us();
        Blacklists::create(['user_id' => $t['business']->customer_id, 'business_id' => $t['business']->id, 'number' => '14155551234', 'reason' => 'Optout by User']);
        $this->authenticateAs($t['customer']);

        $response = $this->postJson($this->url('sent', $t), $this->payload($us, '4155551234'))->assertStatus(422);

        $this->assertSame('error', $response->json('status'));
        $this->assertSame('Number contains in the blacklist', $response->json('message'));
        $this->assertCount(0, $this->fakeAdapter->sentRequests);
        $this->assertSame(0, ChatBox::where('business_id', $t['business']->id)->count());
    }

    public function test_a_repeated_submit_with_the_same_idempotency_token_dispatches_once(): void
    {
        $t = $this->tenant();
        $us = $this->us();
        $this->authenticateAs($t['customer']);
        $payload = $this->payload($us, '4155559996', 'Only once');

        $first = $this->postJson($this->url('sent', $t), $payload);
        $second = $this->postJson($this->url('sent', $t), $payload);

        $first->assertOk();
        $this->assertCount(1, $this->fakeAdapter->sentRequests);
        $this->assertSame(1, DB::table(ManagedMessageDispatcher::TABLE)->where('business_id', (int) $t['business']->id)->count());
        $this->assertSame(1, ChatBoxMessage::whereIn('box_id', ChatBox::where('business_id', $t['business']->id)->pluck('id'))->where('direction', 'outgoing')->count());
        $this->assertNotSame('', (string) $second->getContent());
    }

    public function test_a_foreign_business_is_refused_identically_for_the_new_json_endpoints(): void
    {
        $actor = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $this->addBusiness($actor['customer'], $actor['workspace'], 'Actor Business', BusinessStatus::Active);
        $foreign = $this->tenant();
        $this->authenticateAs($actor['customer']);

        $this->getJson(route('customer.workspaces.businesses.conversations.recipients', [$actor['workspace']->uid, $foreign['business']->uid]) . '?q=a')->assertNotFound();
        $this->getJson(route('customer.workspaces.businesses.conversations.new', [$actor['workspace']->uid, $foreign['business']->uid]))->assertNotFound();
    }

    public function test_the_inbox_renders_the_dialog_and_both_launch_controls_keep_their_full_page_fallback(): void
    {
        $t = $this->tenant();
        $this->us();
        $this->authenticateAs($t['customer']);

        $html = $this->get($this->url('index', $t))->assertOk()->getContent();

        $this->assertStringContainsString('id="new-text-dialog"', $html);
        $this->assertStringContainsString('New text', $html);
        $this->assertStringContainsString('Opens in Conversations after sending.', $html);
        $this->assertStringContainsString('Or type a phone number', $html);
        $this->assertGreaterThanOrEqual(2, substr_count($html, 'data-new-text-open'));
        $this->assertStringContainsString('href="' . $this->url('new', $t) . '" data-new-text-open', $html);
    }
}

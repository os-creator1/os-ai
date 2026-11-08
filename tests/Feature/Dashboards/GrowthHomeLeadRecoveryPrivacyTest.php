<?php

namespace Tests\Feature\Dashboards;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Assistant\AssistantActionRefused;
use App\Library\Assistant\AssistantContext;
use App\Library\Assistant\AssistantHandles;
use App\Library\Assistant\Tools\DraftReplyTool;
use App\Library\Crm\CrmLocationScope;
use App\Library\Navigation\CustomerShellComposer;
use App\Models\Business;
use App\Models\Contacts;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Dashboards\Concerns\CreatesDashboardFixtures;
use Tests\TestCase;

/**
 * "Prepare reply" must give the assistant an opaque, actor-and-Business-bound handle — never a name, number or message — and the handle
 * must reach exactly one accessible waiting conversation. The authenticated customer still sees names on their own Home.
 */
class GrowthHomeLeadRecoveryPrivacyTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDashboardFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeClock();
        config(['opportunity.enabled' => true]);
    }

    private function minutesAgo(int $minutes): string
    {
        return \Carbon\CarbonImmutable::now()->subMinutes($minutes)->setTimezone((string) config('app.timezone', 'UTC'))->format('Y-m-d H:i:s');
    }

    private function context(User $user, Workspace $workspace, Business $business): AssistantContext
    {
        $composer = app(CustomerShellComposer::class);
        $customer = $composer->currentContext($user);

        return new AssistantContext($workspace, $business, $user, $customer, $composer->currentMenuEntitlements($customer), [], [$workspace->uid, $business->uid]);
    }

    /** @return array{0: int, 1: string, 2: string} box id, uid, phone */
    private function waitingCustomer(Business $business, string $first, string $last, int $minutesAgo): array
    {
        $box = $this->conversationWith($business, [['incoming', $this->minutesAgo($minutesAgo)]]);
        $row = DB::table('chat_boxes')->where('id', $box)->first();
        // Names exist the way the application stores them (and ChatBox::displayNamesFor reads them): FIRST_NAME / LAST_NAME custom fields
        // on the Contact's group (same pattern as ChatBoxSecurityTest::test_batched_display_names_match_get_full_name).
        $group = \App\Models\ContactGroups::create(['customer_id' => $business->customer_id, 'business_id' => $business->id, 'name' => 'Names ' . uniqid()]);
        $firstField = \App\Models\ContactGroupFields::create(['contact_group_id' => $group->id, 'label' => 'First name', 'type' => 'text', 'tag' => 'FIRST_NAME']);
        $lastField = \App\Models\ContactGroupFields::create(['contact_group_id' => $group->id, 'label' => 'Last name', 'type' => 'text', 'tag' => 'LAST_NAME']);
        $contact = Contacts::create([
            'customer_id' => $business->customer_id, 'business_id' => $business->id, 'group_id' => $group->id,
            'phone' => $row->to, 'status' => Contacts::STATUS_SUBSCRIBE,
        ]);
        \App\Models\ContactsCustomField::create(['contact_id' => $contact->id, 'field_id' => $firstField->id, 'value' => $first]);
        \App\Models\ContactsCustomField::create(['contact_id' => $contact->id, 'field_id' => $lastField->id, 'value' => $last]);
        $this->assertSame($first . ' ' . $last, $contact->fresh()->getFullName(), 'Fixture precondition: the name resolves.');

        return [$box, (string) $row->uid, (string) $row->to];
    }

    /** @return array<int, array{prompt: string, handle: string}> the Prepare buttons on Home, in page order */
    private function prepareButtons(string $html): array
    {
        preg_match_all('#data-role="prepare-reply" data-ask-prompt="([^"]*)"#', $html, $m);

        return array_map(function (string $prompt): array {
            $decoded = html_entity_decode($prompt, ENT_QUOTES);
            preg_match('/\b(h_[0-9a-f]{12})\b/', $decoded, $h);

            return ['prompt' => $decoded, 'handle' => $h[1] ?? ''];
        }, $m[1]);
    }

    public function test_prompts_carry_only_distinct_opaque_handles_while_the_customer_still_sees_names(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Handle Venue', 'Handle Account');
        [, $uidA, $phoneA] = $this->waitingCustomer($business, 'Jordan', 'Smith', 300);
        [, $uidB, $phoneB] = $this->waitingCustomer($business, 'Jordan', 'Smith', 100);
        $this->authenticateAs($customer);

        $html = $this->home()->assertOk()->getContent();
        $buttons = $this->prepareButtons($html);

        $this->assertCount(2, $buttons);
        $this->assertNotSame($buttons[0]['handle'], $buttons[1]['handle'], 'Two customers with the same name have distinct handles.');
        $context = $this->context($customer->user, $workspace, $business);
        $this->assertSame(AssistantHandles::for($context, 'conversation', $uidA), $buttons[0]['handle'], 'The longest-waiting customer is first.');
        $this->assertSame(AssistantHandles::for($context, 'conversation', $uidB), $buttons[1]['handle']);

        foreach ($buttons as $button) {
            foreach (['Jordan', 'Smith', $phoneA, $phoneB, 'Fixture message'] as $personal) {
                $this->assertStringNotContainsString($personal, $button['prompt'], 'Nothing personal reaches the assistant.');
            }
        }

        $this->assertStringContainsString('Jordan Smith', $html, "The customer's own Home still shows the name.");
    }

    public function test_each_handle_resolves_only_to_its_own_accessible_waiting_conversation(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Resolve Venue', 'Resolve Account');
        [, $uidA] = $this->waitingCustomer($business, 'Jordan', 'Smith', 300);
        [, $uidB] = $this->waitingCustomer($business, 'Jordan', 'Smith', 100);
        $this->authenticateAs($customer);
        $context = $this->context($customer->user, $workspace, $business);
        $tool = app(DraftReplyTool::class);

        foreach ([$uidA, $uidB] as $uid) {
            $preview = $tool->prepare($context, ['conversation_ref' => AssistantHandles::for($context, 'conversation', $uid), 'message' => 'Thanks for getting in touch.']);
            $this->assertSame($uid, $preview->resolved['conversation_uid'], 'The handle reaches exactly its conversation.');
        }

        $this->expectException(AssistantActionRefused::class);
        $tool->prepare($context, ['conversation_ref' => 'h_000000000000', 'message' => 'Hello there.']);
    }

    public function test_another_businesss_handle_cannot_retrieve_a_customer(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Mine Handle Venue', 'Mine Handle Account');
        [$strangerCustomer, $stranger, $strangerWorkspace] = $this->tenant(WorkspacePlanTier::Growth, 'Theirs Handle Venue', 'Theirs Handle Account');
        [, $theirUid] = $this->waitingCustomer($stranger, 'Casey', 'Jones', 200);
        $this->authenticateAs($customer);
        $mine = $this->context($customer->user, $workspace, $business);
        $theirs = $this->context($strangerCustomer->user, $strangerWorkspace, $stranger);
        $theirHandle = AssistantHandles::for($theirs, 'conversation', $theirUid);

        $this->assertNotSame($theirHandle, AssistantHandles::for($mine, 'conversation', $theirUid), 'Handles are bound to the Business and actor.');

        $this->expectException(AssistantActionRefused::class);
        app(DraftReplyTool::class)->prepare($mine, ['conversation_ref' => $theirHandle, 'message' => 'Hello there.']);
    }

    public function test_a_conversation_in_a_location_the_actor_cannot_reach_cannot_be_prepared(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Site Handle Venue', 'Site Handle Account');
        [, $uid] = $this->waitingCustomer($business, 'Jordan', 'Smith', 200);
        $this->authenticateAs($customer);
        $context = $this->context($customer->user, $workspace, $business);
        $handle = AssistantHandles::for($context, 'conversation', $uid);

        // The actor loses reach to every Location: the inbox's own restriction now excludes this conversation.
        $this->mock(CrmLocationScope::class, fn ($m) => $m->shouldReceive('restrict')->andReturnUsing(function ($query, $b, $a, $column) {
            $query->whereIn($column, [-1]);
        }));

        $this->expectException(AssistantActionRefused::class);
        app(DraftReplyTool::class)->prepare($context, ['conversation_ref' => $handle, 'message' => 'Hello there.']);
    }
}

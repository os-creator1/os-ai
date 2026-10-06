<?php

namespace Tests\Feature\Conversations;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Models\Business;
use App\Models\ChatBox;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Conversations\Concerns\CreatesTimelineFixtures;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * V1 messaging final acceptance — the inbox LIST, found wrong in the browser:
 *
 *  - only the "Recents" tab was ordered; Unread / Read / All came back in
 *    primary-key order (oldest conversation first, newest on a later page);
 *  - the list shows a person's NAME but the search box only matched phone
 *    numbers, so searching the name on screen found nothing;
 *  - a list with nothing to show was a blank pane, with no words.
 */
class InboxListFinalAcceptanceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;
    use CreatesTimelineFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        // The first user is always a super admin; keep the customer from being it.
        User::create([
            'first_name' => 'Placeholder',
            'last_name' => 'SuperAdmin',
            'email' => 'placeholder-superadmin' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
        ]);
    }

    /** @return list<string> the conversation uids in the order the list rendered them */
    private function listed(Workspace $workspace, Business $business, array $payload = []): array
    {
        $html = $this->post(route('customer.workspaces.businesses.conversations.load', [$workspace->uid, $business->uid]), $payload)
            ->assertOk()
            ->getContent();

        preg_match_all('/data-id="([0-9a-f\-]{36})"/', $html, $matches);

        return $matches[1];
    }

    private function touch(ChatBox $box, string $at): void
    {
        ChatBox::query()->whereKey($box->id)->update(['updated_at' => Carbon::parse($at)]);
    }

    public function test_every_tab_lists_the_newest_activity_first_not_the_oldest_conversation(): void
    {
        [$business, $workspace] = $this->signedInOwner();

        // Created oldest-first (so ids ascend), but ACTIVITY runs the other way round for the middle one.
        $first = $this->conversationWith($business, '12025550101');
        $second = $this->conversationWith($business, '12025550102');
        $third = $this->conversationWith($business, '12025550103');
        $this->touch($first, '2026-09-10 08:00:00');
        $this->touch($second, '2026-09-10 12:00:00');
        $this->touch($third, '2026-09-10 10:00:00');

        $expected = [$second->uid, $third->uid, $first->uid];

        $this->assertSame($expected, $this->listed($workspace, $business, ['filter' => 'recents']));
        $this->assertSame($expected, $this->listed($workspace, $business, ['filter' => 'all']), 'All must be ordered like Recents.');
        $this->assertSame($expected, $this->listed($workspace, $business), 'No filter at all is still ordered.');

        foreach ([$first, $second, $third] as $box) {
            $box->update(['notification' => 1]);
            $this->touch($box, ['12025550101' => '2026-09-10 08:00:00', '12025550102' => '2026-09-10 12:00:00', '12025550103' => '2026-09-10 10:00:00'][$box->to]);
        }

        $this->assertSame($expected, $this->listed($workspace, $business, ['filter' => 'unread']));

        foreach ([$first, $second, $third] as $box) {
            $box->update(['notification' => 0]);
            $this->touch($box, ['12025550101' => '2026-09-10 08:00:00', '12025550102' => '2026-09-10 12:00:00', '12025550103' => '2026-09-10 10:00:00'][$box->to]);
        }

        $this->assertSame($expected, $this->listed($workspace, $business, ['filter' => 'read']));
    }

    public function test_two_conversations_active_in_the_same_second_are_ordered_deterministically(): void
    {
        [$business, $workspace] = $this->signedInOwner();

        $older = $this->conversationWith($business, '12025550111');
        $newer = $this->conversationWith($business, '12025550112');
        $this->touch($older, '2026-09-10 09:00:00');
        $this->touch($newer, '2026-09-10 09:00:00');

        $this->assertSame([$newer->uid, $older->uid], $this->listed($workspace, $business, ['filter' => 'all']));
    }

    public function test_search_finds_a_conversation_by_the_name_the_list_shows(): void
    {
        [$business, $workspace] = $this->signedInOwner();

        $this->namedContact($business, '12025550121', 'Alan', 'Turing');
        $this->namedContact($business, '12025550122', 'Grace', 'Hopper');
        $alan = $this->conversationWith($business, '12025550121');
        $this->conversationWith($business, '12025550122');
        $this->conversationWith($business, '12025550123');

        $this->assertSame([$alan->uid], $this->listed($workspace, $business, ['search' => 'Turing']));
        $this->assertSame([$alan->uid], $this->listed($workspace, $business, ['search' => 'alan']), 'Case does not matter.');
        $this->assertSame([$alan->uid], $this->listed($workspace, $business, ['search' => 'alan tur']), 'Every word must match, in either name.');
        $this->assertSame([$alan->uid], $this->listed($workspace, $business, ['search' => 'Turing Alan']), 'Word order does not matter.');
        $this->assertSame([], $this->listed($workspace, $business, ['search' => 'Alan Hopper']), 'Words from two different people match nobody.');
        $this->assertCount(1, $this->listed($workspace, $business, ['search' => '2025550123']), 'Searching a number still works.');
    }

    public function test_search_by_name_never_reaches_another_business_or_treats_percent_as_a_wildcard(): void
    {
        [$business, $workspace] = $this->signedInOwner();

        $owner = $this->createCustomer();
        $elsewhere = $this->addBusiness($owner, $this->createWorkspace($owner->user, ['name' => 'Elsewhere']), 'Elsewhere Studio');

        // The same number, named in ANOTHER Business: this Business has no Contact called Turing.
        $this->namedContact($elsewhere, '12025550131', 'Alan', 'Turing');
        $this->conversationWith($business, '12025550131');

        $this->assertSame([], $this->listed($workspace, $business, ['search' => 'Turing']), 'Another Business\'s Contact name selects nothing here.');

        $this->namedContact($business, '12025550132', 'Ada', 'Lovelace');
        $this->conversationWith($business, '12025550132');

        $this->assertSame([], $this->listed($workspace, $business, ['search' => '%']), '% is a literal character, not "match every name".');
    }

    public function test_an_empty_list_says_why_and_a_later_empty_page_does_not(): void
    {
        [$business, $workspace] = $this->signedInOwner();

        $url = route('customer.workspaces.businesses.conversations.load', [$workspace->uid, $business->uid]);

        $this->post($url)->assertOk()->assertSee('data-role="chat-list-empty"', false)->assertSee(__('locale.conversations.list_empty'));
        $this->post($url, ['filter' => 'unread'])->assertOk()->assertSee(__('locale.conversations.list_empty_filtered'));
        $this->post($url, ['search' => 'nobody'])->assertOk()->assertSee(__('locale.conversations.list_empty_search'));

        // Infinite scroll asks for page 2 and gets nothing: that is the end of the list, not an empty inbox.
        $this->post($url, ['page' => 2])->assertOk()->assertDontSee('data-role="chat-list-empty"', false);

        $this->conversationWith($business, '12025550141');
        $this->post($url)->assertOk()->assertDontSee('data-role="chat-list-empty"', false);
    }

    // -----------------------------------------------------------------

    /** @return array{0: Business, 1: Workspace} */
    private function signedInOwner(): array
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core, 'Harbor Lane Studios', 'Harbor Lane');

        $user = $customer->user;
        $user->email_verified_at = now();
        $user->save();

        $customer->permissions = Customer::customerPermissions();
        $customer->save();

        $this->actingAs($user);

        return [$business, $workspace];
    }
}

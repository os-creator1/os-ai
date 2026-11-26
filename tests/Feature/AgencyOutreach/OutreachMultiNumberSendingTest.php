<?php

namespace Tests\Feature\AgencyOutreach;

use App\Jobs\Outreach\OutreachFollowUpJob;
use App\Jobs\Outreach\OutreachInitialSendJob;
use App\Library\AgencyOutreach\OutreachCampaignService;
use App\Library\AgencyOutreach\OutreachMessageSender;
use App\Library\AgencyOutreach\OutreachSenderPool;
use App\Models\AgencyProspectCampaign;
use App\Models\AgencyProspectCampaignMember;
use App\Models\AgencyProspectingChannel;
use App\Models\AgencyProspectMessage;
use App\Models\Blacklists;
use App\Models\Business;
use App\Models\ChatBox;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\AgencyOutreach\Concerns\BuildsAgencyByoChannels;
use Tests\Feature\AgencyOutreach\Concerns\BuildsOutreachFixtures;
use Tests\TestCase;

/**
 * Agency Outreach multi-number sending: a campaign spreads NEW conversations across the agency's
 * own selected Telnyx numbers, and each prospect stays on the one number their conversation started
 * on — openers, scripted replies, follow-ups, deferred and resumed sends alike.
 *
 * Every send goes through the real pipeline → quickSend → BYO dispatcher path; only Telnyx's HTTP
 * API is faked (BuildsAgencyByoChannels). No live provider or AI is contacted.
 */
class OutreachMultiNumberSendingTest extends TestCase
{
    use RefreshDatabase;
    use BuildsOutreachFixtures;
    use BuildsAgencyByoChannels;

    /** Distinct sender numbers across every agency in a test (a number has one live owner). */
    private int $senderSeq = 100;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindFakeAdapter();
        $this->ensureRequiredAppConfigRowsExist();
        User::create([
            'first_name' => 'Placeholder', 'last_name' => 'SuperAdmin',
            'email' => 'placeholder-superadmin' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);
        $this->fakeTelnyx();
    }

    /**
     * An agency with $count of its own Telnyx numbers in Outreach (the first is the default sender).
     *
     * @return array{0: Customer, 1: Business, 2: Workspace, 3: list<AgencyProspectingChannel>}
     */
    private function agencyWithNumbers(int $count, string $name = 'Snap Booth Co'): array
    {
        [$customer, $business, $workspace] = $this->agency($name);
        $this->saveScript($workspace);
        $channels = [];

        for ($i = 0; $i < $count; $i++) {
            $channel = $this->byoChannel($workspace, ['sender' => '12025552' . str_pad((string) ++$this->senderSeq, 3, '0', STR_PAD_LEFT), 'default' => $i === 0]);
            $channel->forceFill(['outreach_enabled' => true])->save();
            $channels[] = $channel->fresh();
        }

        return [$customer, $business, $workspace, $channels];
    }

    /** @param list<AgencyProspectingChannel> $channels */
    private function selectNumbers(AgencyProspectCampaign $campaign, array $channels): void
    {
        $campaign->update(['sender_channel_ids' => array_map(fn ($c) => (int) $c->id, $channels)]);
    }

    /** @return list<AgencyProspectCampaignMember> */
    private function members(Workspace $workspace, AgencyProspectCampaign $campaign, int $count, int $base = 1100): array
    {
        $out = [];

        for ($i = 0; $i < $count; $i++) {
            $out[] = $this->enroll($workspace, $campaign, $this->prospect($workspace, '1202555' . ($base + $i)));
        }

        return $out;
    }

    private function sendOpeners(array $members): void
    {
        foreach ($members as $member) {
            app()->call([new OutreachInitialSendJob($member->id), 'handle']);
        }
    }

    /** @return array<string, string> recipient digits => the number it was sent from */
    private function sentFromByRecipient(): array
    {
        $out = [];

        foreach ($this->telnyxMessageRequests() as $request) {
            $out[ltrim((string) $request['to'], '+')][] = ltrim((string) $request['from'], '+');
        }

        return $out;
    }

    private function assignments(AgencyProspectCampaign $campaign): array
    {
        return AgencyProspectCampaignMember::query()->where('campaign_id', $campaign->id)
            ->selectRaw('sender_channel_id, COUNT(*) as n')->groupBy('sender_channel_id')
            ->pluck('n', 'sender_channel_id')->map(fn ($n) => (int) $n)->all();
    }

    private function actAs(Customer $customer): void
    {
        $customer->user->forceFill(['email_verified_at' => now()])->save();
        $this->withSession(['permissions' => collect(array_merge(['access_backend'], array_keys(config('customer-permissions'))))]);
        $this->actingAs($customer->user);
    }

    // ------------------------------------------------------------------ compatibility

    public function test_an_existing_single_number_campaign_keeps_sending_from_the_default_sender_without_reconfiguration(): void
    {
        [, , $workspace, [$default]] = $this->agencyWithNumbers(1);
        $campaign = $this->managedCampaign($workspace);
        $this->assertNull($campaign->fresh()->sender_channel_ids, 'Never configured.');
        $members = $this->members($workspace, $campaign, 2);

        $this->sendOpeners($members);

        $this->assertCount(2, $this->telnyxMessageRequests());
        foreach ($this->telnyxMessageRequests() as $request) {
            $this->assertSame('+' . $default->sender_number, $request['from']);
        }
        $this->assertSame([(int) $default->id => 2], $this->assignments($campaign), 'Pinned to the sender they started on.');
    }

    public function test_a_legacy_conversation_keeps_its_original_number_after_the_default_changes(): void
    {
        [, $business, $workspace, [$a, $b]] = $this->agencyWithNumbers(2);
        $campaign = $this->managedCampaign($workspace);
        [$member] = $this->members($workspace, $campaign, 1);

        // A conversation that was started on A before this feature: no assignment stored.
        app()->call([new OutreachInitialSendJob($member->id), 'handle']);
        AgencyProspectCampaignMember::query()->whereKey($member->id)->update(['sender_channel_id' => null, 'sender_assigned_at' => null]);
        $this->assertNotNull($this->member($member)->chat_box_id);

        // The agency switches its default to B; the follow-up still goes out from A.
        $a->forceFill(['is_default_sender' => false])->save();
        $b->forceFill(['is_default_sender' => true])->save();
        AgencyProspectCampaignMember::query()->whereKey($member->id)->update(['stage' => 4, 'followup_at' => now()->subHour(), 'booking_link_sent_at' => now()->subDay()]);
        app()->call([new OutreachFollowUpJob($member->id), 'handle']);

        $requests = $this->telnyxMessageRequests();
        $this->assertCount(2, $requests);
        $this->assertSame('+' . $a->sender_number, $requests[1]['from']);
        $this->assertSame((int) $a->id, (int) $this->member($member)->sender_channel_id);
    }

    // ------------------------------------------------------------------ distribution

    public function test_one_two_and_three_selected_numbers_split_new_conversations_evenly(): void
    {
        $this->assertSame([50], OutreachSenderPool::distribution(50, 1));
        $this->assertSame([25, 25], OutreachSenderPool::distribution(50, 2));
        $this->assertSame([17, 17, 16], OutreachSenderPool::distribution(50, 3));

        foreach ([1 => [6], 2 => [3, 3], 3 => [2, 2, 2]] as $count => $expected) {
            [, , $workspace, $channels] = $this->agencyWithNumbers($count, 'Agency ' . $count);
            $campaign = $this->managedCampaign($workspace);
            $this->selectNumbers($campaign, $channels);

            $this->sendOpeners($this->members($workspace, $campaign, 6, 1100 + $count * 10));

            $this->assertSame($expected, array_values($this->assignments($campaign)), "{$count} number(s)");
        }

        // Each opener went out from the number its prospect was assigned.
        foreach (AgencyProspectCampaignMember::query()->with(['prospect', 'senderChannel'])->get() as $member) {
            $this->assertSame([$member->senderChannel->sender_number], $this->sentFromByRecipient()[$member->prospect->phone]);
        }
    }

    public function test_more_than_three_numbers_can_be_selected_and_used(): void
    {
        [, , $workspace, $channels] = $this->agencyWithNumbers(5);
        $campaign = $this->managedCampaign($workspace);
        $this->selectNumbers($campaign, $channels);

        $this->sendOpeners($this->members($workspace, $campaign, 5));

        $this->assertSame(array_fill(0, 5, 1), array_values($this->assignments($campaign)));
        $this->assertCount(5, array_unique(array_merge(...array_values($this->sentFromByRecipient()))));
    }

    public function test_unselected_unverified_and_foreign_numbers_receive_no_new_conversations(): void
    {
        [, , $workspace, [$ready, $unverified, $unselected]] = $this->agencyWithNumbers(3);
        $unverified->forceFill(['credentials_verified_at' => null])->save();
        [, , $otherWorkspace, [$foreign]] = $this->agencyWithNumbers(1, 'Other Agency');

        $campaign = $this->managedCampaign($workspace);
        // The foreign id is written straight to the column: it must still never be used.
        $campaign->update(['sender_channel_ids' => [(int) $ready->id, (int) $unverified->id, (int) $foreign->id]]);

        $this->sendOpeners($this->members($workspace, $campaign, 4));

        $this->assertSame([(int) $ready->id => 4], $this->assignments($campaign));
        $this->assertSame([$ready->sender_number], array_values(array_unique(array_merge(...array_values($this->sentFromByRecipient())))));
        $this->assertNotContains((int) $unselected->id, array_keys($this->assignments($campaign)));
    }

    // ------------------------------------------------------------------ durability

    public function test_retries_and_concurrent_workers_keep_one_stable_sender_and_never_send_a_second_opener(): void
    {
        [, $business, $workspace, [$a, $b]] = $this->agencyWithNumbers(2);
        $campaign = $this->managedCampaign($workspace);
        $this->selectNumbers($campaign, [$a, $b]);
        [$member] = $this->members($workspace, $campaign, 1);

        // A second worker already pinned this member to B (a stale copy still reads null).
        $stale = AgencyProspectCampaignMember::query()->findOrFail($member->id);
        AgencyProspectCampaignMember::query()->whereKey($member->id)->update(['sender_channel_id' => $b->id, 'sender_assigned_at' => now()]);
        $stale->sender_channel_id = null;
        $chosen = DB::transaction(fn () => app(OutreachSenderPool::class)->assign($stale, $campaign->fresh(), $business));
        $this->assertSame((int) $b->id, $chosen, 'An existing assignment is never overwritten.');

        // The opener job runs, is retried, and a duplicate is delivered.
        app()->call([new OutreachInitialSendJob($member->id), 'handle']);
        app()->call([new OutreachInitialSendJob($member->id), 'handle']);

        // Even if the campaign now only allows A, the prospect is not opened again from A.
        $this->selectNumbers($campaign, [$a]);
        app()->call([new OutreachInitialSendJob($member->id), 'handle']);

        $this->assertCount(1, $this->telnyxMessageRequests());
        $this->assertSame('+' . $b->sender_number, $this->telnyxMessageRequests()[0]['from']);
        $this->assertSame(1, AgencyProspectMessage::where('campaign_member_id', $member->id)->where('purpose', 'initial')->count());
        $this->assertSame((int) $b->id, (int) AgencyProspectMessage::where('campaign_member_id', $member->id)->value('channel_id'));
        $this->assertSame((int) $b->id, (int) $this->member($member)->sender_channel_id);
    }

    public function test_replies_and_follow_ups_use_the_assigned_sender_not_the_default(): void
    {
        [, $business, $workspace, [$a, $b]] = $this->agencyWithNumbers(2);
        $campaign = $this->managedCampaign($workspace);
        $this->selectNumbers($campaign, [$b]);
        [$member] = $this->members($workspace, $campaign, 1, 1000); // the prospect is +12025551000

        app()->call([new OutreachInitialSendJob($member->id), 'handle']);

        // The prospect replies to B; the scripted reply goes out from B (A is the default).
        $this->signedWebhook($b, $this->receivedPayload($b, 'Yes, tell me more', 'in-1'))->assertOk();

        $this->assertSame(1, AgencyProspectMessage::where('campaign_member_id', $member->id)->where('direction', 'inbound')->count());
        $outbound = AgencyProspectMessage::where('campaign_member_id', $member->id)->where('direction', 'outbound')->orderBy('id')->get();
        $this->assertCount(2, $outbound);
        $this->assertSame(['sent', 'sent'], $outbound->pluck('status')->all());

        // A timed follow-up later also goes out from B.
        AgencyProspectCampaignMember::query()->whereKey($member->id)->update(['stage' => 4, 'followup_at' => now()->subHour(), 'booking_link_sent_at' => now()->subDay(),
            'last_inbound_at' => now()->subDays(2), 'followup_cancelled_at' => null, 'sequence_sent_ids' => null]);
        app()->call([new OutreachFollowUpJob($member->id), 'handle']);

        $requests = $this->telnyxMessageRequests();
        $this->assertCount(3, $requests);
        foreach ($requests as $request) {
            $this->assertSame('+' . $b->sender_number, $request['from']);
        }
        $this->assertSame([(string) $b->id], AgencyProspectMessage::where('campaign_member_id', $member->id)->where('direction', 'outbound')->pluck('channel_id')->map(fn ($id) => (string) $id)->unique()->values()->all());

        // The conversation lives on B's thread only.
        $this->assertSame([$b->sender_number], ChatBox::query()->where('business_id', $business->id)->pluck('from')->all());
    }

    public function test_removing_a_number_only_affects_new_assignments(): void
    {
        [, , $workspace, [$a, $b]] = $this->agencyWithNumbers(2);
        $campaign = $this->managedCampaign($workspace);
        $this->selectNumbers($campaign, [$a]);
        [$first] = $this->members($workspace, $campaign, 1, 1100);
        $this->sendOpeners([$first]);

        // A is removed from this campaign (and from the agency's Outreach pool).
        $this->selectNumbers($campaign, [$b]);
        $a->forceFill(['outreach_enabled' => false])->save();
        [$second] = $this->members($workspace, $campaign, 1, 1200);
        $this->sendOpeners([$second]);

        // The first prospect's follow-up still goes from A, which can still send.
        AgencyProspectCampaignMember::query()->whereKey($first->id)->update(['stage' => 4, 'followup_at' => now()->subHour(), 'booking_link_sent_at' => now()->subDay()]);
        app()->call([new OutreachFollowUpJob($first->id), 'handle']);

        $sent = $this->sentFromByRecipient();
        $this->assertSame([$a->sender_number, $a->sender_number], $sent['12025551100']);
        $this->assertSame([$b->sender_number], $sent['12025551200']);
        $this->assertSame((int) $a->id, (int) $this->member($first)->sender_channel_id);
    }

    // ------------------------------------------------------------------ availability

    public function test_an_unavailable_assigned_number_holds_its_sends_with_the_reason_and_recovers_on_the_same_number(): void
    {
        [, , $workspace, [$a, $b]] = $this->agencyWithNumbers(2);
        $campaign = $this->managedCampaign($workspace);
        $this->selectNumbers($campaign, [$a, $b]);
        [$member] = $this->members($workspace, $campaign, 1);
        $this->sendOpeners([$member]);
        $assigned = (int) $this->member($member)->sender_channel_id;
        $pinned = AgencyProspectingChannel::findOrFail($assigned);
        $other = $assigned === (int) $a->id ? $b : $a;

        // The pinned number is switched off in Telnyx settings.
        $pinned->forceFill(['status' => AgencyProspectingChannel::STATUS_DISABLED])->save();
        AgencyProspectCampaignMember::query()->whereKey($member->id)->update(['stage' => 4, 'followup_at' => now()->subHour(), 'booking_link_sent_at' => now()->subDay()]);
        app()->call([new OutreachFollowUpJob($member->id), 'handle']);
        app()->call([new OutreachFollowUpJob($member->id), 'handle']);

        $held = AgencyProspectMessage::where('campaign_member_id', $member->id)->where('purpose', 'followup')->sole();
        $this->assertSame('paused', $held->status);
        $this->assertSame('sender_unavailable:channel_disabled', $held->failure_reason);
        $this->assertSame($assigned, (int) $held->channel_id);
        $this->assertCount(1, $this->telnyxMessageRequests(), 'Nothing was sent, from any number.');
        $this->assertSame($assigned, (int) $this->member($member)->sender_channel_id, 'Not moved to ' . $other->sender_number);
        $this->assertNull($this->member($member)->followup_cancelled_at, 'History and the follow-up are kept.');

        // A resume while it is still off retries once and stays held (no loop, no switch).
        app(\App\Library\AgencyOutreach\OutreachResumeService::class)->resumePausedSends($workspace);
        $this->assertCount(1, $this->telnyxMessageRequests());
        $this->assertSame('paused', $held->fresh()->status);

        // The owner turns it back on: the held follow-up goes out from the SAME number.
        $pinned->forceFill(['status' => AgencyProspectingChannel::STATUS_ACTIVE])->save();
        $this->assertSame(1, app(OutreachSenderPool::class)->resumeHeldFor($pinned));

        $this->assertSame('sent', $held->fresh()->status);
        $this->assertCount(2, $this->telnyxMessageRequests());
        $this->assertSame('+' . $pinned->sender_number, $this->telnyxMessageRequests()[1]['from']);
    }

    public function test_starting_or_resuming_needs_an_eligible_selected_number(): void
    {
        [, , $workspace, [$a]] = $this->agencyWithNumbers(1);
        $campaign = $this->managedCampaign($workspace, ['status' => 'draft']);
        $this->members($workspace, $campaign, 1);
        $service = app(OutreachCampaignService::class);
        [, , , [$foreign]] = $this->agencyWithNumbers(1, 'Other Agency');

        $campaign->update(['sender_channel_ids' => [(int) $foreign->id]]);
        $this->assertFalse($service->start($workspace, $campaign->fresh())['ok']);
        $this->assertSame('draft', $campaign->fresh()->status->value);

        $this->selectNumbers($campaign, [$a]);
        $this->assertTrue($service->start($workspace, $campaign->fresh())['ok']);
        $this->assertTrue($service->pause($workspace, $campaign->fresh())['ok']);

        $a->forceFill(['credentials_verified_at' => null])->save();
        $second = $this->byoChannel($workspace, ['sender' => '12025552999']);
        $second->forceFill(['outreach_enabled' => true])->save();
        $this->selectNumbers($campaign, [$a]);
        $resume = $service->resume($workspace, $campaign->fresh());
        $this->assertFalse($resume['ok']);
        $this->assertSame('paused', $campaign->fresh()->status->value);
    }

    // ------------------------------------------------------------------ inbound and opt-out

    public function test_an_inbound_reply_resolves_the_receiving_number_prospect_and_tenant(): void
    {
        [, $business, $workspace, [$a, $b]] = $this->agencyWithNumbers(2);
        $campaign = $this->managedCampaign($workspace);
        $this->selectNumbers($campaign, [$b]);
        [$member] = $this->members($workspace, $campaign, 1, 1000);
        $this->sendOpeners([$member]);

        // Another agency with the same prospect number on its own channel.
        [, $otherBusiness, $otherWorkspace, [$foreign]] = $this->agencyWithNumbers(1, 'Other Agency');
        $otherCampaign = $this->managedCampaign($otherWorkspace);
        [$otherMember] = $this->members($otherWorkspace, $otherCampaign, 1, 1000);

        $this->signedWebhook($b, $this->receivedPayload($b, 'who is this?', 'in-b'))->assertOk();

        $box = ChatBox::query()->where('business_id', $business->id)->sole();
        $this->assertSame([$b->sender_number, '12025551000'], [$box->from, $box->to]);
        $this->assertSame((int) $box->id, (int) $this->member($member)->chat_box_id);
        $this->assertSame(1, AgencyProspectMessage::where('campaign_member_id', $member->id)->where('direction', 'inbound')->count());
        $this->assertSame(0, AgencyProspectMessage::where('campaign_member_id', $otherMember->id)->count(), 'Never routed to another tenant.');
        $this->assertSame(0, ChatBox::query()->where('business_id', $otherBusiness->id)->count());

        // A reply that arrives on A (not the assigned number) is still the same prospect's one conversation.
        $this->signedWebhook($a, $this->receivedPayload($a, 'hello?', 'in-a'))->assertOk();
        $this->assertSame(1, AgencyProspectCampaignMember::where('workspace_id', $workspace->id)->count(), 'No duplicate prospect conversation.');
        $this->assertSame(2, AgencyProspectMessage::where('campaign_member_id', $member->id)->where('direction', 'inbound')->count());
        foreach ($this->telnyxMessageRequests() as $request) {
            $this->assertSame('+' . $b->sender_number, $request['from'], 'Automatic answers only ever come from the assigned number.');
        }
    }

    public function test_an_opt_out_on_one_number_is_enforced_on_every_number(): void
    {
        [, $business, $workspace, [$a, $b]] = $this->agencyWithNumbers(2);
        $campaign = $this->managedCampaign($workspace);
        $this->selectNumbers($campaign, [$b]);
        [$member] = $this->members($workspace, $campaign, 1, 1000);
        $this->sendOpeners([$member]);

        $this->signedWebhook($b, $this->receivedPayload($b, 'STOP', 'in-stop'))->assertOk();
        $this->assertSame(1, Blacklists::query()->where('business_id', $business->id)->where('number', '12025551000')->count());

        $before = count($this->telnyxMessageRequests());
        foreach ([$a, $b] as $channel) {
            $send = app(OutreachMessageSender::class)->send($business, '12025551000', 'Hello again', 'after-stop-' . $channel->id, $channel);
            $this->assertSame(['blocked', 'opted_out'], [$send->status, $send->reason]);
        }

        // And a new campaign that would start them on A sends nothing.
        $campaign->update(['status' => 'ended']);
        $next = $this->managedCampaign($workspace, ['name' => 'Second']);
        $this->selectNumbers($next, [$a]);
        $again = $this->enroll($workspace, $next, $member->prospect()->first());
        app()->call([new OutreachInitialSendJob($again->id), 'handle']);

        $this->assertCount($before, $this->telnyxMessageRequests());
    }

    // ------------------------------------------------------------------ caps and pacing

    public function test_the_opener_cap_is_shared_by_all_numbers_and_pacing_still_applies(): void
    {
        config(['outreach.send_pacing.enabled' => true, 'queue.default' => 'database', 'outreach.reply_delay_enabled' => false]);
        \Illuminate\Support\Facades\Queue::fake();
        $this->freezeTime();

        [, , $workspace, $channels] = $this->agencyWithNumbers(3);
        \App\Models\AgencyProspectingSetting::query()->where('workspace_id', $workspace->id)->update(['opener_daily_cap' => 2]);
        config(['outreach.send_pacing.interval_seconds' => 0]);
        $campaign = $this->managedCampaign($workspace);
        $this->selectNumbers($campaign, $channels);

        $members = $this->members($workspace, $campaign, 4);
        foreach ($members as $member) {
            $this->travel(1)->minutes();
            app()->call([new OutreachInitialSendJob($member->id), 'handle']);
        }

        // Three numbers, but the one allowance of 2 is never multiplied.
        $this->assertCount(2, $this->telnyxMessageRequests());
        $this->assertSame(2, AgencyProspectMessage::where('workspace_id', $workspace->id)->where('purpose', 'initial')->where('status', 'paused')->where('failure_reason', 'opener_daily_cap')->count());

        // Spacing: with an interval, a second send on the same number right away waits.
        config(['outreach.send_pacing.interval_seconds' => 600]);
        [$late] = $this->members($workspace, $campaign, 1, 1300);
        \App\Models\AgencyProspectingSetting::query()->where('workspace_id', $workspace->id)->update(['opener_daily_cap' => 100]);
        app()->call([new OutreachInitialSendJob($late->id), 'handle']);
        $this->assertSame('rate_limited', AgencyProspectMessage::where('campaign_member_id', $late->id)->value('failure_reason'));
        $this->assertCount(2, $this->telnyxMessageRequests());
    }

    // ------------------------------------------------------------------ tenancy at the dispatcher

    public function test_a_foreign_channel_is_refused_at_the_dispatcher_and_nothing_is_sent(): void
    {
        [, $business] = $this->agencyWithNumbers(1);
        [, , , [$foreign]] = $this->agencyWithNumbers(1, 'Other Agency');

        $send = app(OutreachMessageSender::class)->send($business, '12025551000', 'Hi', 'foreign-op', $foreign);

        $this->assertSame(['paused', 'sender_unavailable:not_owned'], [$send->status, $send->reason]);
        $this->assertCount(0, $this->telnyxMessageRequests());
        $this->assertSame(0, DB::table('business_messaging_operations')->where('operation_key', 'foreign-op')->count());
    }

    public function test_a_manual_conversations_reply_answers_from_the_threads_own_number(): void
    {
        [, $business, $workspace, [$a, $b]] = $this->agencyWithNumbers(2);
        $campaign = $this->managedCampaign($workspace);
        $this->selectNumbers($campaign, [$b]);
        [$member] = $this->members($workspace, $campaign, 1, 1000);
        $this->sendOpeners([$member]);
        $box = ChatBox::query()->findOrFail($this->member($member)->chat_box_id);

        app(\App\Library\Conversations\ConversationSender::class)->reply($box, 'Hi, this is Sam replying by hand.', null, 'manual-uid-1');

        $requests = $this->telnyxMessageRequests();
        $this->assertCount(2, $requests);
        $this->assertSame('+' . $b->sender_number, $requests[1]['from'], 'Not the default sender ' . $a->sender_number);
        $this->assertSame(1, ChatBox::query()->where('business_id', $business->id)->count());
    }
}

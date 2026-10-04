<?php

namespace Tests\Feature\AgencyOutreach;

use App\Enums\AgencyProspecting\AgencyProspectStatus;
use App\Jobs\Outreach\OutreachFollowUpJob;
use App\Jobs\Outreach\OutreachRespondJob;
use App\Library\AgencyOutreach\OutreachMessageSender;
use App\Library\AgencyOutreach\OutreachTakeoverService;
use App\Models\AgencyProspect;
use App\Models\AgencyProspectCampaign;
use App\Models\AgencyProspectCampaignMember;
use App\Models\AgencyProspectMessage;
use App\Models\Blacklists;
use App\Models\Business;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\AgencyOutreach\Concerns\BuildsOutreachFixtures;
use Tests\TestCase;

/**
 * Agency Outreach V1 — everything that must NOT happen: opt-out and rejection, the
 * send-time eligibility gates, manual takeover, tenancy isolation between Agencies and
 * forged ids (contract §6/§9/§13).
 */
class OutreachSafetyTest extends TestCase
{
    use RefreshDatabase;
    use BuildsOutreachFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootOutreach();
    }

    /** @return array{0: Workspace, 1: Business, 2: AgencyProspectCampaignMember, 3: AgencyProspect, 4: AgencyProspectCampaign} */
    private function scenario(string $phone = '12025551000', int $stage = 1, string $name = 'Snap Booth Co'): array
    {
        [, $business, $workspace] = $this->agency($name);
        $this->saveScript($workspace, ['agency_name' => $name]);

        $campaign = $this->managedCampaign($workspace);
        $prospect = $this->prospect($workspace, $phone);
        $member = $this->enroll($workspace, $campaign, $prospect, $stage);

        return [$workspace, $business, $member, $prospect, $campaign];
    }

    // ------------------------------------------------------------------ opt-out / rejection

    public function test_stop_blacklists_the_number_stops_the_prospect_cancels_the_follow_up_and_sends_nothing(): void
    {
        [$workspace, $business, $member, $prospect] = $this->scenario('12025551000', 4);
        $member->update(['followup_at' => now()->addHours(24), 'booking_link_sent_at' => now()]);

        $this->inbound($business, 'STOP');

        $this->assertCount(0, $this->fakeAdapter->sentRequests);

        $black = Blacklists::query()->where('business_id', $business->id)->sole();
        $this->assertSame('12025551000', $black->number);
        $this->assertSame($business->customer_id, $black->user_id);

        $prospect = $prospect->fresh();
        $this->assertSame(AgencyProspectStatus::Stopped, $prospect->status);
        $this->assertSame('opt_out', $prospect->stop_reason);
        $this->assertNotNull($prospect->stopped_at);

        $fresh = $this->member($member);
        $this->assertSame(99, $fresh->stage->value);
        $this->assertNotNull($fresh->followup_cancelled_at);

        $inbound = $this->ledger($member, 'inbound')[0];
        $this->assertSame('handled', $inbound->status);
        $this->assertSame('opt_out', $inbound->intent);
    }

    public function test_stop_is_idempotent_and_a_stopped_prospect_is_never_texted_again_by_anything(): void
    {
        [$workspace, $business, $member, $prospect] = $this->scenario('12025551000', 4);
        $member->update(['followup_at' => now()->subHour(), 'booking_link_sent_at' => now()->subDay()]);

        $key = $this->inbound($business, 'unsubscribe');
        $this->deliverInbound($business, '12025551000', $key);
        $this->inbound($business, 'stop');

        $this->assertSame(1, Blacklists::query()->where('business_id', $business->id)->count());

        // No follow-up, no reply job, no direct send gets through.
        OutreachFollowUpJob::dispatch($member->id);
        $this->inbound($business, 'hello again');
        $this->assertCount(0, $this->fakeAdapter->sentRequests);

        $sender = app(OutreachMessageSender::class)->send($business, '12025551000', 'forced', 'forced-key-1');
        $this->assertSame(['blocked', 'opted_out'], [$sender->status, $sender->reason]);
        $this->assertCount(0, $this->fakeAdapter->sentRequests);
    }

    public function test_nonstop_and_unstoppable_do_not_opt_out(): void
    {
        [$workspace, $business, $member] = $this->scenario();

        $this->inbound($business, 'we are busy nonstop but yes tell me more');

        $this->assertSame(0, Blacklists::query()->count());
        $this->assertCount(1, $this->fakeAdapter->sentRequests);
        $this->assertSame(2, $this->member($member)->stage->value);
    }

    public function test_a_hard_rejection_ends_the_conversation_without_a_blacklist_row(): void
    {
        [$workspace, $business, $member, $prospect] = $this->scenario('12025551000', 2);

        $this->inbound($business, 'Not interested, thanks');

        $this->assertCount(0, $this->fakeAdapter->sentRequests);
        $this->assertSame(0, Blacklists::query()->count(), 'A polite no is not a carrier opt-out.');
        $this->assertSame('rejected', $prospect->fresh()->stop_reason);
        $this->assertSame(AgencyProspectStatus::Stopped, $prospect->fresh()->status);
        $this->assertSame(99, $this->member($member)->stage->value);
    }

    public function test_a_soft_no_before_message_1_is_an_ordinary_reply_and_after_it_ends_the_conversation(): void
    {
        [$workspace, $business, $member] = $this->scenario();

        $this->inbound($business, 'no thanks');
        $this->assertCount(1, $this->fakeAdapter->sentRequests, 'Before message 1 a soft no still gets message 1.');
        $this->assertSame(2, $this->member($member)->stage->value);

        $this->inbound($business, 'no thanks');
        $this->assertCount(1, $this->fakeAdapter->sentRequests);
        $this->assertSame(99, $this->member($member)->stage->value);
        $this->assertSame(0, Blacklists::query()->count());
    }

    public function test_booked_and_terminal_members_never_reply(): void
    {
        [$workspace, $business, $member, $prospect] = $this->scenario('12025551000', 6);

        $this->inbound($business, 'How much does it cost?');

        $this->assertCount(0, $this->fakeAdapter->sentRequests);
        $this->assertCount(0, $this->ledger($member));
    }

    // ------------------------------------------------------------------ send-time gates

    public function test_the_gates_are_rechecked_at_send_time_and_the_reason_is_recorded(): void
    {
        $cases = [
            'campaign_not_active' => fn (AgencyProspectCampaign $c, AgencyProspect $p, Workspace $w, Business $b) => $c->update(['status' => 'paused']),
            'campaign_not_managed' => fn (AgencyProspectCampaign $c) => $c->update(['sending_mode' => 'channel']),
            'prospect_not_active' => fn (AgencyProspectCampaign $c, AgencyProspect $p) => $p->update(['status' => 'stopped']),
            'workspace_inactive' => fn (AgencyProspectCampaign $c, AgencyProspect $p, Workspace $w) => $w->update(['is_active' => false]),
            'no_business' => fn (AgencyProspectCampaign $c, AgencyProspect $p, Workspace $w, Business $b) => DB::table('businesses')->where('id', $b->id)->update(['status' => 'inactive']),
            'opted_out' => fn (AgencyProspectCampaign $c, AgencyProspect $p, Workspace $w, Business $b) => Blacklists::create(['user_id' => $b->customer_id, 'business_id' => $b->id, 'number' => $p->phone, 'reason' => 'manual']),
        ];

        foreach ($cases as $reason => $break) {
            $this->fakeAdapter->sentRequests = [];
            [$workspace, $business, $member, $prospect, $campaign] = $this->scenario('12025551000', 1, 'Gate ' . $reason);

            $break($campaign, $prospect, $workspace, $business);
            // Provoke the responder directly: the listener has its own (earlier) filters.
            $this->inbound($business, 'yes', '12025551000', null, false);
            $incoming = \App\Models\ChatBoxMessage::query()->latest('id')->first();
            $row = AgencyProspectMessage::create([
                'workspace_id' => $workspace->id, 'campaign_member_id' => $member->id, 'direction' => 'inbound',
                'operation_key' => 'outreach:in:gate:' . $reason, 'body' => (string) $incoming->message, 'status' => 'received', 'received_at' => now(),
            ]);

            app()->call([new OutreachRespondJob($member->id, $row->id), 'handle']);

            $this->assertCount(0, $this->fakeAdapter->sentRequests, $reason);
            $this->assertSame($reason, $row->fresh()->failure_reason, $reason);
            $this->assertSame('handled', $row->fresh()->status, $reason);
            $this->assertSame(0, AgencyProspectMessage::query()->where('campaign_member_id', $member->id)->where('direction', 'outbound')->count(), $reason);
            $this->assertSame(1, $this->member($member)->stage->value, $reason);
        }
    }

    public function test_a_business_without_managed_messaging_is_blocked_not_sent_through_a_legacy_gateway(): void
    {
        [$workspace, $business, $member] = $this->scenario();
        \App\Models\BusinessMessagingIdentity::query()->where('business_id', $business->id)->update(['status' => 'suspended']);

        $this->inbound($business, 'yes');

        $this->assertCount(0, $this->fakeAdapter->sentRequests);
        // The canonical readiness gate refuses before an outbound row is even claimed, and says why on the inbound.
        $this->assertSame([], $this->ledger($member, 'outbound'));
        $this->assertSame('no_sending_number', $this->ledger($member, 'inbound')[0]->failure_reason);
        $this->assertSame(1, $this->member($member)->stage->value);
    }

    public function test_the_platform_kill_switch_is_a_blocked_provider_not_configured_result(): void
    {
        [$workspace, $business] = $this->scenario();
        config(['messaging.managed_messaging_enabled' => false]);

        $result = app(OutreachMessageSender::class)->send($business, '12025551000', 'hello', 'kill-switch-1');

        $this->assertSame(['blocked', 'provider_not_configured'], [$result->status, $result->reason]);
        $this->assertCount(0, $this->fakeAdapter->sentRequests);
    }

    public function test_every_canonical_refusal_maps_to_its_exact_reason_and_never_throws(): void
    {
        [$workspace, $business] = $this->scenario();

        $map = [
            \App\Library\Messaging\Exceptions\MessagingInsufficientFundsException::class => ['paused', 'insufficient_balance', fn () => new \App\Library\Messaging\Exceptions\MessagingInsufficientFundsException('insufficient_balance')],
            \App\Library\Messaging\Exceptions\MessagingIdentityConflictException::class => ['blocked', 'messaging_not_ready', fn () => new \App\Library\Messaging\Exceptions\MessagingIdentityConflictException('no identity')],
            \App\Library\Messaging\Exceptions\MessagingCampaignAssignmentNotConfirmedException::class => ['blocked', 'campaign_assignment_not_confirmed', fn () => new \App\Library\Messaging\Exceptions\MessagingCampaignAssignmentNotConfirmedException('not confirmed')],
            \App\Library\Messaging\Exceptions\MessagingProviderNotConfiguredException::class => ['blocked', 'provider_not_configured', fn () => new \App\Library\Messaging\Exceptions\MessagingProviderNotConfiguredException('off')],
            \RuntimeException::class => ['failed', 'send_exception', fn () => new \RuntimeException('boom')],
        ];

        foreach ($map as $class => [$status, $reason, $make]) {
            $mock = \Mockery::mock(\App\Repositories\Contracts\CampaignRepository::class);
            $mock->shouldReceive('quickSend')->once()->andThrow($make());
            $this->app->instance(\App\Repositories\Contracts\CampaignRepository::class, $mock);

            $result = app(OutreachMessageSender::class)->send($business, '12025551000', 'hello', 'map-' . class_basename($class));

            $this->assertSame([$status, $reason], [$result->status, $result->reason], $class);
        }
    }

    // ------------------------------------------------------------------ manual takeover

    public function test_manual_takeover_pauses_the_ai_is_audited_and_resume_restores_it(): void
    {
        [$workspace, $business, $member] = $this->scenario();
        $actor = User::query()->findOrFail($business->customer_id);
        $takeover = app(OutreachTakeoverService::class);

        $takeover->pause($member, $actor);
        $takeover->pause($member, $actor); // idempotent

        $paused = $this->member($member);
        $this->assertNotNull($paused->ai_paused_at);
        $this->assertSame($actor->id, (int) $paused->ai_paused_by_user_id);

        $this->inbound($business, 'yes');
        $this->assertCount(0, $this->fakeAdapter->sentRequests);
        $this->assertSame('manual_hold', $this->ledger($member, 'inbound')[0]->failure_reason);
        $this->assertSame(1, $this->member($member)->stage->value);

        $events = AgencyProspectMessage::query()->where('campaign_member_id', $member->id)->where('direction', 'event')->get();
        $this->assertCount(1, $events, 'One audit row per real change.');
        $this->assertSame([$actor->id, 'manual', 'ai_paused'], [(int) $events[0]->actor_user_id, $events[0]->source, $events[0]->intent]);

        $takeover->resume($member, $actor);
        $this->assertNull($this->member($member)->ai_paused_at);
        $this->assertSame(['ai_paused', 'ai_resumed'], AgencyProspectMessage::query()->where('campaign_member_id', $member->id)->where('direction', 'event')->orderBy('id')->pluck('intent')->all());

        $this->inbound($business, 'yes again');
        $this->assertCount(1, $this->fakeAdapter->sentRequests, 'After resume the engine answers the next reply.');
    }

    public function test_the_follow_up_does_nothing_while_the_ai_is_paused(): void
    {
        [$workspace, $business, $member] = $this->scenario('12025551000', 4);
        $member->update(['followup_at' => now()->subHour(), 'booking_link_sent_at' => now()->subDay()]);
        app(OutreachTakeoverService::class)->pause($member, User::query()->findOrFail($business->customer_id));

        OutreachFollowUpJob::dispatch($member->id);

        $this->assertCount(0, $this->fakeAdapter->sentRequests);
        $this->assertNull($this->member($member)->followup_cancelled_at, 'Held, not cancelled: it goes out after Resume.');
    }

    public function test_an_owner_reply_after_the_latest_inbound_suppresses_the_automatic_reply(): void
    {
        [$workspace, $business, $member] = $this->scenario();

        // The prospect writes; before the engine answers, the owner replies by hand in Conversations.
        $this->inbound($business, 'yes sure', '12025551000', null, false);
        $this->ownerRepliesByHand($business, '12025551000', 'Hi, it is the owner, call me.');
        $this->deliverInbound($business, '12025551000', 'operation:5001');

        $this->assertCount(0, $this->fakeAdapter->sentRequests, 'No double reply.');
        $inbound = $this->ledger($member, 'inbound')[0];
        $this->assertSame('manual_reply_sent', $inbound->failure_reason);
        $this->assertSame('handled', $inbound->status);

        // A NEW inbound after the owner's message is answered normally.
        $this->inbound($business, 'ok great');
        $this->assertCount(1, $this->fakeAdapter->sentRequests);
    }

    // ------------------------------------------------------------------ tenancy

    public function test_an_inbound_for_agency_a_never_touches_agency_b_even_for_the_same_number(): void
    {
        [$wsA, $bizA, $memberA] = $this->scenario('12025551000', 1, 'Alpha Booths');
        [$wsB, $bizB, $memberB] = $this->scenario('12025551000', 1, 'Beta Gyms');
        \App\Library\AgencyOutreach\OutreachScriptManager::save($wsA, ['message_1' => 'Alpha opener from {{agency_name}}']);
        \App\Library\AgencyOutreach\OutreachScriptManager::save($wsB, ['message_1' => 'Beta opener from {{agency_name}}']);

        $this->inbound($bizA, 'yes');

        $this->assertSame(['Alpha opener from Alpha Booths'], $this->sentBodies());
        $this->assertSame(2, $this->member($memberA)->stage->value);
        $this->assertSame(1, $this->member($memberB)->stage->value);
        $this->assertCount(0, $this->ledger($memberB));

        // A STOP to Agency A blacklists for A only.
        $this->inbound($bizA, 'STOP');
        $this->assertSame(1, Blacklists::query()->where('business_id', $bizA->id)->count());
        $this->assertSame(0, Blacklists::query()->where('business_id', $bizB->id)->count());
        $this->assertSame(AgencyProspectStatus::Active, $memberB->prospect->fresh()->status);

        // And B can still reach that same number with its own copy.
        $this->inbound($bizB, 'yes');
        $this->assertSame('Beta opener from Beta Gyms', $this->sentBodies()[1]);
        $this->assertSame(2, $this->member($memberB)->stage->value);
    }

    public function test_a_number_that_is_only_another_agencys_prospect_is_ignored(): void
    {
        [$wsA, $bizA, $memberA] = $this->scenario('12025551000', 1, 'Alpha Booths');
        [$wsB, $bizB, $memberB] = $this->scenario('12025559999', 1, 'Beta Gyms');

        $this->inbound($bizA, 'yes', '12025559999');

        $this->assertCount(0, $this->fakeAdapter->sentRequests);
        $this->assertCount(0, $this->ledger($memberB));
        $this->assertCount(0, $this->ledger($memberA));
    }

    public function test_forged_or_cross_agency_ids_fail_closed(): void
    {
        [$wsA, $bizA, $memberA] = $this->scenario('12025551000', 1, 'Alpha Booths');
        [$wsB, $bizB, $memberB] = $this->scenario('12025552000', 1, 'Beta Gyms');

        $this->inbound($bizA, 'yes', '12025551000', null, false);
        $this->deliverInbound($bizA, '12025551000', 'operation:7001');
        $inboundA = $this->ledger($memberA, 'inbound')[0];
        $this->fakeAdapter->sentRequests = [];
        DB::table('agency_prospect_messages')->where('operation_key', 'like', 'outreach:reply:%')->delete();
        $inboundA->update(['status' => 'received']);
        $memberA->update(['stage' => 1]);

        // Member B paired with Agency A's inbound message: ids that do not bind to one member.
        app()->call([new OutreachRespondJob($memberB->id, $inboundA->id), 'handle']);
        $this->assertCount(0, $this->fakeAdapter->sentRequests);

        // Inbound row claiming Agency B's Workspace but attached to Agency A's member.
        $forged = AgencyProspectMessage::create([
            'workspace_id' => $wsB->id, 'campaign_member_id' => $memberA->id, 'direction' => 'inbound',
            'operation_key' => 'outreach:in:forged', 'body' => 'yes', 'status' => 'received', 'received_at' => now(),
        ]);
        app()->call([new OutreachRespondJob($memberA->id, $forged->id), 'handle']);
        $this->assertCount(0, $this->fakeAdapter->sentRequests);

        // A member whose prospect belongs to ANOTHER Workspace (forged pairing): tenancy_mismatch.
        $memberA->update(['prospect_id' => $memberB->prospect_id]);
        app()->call([new OutreachRespondJob($memberA->id, $inboundA->id), 'handle']);
        $this->assertCount(0, $this->fakeAdapter->sentRequests);
        $this->assertSame('tenancy_mismatch', $inboundA->fresh()->failure_reason);

        // Nothing for B was ever written.
        $this->assertCount(0, $this->ledger($memberB));
        $this->assertSame(1, $this->member($memberB)->stage->value);
    }
}

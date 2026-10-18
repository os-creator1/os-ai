<?php

namespace Tests\Feature\Assistant;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Calendar\BookingTypeManager;
use App\Library\Calendar\CalendarLocationResolver;
use App\Models\AssistantActionAudit;
use App\Models\AssistantActionProposal;
use App\Models\BookingType;
use App\Models\BusinessLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Assistant\Concerns\AssistantTestSupport;
use Tests\TestCase;

class AssistantActionTest extends TestCase
{
    use RefreshDatabase;
    use AssistantTestSupport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAssistant();
    }

    /** @return array{0: \App\Models\Customer, 1: \App\Models\Business, 2: \App\Models\Workspace, 3: string} the proposal uid */
    private function proposeBookingType(string $name = 'Wedding package', int $minutes = 180): array
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Action Venue', 'Action Account');
        $this->authenticateAs($customer);

        $this->modelSays(['action' => 'propose', 'tool' => 'create_booking_type', 'args' => ['name' => $name, 'duration_minutes' => $minutes], 'message' => 'I can set that up.']);
        $r = $this->say($workspace, $business, 'Create a booking type for a 3-hour wedding package')->assertOk();
        $reply = $this->lastReply($r);

        $this->assertSame('proposal', $reply['kind']);

        return [$customer, $business, $workspace, $reply['proposal']['uid']];
    }

    private function approve($workspace, $business, string $uid)
    {
        return $this->postJson($this->assistantUrl($workspace, $business, 'approve', [$uid]));
    }

    public function test_a_write_is_only_prepared_until_the_customer_approves_it(): void
    {
        [$customer, $business, $workspace, $uid] = $this->proposeBookingType();

        $proposal = AssistantActionProposal::query()->where('uid', $uid)->firstOrFail();
        $this->assertSame('pending', $proposal->status);
        $this->assertSame(0, BookingType::query()->where('name', 'Wedding package')->count(), 'Nothing is written before approval.');
        $this->assertSame('Wedding package', $proposal->preview['lines'][0]['value']);
        $this->assertSame('3 hours', $proposal->preview['lines'][1]['value']);
        $this->assertNotEmpty($proposal->preview['effects']);
    }

    public function test_approval_runs_the_domain_mechanism_once_and_shows_the_actual_outcome(): void
    {
        [$customer, $business, $workspace, $uid] = $this->proposeBookingType();

        $r = $this->approve($workspace, $business, $uid)->assertOk();

        $this->assertSame('completed', $r->json('state'));
        $this->assertStringContainsString('Wedding package', $r->json('message'));
        $this->assertNotNull($r->json('url'));
        $this->assertSame(1, BookingType::query()->where('name', 'Wedding package')->count());

        $events = AssistantActionAudit::query()->orderBy('id')->pluck('event')->all();
        $this->assertSame(['proposed', 'approved', 'executed'], $events);
        $this->assertSame($customer->user_id, AssistantActionAudit::query()->where('event', 'executed')->value('actor_user_id'));

        $history = $this->getJson($this->assistantUrl($workspace, $business, 'show'))->json('messages');
        $this->assertSame('outcome', end($history)['kind']);
    }

    public function test_a_double_click_or_retry_cannot_create_a_second_effect(): void
    {
        [, $business, $workspace, $uid] = $this->proposeBookingType();

        $first = $this->approve($workspace, $business, $uid)->assertOk();
        $second = $this->approve($workspace, $business, $uid)->assertOk();

        $this->assertSame('completed', $first->json('state'));
        $this->assertSame('replayed', $second->json('state'));
        $this->assertSame(1, BookingType::query()->where('name', 'Wedding package')->count());
        $this->assertSame(1, AssistantActionAudit::query()->where('event', 'executed')->count());
    }

    public function test_a_claim_in_flight_blocks_a_concurrent_second_request(): void
    {
        [, $business, $workspace, $uid] = $this->proposeBookingType();
        AssistantActionProposal::query()->where('uid', $uid)->update(['status' => 'executing', 'attempts' => 1, 'lease_token' => 'live-owner', 'fence' => 1, 'lease_expires_at' => now()->addSeconds(30)]);

        $r = $this->approve($workspace, $business, $uid);

        $r->assertStatus(409);
        $this->assertSame('busy', $r->json('state'));
        $this->assertSame(0, BookingType::query()->where('name', 'Wedding package')->count());
    }

    public function test_declining_does_nothing_and_cannot_be_approved_later(): void
    {
        [, $business, $workspace, $uid] = $this->proposeBookingType();

        $this->postJson($this->assistantUrl($workspace, $business, 'decline', [$uid]))->assertOk()->assertJsonPath('state', 'declined');
        $r = $this->approve($workspace, $business, $uid)->assertOk();

        $this->assertSame('declined', $r->json('state'));
        $this->assertSame(0, BookingType::query()->where('name', 'Wedding package')->count());
    }

    public function test_an_expired_proposal_is_never_executed(): void
    {
        [, $business, $workspace, $uid] = $this->proposeBookingType();
        AssistantActionProposal::query()->where('uid', $uid)->update(['expires_at' => now()->subMinute()]);

        $r = $this->approve($workspace, $business, $uid)->assertOk();

        $this->assertSame('expired', $r->json('state'));
        $this->assertSame(0, BookingType::query()->where('name', 'Wedding package')->count());
        $this->assertContains('expired', AssistantActionAudit::query()->pluck('event')->all());
    }

    public function test_losing_permission_between_preview_and_approval_blocks_the_write(): void
    {
        [$customer, $business, $workspace, $uid] = $this->proposeBookingType();

        // The request-level backend gate stops an actor who lost all backend access outright.
        $this->withSession(['permissions' => collect([])]);
        $this->approve($workspace, $business, $uid)->assertStatus(401);
        $this->withSession(['permissions' => collect(['access_backend'])]);

        // The assistant's own revalidation: the same actor, but the action is no longer reachable for them
        // (the four-way rule behind HomeActionRouter), as when the plan or role changed in the meantime.
        $live = $this->liveContext($workspace, $business, $customer);
        $reduced = new \App\Library\Assistant\AssistantContext($live->workspace, $live->business, $live->user, $live->customer, $live->entitlements, [], $live->scoped);
        $result = app(\App\Library\Assistant\AssistantActionService::class)->approve($reduced, $uid);

        $this->assertSame('stale', $result['state']);
        $this->assertStringContainsString('no longer have access', (string) $result['message']);
        $this->assertSame(0, BookingType::query()->where('name', 'Wedding package')->count());
        $this->assertContains('revalidation_failed', AssistantActionAudit::query()->pluck('event')->all());
    }

    private function liveContext($workspace, $business, $customer): \App\Library\Assistant\AssistantContext
    {
        $shell = app(\App\Library\Navigation\CustomerShellComposer::class);
        $context = $shell->currentContext($customer->user);
        $entitlements = $shell->currentMenuEntitlements($context);

        return new \App\Library\Assistant\AssistantContext(
            $workspace, $business, $customer->user, $context, $entitlements,
            app(\App\Library\Dashboard\Home\HomeActionRouter::class)->forActor($context, $customer->user, $entitlements, [$workspace->uid, $business->uid], null),
            [$workspace->uid, $business->uid],
        );
    }

    public function test_a_changed_underlying_record_between_preview_and_approval_makes_it_stale(): void
    {
        [, $business, $workspace, $uid] = $this->proposeBookingType();
        $location = BusinessLocation::query()->where('business_id', $business->id)->firstOrFail();
        app(BookingTypeManager::class)->create($location, ['name' => 'Wedding package', 'duration_minutes' => 60], (int) $business->customer_id);

        $r = $this->approve($workspace, $business, $uid)->assertOk();

        $this->assertSame('stale', $r->json('state'));
        $this->assertSame(1, BookingType::query()->where('name', 'Wedding package')->count(), 'Only the pre-existing one.');
    }

    public function test_a_failed_execution_leaves_nothing_behind_and_can_be_retried_without_duplicating(): void
    {
        [, $business, $workspace, $uid] = $this->proposeBookingType();

        $failing = new class(app(CalendarLocationResolver::class)) extends BookingTypeManager {
            public static int $calls = 0;

            public function create(BusinessLocation $location, array $attributes, int $actorUserId): BookingType
            {
                $created = parent::create($location, $attributes, $actorUserId);

                // The first attempt writes, then dies before the result is recorded: the shared transaction must undo the write.
                if (++self::$calls === 1) {
                    throw new \RuntimeException('database went away');
                }

                return $created;
            }
        };
        // Resolve the tool with the failing manager (the registry is built lazily, so this is in time).
        $this->app->instance(BookingTypeManager::class, $failing);
        $this->app->forgetInstance(\App\Library\Assistant\AssistantToolRegistry::class);

        $first = $this->approve($workspace, $business, $uid)->assertOk();
        $this->assertSame('failed', $first->json('state'));
        $this->assertTrue($first->json('proposal.retry'));
        $this->assertSame(0, BookingType::query()->where('name', 'Wedding package')->count(), 'The failed attempt was rolled back.');

        $retry = $this->approve($workspace, $business, $uid)->assertOk();
        $this->assertSame('completed', $retry->json('state'));
        $this->assertSame(2, AssistantActionProposal::query()->where('uid', $uid)->value('attempts'));
        $this->assertSame(1, BookingType::query()->where('name', 'Wedding package')->count());
        $this->assertContains('retried', AssistantActionAudit::query()->pluck('event')->all());
    }

    public function test_another_customers_proposal_cannot_be_approved_or_declined(): void
    {
        [, $business, $workspace, $uid] = $this->proposeBookingType();
        [$other, $otherBusiness, $otherWorkspace] = $this->tenant(WorkspacePlanTier::Growth, 'Other Venue', 'Other Account');
        $this->authenticateAs($other);

        $this->approve($otherWorkspace, $otherBusiness, $uid)->assertNotFound();
        $this->postJson($this->assistantUrl($otherWorkspace, $otherBusiness, 'decline', [$uid]))->assertNotFound();
        $this->approve($workspace, $business, $uid)->assertNotFound();
        $this->assertSame('pending', AssistantActionProposal::query()->where('uid', $uid)->value('status'));
    }

    public function test_missing_information_is_asked_for_conversationally(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Ask Venue', 'Ask Account');
        $this->authenticateAs($customer);

        $this->modelSays(['action' => 'propose', 'tool' => 'create_booking_type', 'args' => ['name' => 'Wedding package'], 'message' => 'Sure.']);
        $r = $this->say($workspace, $business, 'Create a booking type for a wedding package')->assertOk();

        $reply = $this->lastReply($r);
        $this->assertSame('clarify', $reply['kind']);
        $this->assertStringContainsString('duration minutes', $reply['body']);
        $this->assertSame(0, AssistantActionProposal::query()->count());
    }

    public function test_an_appointment_is_prepared_for_review_and_booked_only_on_approval(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Book Venue', 'Book Account');
        $this->authenticateAs($customer);
        $location = BusinessLocation::query()->where('business_id', $business->id)->firstOrFail();
        DB::table('contacts')->insert([
            'uid' => (string) Str::uuid(), 'customer_id' => $business->customer_id, 'business_id' => $business->id,
            'location_id' => $location->id, 'group_id' => $this->contactGroup($business)->id, 'phone' => '15557770000', 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->modelSays(['action' => 'propose', 'tool' => 'create_appointment', 'args' => ['contact' => '15557770000', 'date' => '2026-09-14', 'time' => '10:00'], 'message' => 'Here is the booking.']);
        $reply = $this->lastReply($this->say($workspace, $business, 'Book an appointment for Monday at 10 for 15557770000')->assertOk());

        $this->assertSame('proposal', $reply['kind']);
        $labels = array_column($reply['proposal']['preview']['lines'], 'value', 'label');
        $this->assertStringContainsString('15557770000', $labels['Customer']);
        $this->assertSame('Consultation', $labels['Service']);
        $this->assertSame(0, DB::table('appointments')->count());

        $done = $this->approve($workspace, $business, $reply['proposal']['uid'])->assertOk();
        $this->assertSame('completed', $done->json('state'));
        $this->assertSame(1, DB::table('appointments')->count());
        $this->assertSame('scheduled', DB::table('appointments')->value('status'));
    }

    public function test_a_slot_the_engine_refuses_is_reported_as_a_failure_not_a_booking(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Refuse Venue', 'Refuse Account');
        $this->authenticateAs($customer);
        $location = BusinessLocation::query()->where('business_id', $business->id)->firstOrFail();
        DB::table('contacts')->insert([
            'uid' => (string) Str::uuid(), 'customer_id' => $business->customer_id, 'business_id' => $business->id,
            'location_id' => $location->id, 'group_id' => $this->contactGroup($business)->id, 'phone' => '15558880000', 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Monday 03:00 is outside the fixture's working hours.
        $this->modelSays(['action' => 'propose', 'tool' => 'create_appointment', 'args' => ['contact' => '15558880000', 'date' => '2026-09-14', 'time' => '03:00'], 'message' => 'Booking.']);
        $reply = $this->lastReply($this->say($workspace, $business, 'Book 15558880000 for 3am Monday')->assertOk());
        $r = $this->approve($workspace, $business, $reply['proposal']['uid'])->assertOk();

        $this->assertSame('failed', $r->json('state'));
        $this->assertNotSame('', (string) $r->json('message'));
        $this->assertSame(0, DB::table('appointments')->count());
    }

    public function test_a_reply_to_a_waiting_customer_is_handed_to_follow_ups_and_never_sent_by_the_assistant(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Reply Venue', 'Reply Account');
        $this->conversationWith($business, [['incoming', \Carbon\CarbonImmutable::now()->subMinutes(40)->setTimezone((string) config('app.timezone', 'UTC'))->format('Y-m-d H:i:s')]]);
        $this->authenticateAs($customer);
        $ref = (string) DB::table('chat_boxes')->value('uid');
        $outbound = DB::table('chat_box_messages')->count();

        $this->modelSays(['action' => 'propose', 'tool' => 'draft_reply', 'args' => ['conversation_ref' => $ref, 'message' => 'Thanks for getting in touch — we can help on Friday.'], 'message' => 'Here is a reply.']);
        $reply = $this->lastReply($this->say($workspace, $business, 'Help me reply to that customer')->assertOk());

        $this->assertSame('proposal', $reply['kind']);
        $this->assertSame('Thanks for getting in touch — we can help on Friday.', array_column($reply['proposal']['preview']['lines'], 'value', 'label')['Your reply']);
        $this->assertNotEmpty($reply['proposal']['preview']['recipients']);

        $done = $this->approve($workspace, $business, $reply['proposal']['uid'])->assertOk();

        $this->assertSame('handed_off', $done->json('state'));
        $this->assertStringContainsString('Nothing has been sent', $done->json('message'));
        $this->assertStringContainsString('proposal=', $done->json('url'));
        $this->assertSame($outbound, DB::table('chat_box_messages')->count(), 'The assistant itself sends nothing.');
    }

    public function test_a_reply_for_a_conversation_the_actor_cannot_see_is_refused(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Hidden Venue', 'Hidden Account');
        $this->authenticateAs($customer);

        $this->modelSays(['action' => 'propose', 'tool' => 'draft_reply', 'args' => ['conversation_ref' => 'someone-elses-uid', 'message' => 'Hi there'], 'message' => 'Reply.']);
        $reply = $this->lastReply($this->say($workspace, $business, 'Reply to them')->assertOk());

        $this->assertSame('unsupported', $reply['kind']);
        $this->assertSame(0, AssistantActionProposal::query()->count());
    }

    public function test_a_draft_automation_is_created_inactive_through_the_workflow_service(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Flow Venue', 'Flow Account');
        $this->authenticateAs($customer);

        $this->modelSays(['action' => 'propose', 'tool' => 'prepare_automation', 'args' => ['name' => 'Follow up new leads', 'trigger' => 'contact_created'], 'message' => 'Draft ready.']);
        $reply = $this->lastReply($this->say($workspace, $business, 'Create an automation that follows up with new leads')->assertOk());

        $this->assertSame('proposal', $reply['kind']);
        $this->assertStringContainsString('NOT switched on', implode(' ', $reply['proposal']['preview']['effects']));
        $this->assertSame(0, DB::table('automation_workflows')->count());

        $done = $this->approve($workspace, $business, $reply['proposal']['uid'])->assertOk();

        $this->assertSame('completed', $done->json('state'));
        $this->assertSame(1, DB::table('automation_workflows')->count());
        $this->assertSame('draft', DB::table('automation_workflows')->value('status'));
        $this->assertNull(DB::table('automation_workflows')->value('published_version_id'));
    }
}

<?php

namespace Tests\Feature\Conversations;

use App\Enums\Automation\AutomationActionType;
use App\Enums\Automation\AutomationExecutionStatus;
use App\Enums\Automation\AutomationTriggerType;
use App\Enums\Automation\Workflow\EnrollmentStatus;
use App\Enums\Automation\Workflow\StepRunStatus;
use App\Enums\Timeline\TimelineItemKind;
use App\Library\Timeline\ContactActivityTimeline;
use App\Library\Timeline\TimelineItem;
use App\Library\Timeline\TimelinePage;
use App\Models\Automation;
use App\Models\Business;
use App\Models\ChatBox;
use App\Models\Contacts;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\Feature\Automations\Workflow\Actions\Support\BuildsActionWorkflows;
use Tests\Feature\Automations\Workflow\Runtime\Support\BuildsWorkflows;
use Tests\Feature\Conversations\Concerns\CreatesTimelineFixtures;
use Tests\Feature\Conversations\Support\StubTimelineSource;
use Tests\TestCase;

/**
 * Conversations — one chronological activity timeline per person, merged from
 * canonical rows only: conversation messages, automation and campaign texts
 * from `reports`, automation outcomes, the contact record and the block list.
 * Never a copy, never an inference, never another Business's row, and never
 * the same event twice.
 */
class ContactActivityTimelineTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAutomationFixtures;
    use BuildsWorkflows;
    use BuildsActionWorkflows;
    use CreatesTimelineFixtures;

    private const PHONE = '12025550111';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-10 15:00:00'));
        StubTimelineSource::$items = [];
    }

    public function test_messages_both_ways_and_what_happened_around_them_read_oldest_first(): void
    {
        [, $business] = $this->entitledTenant();
        $contact = $this->addedOn($this->namedContact($business, self::PHONE, 'Maya', 'Lopez'), '2026-09-01 09:00:00');
        $box = $this->conversationWith($business, self::PHONE);

        $this->message($box, 'incoming', 'Do you have Saturday slots?', Carbon::parse('2026-09-08 10:00:00'));
        $this->message($box, 'outgoing', 'Yes — 10am or 2pm.', Carbon::parse('2026-09-08 10:05:00'));
        $this->blockListEntry($business, self::PHONE, 'Optout by User', Carbon::parse('2026-09-09 08:00:00'));

        $page = $this->timeline($business, $box);

        $this->assertSame([
            'activity: Added to contacts — Group: Clients',
            'in: Do you have Saturday slots?',
            'out: Yes — 10am or 2pm.',
            'activity: Opted out of texts',
        ], $this->describe($page));
        $this->assertFalse($page->truncated);
        $this->assertSame((int) $contact->id, (int) $page->subject->contact->id);
    }

    public function test_an_automation_text_shows_once_as_the_message_it_sent_and_an_inbox_reply_is_never_doubled(): void
    {
        [, $business] = $this->entitledTenant();
        $contact = $this->addedOn($this->namedContact($business, self::PHONE, 'Maya', 'Lopez'), '2026-09-01 09:00:00');
        $box = $this->conversationWith($business, self::PHONE);

        $journey = $this->journey($business, $contact, 'Welcome flow', EnrollmentStatus::Completed, Carbon::parse('2026-09-05 09:00:00'), Carbon::parse('2026-09-05 09:02:00'));
        $step = $this->stepRun($business, $journey['enrollment'], $journey['nodes'][0], 'send_sms', StepRunStatus::Succeeded, Carbon::parse('2026-09-05 09:01:00'));
        $this->sentReport($business, self::PHONE, 'Welcome to Harbor Lane!', Carbon::parse('2026-09-05 09:01:00'), ['automation_step_run_id' => $step]);

        // An inbox send writes its conversation message AND an unmarked report.
        $this->message($box, 'outgoing', 'Thanks for reaching out', Carbon::parse('2026-09-06 11:00:00'));
        $this->sentReport($business, self::PHONE, 'Thanks for reaching out', Carbon::parse('2026-09-06 11:00:00'));

        $this->assertSame([
            'activity: Added to contacts — Group: Clients',
            'activity: Added to automation “Welcome flow”',
            'out: Welcome to Harbor Lane! [Automation · Welcome flow]',
            'activity: Finished automation “Welcome flow”',
            'out: Thanks for reaching out',
        ], $this->describe($this->timeline($business, $box)));
    }

    public function test_campaign_and_legacy_automation_texts_are_attributed_and_an_undelivered_one_says_so(): void
    {
        [, $business] = $this->entitledTenant();
        $box = $this->conversationWith($business, self::PHONE);

        $campaign = $this->campaignNamed($business, 'Fall promo');
        $legacy = Automation::create([
            'business_id' => $business->id,
            'user_id' => $business->customer_id,
            'name' => 'Birthday wishes',
            'status' => Automation::STATUS_ACTIVE,
            'trigger_type' => AutomationTriggerType::ContactCreated->value,
            'trigger_config' => ['contact_group_id' => null],
            'action_type' => AutomationActionType::SendMessage->value,
            'action_config' => ['sms_type' => 'plain', 'message' => 'Happy birthday!'],
        ]);

        $this->sentReport($business, '+' . self::PHONE, 'Fall promo: 20% off', Carbon::parse('2026-09-02 12:00:00'), ['campaign_id' => $campaign->id]);
        $this->sentReport($business, self::PHONE, 'Happy birthday!', Carbon::parse('2026-09-03 12:00:00'), ['automation_id' => $legacy->id]);
        $this->sentReport($business, self::PHONE, 'Last call', Carbon::parse('2026-09-04 12:00:00'), ['campaign_id' => $campaign->id, 'status' => 'Failed', 'customer_status' => 'Failed']);

        $page = $this->timeline($business, $box);

        $this->assertSame([
            'out: Fall promo: 20% off [Campaign · Fall promo]',
            'out: Happy birthday! [Automation · Birthday wishes]',
            'out: Last call [Campaign · Fall promo] — Not delivered',
        ], $this->describe($page));
    }

    public function test_only_automation_outcomes_a_person_cares_about_appear_and_raw_codes_never_do(): void
    {
        [, $business] = $this->entitledTenant();
        $contact = $this->addedOn($this->namedContact($business, self::PHONE, 'Maya', 'Lopez'), '2026-08-01 09:00:00');
        $box = $this->conversationWith($business, self::PHONE);

        $reminder = $this->journey($business, $contact, 'Reminder flow', EnrollmentStatus::Failed, Carbon::parse('2026-09-05 09:00:00'), Carbon::parse('2026-09-05 09:04:00'));
        $this->stepRun($business, $reminder['enrollment'], $reminder['nodes'][0], 'wait', StepRunStatus::Succeeded, Carbon::parse('2026-09-05 09:01:00'));
        $this->stepRun($business, $reminder['enrollment'], $reminder['nodes'][1], 'if_else', StepRunStatus::Succeeded, Carbon::parse('2026-09-05 09:02:00'));
        $this->stepRun($business, $reminder['enrollment'], $reminder['nodes'][2], 'update_contact_field', StepRunStatus::Succeeded, Carbon::parse('2026-09-05 09:03:00'));
        $this->stepRun($business, $reminder['enrollment'], $reminder['nodes'][3], 'send_sms', StepRunStatus::Skipped, Carbon::parse('2026-09-05 09:04:00'), 'contact_unsubscribed');

        $followUp = $this->journey($business, $contact, 'Follow-up', EnrollmentStatus::Active, Carbon::parse('2026-09-06 09:00:00'));
        $this->stepRun($business, $followUp['enrollment'], $followUp['nodes'][0], 'internal_notification', StepRunStatus::Succeeded, Carbon::parse('2026-09-06 09:01:00'));
        $this->stepRun($business, $followUp['enrollment'], $followUp['nodes'][1], 'send_sms', StepRunStatus::Started, Carbon::parse('2026-09-06 09:02:00'));

        $broken = $this->journey($business, $contact, 'Broken flow', EnrollmentStatus::Failed, Carbon::parse('2026-09-07 09:00:00'), Carbon::parse('2026-09-07 09:01:00'));
        $this->stepRun($business, $broken['enrollment'], $broken['nodes'][0], 'send_sms', StepRunStatus::Failed, Carbon::parse('2026-09-07 09:01:00'), 'some_internal_code_nobody_mapped');

        $this->assertSame([
            'activity: Added to contacts — Group: Clients',
            'activity: Added to automation “Reminder flow”',
            'activity: Automation “Reminder flow” updated contact details',
            // The skipped text ended the journey; its card IS the ending, so no "stopped" card.
            'activity: Automation “Reminder flow” did not send a text — This person is unsubscribed',
            'activity: Added to automation “Follow-up”',
            'activity: Automation “Follow-up” notified your team',
            'activity: Added to automation “Broken flow”',
            'activity: Automation “Broken flow” did not send a text',
        ], $this->describe($this->timeline($business, $box)));
    }

    public function test_b4_automation_runs_show_their_outcome(): void
    {
        [, $business] = $this->entitledTenant();
        $contact = $this->addedOn($this->namedContact($business, self::PHONE, 'Maya', 'Lopez'), '2026-08-01 09:00:00');
        $box = $this->conversationWith($business, self::PHONE);

        $welcome = $this->b4Automation($business, 'Welcome text', AutomationActionType::SendMessage);
        $tagger = $this->b4Automation($business, 'Mark as lead', AutomationActionType::UpdateContactField);

        $this->b4Execution($business, $welcome, $contact, AutomationExecutionStatus::Succeeded, Carbon::parse('2026-09-02 10:00:00'));
        $this->b4Execution($business, $welcome, $contact, AutomationExecutionStatus::Skipped, Carbon::parse('2026-09-03 10:00:00'), 'channel_unavailable');
        $this->b4Execution($business, $tagger, $contact, AutomationExecutionStatus::Succeeded, Carbon::parse('2026-09-04 10:00:00'));
        $this->b4Execution($business, $tagger, $contact, AutomationExecutionStatus::Pending, Carbon::parse('2026-09-05 10:00:00'));

        $this->assertSame([
            'activity: Added to contacts — Group: Clients',
            'activity: Automation “Welcome text” sent a text',
            'activity: Automation “Welcome text” did not send a text — No sending number is set up',
            'activity: Automation “Mark as lead” updated contact details',
        ], $this->describe($this->timeline($business, $box)));
    }

    public function test_contact_keyed_activity_needs_exactly_one_contact_on_the_number(): void
    {
        [, $business] = $this->entitledTenant();
        $maya = $this->namedContact($business, self::PHONE, 'Maya', 'Lopez');
        $this->namedContact($business, self::PHONE, 'Marco', 'Lopez');
        $box = $this->conversationWith($business, self::PHONE);

        $journey = $this->journey($business, $maya, 'Welcome flow', EnrollmentStatus::Active, Carbon::parse('2026-09-05 09:00:00'));
        $this->stepRun($business, $journey['enrollment'], $journey['nodes'][0], 'internal_notification', StepRunStatus::Succeeded, Carbon::parse('2026-09-05 09:01:00'));
        $this->message($box, 'incoming', 'Hello?', Carbon::parse('2026-09-06 10:00:00'));
        $this->blockListEntry($business, self::PHONE, 'Blacklisted by Front Desk', Carbon::parse('2026-09-07 10:00:00'));

        $page = $this->timeline($business, $box);

        $this->assertNull($page->subject->contact, 'Two contacts share the number: neither is chosen.');
        $this->assertSame([
            'in: Hello?',
            'activity: Blocked from Conversations',
        ], $this->describe($page));
    }

    public function test_nothing_from_another_business_ever_appears_even_on_the_same_number(): void
    {
        [, $business] = $this->entitledTenant();
        [, $sibling] = $this->entitledTenant();

        $box = $this->conversationWith($business, self::PHONE);
        $this->addedOn($this->namedContact($business, self::PHONE, 'Maya', 'Lopez'), '2026-09-01 09:00:00');
        $this->message($box, 'incoming', 'Mine', Carbon::parse('2026-09-06 10:00:00'));

        $theirs = $this->namedContact($sibling, self::PHONE, 'Maya', 'Elsewhere');
        $theirBox = $this->conversationWith($sibling, self::PHONE);
        $this->message($theirBox, 'incoming', 'Theirs', Carbon::parse('2026-09-06 10:01:00'));
        $this->sentReport($sibling, self::PHONE, 'Their campaign', Carbon::parse('2026-09-06 10:02:00'), ['campaign_id' => $this->campaignNamed($sibling, 'Theirs')->id]);
        $this->blockListEntry($sibling, self::PHONE, 'Optout by User', Carbon::parse('2026-09-06 10:03:00'));
        $theirJourney = $this->journey($sibling, $theirs, 'Their flow', EnrollmentStatus::Active, Carbon::parse('2026-09-06 10:04:00'));
        $theirStep = $this->stepRun($sibling, $theirJourney['enrollment'], $theirJourney['nodes'][0], 'send_sms', StepRunStatus::Succeeded, Carbon::parse('2026-09-06 10:05:00'));

        // A mark on this Business's own report that points at the sibling's step
        // names nothing here: the message shows, attributed generically.
        $this->sentReport($business, self::PHONE, 'Stamped oddly', Carbon::parse('2026-09-07 10:00:00'), ['automation_step_run_id' => $theirStep]);

        $this->assertSame([
            'activity: Added to contacts — Group: Clients',
            'in: Mine',
            'out: Stamped oddly [Automation]',
        ], $this->describe($this->timeline($business, $box)));
    }

    public function test_a_source_with_more_than_the_limit_cuts_every_source_at_the_same_moment(): void
    {
        [, $business] = $this->entitledTenant();
        $box = $this->conversationWith($business, self::PHONE);

        $this->message($box, 'incoming', 'Long before the window', Carbon::parse('2026-01-01 09:00:00'));
        $this->message($box, 'incoming', 'Inside the window', Carbon::parse('2026-09-10 14:59:30'));

        $this->app->tag([StubTimelineSource::class], ContactActivityTimeline::SOURCES_TAG);

        for ($i = 1; $i <= ContactActivityTimeline::PER_SOURCE_LIMIT + 1; $i++) {
            StubTimelineSource::$items[] = new TimelineItem(
                key: 'stub:' . $i,
                kind: TimelineItemKind::Activity,
                at: CarbonImmutable::parse('2026-09-10 15:00:00')->subMinutes($i),
                title: 'Stub event ' . $i,
                sequence: $i,
            );
        }

        $page = $this->timeline($business, $box);

        $this->assertTrue($page->truncated);
        $this->assertCount(ContactActivityTimeline::PER_SOURCE_LIMIT + 1, $page->items, 'The 200 stub events that fit, and the one message inside the window.');
        $this->assertNotContains('in: Long before the window', $this->describe($page), 'Older than the cut: another source may have events that old that are not shown, so nothing that old is.');
        $this->assertContains('in: Inside the window', $this->describe($page));
    }

    public function test_a_future_domain_joins_the_timeline_by_registering_a_source(): void
    {
        [, $business] = $this->entitledTenant();
        $box = $this->conversationWith($business, self::PHONE);
        $this->message($box, 'incoming', 'Can you send the invoice?', Carbon::parse('2026-09-08 09:00:00'));

        $this->app->tag([StubTimelineSource::class], ContactActivityTimeline::SOURCES_TAG);
        StubTimelineSource::$items = [
            new TimelineItem(key: 'invoice:7', kind: TimelineItemKind::Activity, at: CarbonImmutable::parse('2026-09-08 09:30:00'), title: 'Invoice INV-7 sent', icon: 'receipt'),
            new TimelineItem(key: 'email:3', kind: TimelineItemKind::Message, at: CarbonImmutable::parse('2026-09-08 09:10:00'), body: 'Invoice attached', direction: \App\Enums\Timeline\TimelineDirection::Outbound, channel: 'email', represents: ['invoice:unused']),
        ];

        $page = $this->timeline($business, $box);

        $this->assertSame([
            'in: Can you send the invoice?',
            'out: Invoice attached',
            'activity: Invoice INV-7 sent',
        ], $this->describe($page));
        $this->assertSame('email', $page->items[1]->channel);
    }

    public function test_the_timeline_costs_the_same_number_of_queries_however_much_history_there_is(): void
    {
        [, $business] = $this->entitledTenant();
        $contact = $this->namedContact($business, self::PHONE, 'Maya', 'Lopez');
        $box = $this->conversationWith($business, self::PHONE);
        $campaign = $this->campaignNamed($business, 'Fall promo');
        $welcome = $this->b4Automation($business, 'Welcome text', AutomationActionType::SendMessage);

        $seed = function (int $rounds) use ($business, $contact, $box, $campaign, $welcome): void {
            for ($i = 0; $i < $rounds; $i++) {
                $at = Carbon::parse('2026-09-01 09:00:00')->addMinutes(random_int(1, 10000));
                $this->message($box, 'incoming', 'Message ' . Str::random(6), $at);
                $this->sentReport($business, self::PHONE, 'Promo ' . Str::random(6), $at, ['campaign_id' => $campaign->id]);
                $this->blockListEntry($business, self::PHONE, 'Optout by User', $at);
                $journey = $this->journey($business, $contact, 'Flow ' . Str::random(6), EnrollmentStatus::Completed, $at, $at->copy()->addMinute(), 2);
                $this->stepRun($business, $journey['enrollment'], $journey['nodes'][0], 'send_sms', StepRunStatus::Failed, $at, 'send_failed');
                $this->b4Execution($business, $welcome, $contact, AutomationExecutionStatus::Succeeded, $at);
            }
        };

        $read = function () use ($business, $box): int {
            $fresh = $box->fresh();
            $resolved = $fresh->resolveDisplayContact($business);

            return $this->queriesFor(fn () => app(ContactActivityTimeline::class)->forConversation($business, $fresh, $resolved));
        };

        $seed(2);
        $few = $read();

        $seed(10);
        $many = $read();

        $this->assertSame($few, $many);
        $this->assertSame(7, $many, 'Messages, sent reports, the three automation ledgers, the block list, and the contact\'s group.');
    }

    // -----------------------------------------------------------------

    private function timeline(Business $business, ChatBox $box): TimelinePage
    {
        $box = $box->fresh();

        return app(ContactActivityTimeline::class)->forConversation($business, $box, $box->resolveDisplayContact($business));
    }

    /** @return list<string> */
    private function describe(TimelinePage $page): array
    {
        return array_map(static function (TimelineItem $item): string {
            if ($item->isMessage()) {
                return ($item->isInbound() ? 'in: ' : 'out: ') . $item->body
                    . ($item->via !== null ? ' [' . $item->via . ']' : '')
                    . ($item->detail !== null ? ' — ' . $item->detail : '');
            }

            return 'activity: ' . $item->title . ($item->detail !== null ? ' — ' . $item->detail : '');
        }, $page->items);
    }

    private function addedOn(Contacts $contact, string $at): Contacts
    {
        DB::table('contacts')->where('id', $contact->id)->update(['created_at' => $at]);

        return $contact->fresh();
    }

    private function b4Automation(Business $business, string $name, AutomationActionType $action): Automation
    {
        return Automation::create([
            'business_id' => $business->id,
            'user_id' => $business->customer_id,
            'name' => $name,
            'status' => Automation::STATUS_ACTIVE,
            'trigger_type' => AutomationTriggerType::ContactCreated->value,
            'trigger_config' => ['contact_group_id' => null],
            'action_type' => $action->value,
            'action_config' => [],
        ]);
    }

    private function b4Execution(Business $business, Automation $automation, Contacts $contact, AutomationExecutionStatus $status, Carbon $at, ?string $error = null): void
    {
        DB::table('automation_executions')->insert([
            'uid' => (string) Str::uuid(),
            'business_id' => $business->id,
            'automation_id' => $automation->id,
            'contact_id' => $contact->id,
            'trigger_type' => AutomationTriggerType::ContactCreated->value,
            'idempotency_key' => (string) Str::uuid(),
            'status' => $status->value,
            'completed_at' => $status === AutomationExecutionStatus::Pending ? null : $at,
            'safe_error_summary' => $error,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    private function queriesFor(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $callback();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}

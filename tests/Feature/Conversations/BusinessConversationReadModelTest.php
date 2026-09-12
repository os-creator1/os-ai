<?php

namespace Tests\Feature\Conversations;

use App\Library\Conversations\BusinessConversationReadModel;
use App\Models\Business;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * Customer Experience Redesign Slice 2B §15 — the two Business-isolated
 * counts Dashboard Slice 4 will consume, and nothing more.
 */
class BusinessConversationReadModelTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    protected function setUp(): void
    {
        parent::setUp();

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

    /**
     * The seam stays deliberately small. Unified Home §3.1 (A-1) added the
     * third method: startedCount() for several Businesses at once, so the
     * Agency Account Home can show every client without asking this seam once
     * per client. It is the same count, grouped — not a new kind of read.
     */
    public function test_the_public_surface_is_exactly_the_counts_this_application_reads(): void
    {
        $methods = array_map(
            fn (\ReflectionMethod $method) => $method->getName(),
            (new \ReflectionClass(BusinessConversationReadModel::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );

        sort($methods);

        // H-4 (§2.6) added the three Business Home figures. They live here,
        // and only here, because this class is the one door to the
        // conversation table: Dashboard code, Blade and B5 never query it.
        $this->assertSame([
            'awaitingReplyCount',
            'incomingCount',
            'periodCounts',
            'repliedCount',
            'startedCount',
            'startedCountsForBusinesses',
            'unreadCount',
        ], $methods);
    }

    public function test_the_grouped_count_gives_each_business_its_own_figure_and_isolates_them(): void
    {
        [$businessA, $businessB] = $this->twoBusinessesOfOneCustomer();
        $model = new BusinessConversationReadModel();
        $start = CarbonImmutable::parse('2026-09-01 00:00:00', 'UTC');
        $end = CarbonImmutable::parse('2026-09-30 00:00:00', 'UTC');

        $this->box($businessA, CarbonImmutable::parse('2026-09-02 10:00:00', 'UTC'));
        $this->box($businessA, CarbonImmutable::parse('2026-09-03 10:00:00', 'UTC'));
        $this->box($businessB, CarbonImmutable::parse('2026-09-04 10:00:00', 'UTC'));

        $counts = $model->startedCountsForBusinesses([$businessA->id, $businessB->id], $start, $end);

        $this->assertSame(2, $counts[$businessA->id]);
        $this->assertSame(1, $counts[$businessB->id]);

        // Asking for one Business never returns another's rows.
        $this->assertSame([$businessB->id => 1], $model->startedCountsForBusinesses([$businessB->id], $start, $end));

        // Each figure equals what the single-Business count reports.
        $this->assertSame($model->startedCount($businessA, $start, $end), $counts[$businessA->id]);
        $this->assertSame($model->startedCount($businessB, $start, $end), $counts[$businessB->id]);
    }

    public function test_the_grouped_count_is_half_open_and_costs_one_query_for_any_number_of_businesses(): void
    {
        [$businessA, $businessB] = $this->twoBusinessesOfOneCustomer();
        $model = new BusinessConversationReadModel();
        $start = CarbonImmutable::parse('2026-09-01 00:00:00', 'UTC');
        $end = CarbonImmutable::parse('2026-09-02 00:00:00', 'UTC');

        $this->box($businessA, CarbonImmutable::parse('2026-09-01 00:00:00', 'UTC'));
        $this->box($businessA, CarbonImmutable::parse('2026-09-02 00:00:00', 'UTC'));
        $this->box($businessB, CarbonImmutable::parse('2026-08-31 23:59:59', 'UTC'));

        DB::enableQueryLog();
        DB::flushQueryLog();
        $counts = $model->startedCountsForBusinesses([$businessA->id, $businessB->id], $start, $end);
        $this->assertCount(1, DB::getQueryLog(), 'One grouped statement, whatever the number of ids.');
        DB::disableQueryLog();

        $this->assertSame([$businessA->id => 1], $counts, 'The start instant is inside the window and the end instant is not.');

        // No ids, no query and nothing invented.
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertSame([], $model->startedCountsForBusinesses([], $start, $end));
        $this->assertCount(0, DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_started_count_is_isolated_to_the_business(): void
    {
        [$businessA, $businessB] = $this->twoBusinessesOfOneCustomer();
        $at = CarbonImmutable::parse('2026-03-10 12:00:00', 'UTC');

        $this->box($businessA, $at);
        $this->box($businessA, $at);
        $this->box($businessB, $at);
        // A NULL-business legacy conversation of the same customer.
        $this->box(null, $at, (int) $businessA->customer_id);

        $model = new BusinessConversationReadModel();
        $start = CarbonImmutable::parse('2026-03-01 00:00:00', 'UTC');
        $end = CarbonImmutable::parse('2026-04-01 00:00:00', 'UTC');

        $this->assertSame(2, $model->startedCount($businessA, $start, $end));
        $this->assertSame(1, $model->startedCount($businessB, $start, $end));
    }

    /**
     * [start, end): a conversation created exactly at `end` belongs to the
     * NEXT window, so adjacent windows never count one conversation twice.
     */
    public function test_started_count_is_half_open(): void
    {
        [$business] = $this->twoBusinessesOfOneCustomer();

        $start = CarbonImmutable::parse('2026-03-01 00:00:00', 'UTC');
        $end = CarbonImmutable::parse('2026-04-01 00:00:00', 'UTC');

        $this->box($business, $start);                       // included
        $this->box($business, $end->subSecond());            // included
        $this->box($business, $end);                         // next window
        $this->box($business, $start->subSecond());          // previous window

        $model = new BusinessConversationReadModel();

        $this->assertSame(2, $model->startedCount($business, $start, $end));
        $this->assertSame(1, $model->startedCount($business, $end, $end->addMonth()));
        $this->assertSame(1, $model->startedCount($business, $start->subMonth(), $start));
    }

    public function test_unread_count_counts_conversations_with_unread_messages_in_the_business_only(): void
    {
        [$businessA, $businessB] = $this->twoBusinessesOfOneCustomer();
        $at = CarbonImmutable::now('UTC');

        $this->box($businessA, $at, null, 3);
        $this->box($businessA, $at, null, 1);
        $this->box($businessA, $at, null, 0);
        $this->box($businessB, $at, null, 5);
        $this->box(null, $at, (int) $businessA->customer_id, 7);

        $model = new BusinessConversationReadModel();

        $this->assertSame(2, $model->unreadCount($businessA), 'Conversations, not messages.');
        $this->assertSame(1, $model->unreadCount($businessB));
    }

    public function test_each_count_is_a_single_query(): void
    {
        [$business] = $this->twoBusinessesOfOneCustomer();
        $model = new BusinessConversationReadModel();

        DB::enableQueryLog();
        DB::flushQueryLog();
        $model->startedCount($business, CarbonImmutable::now('UTC')->subDay(), CarbonImmutable::now('UTC'));
        $this->assertCount(1, DB::getQueryLog());

        DB::flushQueryLog();
        $model->unreadCount($business);
        $this->assertCount(1, DB::getQueryLog());
        DB::disableQueryLog();
    }

    // =================================================================
    // Unified Business Home §2.6 (H-4) — T-CONV-1
    // =================================================================

    /**
     * The truth table for the two period figures. A conversation is counted
     * once however many messages it holds, and "replied" means the Business
     * answered the customer — not that it sent something.
     */
    public function test_incoming_and_replied_are_conversations_not_messages(): void
    {
        [$business, $rival] = $this->twoBusinessesOfOneCustomer();
        $model = new BusinessConversationReadModel();
        $start = CarbonImmutable::parse('2026-09-01 00:00:00');
        $end = CarbonImmutable::parse('2026-10-01 00:00:00');
        $day = fn (string $time) => CarbonImmutable::parse('2026-09-10 ' . $time);

        // The customer wrote; a person answered.
        $this->thread($business, [['incoming', $day('09:00:00')], ['outgoing', $day('09:05:00')]]);
        // The customer wrote; five outgoing messages followed. Still one.
        $this->thread($business, [
            ['incoming', $day('10:00:00')],
            ['outgoing', $day('10:01:00')],
            ['outgoing', $day('10:02:00')],
            ['outgoing', $day('10:03:00')],
            ['outgoing', $day('10:04:00')],
            ['outgoing', $day('10:05:00')],
        ]);
        // The customer wrote three times and nobody answered.
        $this->thread($business, [['incoming', $day('11:00:00')], ['incoming', $day('11:01:00')], ['incoming', $day('11:02:00')]]);
        // The Business wrote first and the customer replied afterwards: the
        // outgoing message came BEFORE the question, so it answered nothing.
        $this->thread($business, [['outgoing', $day('12:00:00')], ['incoming', $day('12:30:00')]]);
        // Outbound only: not an incoming conversation at all.
        $this->thread($business, [['outgoing', $day('13:00:00')]]);
        // A legacy row with no recorded direction proves nothing either way.
        $this->thread($business, [[null, $day('14:00:00')]]);
        // Another Business entirely.
        $this->thread($rival, [['incoming', $day('09:00:00')], ['outgoing', $day('09:05:00')]]);

        $this->assertSame(4, $model->incomingCount($business, $start, $end));
        $this->assertSame(2, $model->repliedCount($business, $start, $end));
        $this->assertSame(['incoming' => 4, 'replied' => 2], $model->periodCounts($business, $start, $end));
        $this->assertSame(1, $model->incomingCount($rival, $start, $end), 'Each Business sees only its own.');
    }

    /**
     * An automated reply and one typed by a person are the same row to this
     * schema: `chat_box_messages` records a direction and nothing else. The
     * count says "answered", and the Home tile says so in words rather than
     * implying a human did it.
     */
    public function test_an_automated_reply_counts_exactly_as_a_typed_one(): void
    {
        [$business] = $this->twoBusinessesOfOneCustomer();
        $model = new BusinessConversationReadModel();
        $start = CarbonImmutable::parse('2026-09-01 00:00:00');
        $end = CarbonImmutable::parse('2026-10-01 00:00:00');

        $this->thread($business, [
            ['incoming', CarbonImmutable::parse('2026-09-10 09:00:00')],
            ['outgoing', CarbonImmutable::parse('2026-09-10 09:00:30')],
        ]);

        $this->assertSame(1, $model->repliedCount($business, $start, $end));

        $columns = array_map(
            fn ($column) => $column->Field ?? $column->field ?? '',
            DB::select('DESCRIBE ' . DB::getTablePrefix() . 'chat_box_messages'),
        );

        $this->assertNotContains('is_automated', $columns, 'If this ever gains an author column, the tile copy can become more specific.');
        $this->assertNotContains('sent_by_automation', $columns);
    }

    public function test_awaiting_reply_is_the_latest_message_the_grace_period_and_the_scan_horizon(): void
    {
        [$business, $rival] = $this->twoBusinessesOfOneCustomer();
        $model = new BusinessConversationReadModel();
        $now = CarbonImmutable::now();
        $ago = fn (int $minutes) => $now->subMinutes($minutes);

        // Waiting: the customer wrote last, longer ago than the grace period.
        $this->thread($business, [['incoming', $ago(30)]]);
        // Not waiting yet: inside the grace period.
        $this->thread($business, [['incoming', $ago(1)]]);
        // Answered.
        $this->thread($business, [['incoming', $ago(60)], ['outgoing', $ago(55)]]);
        // Answered, then asked again: waiting.
        $this->thread($business, [['incoming', $ago(120)], ['outgoing', $ago(110)], ['incoming', $ago(100)]]);
        // Outside the 30-day scan horizon: not waiting forever.
        $this->thread($business, [['incoming', $now->subDays(40)]], $now->subDays(40));
        // Another Business.
        $this->thread($rival, [['incoming', $ago(30)]]);

        $this->assertSame(2, $model->awaitingReplyCount($business));
        $this->assertSame(1, $model->awaitingReplyCount($rival));
    }

    public function test_each_h4_figure_is_a_single_statement(): void
    {
        [$business] = $this->twoBusinessesOfOneCustomer();
        $model = new BusinessConversationReadModel();
        $now = CarbonImmutable::now();

        foreach (range(1, 12) as $i) {
            $this->thread($business, [['incoming', $now->subMinutes(60 + $i)], ['outgoing', $now->subMinutes(30 + $i)]]);
        }

        DB::enableQueryLog();

        foreach ([
            fn () => $model->incomingCount($business, $now->subMonth(), $now),
            fn () => $model->repliedCount($business, $now->subMonth(), $now),
            fn () => $model->periodCounts($business, $now->subMonth(), $now),
            fn () => $model->awaitingReplyCount($business),
        ] as $call) {
            DB::flushQueryLog();
            $call();
            $this->assertCount(1, DB::getQueryLog(), 'One statement, never one per conversation.');
        }

        DB::disableQueryLog();
    }

    // -----------------------------------------------------------------

    /**
     * One conversation carrying an exact sequence of messages, in the order
     * given — which is also the order their ids take, as in production.
     *
     * @param  array<int, array{0: ?string, 1: CarbonImmutable}>  $messages
     */
    private function thread(Business $business, array $messages, ?CarbonImmutable $touchedAt = null): void
    {
        $first = $messages[0][1];
        $last = $messages[count($messages) - 1][1];

        $boxId = (int) DB::table('chat_boxes')->insertGetId([
            'uid' => (string) Str::uuid(),
            'user_id' => (int) $business->customer_id,
            'business_id' => $business->id,
            'from' => '14155550100',
            'to' => '1415555' . random_int(1000, 9999),
            'notification' => 0,
            'reply_by_customer' => false,
            'created_at' => $first->format('Y-m-d H:i:s'),
            'updated_at' => ($touchedAt ?? $last)->format('Y-m-d H:i:s'),
        ]);

        foreach ($messages as [$direction, $at]) {
            DB::table('chat_box_messages')->insert([
                'box_id' => $boxId,
                'message' => 'Fixture message',
                'sms_type' => 'sms',
                'send_by' => $direction === 'incoming' ? 'to' : ($direction === 'outgoing' ? 'from' : null),
                'direction' => $direction,
                'created_at' => $at->format('Y-m-d H:i:s'),
                'updated_at' => $at->format('Y-m-d H:i:s'),
            ]);
        }
    }

    // -----------------------------------------------------------------

    /**
     * @return array{0: Business, 1: Business}
     */
    private function twoBusinessesOfOneCustomer(): array
    {
        $customer = $this->createCustomer();

        return [
            $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => 'Read A ' . uniqid()]))->fresh(),
            $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => 'Read B ' . uniqid()]))->fresh(),
        ];
    }

    private function box(?Business $business, CarbonImmutable $createdAt, ?int $userId = null, int $notification = 0): void
    {
        DB::table('chat_boxes')->insert([
            'uid' => (string) Str::uuid(),
            'user_id' => $userId ?? (int) $business->customer_id,
            'business_id' => $business?->id,
            'from' => '14155550100',
            'to' => '1415555' . random_int(1000, 9999),
            'notification' => $notification,
            'reply_by_customer' => false,
            'created_at' => $createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $createdAt->format('Y-m-d H:i:s'),
        ]);
    }
}

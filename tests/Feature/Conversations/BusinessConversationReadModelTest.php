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
    public function test_the_public_surface_is_exactly_the_two_counts_and_the_grouped_variant(): void
    {
        $methods = array_map(
            fn (\ReflectionMethod $method) => $method->getName(),
            (new \ReflectionClass(BusinessConversationReadModel::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );

        sort($methods);

        $this->assertSame(['startedCount', 'startedCountsForBusinesses', 'unreadCount'], $methods);
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

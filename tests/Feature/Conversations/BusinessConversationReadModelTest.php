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

    public function test_the_public_surface_is_exactly_the_two_counts(): void
    {
        $methods = array_map(
            fn (\ReflectionMethod $method) => $method->getName(),
            (new \ReflectionClass(BusinessConversationReadModel::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );

        sort($methods);

        $this->assertSame(['startedCount', 'unreadCount'], $methods);
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

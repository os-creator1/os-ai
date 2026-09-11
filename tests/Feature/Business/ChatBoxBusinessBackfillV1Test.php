<?php

namespace Tests\Feature\Business;

use App\Library\Business\Migration\ChatBoxBusinessBackfillV1;
use App\Models\Business;
use App\Models\Contacts;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * Customer Experience Redesign Slice 2B §3/§4 — the conversation tenancy
 * column and ChatBoxBusinessBackfillV1.
 *
 * The rule under test, in order: Contact evidence on the counterparty (`to`)
 * naming exactly one distinct Business resolves; evidence naming two or more
 * leaves NULL WITHOUT consulting the single-Business fallback; only when there
 * is no evidence at all does a customer owning exactly one Business resolve to
 * it. Nothing is ever guessed, overwritten or deleted.
 */
class ChatBoxBusinessBackfillV1Test extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    protected function setUp(): void
    {
        parent::setUp();

        // Consumes user id 1 so no fixture customer is the super admin.
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

    // -----------------------------------------------------------------
    // §3 — the column
    // -----------------------------------------------------------------

    public function test_the_column_is_nullable_indexed_and_restricts_business_deletion(): void
    {
        $this->assertTrue(Schema::hasColumn('chat_boxes', 'business_id'));
        $this->assertTrue(Schema::hasIndex('chat_boxes', 'chat_boxes_business_id_index'));

        $column = DB::selectOne(
            'select IS_NULLABLE as is_nullable from information_schema.columns
             where table_schema = database() and table_name = ? and column_name = ?',
            ['chat_boxes', 'business_id'],
        );
        $this->assertSame('YES', $column->is_nullable);

        $foreign = DB::selectOne(
            'select k.REFERENCED_TABLE_NAME as ref_table, k.REFERENCED_COLUMN_NAME as ref_column, r.DELETE_RULE as delete_rule
             from information_schema.key_column_usage k
             join information_schema.referential_constraints r
               on r.constraint_schema = k.constraint_schema and r.constraint_name = k.constraint_name
             where k.table_schema = database() and k.table_name = ? and k.column_name = ?',
            ['chat_boxes', 'business_id'],
        );
        $this->assertSame('businesses', $foreign->ref_table);
        $this->assertSame('id', $foreign->ref_column);
        $this->assertSame('RESTRICT', $foreign->delete_rule);

        // Messages inherit their Business through the box; no second copy.
        $this->assertFalse(Schema::hasColumn('chat_box_messages', 'business_id'));
    }

    // -----------------------------------------------------------------
    // Step 1 — Contact evidence on the counterparty
    // -----------------------------------------------------------------

    public function test_contact_evidence_in_exactly_one_business_resolves_even_for_a_multi_business_customer(): void
    {
        [$customer, $businessA] = $this->customerWithBusinesses(2);
        $box = $this->box($customer, '14155550100', '14155557001');
        $this->contact($customer, $businessA, '14155557001');

        (new ChatBoxBusinessBackfillV1())->run();

        $this->assertSame((int) $businessA->id, $this->businessOf($box));
    }

    public function test_several_contacts_inside_one_business_are_duplicates_not_ambiguity(): void
    {
        [$customer, $businessA] = $this->customerWithBusinesses(2);
        $box = $this->box($customer, '14155550100', '14155557002');
        $this->contact($customer, $businessA, '14155557002');
        $this->contact($customer, $businessA, '14155557002');

        $summary = (new ChatBoxBusinessBackfillV1())->run();

        $this->assertSame((int) $businessA->id, $this->businessOf($box));
        $this->assertSame(0, $summary['ambiguous']);
    }

    /**
     * The number belongs to two Businesses: a genuine conflict, left NULL
     * and counted as ambiguous. (The next test proves the single-Business
     * fallback is not consulted after a conflict either.)
     */
    public function test_contact_evidence_across_two_businesses_stays_null(): void
    {
        [$customer, $businessA, $businessB] = $this->customerWithBusinesses(2);
        $box = $this->box($customer, '14155550100', '14155557003');
        $this->contact($customer, $businessA, '14155557003');
        $this->contact($customer, $businessB, '14155557003');

        $summary = (new ChatBoxBusinessBackfillV1())->run();

        $this->assertNull($this->businessOf($box));
        $this->assertSame(1, $summary['ambiguous']);
    }

    /**
     * Conflicting evidence is never overruled by the single-Business
     * fallback. The customer owns exactly ONE Business, so step 2 alone
     * would resolve — but their Contacts on this number name two different
     * Businesses (one of them since transferred), and that is a conflict.
     */
    public function test_conflicting_evidence_does_not_fall_through_to_the_single_business_fallback(): void
    {
        [$customer, $ownBusiness] = $this->customerWithBusinesses(1);
        [, $foreignBusiness] = $this->customerWithBusinesses(1);

        $box = $this->box($customer, '14155550100', '14155557004');
        $this->contact($customer, $ownBusiness, '14155557004');
        // Same customer's Contact row, carrying a Business they no longer own.
        $this->contact($customer, $foreignBusiness, '14155557004');

        (new ChatBoxBusinessBackfillV1())->run();

        $this->assertNull($this->businessOf($box), 'Step 2 must not overrule conflicting evidence.');
    }

    public function test_another_customers_contact_is_never_evidence(): void
    {
        [$customer] = $this->customerWithBusinesses(2);
        [$otherCustomer, $otherBusiness] = $this->customerWithBusinesses(1);

        $box = $this->box($customer, '14155550100', '14155557005');
        $this->contact($otherCustomer, $otherBusiness, '14155557005');

        (new ChatBoxBusinessBackfillV1())->run();

        $this->assertNull($this->businessOf($box));
    }

    /**
     * No direction branching: evidence is read from `to` for every row. A
     * Contact matching the Business-side `from` number is not evidence.
     */
    public function test_evidence_is_read_from_the_counterparty_never_from_the_business_side(): void
    {
        [$customer, $businessA, $businessB] = $this->customerWithBusinesses(2);
        $box = $this->box($customer, '14155550100', '14155557006');

        $this->contact($customer, $businessA, '14155557006');
        // Would decide the row if `from` were consulted.
        $this->contact($customer, $businessB, '14155550100');

        (new ChatBoxBusinessBackfillV1())->run();

        $this->assertSame((int) $businessA->id, $this->businessOf($box));
    }

    /**
     * An outbound send and an inbound message write the same orientation
     * (`from` = Business number, `to` = counterparty), so a row from either
     * producer resolves identically.
     */
    public function test_outbound_and_inbound_created_rows_resolve_identically(): void
    {
        [$customer, $businessA] = $this->customerWithBusinesses(2);
        $this->contact($customer, $businessA, '14155557007');

        // As EloquentCampaignRepository::quickSend() writes it.
        $outbound = $this->box($customer, '14155550100', '14155557007', null, ['reply_by_customer' => false]);
        // As DLRController::inboundDLR() writes it, on a different gateway.
        $inbound = $this->box($customer, '14155550101', '14155557007', null, ['reply_by_customer' => true, 'notification' => 1]);

        (new ChatBoxBusinessBackfillV1())->run();

        $this->assertSame((int) $businessA->id, $this->businessOf($outbound));
        $this->assertSame((int) $businessA->id, $this->businessOf($inbound));
    }

    public function test_the_counterparty_is_compared_in_the_digits_only_form_contacts_are_stored_in(): void
    {
        [$customer, $businessA] = $this->customerWithBusinesses(2);
        $box = $this->box($customer, '14155550100', '+1 (415) 555-7008');
        $this->contact($customer, $businessA, '14155557008');

        (new ChatBoxBusinessBackfillV1())->run();

        $this->assertSame((int) $businessA->id, $this->businessOf($box));
    }

    // -----------------------------------------------------------------
    // Step 2 — only when there is no evidence at all
    // -----------------------------------------------------------------

    public function test_no_evidence_and_exactly_one_business_resolves_to_it(): void
    {
        [$customer, $only] = $this->customerWithBusinesses(1);
        $box = $this->box($customer, '14155550100', '14155557009');

        (new ChatBoxBusinessBackfillV1())->run();

        $this->assertSame((int) $only->id, $this->businessOf($box));
    }

    /**
     * Several Businesses and no evidence: NULL. Never the primary Business,
     * never the lowest id.
     */
    public function test_no_evidence_and_several_businesses_stays_null_even_with_a_primary(): void
    {
        [$customer, $businessA] = $this->customerWithBusinesses(2);
        DB::table('businesses')->where('id', $businessA->id)->update(['is_primary' => true]);

        $box = $this->box($customer, '14155550100', '14155557010');

        (new ChatBoxBusinessBackfillV1())->run();

        $this->assertNull($this->businessOf($box));
    }

    public function test_no_evidence_and_no_business_stays_null(): void
    {
        $customer = $this->createCustomer();
        $box = $this->box($customer, '14155550100', '14155557011');

        (new ChatBoxBusinessBackfillV1())->run();

        $this->assertNull($this->businessOf($box));
    }

    // -----------------------------------------------------------------
    // Safety — idempotent, non-destructive, never overwrites
    // -----------------------------------------------------------------

    public function test_an_already_attributed_row_is_never_overwritten(): void
    {
        [$customer, $businessA, $businessB] = $this->customerWithBusinesses(2);
        $box = $this->box($customer, '14155550100', '14155557012', $businessB->id);
        $this->contact($customer, $businessA, '14155557012');

        (new ChatBoxBusinessBackfillV1())->run();

        $this->assertSame((int) $businessB->id, $this->businessOf($box));
    }

    public function test_a_rerun_is_idempotent_deletes_nothing_and_touches_no_message(): void
    {
        [$customer, $businessA, $businessB] = $this->customerWithBusinesses(2);
        $resolvable = $this->box($customer, '14155550100', '14155557013');
        $ambiguous = $this->box($customer, '14155550100', '14155557014');
        $this->contact($customer, $businessA, '14155557013');
        $this->contact($customer, $businessA, '14155557014');
        $this->contact($customer, $businessB, '14155557014');

        DB::table('chat_box_messages')->insert([
            'box_id' => $resolvable, 'message' => 'kept', 'direction' => 'incoming', 'send_by' => 'to',
            'sms_type' => 'plain', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $messagesBefore = DB::table('chat_box_messages')->get()->map(fn ($row) => (array) $row)->all();

        $first = (new ChatBoxBusinessBackfillV1())->run();
        $afterFirst = DB::table('chat_boxes')->orderBy('id')->pluck('business_id', 'id')->all();

        $second = (new ChatBoxBusinessBackfillV1())->run();
        $afterSecond = DB::table('chat_boxes')->orderBy('id')->pluck('business_id', 'id')->all();

        $this->assertSame(['resolved' => 1, 'unresolved' => 1, 'ambiguous' => 1], $first);
        $this->assertSame(['resolved' => 0, 'unresolved' => 1, 'ambiguous' => 1], $second);
        $this->assertSame($afterFirst, $afterSecond);
        $this->assertSame(2, DB::table('chat_boxes')->count(), 'No conversation is deleted.');
        $this->assertSame($messagesBefore, DB::table('chat_box_messages')->get()->map(fn ($row) => (array) $row)->all(), 'No message is touched.');
        $this->assertSame(1, (new ChatBoxBusinessBackfillV1())->unresolvedCount());
    }

    /**
     * The shipped migration runs this class and logs aggregate counts only —
     * the first backfill whose evidence is a phone number must never put one
     * in a log line.
     */
    public function test_the_migration_logs_counts_and_never_a_phone_number(): void
    {
        [$customer, $businessA] = $this->customerWithBusinesses(2);
        $resolvable = $this->box($customer, '14155550100', '14155557015');
        $this->box($customer, '14155550100', '14155557016');
        $this->contact($customer, $businessA, '14155557015');

        $lines = [];
        Log::listen(function ($event) use (&$lines) {
            $lines[] = $event->message;
        });

        (require database_path('migrations/2026_09_14_100002_backfill_chat_boxes_business_id.php'))->up();

        $this->assertSame((int) $businessA->id, $this->businessOf($resolvable));
        $this->assertNotEmpty($lines);

        foreach ($lines as $line) {
            $this->assertStringNotContainsString('4155557015', $line);
            $this->assertStringNotContainsString('4155557016', $line);
            $this->assertStringNotContainsString('4155550100', $line);
        }

        $this->assertStringContainsString('resolved=1', implode("\n", $lines));
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * @return array{0: Customer, 1: Business, 2?: Business}
     */
    private function customerWithBusinesses(int $count): array
    {
        $customer = $this->createCustomer();
        $businesses = [];

        for ($i = 0; $i < $count; $i++) {
            $businesses[] = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => 'Backfill Co ' . uniqid()]));
        }

        DB::table('businesses')->where('customer_id', $customer->user_id)->update(['is_primary' => false]);

        return [$customer, ...array_map(fn (Business $b) => $b->fresh(), $businesses)];
    }

    /**
     * A legacy (or live) conversation row, written the way the producers
     * write it. Returns the id.
     */
    private function box(Customer $customer, string $from, string $to, ?int $businessId = null, array $overrides = []): int
    {
        return DB::table('chat_boxes')->insertGetId(array_merge([
            'uid' => (string) Str::uuid(),
            'user_id' => $customer->user_id,
            'business_id' => $businessId,
            'from' => $from,
            'to' => $to,
            'notification' => 0,
            'reply_by_customer' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function contact(Customer $customer, Business $business, string $phone): Contacts
    {
        return Contacts::create([
            'customer_id' => $customer->user_id,
            'business_id' => $business->id,
            'group_id' => null,
            'phone' => $phone,
            'status' => 'subscribe',
        ]);
    }

    private function businessOf(int $boxId): ?int
    {
        $value = DB::table('chat_boxes')->where('id', $boxId)->value('business_id');

        return $value === null ? null : (int) $value;
    }
}

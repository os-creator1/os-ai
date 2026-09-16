<?php

namespace Tests\Feature\Conversations;

use App\Enums\Business\BusinessLocationLifecycleState;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Business\Migration\ChatBoxLocationBackfillV1;
use App\Models\Business;
use App\Models\ChatBox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Feature\Business\Concerns\CreatesLocationCapacityFixtures;
use Tests\TestCase;

/**
 * Implementation Contract 06 §8/§13 — ChatBoxLocationBackfillV1.
 *
 * The rule under test: a historical conversation resolves ONLY through its own
 * Business, and only when that Business has exactly one ACTIVE Location.
 * Everything else stays NULL — a Business with several active Locations
 * (ambiguous), a Business with none, and every row whose `business_id` is
 * itself NULL, including the legacy Agency AI-Prospecting rows site 4 writes.
 * Nothing is ever guessed, overwritten or deleted.
 */
class ChatBoxLocationBackfillV1Test extends TestCase
{
    use RefreshDatabase;
    use CreatesLocationCapacityFixtures;

    /** @return array{resolved: int, unresolved: int, ambiguous: int} */
    private function backfill(): array
    {
        return (new ChatBoxLocationBackfillV1())->run();
    }

    private function conversation(?Business $business, ?int $userId = null, ?int $locationId = null): ChatBox
    {
        $box = new ChatBox([
            'user_id' => $userId ?? ($business !== null ? (int) $business->customer_id : 1),
            'business_id' => $business?->id,
            'location_id' => $locationId,
            'from' => '14155550' . random_int(100, 999),
            'to' => '14155551' . random_int(100, 999),
        ]);
        $box->uid = (string) Str::uuid();
        $box->save();

        return $box;
    }

    private function archive(int $locationId): void
    {
        DB::table('business_locations')->where('id', $locationId)->update([
            'lifecycle_state' => BusinessLocationLifecycleState::Archived->value,
            'archived_at' => now(),
        ]);
    }

    // -----------------------------------------------------------------
    // Resolved
    // -----------------------------------------------------------------

    public function test_a_business_with_exactly_one_active_location_resolves(): void
    {
        [, $business] = $this->locationTenant(WorkspacePlanTier::Core, 1);
        $location = $business->activeLocations()->sole();
        $box = $this->conversation($business);

        $summary = $this->backfill();

        $this->assertSame(['resolved' => 1, 'unresolved' => 0, 'ambiguous' => 0], $summary);
        $this->assertSame((int) $location->id, (int) $box->fresh()->location_id);
    }

    public function test_every_conversation_of_one_business_resolves_to_the_same_location(): void
    {
        [, $business] = $this->locationTenant(WorkspacePlanTier::Core, 1);
        $location = $business->activeLocations()->sole();
        $boxes = [$this->conversation($business), $this->conversation($business), $this->conversation($business)];

        $summary = $this->backfill();

        $this->assertSame(3, $summary['resolved']);

        foreach ($boxes as $box) {
            $this->assertSame((int) $location->id, (int) $box->fresh()->location_id);
        }
    }

    public function test_an_archived_location_is_not_evidence_but_its_active_sibling_is(): void
    {
        [$customer, $business] = $this->locationTenant(WorkspacePlanTier::Core, 1);
        $active = $business->activeLocations()->sole();
        $second = $this->locations()->createLocation($business, $this->locationAttributes('Second'), (int) $customer->user_id);
        $this->archive((int) $second->id);

        $box = $this->conversation($business);

        $this->assertSame(['resolved' => 1, 'unresolved' => 0, 'ambiguous' => 0], $this->backfill());
        $this->assertSame((int) $active->id, (int) $box->fresh()->location_id);
    }

    // -----------------------------------------------------------------
    // Unresolved / ambiguous
    // -----------------------------------------------------------------

    public function test_a_business_with_several_active_locations_stays_null_and_counts_as_ambiguous(): void
    {
        [$customer, $business] = $this->locationTenant(WorkspacePlanTier::Core, 1);
        $this->locations()->createLocation($business, $this->locationAttributes('Second'), (int) $customer->user_id);
        $box = $this->conversation($business);

        $summary = $this->backfill();

        $this->assertSame(['resolved' => 0, 'unresolved' => 1, 'ambiguous' => 1], $summary);
        $this->assertNull($box->fresh()->location_id, 'Several active Locations is a real conflict, never resolved by picking one.');
    }

    public function test_a_business_with_no_active_location_stays_null_without_being_called_ambiguous(): void
    {
        [, $business] = $this->locationTenant(WorkspacePlanTier::Core, 0);
        $box = $this->conversation($business);

        $summary = $this->backfill();

        $this->assertSame(['resolved' => 0, 'unresolved' => 1, 'ambiguous' => 0], $summary, 'Missing data is not ambiguity.');
        $this->assertNull($box->fresh()->location_id);
    }

    /** Contract §5 site 4 / §8 — the legacy Agency AI-Prospecting rows. */
    public function test_a_row_without_a_business_stays_null_even_when_the_customer_owns_one(): void
    {
        [$customer, $business] = $this->locationTenant(WorkspacePlanTier::Core, 1);
        $this->assertNotNull(ChatBox::singleActiveLocationIdFor((int) $business->id), 'Precondition: this customer HAS a resolvable Business.');

        $legacy = $this->conversation(null, (int) $customer->user_id);

        $summary = $this->backfill();

        $this->assertSame(['resolved' => 0, 'unresolved' => 1, 'ambiguous' => 0], $summary);
        $this->assertNull($legacy->fresh()->business_id);
        $this->assertNull($legacy->fresh()->location_id, 'With no Business there is no evidence — the customer owning one elsewhere is not evidence about this row.');
    }

    public function test_another_businesss_location_is_never_borrowed(): void
    {
        [, $mine] = $this->locationTenant(WorkspacePlanTier::Core, 0);
        [, $theirs] = $this->locationTenant(WorkspacePlanTier::Core, 1);
        $box = $this->conversation($mine);

        $this->backfill();

        $this->assertNull($box->fresh()->location_id);
        $this->assertSame(0, ChatBox::query()->where('location_id', $theirs->activeLocations()->sole()->id)->count());
    }

    public function test_a_mixed_population_reports_each_outcome_exactly_once(): void
    {
        [, $resolvable] = $this->locationTenant(WorkspacePlanTier::Core, 1);
        [$ambiguousCustomer, $ambiguous] = $this->locationTenant(WorkspacePlanTier::Core, 1);
        $this->locations()->createLocation($ambiguous, $this->locationAttributes('Second'), (int) $ambiguousCustomer->user_id);
        [, $locationless] = $this->locationTenant(WorkspacePlanTier::Core, 0);

        $this->conversation($resolvable);
        $this->conversation($ambiguous);
        $this->conversation($locationless);
        $this->conversation(null, (int) $resolvable->customer_id);

        $summary = $this->backfill();

        $this->assertSame(['resolved' => 1, 'unresolved' => 3, 'ambiguous' => 1], $summary);
        $this->assertSame(3, (new ChatBoxLocationBackfillV1())->unresolvedCount());
    }

    // -----------------------------------------------------------------
    // Idempotency and safety
    // -----------------------------------------------------------------

    public function test_an_already_attributed_row_is_never_overwritten(): void
    {
        [$customer, $business] = $this->locationTenant(WorkspacePlanTier::Core, 1);
        $first = $business->activeLocations()->sole();
        $second = $this->locations()->createLocation($business, $this->locationAttributes('Second'), (int) $customer->user_id);

        // Attributed to the second Location by a live writer; the Business is
        // now ambiguous, so the backfill must leave this row exactly as it is.
        $box = $this->conversation($business, null, (int) $second->id);

        $summary = $this->backfill();

        $this->assertSame(['resolved' => 0, 'unresolved' => 0, 'ambiguous' => 0], $summary, 'A row with a Location is never revisited.');
        $this->assertSame((int) $second->id, (int) $box->fresh()->location_id);
        $this->assertNotSame((int) $first->id, (int) $box->fresh()->location_id);
    }

    public function test_a_row_a_live_writer_attributes_mid_run_is_neither_overwritten_nor_counted(): void
    {
        [$customer, $business] = $this->locationTenant(WorkspacePlanTier::Core, 1);
        $single = $business->activeLocations()->sole();
        $box = $this->conversation($business);

        // A live writer attributes the row to a different Location of the same
        // Business in the gap between the backfill's read and its write.
        $live = $this->locations()->createLocation($business, $this->locationAttributes('Live'), (int) $customer->user_id);
        $this->archive((int) $live->id);

        $injected = false;
        DB::listen(function ($query) use (&$injected, $box, $live): void {
            if ($injected || ! str_contains($query->sql, '`business_locations`')) {
                return;
            }

            $injected = true;
            DB::table('chat_boxes')->where('id', $box->id)->update(['location_id' => $live->id]);
        });

        $summary = $this->backfill();

        $this->assertTrue($injected, 'The race was actually reproduced.');
        $this->assertSame(['resolved' => 0, 'unresolved' => 0, 'ambiguous' => 0], $summary, 'A row the backfill did not resolve, and that is no longer NULL, is counted as neither.');
        $this->assertSame((int) $live->id, (int) $box->fresh()->location_id, 'The live attribution is never overwritten.');
        $this->assertNotSame((int) $single->id, (int) $box->fresh()->location_id);
    }

    public function test_a_rerun_is_idempotent_and_touches_nothing(): void
    {
        [, $business] = $this->locationTenant(WorkspacePlanTier::Core, 1);
        $location = $business->activeLocations()->sole();
        $box = $this->conversation($business);
        $messageCount = DB::table('chat_box_messages')->count();

        $this->assertSame(1, $this->backfill()['resolved']);
        $firstTouch = ChatBox::query()->whereKey($box->id)->sole()->updated_at;

        $second = $this->backfill();

        $this->assertSame(['resolved' => 0, 'unresolved' => 0, 'ambiguous' => 0], $second, 'The second run finds nothing left to do.');
        $this->assertSame((int) $location->id, (int) $box->fresh()->location_id);
        $this->assertEquals($firstTouch, ChatBox::query()->whereKey($box->id)->sole()->updated_at, 'A backfilled row is not re-stamped.');
        $this->assertSame(1, ChatBox::query()->count(), 'Nothing is deleted.');
        $this->assertSame($messageCount, DB::table('chat_box_messages')->count(), 'No message is touched.');
    }

    public function test_it_resolves_a_population_larger_than_one_chunk(): void
    {
        [, $business] = $this->locationTenant(WorkspacePlanTier::Core, 1);
        $location = $business->activeLocations()->sole();

        $rows = [];
        for ($i = 0; $i < 520; $i++) {
            $rows[] = [
                'uid' => (string) Str::uuid(),
                'user_id' => (int) $business->customer_id,
                'business_id' => (int) $business->id,
                'from' => '1415556' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'to' => '1415557' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        DB::table('chat_boxes')->insert($rows);

        $summary = $this->backfill();

        $this->assertSame(520, $summary['resolved'], 'Chunking resolves every page, not only the first.');
        $this->assertSame(0, ChatBox::query()->whereNull('location_id')->count());
        $this->assertSame(520, ChatBox::query()->where('location_id', $location->id)->count());
    }

    // -----------------------------------------------------------------
    // Privacy — aggregate counts only
    // -----------------------------------------------------------------

    public function test_the_migration_logs_counts_and_never_a_phone_number(): void
    {
        [, $business] = $this->locationTenant(WorkspacePlanTier::Core, 1);
        $box = $this->conversation($business);
        $counterparty = (string) $box->to;

        $lines = [];
        Log::listen(function ($message) use (&$lines): void {
            $lines[] = $message->message . ' ' . json_encode($message->context);
        });

        (require database_path('migrations/2026_09_20_100008_backfill_chat_box_location_ids.php'))->up();

        $logged = implode("\n", $lines);

        $this->assertStringContainsString('location_id backfill', $logged);
        $this->assertStringContainsString('resolved=', $logged);
        $this->assertStringNotContainsString($counterparty, $logged, 'No phone number may ever reach a log line.');
        $this->assertStringNotContainsString((string) $box->from, $logged);
    }
}

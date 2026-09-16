<?php

namespace Tests\Feature\Contacts;

use App\Enums\Business\BusinessLocationLifecycleState;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Contacts\Migration\ContactsLocationBackfillV1;
use App\Models\Business;
use App\Models\Contacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Business\Concerns\CreatesLocationCapacityFixtures;
use Tests\TestCase;

/**
 * Implementation Contract 08B §8/§13 — ContactsLocationBackfillV1, mirroring
 * ChatBoxLocationBackfillV1Test's exact shape (Contract 06).
 *
 * The rule under test: a historical Contact resolves ONLY through its own
 * Business, and only when that Business has exactly one ACTIVE Location.
 * Everything else stays NULL.
 */
class ContactsLocationBackfillV1Test extends TestCase
{
    use RefreshDatabase;
    use CreatesLocationCapacityFixtures;

    /** @return array{resolved: int, unresolved: int, ambiguous: int} */
    private function backfill(): array
    {
        return (new ContactsLocationBackfillV1())->run();
    }

    private function contact(?Business $business, ?int $customerId = null, ?int $locationId = null): Contacts
    {
        return Contacts::create([
            'customer_id' => $customerId ?? ($business !== null ? (int) $business->customer_id : 1),
            'business_id' => $business?->id,
            'location_id' => $locationId,
            'group_id' => null,
            'phone' => '1415555' . random_int(1000, 9999),
            'status' => 'subscribe',
        ]);
    }

    private function archive(int $locationId): void
    {
        DB::table('business_locations')->where('id', $locationId)->update([
            'lifecycle_state' => BusinessLocationLifecycleState::Archived->value,
            'archived_at' => now(),
        ]);
    }

    public function test_a_business_with_exactly_one_active_location_resolves(): void
    {
        [, $business] = $this->locationTenant(WorkspacePlanTier::Core, 1);
        $location = $business->activeLocations()->sole();
        $contact = $this->contact($business);

        $summary = $this->backfill();

        $this->assertSame(['resolved' => 1, 'unresolved' => 0, 'ambiguous' => 0], $summary);
        $this->assertSame((int) $location->id, (int) $contact->fresh()->location_id);
    }

    public function test_an_archived_location_is_not_evidence_but_its_active_sibling_is(): void
    {
        [$customer, $business] = $this->locationTenant(WorkspacePlanTier::Core, 1);
        $active = $business->activeLocations()->sole();
        $second = $this->locations()->createLocation($business, $this->locationAttributes('Second'), (int) $customer->user_id);
        $this->archive((int) $second->id);

        $contact = $this->contact($business);

        $this->assertSame(['resolved' => 1, 'unresolved' => 0, 'ambiguous' => 0], $this->backfill());
        $this->assertSame((int) $active->id, (int) $contact->fresh()->location_id);
    }

    public function test_a_business_with_several_active_locations_stays_null_and_counts_as_ambiguous(): void
    {
        [$customer, $business] = $this->locationTenant(WorkspacePlanTier::Core, 1);
        $this->locations()->createLocation($business, $this->locationAttributes('Second'), (int) $customer->user_id);
        $contact = $this->contact($business);

        $summary = $this->backfill();

        $this->assertSame(['resolved' => 0, 'unresolved' => 1, 'ambiguous' => 1], $summary);
        $this->assertNull($contact->fresh()->location_id);
    }

    public function test_a_business_with_no_active_location_stays_null_without_being_called_ambiguous(): void
    {
        [, $business] = $this->locationTenant(WorkspacePlanTier::Core, 0);
        $contact = $this->contact($business);

        $summary = $this->backfill();

        $this->assertSame(['resolved' => 0, 'unresolved' => 1, 'ambiguous' => 0], $summary, 'Missing data is not ambiguity.');
        $this->assertNull($contact->fresh()->location_id);
    }

    public function test_a_row_without_a_business_stays_null_even_when_the_customer_owns_one(): void
    {
        [$customer, $business] = $this->locationTenant(WorkspacePlanTier::Core, 1);
        $legacy = $this->contact(null, (int) $customer->user_id);

        $summary = $this->backfill();

        $this->assertSame(['resolved' => 0, 'unresolved' => 1, 'ambiguous' => 0], $summary);
        $this->assertNull($legacy->fresh()->location_id);
    }

    public function test_an_already_attributed_row_is_never_overwritten(): void
    {
        [$customer, $business] = $this->locationTenant(WorkspacePlanTier::Core, 1);
        $second = $this->locations()->createLocation($business, $this->locationAttributes('Second'), (int) $customer->user_id);
        $contact = $this->contact($business, null, (int) $second->id);

        $summary = $this->backfill();

        $this->assertSame(['resolved' => 0, 'unresolved' => 0, 'ambiguous' => 0], $summary);
        $this->assertSame((int) $second->id, (int) $contact->fresh()->location_id);
    }

    public function test_a_row_a_live_writer_attributes_mid_run_is_neither_overwritten_nor_counted(): void
    {
        [$customer, $business] = $this->locationTenant(WorkspacePlanTier::Core, 1);
        $single = $business->activeLocations()->sole();
        $contact = $this->contact($business);

        $live = $this->locations()->createLocation($business, $this->locationAttributes('Live'), (int) $customer->user_id);
        $this->archive((int) $live->id);

        $injected = false;
        DB::listen(function ($query) use (&$injected, $contact, $live): void {
            if ($injected || ! str_contains($query->sql, '`business_locations`')) {
                return;
            }

            $injected = true;
            DB::table('contacts')->where('id', $contact->id)->update(['location_id' => $live->id]);
        });

        $summary = $this->backfill();

        $this->assertTrue($injected, 'The race was actually reproduced.');
        $this->assertSame(['resolved' => 0, 'unresolved' => 0, 'ambiguous' => 0], $summary);
        $this->assertSame((int) $live->id, (int) $contact->fresh()->location_id);
        $this->assertNotSame((int) $single->id, (int) $contact->fresh()->location_id);
    }

    public function test_a_rerun_is_idempotent_and_touches_nothing(): void
    {
        [, $business] = $this->locationTenant(WorkspacePlanTier::Core, 1);
        $location = $business->activeLocations()->sole();
        $contact = $this->contact($business);

        $this->assertSame(1, $this->backfill()['resolved']);
        $firstTouch = Contacts::query()->whereKey($contact->id)->sole()->updated_at;

        $second = $this->backfill();

        $this->assertSame(['resolved' => 0, 'unresolved' => 0, 'ambiguous' => 0], $second);
        $this->assertSame((int) $location->id, (int) $contact->fresh()->location_id);
        $this->assertEquals($firstTouch, Contacts::query()->whereKey($contact->id)->sole()->updated_at);
    }

    public function test_the_migration_logs_counts_and_never_a_phone_number(): void
    {
        [, $business] = $this->locationTenant(WorkspacePlanTier::Core, 1);
        $contact = $this->contact($business);
        $phone = (string) $contact->phone;

        $lines = [];
        Log::listen(function ($message) use (&$lines): void {
            $lines[] = $message->message . ' ' . json_encode($message->context);
        });

        (require database_path('migrations/2026_09_21_100003_backfill_contacts_location_ids.php'))->up();

        $logged = implode("\n", $lines);

        $this->assertStringContainsString('location_id backfill', $logged);
        $this->assertStringContainsString('resolved=', $logged);
        $this->assertStringNotContainsString($phone, $logged, 'No phone number may ever reach a log line.');
    }
}

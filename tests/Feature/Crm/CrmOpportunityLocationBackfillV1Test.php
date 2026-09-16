<?php

namespace Tests\Feature\Crm;

use App\Enums\Business\BusinessLocationLifecycleState;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Crm\Migration\CrmOpportunityLocationBackfillV1;
use App\Models\Business;
use App\Models\CrmOpportunity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Business\Concerns\CreatesLocationCapacityFixtures;
use Tests\Feature\Crm\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Implementation Contract 08B §8/§13 — CrmOpportunityLocationBackfillV1,
 * mirroring ChatBoxLocationBackfillV1Test's exact shape (Contract 06).
 *
 * The rule under test: a historical deal resolves ONLY through its own
 * Business, and only when that Business has exactly one ACTIVE Location —
 * never through its linked Contact's own Location. Everything else stays
 * NULL.
 */
class CrmOpportunityLocationBackfillV1Test extends TestCase
{
    use RefreshDatabase;
    use CreatesLocationCapacityFixtures;
    use CreatesCrmFixtures;

    /** @return array{resolved: int, unresolved: int, ambiguous: int} */
    private function backfill(): array
    {
        return (new CrmOpportunityLocationBackfillV1())->run();
    }

    private function opportunity(?Business $business, ?int $locationId = null): CrmOpportunity
    {
        $deal = $this->deal($business);
        $deal->forceFill(['location_id' => $locationId])->save();

        return $deal->fresh();
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
        [, $business] = $this->locationTenant(WorkspacePlanTier::Growth, 1);
        $location = $business->activeLocations()->sole();
        $deal = $this->opportunity($business);

        $summary = $this->backfill();

        $this->assertSame(['resolved' => 1, 'unresolved' => 0, 'ambiguous' => 0], $summary);
        $this->assertSame((int) $location->id, (int) $deal->fresh()->location_id);
    }

    public function test_an_archived_location_is_not_evidence_but_its_active_sibling_is(): void
    {
        [$customer, $business] = $this->locationTenant(WorkspacePlanTier::Growth, 1);
        $active = $business->activeLocations()->sole();
        $second = $this->locations()->createLocation($business, $this->locationAttributes('Second'), (int) $customer->user_id);
        $this->archive((int) $second->id);

        $deal = $this->opportunity($business);

        $this->assertSame(['resolved' => 1, 'unresolved' => 0, 'ambiguous' => 0], $this->backfill());
        $this->assertSame((int) $active->id, (int) $deal->fresh()->location_id);
    }

    public function test_a_business_with_several_active_locations_stays_null_and_counts_as_ambiguous(): void
    {
        [$customer, $business] = $this->locationTenant(WorkspacePlanTier::Growth, 1);
        $this->locations()->createLocation($business, $this->locationAttributes('Second'), (int) $customer->user_id);
        $deal = $this->opportunity($business);

        $summary = $this->backfill();

        $this->assertSame(['resolved' => 0, 'unresolved' => 1, 'ambiguous' => 1], $summary);
        $this->assertNull($deal->fresh()->location_id);
    }

    public function test_a_business_with_no_active_location_stays_null_without_being_called_ambiguous(): void
    {
        [, $business] = $this->locationTenant(WorkspacePlanTier::Growth, 0);
        $deal = $this->opportunity($business);

        $summary = $this->backfill();

        $this->assertSame(['resolved' => 0, 'unresolved' => 1, 'ambiguous' => 0], $summary, 'Missing data is not ambiguity.');
        $this->assertNull($deal->fresh()->location_id);
    }

    public function test_an_already_attributed_row_is_never_overwritten(): void
    {
        [$customer, $business] = $this->locationTenant(WorkspacePlanTier::Growth, 1);
        $second = $this->locations()->createLocation($business, $this->locationAttributes('Second'), (int) $customer->user_id);
        $deal = $this->opportunity($business, (int) $second->id);

        $summary = $this->backfill();

        $this->assertSame(['resolved' => 0, 'unresolved' => 0, 'ambiguous' => 0], $summary);
        $this->assertSame((int) $second->id, (int) $deal->fresh()->location_id);
    }

    public function test_a_row_a_live_writer_attributes_mid_run_is_neither_overwritten_nor_counted(): void
    {
        [$customer, $business] = $this->locationTenant(WorkspacePlanTier::Growth, 1);
        $single = $business->activeLocations()->sole();
        $deal = $this->opportunity($business);

        $live = $this->locations()->createLocation($business, $this->locationAttributes('Live'), (int) $customer->user_id);
        $this->archive((int) $live->id);

        $injected = false;
        DB::listen(function ($query) use (&$injected, $deal, $live): void {
            if ($injected || ! str_contains($query->sql, '`business_locations`')) {
                return;
            }

            $injected = true;
            DB::table('crm_opportunities')->where('id', $deal->id)->update(['location_id' => $live->id]);
        });

        $summary = $this->backfill();

        $this->assertTrue($injected, 'The race was actually reproduced.');
        $this->assertSame(['resolved' => 0, 'unresolved' => 0, 'ambiguous' => 0], $summary);
        $this->assertSame((int) $live->id, (int) $deal->fresh()->location_id);
        $this->assertNotSame((int) $single->id, (int) $deal->fresh()->location_id);
    }

    public function test_a_rerun_is_idempotent_and_touches_nothing(): void
    {
        [, $business] = $this->locationTenant(WorkspacePlanTier::Growth, 1);
        $location = $business->activeLocations()->sole();
        $deal = $this->opportunity($business);

        $this->assertSame(1, $this->backfill()['resolved']);
        $firstTouch = CrmOpportunity::query()->whereKey($deal->id)->sole()->updated_at;

        $second = $this->backfill();

        $this->assertSame(['resolved' => 0, 'unresolved' => 0, 'ambiguous' => 0], $second);
        $this->assertSame((int) $location->id, (int) $deal->fresh()->location_id);
        $this->assertEquals($firstTouch, CrmOpportunity::query()->whereKey($deal->id)->sole()->updated_at);
    }

    public function test_the_migration_logs_counts_and_never_a_deal_title(): void
    {
        [, $business] = $this->locationTenant(WorkspacePlanTier::Growth, 1);
        $deal = $this->opportunity($business);
        $title = $deal->title;

        $lines = [];
        Log::listen(function ($message) use (&$lines): void {
            $lines[] = $message->message . ' ' . json_encode($message->context);
        });

        (require database_path('migrations/2026_09_21_100004_backfill_crm_opportunities_location_ids.php'))->up();

        $logged = implode("\n", $lines);

        $this->assertStringContainsString('location_id backfill', $logged);
        $this->assertStringContainsString('resolved=', $logged);
        $this->assertStringNotContainsString($title, $logged, 'No deal title may ever reach a log line.');
    }
}

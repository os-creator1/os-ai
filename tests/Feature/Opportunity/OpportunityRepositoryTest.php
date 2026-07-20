<?php

namespace Tests\Feature\Opportunity;

use App\Enums\Opportunity\OpportunityFreshness;
use App\Enums\Opportunity\OpportunityStatus;
use App\Enums\Opportunity\OpportunityWorkerKey;
use App\Repositories\Contracts\OpportunityRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Opportunity\Concerns\CreatesOpportunityTestData;
use Tests\TestCase;

class OpportunityRepositoryTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOpportunityTestData;

    public function test_uid_is_automatically_generated_and_unique(): void
    {
        $business = $this->createBusinessForOpportunities();

        $first = $this->createOpportunity($business, ['fingerprint' => hash('sha256', 'a')]);
        $second = $this->createOpportunity($business, ['fingerprint' => hash('sha256', 'b')]);

        $this->assertNotNull($first->uid);
        $this->assertNotNull($second->uid);
        $this->assertNotSame($first->uid, $second->uid);
    }

    public function test_fingerprint_must_be_unique(): void
    {
        $business = $this->createBusinessForOpportunities();
        $fingerprint = hash('sha256', 'duplicate-fingerprint');
        $this->createOpportunity($business, ['fingerprint' => $fingerprint]);

        $this->expectException(QueryException::class);

        $this->createOpportunity($business, ['fingerprint' => $fingerprint]);
    }

    /**
     * The testing MySQL connection intentionally runs with strict=false and
     * sql_mode=NO_ENGINE_SUBSTITUTION, so a non-strict server can silently
     * coerce an omitted NOT NULL column instead of raising an error —
     * asserting against a QueryException would not be portable. Instead
     * this asserts the schema itself declares the column correctly, via
     * information_schema.
     */
    public function test_evidence_column_is_json_not_null_without_a_default(): void
    {
        $column = DB::selectOne(
            'select IS_NULLABLE, COLUMN_DEFAULT, COLUMN_TYPE from information_schema.COLUMNS
                where TABLE_SCHEMA = DATABASE() and TABLE_NAME = ? and COLUMN_NAME = ?',
            ['opportunities', 'evidence']
        );

        $this->assertNotNull($column);
        $this->assertSame('NO', $column->IS_NULLABLE);
        $this->assertNull($column->COLUMN_DEFAULT);
        $this->assertSame('json', $column->COLUMN_TYPE);
    }

    public function test_deleting_the_confirming_run_sets_last_confirmed_run_id_to_null(): void
    {
        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business);
        $opportunity = $this->createOpportunity($business, [
            'fingerprint' => hash('sha256', 'set-null-fk'),
            'last_confirmed_run_id' => $run->id,
        ]);

        $run->delete();
        $opportunity->refresh();

        $this->assertNull($opportunity->last_confirmed_run_id);
    }

    public function test_current_missing_from_run_for_update_includes_null_last_confirmed_run_id(): void
    {
        $business = $this->createBusinessForOpportunities();
        $excludeRun = $this->createOpportunityRun($business);
        $repository = app(OpportunityRepository::class);

        $neverConfirmed = $this->createOpportunity($business, [
            'fingerprint' => hash('sha256', 'never-confirmed'),
            'last_confirmed_run_id' => null,
        ]);

        $result = $repository->currentMissingFromRunForUpdate($business->id, OpportunityWorkerKey::BusinessAdvisor, $excludeRun->id);

        $this->assertTrue($result->contains('id', $neverConfirmed->id));
    }

    public function test_current_missing_from_run_for_update_excludes_the_given_run_and_non_current_freshness(): void
    {
        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business);
        $otherRun = $this->createOpportunityRun($business);
        $repository = app(OpportunityRepository::class);

        $confirmedByThisRun = $this->createOpportunity($business, [
            'fingerprint' => hash('sha256', 'confirmed-this-run'),
            'last_confirmed_run_id' => $run->id,
        ]);
        $confirmedByOtherRun = $this->createOpportunity($business, [
            'fingerprint' => hash('sha256', 'confirmed-other-run'),
            'last_confirmed_run_id' => $otherRun->id,
        ]);
        $stale = $this->createOpportunity($business, [
            'fingerprint' => hash('sha256', 'already-stale'),
            'freshness' => OpportunityFreshness::Stale->value,
            'last_confirmed_run_id' => null,
        ]);

        $result = $repository->currentMissingFromRunForUpdate($business->id, OpportunityWorkerKey::BusinessAdvisor, $run->id);

        $this->assertFalse($result->contains('id', $confirmedByThisRun->id));
        $this->assertTrue($result->contains('id', $confirmedByOtherRun->id));
        $this->assertFalse($result->contains('id', $stale->id));
    }

    public function test_find_owned_for_update_rejects_other_businesses(): void
    {
        $business = $this->createBusinessForOpportunities();
        $otherBusiness = $this->createBusinessForOpportunities();
        $opportunity = $this->createOpportunity($business, ['fingerprint' => hash('sha256', 'owned')]);
        $repository = app(OpportunityRepository::class);

        $this->assertNull($repository->findOwnedForUpdate($opportunity->id, $otherBusiness->id));
        $this->assertNotNull($repository->findOwnedForUpdate($opportunity->id, $business->id));
    }

    public function test_find_owned_returns_the_matching_opportunity(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->createOpportunity($business, ['fingerprint' => hash('sha256', 'find-owned')]);
        $repository = app(OpportunityRepository::class);

        $found = $repository->findOwned($opportunity->id, $business->id);

        $this->assertNotNull($found);
        $this->assertSame($opportunity->id, $found->id);
    }

    public function test_find_owned_returns_null_for_another_business(): void
    {
        $business = $this->createBusinessForOpportunities();
        $otherBusiness = $this->createBusinessForOpportunities();
        $opportunity = $this->createOpportunity($business, ['fingerprint' => hash('sha256', 'find-owned-other-business')]);
        $repository = app(OpportunityRepository::class);

        $this->assertNull($repository->findOwned($opportunity->id, $otherBusiness->id));
    }

    public function test_find_owned_cannot_be_exposed_by_a_spoofed_business_id(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->createOpportunity($business, ['fingerprint' => hash('sha256', 'find-owned-spoofed')]);
        $repository = app(OpportunityRepository::class);

        $spoofedBusinessId = $business->id + 999999;

        $this->assertNull($repository->findOwned($opportunity->id, $spoofedBusinessId));
        $this->assertNotNull($repository->findOwned($opportunity->id, $business->id));
    }

    public function test_find_owned_returns_null_for_a_missing_opportunity(): void
    {
        $business = $this->createBusinessForOpportunities();
        $repository = app(OpportunityRepository::class);

        $this->assertNull($repository->findOwned(999999999, $business->id));
    }

    public function test_find_owned_performs_no_mutation(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->createOpportunity($business, ['fingerprint' => hash('sha256', 'find-owned-no-mutation')]);
        $originalUpdatedAt = $opportunity->updated_at;
        $repository = app(OpportunityRepository::class);

        $repository->findOwned($opportunity->id, $business->id);

        $this->assertTrue($originalUpdatedAt->equalTo($opportunity->fresh()->updated_at));
    }

    public function test_expired_snoozes_batch_only_returns_due_snoozed_opportunities(): void
    {
        $business = $this->createBusinessForOpportunities();
        $repository = app(OpportunityRepository::class);

        $due = $this->createOpportunity($business, [
            'fingerprint' => hash('sha256', 'due-snooze'),
            'status' => OpportunityStatus::Snoozed->value,
            'snoozed_until' => now()->subMinute(),
        ]);
        $this->createOpportunity($business, [
            'fingerprint' => hash('sha256', 'future-snooze'),
            'status' => OpportunityStatus::Snoozed->value,
            'snoozed_until' => now()->addDay(),
        ]);
        $this->createOpportunity($business, [
            'fingerprint' => hash('sha256', 'not-snoozed'),
            'status' => OpportunityStatus::Open->value,
        ]);

        $result = $repository->expiredSnoozesBatch(10);

        $this->assertCount(1, $result);
        $this->assertSame($due->id, $result->first()->id);
    }

    public function test_update_ignores_immutable_identity_fields(): void
    {
        $business = $this->createBusinessForOpportunities();
        $otherBusiness = $this->createBusinessForOpportunities();
        $opportunity = $this->createOpportunity($business, ['fingerprint' => hash('sha256', 'protected')]);
        $repository = app(OpportunityRepository::class);
        $originalFirstDetectedAt = $opportunity->first_detected_at;

        $repository->update($opportunity, [
            'business_id' => $otherBusiness->id,
            'worker_key' => OpportunityWorkerKey::Seo->value,
            'type' => 'missing_email',
            'fingerprint_version' => 99,
            'fingerprint' => hash('sha256', 'attacker-controlled'),
            'context_key' => 'attacker-context',
            'first_detected_at' => now()->addDay(),
            'title' => 'Updated title',
            'status' => OpportunityStatus::Completed->value,
        ]);

        $opportunity->refresh();

        $this->assertSame($business->id, $opportunity->business_id);
        $this->assertSame(OpportunityWorkerKey::BusinessAdvisor, $opportunity->worker_key);
        $this->assertSame('missing_phone', $opportunity->type);
        $this->assertSame(1, $opportunity->fingerprint_version);
        $this->assertSame(hash('sha256', 'protected'), $opportunity->fingerprint);
        $this->assertNull($opportunity->context_key);
        $this->assertTrue($originalFirstDetectedAt->equalTo($opportunity->first_detected_at));
        $this->assertSame('Updated title', $opportunity->title);
        $this->assertSame(OpportunityStatus::Completed, $opportunity->status);
    }

    public function test_paginate_for_customer_is_scoped_to_the_business(): void
    {
        $business = $this->createBusinessForOpportunities();
        $otherBusiness = $this->createBusinessForOpportunities();
        $this->createOpportunity($business, ['fingerprint' => hash('sha256', 'mine')]);
        $this->createOpportunity($otherBusiness, ['fingerprint' => hash('sha256', 'not-mine')]);
        $repository = app(OpportunityRepository::class);

        $page = $repository->paginateForCustomer($business, []);

        $this->assertSame(1, $page->total());
    }

    public function test_paginate_for_customer_filters_by_status(): void
    {
        $business = $this->createBusinessForOpportunities();
        $repository = app(OpportunityRepository::class);
        $open = $this->createOpportunity($business, [
            'fingerprint' => hash('sha256', 'open-one'),
            'status' => OpportunityStatus::Open->value,
        ]);
        $this->createOpportunity($business, [
            'fingerprint' => hash('sha256', 'dismissed-one'),
            'status' => OpportunityStatus::Dismissed->value,
        ]);

        $page = $repository->paginateForCustomer($business, ['status' => OpportunityStatus::Open->value]);

        $this->assertSame(1, $page->total());
        $this->assertSame($open->id, $page->first()->id);
    }

    public function test_paginate_for_customer_filters_by_freshness(): void
    {
        $business = $this->createBusinessForOpportunities();
        $repository = app(OpportunityRepository::class);
        $current = $this->createOpportunity($business, [
            'fingerprint' => hash('sha256', 'current-one'),
            'freshness' => OpportunityFreshness::Current->value,
        ]);
        $this->createOpportunity($business, [
            'fingerprint' => hash('sha256', 'stale-one'),
            'freshness' => OpportunityFreshness::Stale->value,
        ]);

        $page = $repository->paginateForCustomer($business, ['freshness' => OpportunityFreshness::Current->value]);

        $this->assertSame(1, $page->total());
        $this->assertSame($current->id, $page->first()->id);
    }

    public function test_paginate_for_customer_returns_the_expected_paginator_shape(): void
    {
        $business = $this->createBusinessForOpportunities();
        $this->createOpportunity($business, ['fingerprint' => hash('sha256', 'shape-one')]);
        $repository = app(OpportunityRepository::class);

        $page = $repository->paginateForCustomer($business, []);

        $this->assertInstanceOf(LengthAwarePaginator::class, $page);
        $this->assertSame(1, $page->total());
        $this->assertSame(1, $page->currentPage());
    }

    /**
     * A stale Opportunity is given every tie-break advantage (higher
     * priority_score, impact, urgency, an earlier first_detected_at) over a
     * current one — proving freshness=current still sorts first regardless.
     */
    public function test_current_opportunities_sort_before_stale_opportunities(): void
    {
        $business = $this->createBusinessForOpportunities();
        $repository = app(OpportunityRepository::class);

        $stale = $this->createOpportunity($business, [
            'fingerprint' => hash('sha256', 'ordering-stale-favored'),
            'freshness' => OpportunityFreshness::Stale->value,
            'priority_score' => 100,
            'impact' => 5,
            'urgency' => 5,
            'first_detected_at' => now()->subDay(),
        ]);
        $current = $this->createOpportunity($business, [
            'fingerprint' => hash('sha256', 'ordering-current-disfavored'),
            'freshness' => OpportunityFreshness::Current->value,
            'priority_score' => 0,
            'impact' => 0,
            'urgency' => 0,
            'first_detected_at' => now(),
        ]);

        $ids = $repository->paginateForCustomer($business, [])->pluck('id')->all();

        $this->assertSame([$current->id, $stale->id], $ids);
    }

    /**
     * A snoozed (non-actionable) Opportunity is given every tie-break
     * advantage over an open (actionable) one — proving the actionable
     * bucket still sorts first regardless.
     */
    public function test_actionable_statuses_sort_before_non_actionable_statuses(): void
    {
        $business = $this->createBusinessForOpportunities();
        $repository = app(OpportunityRepository::class);

        $snoozed = $this->createOpportunity($business, [
            'fingerprint' => hash('sha256', 'ordering-snoozed-favored'),
            'status' => OpportunityStatus::Snoozed->value,
            'priority_score' => 100,
            'impact' => 5,
            'urgency' => 5,
            'first_detected_at' => now()->subDay(),
        ]);
        $open = $this->createOpportunity($business, [
            'fingerprint' => hash('sha256', 'ordering-open-disfavored'),
            'status' => OpportunityStatus::Open->value,
            'priority_score' => 0,
            'impact' => 0,
            'urgency' => 0,
            'first_detected_at' => now(),
        ]);

        $ids = $repository->paginateForCustomer($business, [])->pluck('id')->all();

        $this->assertSame([$open->id, $snoozed->id], $ids);

        // Dismissed and completed are non-actionable the same way.
        $dismissed = $this->createOpportunity($business, [
            'fingerprint' => hash('sha256', 'ordering-dismissed-favored'),
            'status' => OpportunityStatus::Dismissed->value,
            'priority_score' => 100,
        ]);
        $completed = $this->createOpportunity($business, [
            'fingerprint' => hash('sha256', 'ordering-completed-favored'),
            'status' => OpportunityStatus::Completed->value,
            'priority_score' => 100,
        ]);

        $idsAfter = $repository->paginateForCustomer($business, [])->pluck('id')->all();

        $this->assertLessThan(array_search($dismissed->id, $idsAfter), array_search($open->id, $idsAfter));
        $this->assertLessThan(array_search($completed->id, $idsAfter), array_search($open->id, $idsAfter));
    }

    /**
     * open, awaiting_approval and in_progress are one priority bucket, not
     * internally ordered by status — their relative order is decided
     * entirely by the existing priority_score tie-breaker.
     */
    public function test_open_awaiting_approval_and_in_progress_share_one_priority_bucket(): void
    {
        $business = $this->createBusinessForOpportunities();
        $repository = app(OpportunityRepository::class);

        $inProgress = $this->createOpportunity($business, [
            'fingerprint' => hash('sha256', 'bucket-in-progress'),
            'status' => OpportunityStatus::InProgress->value,
            'priority_score' => 90,
        ]);
        $open = $this->createOpportunity($business, [
            'fingerprint' => hash('sha256', 'bucket-open'),
            'status' => OpportunityStatus::Open->value,
            'priority_score' => 50,
        ]);
        $awaitingApproval = $this->createOpportunity($business, [
            'fingerprint' => hash('sha256', 'bucket-awaiting-approval'),
            'status' => OpportunityStatus::AwaitingApproval->value,
            'priority_score' => 10,
        ]);

        $ids = $repository->paginateForCustomer($business, [])->pluck('id')->all();

        $this->assertSame([$inProgress->id, $open->id, $awaitingApproval->id], $ids);
    }

    public function test_priority_score_impact_urgency_first_detected_at_and_id_retain_their_exact_tie_break_sequence(): void
    {
        $business = $this->createBusinessForOpportunities();
        $repository = app(OpportunityRepository::class);
        $now = now();

        // Tied on priority_score; impact breaks the tie.
        $higherImpact = $this->createOpportunity($business, [
            'fingerprint' => hash('sha256', 'tie-impact-high'),
            'priority_score' => 50, 'impact' => 5, 'urgency' => 1, 'first_detected_at' => $now,
        ]);
        $lowerImpact = $this->createOpportunity($business, [
            'fingerprint' => hash('sha256', 'tie-impact-low'),
            'priority_score' => 50, 'impact' => 3, 'urgency' => 5, 'first_detected_at' => $now,
        ]);

        // Tied on priority_score and impact; urgency breaks the tie.
        $higherUrgency = $this->createOpportunity($business, [
            'fingerprint' => hash('sha256', 'tie-urgency-high'),
            'priority_score' => 20, 'impact' => 2, 'urgency' => 5, 'first_detected_at' => $now,
        ]);
        $lowerUrgency = $this->createOpportunity($business, [
            'fingerprint' => hash('sha256', 'tie-urgency-low'),
            'priority_score' => 20, 'impact' => 2, 'urgency' => 1, 'first_detected_at' => (clone $now)->addMinute(),
        ]);

        // Tied on priority_score, impact and urgency; first_detected_at breaks the tie.
        $earlier = $this->createOpportunity($business, [
            'fingerprint' => hash('sha256', 'tie-time-earlier'),
            'priority_score' => 10, 'impact' => 1, 'urgency' => 1, 'first_detected_at' => (clone $now)->subDay(),
        ]);
        $later = $this->createOpportunity($business, [
            'fingerprint' => hash('sha256', 'tie-time-later'),
            'priority_score' => 10, 'impact' => 1, 'urgency' => 1, 'first_detected_at' => $now,
        ]);

        // Tied on everything through first_detected_at; id (creation order) breaks the tie.
        $sharedTimestamp = (clone $now)->subHour();
        $firstCreated = $this->createOpportunity($business, [
            'fingerprint' => hash('sha256', 'tie-id-first'),
            'priority_score' => 5, 'impact' => 1, 'urgency' => 1, 'first_detected_at' => $sharedTimestamp,
        ]);
        $secondCreated = $this->createOpportunity($business, [
            'fingerprint' => hash('sha256', 'tie-id-second'),
            'priority_score' => 5, 'impact' => 1, 'urgency' => 1, 'first_detected_at' => $sharedTimestamp,
        ]);

        $ids = $repository->paginateForCustomer($business, [])->pluck('id')->all();

        $this->assertLessThan(array_search($lowerImpact->id, $ids), array_search($higherImpact->id, $ids));
        $this->assertLessThan(array_search($lowerUrgency->id, $ids), array_search($higherUrgency->id, $ids));
        $this->assertLessThan(array_search($later->id, $ids), array_search($earlier->id, $ids));
        $this->assertLessThan(array_search($secondCreated->id, $ids), array_search($firstCreated->id, $ids));
    }

    public function test_paginate_for_admin_returns_across_tenants(): void
    {
        $business = $this->createBusinessForOpportunities();
        $otherBusiness = $this->createBusinessForOpportunities();
        $this->createOpportunity($business, ['fingerprint' => hash('sha256', 'admin-1')]);
        $this->createOpportunity($otherBusiness, ['fingerprint' => hash('sha256', 'admin-2')]);
        $repository = app(OpportunityRepository::class);

        $page = $repository->paginateForAdmin([]);

        $this->assertSame(2, $page->total());
    }

    public function test_opportunity_is_scoped_to_its_owning_business(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->createOpportunity($business, ['fingerprint' => hash('sha256', 'relationship')]);

        $this->assertTrue($business->opportunities->contains($opportunity));
    }
}

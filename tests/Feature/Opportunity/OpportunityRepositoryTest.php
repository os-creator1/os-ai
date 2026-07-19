<?php

namespace Tests\Feature\Opportunity;

use App\Enums\Opportunity\OpportunityFreshness;
use App\Enums\Opportunity\OpportunityStatus;
use App\Enums\Opportunity\OpportunityWorkerKey;
use App\Repositories\Contracts\OpportunityRepository;
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

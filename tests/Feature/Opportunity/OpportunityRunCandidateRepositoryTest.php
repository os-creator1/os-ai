<?php

namespace Tests\Feature\Opportunity;

use App\Repositories\Contracts\OpportunityRunCandidateRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Opportunity\Concerns\CreatesOpportunityTestData;
use Tests\TestCase;

class OpportunityRunCandidateRepositoryTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOpportunityTestData;

    public function test_uid_is_automatically_generated_and_unique(): void
    {
        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business);

        $first = $this->createOpportunityRunCandidate($run, ['fingerprint' => hash('sha256', 'a')]);
        $second = $this->createOpportunityRunCandidate($run, ['fingerprint' => hash('sha256', 'b')]);

        $this->assertNotNull($first->uid);
        $this->assertNotNull($second->uid);
        $this->assertNotSame($first->uid, $second->uid);
    }

    public function test_fingerprint_must_be_unique_within_a_run(): void
    {
        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business);
        $fingerprint = hash('sha256', 'duplicate-in-run');
        $this->createOpportunityRunCandidate($run, ['fingerprint' => $fingerprint]);

        $this->expectException(QueryException::class);

        $this->createOpportunityRunCandidate($run, ['fingerprint' => $fingerprint]);
    }

    public function test_the_same_fingerprint_is_allowed_across_different_runs(): void
    {
        $business = $this->createBusinessForOpportunities();
        $runA = $this->createOpportunityRun($business);
        $runB = $this->createOpportunityRun($business);
        $fingerprint = hash('sha256', 'shared-across-runs');

        $inA = $this->createOpportunityRunCandidate($runA, ['fingerprint' => $fingerprint]);
        $inB = $this->createOpportunityRunCandidate($runB, ['fingerprint' => $fingerprint]);

        $this->assertNotSame($inA->id, $inB->id);
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
            ['opportunity_run_candidates', 'evidence']
        );

        $this->assertNotNull($column);
        $this->assertSame('NO', $column->IS_NULLABLE);
        $this->assertNull($column->COLUMN_DEFAULT);
        $this->assertSame('json', $column->COLUMN_TYPE);
    }

    public function test_upsert_creates_a_new_candidate_when_none_exists_for_the_fingerprint(): void
    {
        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business);
        $repository = app(OpportunityRunCandidateRepository::class);
        $fingerprint = hash('sha256', 'first-seen');

        $candidate = $repository->upsertMutableFields($run->id, $fingerprint, $this->opportunityRunCandidateAttributes($run, [
            'fingerprint' => $fingerprint,
            'title' => 'First title',
        ]));

        $this->assertSame($fingerprint, $candidate->fingerprint);
        $this->assertSame('First title', $candidate->title);
        $this->assertSame(1, $repository->countDistinctForRun($run->id));
    }

    public function test_upsert_updates_mutable_fields_without_creating_a_duplicate_row(): void
    {
        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business);
        $repository = app(OpportunityRunCandidateRepository::class);
        $fingerprint = hash('sha256', 'repeat-seen');

        $first = $repository->upsertMutableFields($run->id, $fingerprint, $this->opportunityRunCandidateAttributes($run, [
            'fingerprint' => $fingerprint,
            'title' => 'Original title',
            'priority_score' => 40,
        ]));

        $second = $repository->upsertMutableFields($run->id, $fingerprint, $this->opportunityRunCandidateAttributes($run, [
            'fingerprint' => $fingerprint,
            'title' => 'Updated title',
            'priority_score' => 75,
        ]));

        $this->assertSame($first->id, $second->id);
        $this->assertSame('Updated title', $second->fresh()->title);
        $this->assertSame(75, $second->fresh()->priority_score);
        $this->assertSame(1, $repository->countDistinctForRun($run->id));
    }

    public function test_upsert_never_overwrites_identity_fields_on_an_existing_row(): void
    {
        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business);
        $repository = app(OpportunityRunCandidateRepository::class);
        $fingerprint = hash('sha256', 'identity-protected');

        $original = $repository->upsertMutableFields($run->id, $fingerprint, $this->opportunityRunCandidateAttributes($run, [
            'fingerprint' => $fingerprint,
            'type' => 'missing_phone',
            'fingerprint_version' => 1,
            'context_key' => null,
        ]));

        $updated = $repository->upsertMutableFields($run->id, $fingerprint, array_merge(
            $this->opportunityRunCandidateAttributes($run, ['fingerprint' => $fingerprint]),
            [
                'type' => 'missing_email',
                'fingerprint_version' => 99,
                'context_key' => 'attacker-context',
                'opportunity_run_id' => $original->opportunity_run_id + 999,
            ]
        ));

        $this->assertSame($original->id, $updated->id);
        $this->assertSame('missing_phone', $updated->fresh()->type);
        $this->assertSame(1, $updated->fresh()->fingerprint_version);
        $this->assertNull($updated->fresh()->context_key);
        $this->assertSame($run->id, $updated->fresh()->opportunity_run_id);
    }

    public function test_ordered_for_run_returns_only_that_runs_candidates_in_order(): void
    {
        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business);
        $otherRun = $this->createOpportunityRun($business);
        $repository = app(OpportunityRunCandidateRepository::class);

        $first = $this->createOpportunityRunCandidate($run, ['fingerprint' => hash('sha256', 'ord-1')]);
        $second = $this->createOpportunityRunCandidate($run, ['fingerprint' => hash('sha256', 'ord-2')]);
        $this->createOpportunityRunCandidate($otherRun, ['fingerprint' => hash('sha256', 'ord-3')]);

        $result = $repository->orderedForRun($run->id);

        $this->assertSame([$first->id, $second->id], $result->pluck('id')->all());
    }
}

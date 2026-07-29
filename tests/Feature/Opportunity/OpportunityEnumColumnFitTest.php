<?php

namespace Tests\Feature\Opportunity;

use App\Enums\Opportunity\OpportunityActionExecutionStatus;
use App\Enums\Opportunity\OpportunityCompletionPolicy;
use App\Enums\Opportunity\OpportunityFreshness;
use App\Enums\Opportunity\OpportunityRunStatus;
use App\Enums\Opportunity\OpportunityStatus;
use App\Enums\Opportunity\OpportunityTransitionActorType;
use App\Enums\Opportunity\OpportunityTransitionCategory;
use App\Enums\Opportunity\OpportunityWorkerKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * RFC-002 §52 acceptance: "Every enum backing value fits its database
 * column." Reads the live testing-database schema via
 * information_schema.COLUMNS (the same portable convention already used by
 * OpportunityRepositoryTest::test_evidence_column_is_json_not_null_without_a_default())
 * and every currently-registered case of the matching enum class — never a
 * copied-down literal column width or a hardcoded "longest value" — so this
 * test fails automatically if a future enum case grows too long for its
 * column, if a migration narrows a column below an existing case, if a
 * mapped column is ever removed, or if a mapped column stops being varchar.
 */
class OpportunityEnumColumnFitTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('enumColumnMappingProvider')]
    public function test_every_persisted_enum_backing_value_fits_its_database_column(
        string $table,
        string $column,
        string $enumClass,
    ): void {
        $columnInfo = DB::selectOne(
            'select DATA_TYPE, CHARACTER_MAXIMUM_LENGTH from information_schema.COLUMNS
                where TABLE_SCHEMA = DATABASE() and TABLE_NAME = ? and COLUMN_NAME = ?',
            [$table, $column]
        );

        $this->assertNotNull($columnInfo, "Column [{$table}.{$column}] (expected to back {$enumClass}) does not exist.");
        $this->assertSame(
            'varchar',
            $columnInfo->DATA_TYPE,
            "Column [{$table}.{$column}] backing {$enumClass} is expected to be varchar, found [{$columnInfo->DATA_TYPE}]."
        );

        $limit = (int) $columnInfo->CHARACTER_MAXIMUM_LENGTH;
        $this->assertGreaterThan(
            0,
            $limit,
            "Column [{$table}.{$column}] backing {$enumClass} must report a positive CHARACTER_MAXIMUM_LENGTH."
        );

        foreach ($enumClass::cases() as $case) {
            $length = mb_strlen($case->value, 'UTF-8');

            $this->assertLessThanOrEqual(
                $limit,
                $length,
                "{$enumClass}::{$case->name} (backing value '{$case->value}', {$length} chars) exceeds "
                . "[{$table}.{$column}]'s varchar({$limit}) limit."
            );
        }
    }

    /**
     * Every native, enum-cast persisted column in the RFC-002 domain, as
     * verified against each model's own $casts array — no non-enum string
     * field (e.g. opportunity_action_executions.initiated_by_type,
     * opportunity_runs.reason_code, opportunity_run_candidates.type) is
     * included, since none of those is cast to a BackedEnum.
     *
     * @return array<string, array{0: string, 1: string, 2: class-string}>
     */
    public static function enumColumnMappingProvider(): array
    {
        return [
            'opportunity_runs.worker_key -> OpportunityWorkerKey' => ['opportunity_runs', 'worker_key', OpportunityWorkerKey::class],
            'opportunity_runs.status -> OpportunityRunStatus' => ['opportunity_runs', 'status', OpportunityRunStatus::class],
            'opportunities.worker_key -> OpportunityWorkerKey' => ['opportunities', 'worker_key', OpportunityWorkerKey::class],
            'opportunities.status -> OpportunityStatus' => ['opportunities', 'status', OpportunityStatus::class],
            'opportunities.freshness -> OpportunityFreshness' => ['opportunities', 'freshness', OpportunityFreshness::class],
            'opportunity_action_executions.status -> OpportunityActionExecutionStatus' => ['opportunity_action_executions', 'status', OpportunityActionExecutionStatus::class],
            'opportunity_action_executions.completion_policy -> OpportunityCompletionPolicy' => ['opportunity_action_executions', 'completion_policy', OpportunityCompletionPolicy::class],
            'opportunity_transitions.category -> OpportunityTransitionCategory' => ['opportunity_transitions', 'category', OpportunityTransitionCategory::class],
            'opportunity_transitions.actor_type -> OpportunityTransitionActorType' => ['opportunity_transitions', 'actor_type', OpportunityTransitionActorType::class],
        ];
    }
}

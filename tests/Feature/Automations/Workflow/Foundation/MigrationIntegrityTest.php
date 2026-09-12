<?php

namespace Tests\Feature\Automations\Workflow\Foundation;

use Illuminate\Support\Facades\DB;
use Tests\Feature\Automations\Concerns\UsesFreshSchema;
use Tests\TestCase;

/**
 * Automations V2 V2-0 — T-WF-28, T-WF-29, T-WF-30.
 *
 * The schema's circular reference between `automation_workflows` and
 * `automation_workflow_versions` cannot be created as two ordinary foreign keys,
 * so the contract fixes an exact migration order (§4.7): migration 1 creates the
 * column unconstrained, migration 2 creates the versions table and only then adds
 * the back-reference, and migration 2's down() removes that key BEFORE dropping
 * the table it points at.
 *
 * This test proves the whole cycle on the repository's real MySQL connection —
 * forward, backward, and forward again — because an ordering bug here is the kind
 * that only ever appears on someone's deploy.
 *
 * It holds the DDL work on its own, in one test, because DDL cannot run inside
 * RefreshDatabase's transaction (MySQL commits implicitly) and UsesFreshSchema
 * re-migrates around each test. Everything that can be proven without DDL lives
 * in SchemaConstraintsTest instead, where it costs nothing.
 */
class MigrationIntegrityTest extends TestCase
{
    use UsesFreshSchema;

    /** The six V2-0 migrations, in the order the contract fixes. */
    private const V2_MIGRATIONS = [
        '2026_09_15_100001_create_automation_workflows_table',
        '2026_09_15_100002_create_automation_workflow_versions_table',
        '2026_09_15_100003_create_automation_workflow_nodes_table',
        '2026_09_15_100004_create_automation_workflow_edges_table',
        '2026_09_15_100005_create_automation_enrollments_table',
        '2026_09_15_100006_create_automation_step_runs_table',
    ];

    private const V2_TABLES = [
        'automation_workflows',
        'automation_workflow_versions',
        'automation_workflow_nodes',
        'automation_workflow_edges',
        'automation_enrollments',
        'automation_step_runs',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFreshSchema();
    }

    /**
     * T-WF-28, T-WF-29, T-WF-30 — fresh migrate, path-scoped rollback, replay.
     *
     * The rollback is path-scoped deliberately: this repository's global down()
     * chain is not rollback-clean (see UsesFreshSchema's own docblock), so a broad
     * rollback would prove nothing about these six migrations. An unrelated table
     * is asserted to survive, which is what shows the scoping actually held.
     */
    public function test_the_six_migrations_migrate_roll_back_and_replay(): void
    {
        // T-WF-28 — a fresh migrate created everything.
        foreach (self::V2_TABLES as $table) {
            $this->assertTrue($this->tableExists($table), sprintf('%s should exist after migrating.', $table));
        }

        $this->assertSame(
            1,
            $this->foreignKeyCount('aw_published_version_foreign'),
            'The back-reference must exist after up().',
        );

        $this->assertTrue($this->tableExists('automation_executions'), 'Sanity: the B4 ledger should exist.');

        // T-WF-29 — roll back exactly these six.
        $this->artisan('migrate:rollback', [
            '--force' => true,
            '--path' => $this->migrationPaths(),
        ]);

        foreach (self::V2_TABLES as $table) {
            $this->assertFalse($this->tableExists($table), sprintf('%s should be gone after rollback.', $table));
        }

        $this->assertSame(
            0,
            $this->foreignKeyCount('aw_published_version_foreign'),
            'Migration 2 must drop the back-reference before dropping the versions table, leaving nothing behind.',
        );

        $this->assertTrue(
            $this->tableExists('automation_executions'),
            'A path-scoped rollback must leave unrelated migrations alone.',
        );

        // T-WF-30 — replay.
        $this->artisan('migrate', [
            '--force' => true,
            '--path' => $this->migrationPaths(),
        ]);

        foreach (self::V2_TABLES as $table) {
            $this->assertTrue($this->tableExists($table), sprintf('%s should be back after replay.', $table));
        }

        $this->assertSame(
            1,
            $this->foreignKeyCount('aw_published_version_foreign'),
            'Every constraint must behave identically after a replay.',
        );

        // The five composite keys are the ones a replay is most likely to lose,
        // because each depends on an index created in an earlier statement.
        foreach ([
            'aw_published_version_foreign',
            'awv_workflow_foreign',
            'awe_from_node_foreign',
            'awe_to_node_foreign',
            'aen_version_workflow_foreign',
            'aen_current_node_foreign',
        ] as $constraint) {
            $this->assertSame(1, $this->foreignKeyCount($constraint), $constraint . ' should exist after replay.');
        }
    }

    /** @return list<string> */
    private function migrationPaths(): array
    {
        return array_map(
            static fn (string $name): string => 'database/migrations/' . $name . '.php',
            self::V2_MIGRATIONS,
        );
    }

    private function tableExists(string $table): bool
    {
        return DB::getSchemaBuilder()->hasTable($table);
    }

    private function foreignKeyCount(string $name): int
    {
        return DB::table('information_schema.REFERENTIAL_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::connection()->getDatabaseName())
            ->where('CONSTRAINT_NAME', $name)
            ->count();
    }
}

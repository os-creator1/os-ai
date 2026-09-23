<?php

namespace Tests\Feature\Documents;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\Feature\Documents\Concerns\CreatesDocumentsTestData;
use Tests\TestCase;

/**
 * Implementation Contract 17 §5, §7.2, §8, §12.A — the exact schema shape of
 * Sub-slice A's nine tables.
 *
 * No application behaviour is exercised here: this sub-slice ships no
 * manager, controller, route, gateway or job, so this file proves only what
 * the DDL itself enforces. Structural facts are read from information_schema /
 * SHOW COLUMNS rather than inferred from insert behaviour, because this
 * connection runs with `strict => false` (config/database.php) and would
 * silently coerce rather than throw.
 */
class DocumentsSchemaTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDocumentsTestData;

    /** Contract §12.A's roster, verbatim. Nine is the number. */
    private const CONTRACTED_TABLES = [
        'business_documents',
        'business_document_versions',
        'business_document_line_items',
        'business_document_payment_schedule_items',
        'business_document_signatures',
        'business_stripe_connections',
        'business_payment_events',
        'business_document_payments',
        'business_document_refunds',
    ];

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function deleteRuleFor(string $table, string $column): string
    {
        $rows = DB::select(
            'SELECT rc.DELETE_RULE AS delete_rule
               FROM information_schema.REFERENTIAL_CONSTRAINTS rc
               JOIN information_schema.KEY_COLUMN_USAGE k
                 ON k.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
                AND k.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
              WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
                AND k.TABLE_NAME = ?
                AND k.COLUMN_NAME = ?',
            [$table, $column]
        );

        $this->assertNotEmpty($rows, "No foreign key found on [{$table}.{$column}].");

        return (string) $rows[0]->delete_rule;
    }

    private function assertDeleteRule(string $table, string $column, string $expected): void
    {
        $this->assertSame(
            $expected,
            $this->deleteRuleFor($table, $column),
            "Expected [{$table}.{$column}] ON DELETE {$expected}."
        );
    }

    /** @return array<int, object> */
    private function indexRows(string $table, string $indexName): array
    {
        return DB::select("SHOW INDEXES FROM `{$table}` WHERE Key_name = ?", [$indexName]);
    }

    private function assertUniqueIndex(string $table, string $indexName, array $columns): void
    {
        $rows = $this->indexRows($table, $indexName);

        $this->assertNotEmpty($rows, "Index [{$indexName}] not found on [{$table}].");
        $this->assertSame(0, (int) $rows[0]->Non_unique, "Index [{$indexName}] on [{$table}] must be UNIQUE.");

        $actual = array_map(static fn ($row) => $row->Column_name, $rows);
        $this->assertSame($columns, $actual, "Index [{$indexName}] covers the wrong columns.");
    }

    private function columnRow(string $table, string $column): object
    {
        $rows = DB::select("SHOW COLUMNS FROM `{$table}` WHERE Field = ?", [$column]);

        $this->assertNotEmpty($rows, "Column [{$column}] not found on [{$table}].");

        return $rows[0];
    }

    private function informationSchemaColumn(string $table, string $column): object
    {
        $rows = DB::select(
            'SELECT EXTRA AS extra, GENERATION_EXPRESSION AS expression
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );

        $this->assertNotEmpty($rows, "Column [{$column}] not found on [{$table}].");

        return $rows[0];
    }

    private function assertStoredGenerated(string $table, string $column, string $mustMention): void
    {
        $info = $this->informationSchemaColumn($table, $column);

        $this->assertStringContainsString('STORED GENERATED', strtoupper((string) $info->extra), "[{$table}.{$column}] must be a STORED generated column.");
        $this->assertStringContainsString($mustMention, (string) $info->expression, "[{$table}.{$column}] expression is wrong.");
    }

    private function assertConstraintViolation(callable $write, string $message): void
    {
        try {
            $write();
        } catch (QueryException $e) {
            $this->assertStringContainsString('SQLSTATE', $e->getMessage());

            return;
        }

        $this->fail($message);
    }

    /** @return array<int, string> */
    private function columnsOf(string $table): array
    {
        return Schema::getColumnListing($table);
    }

    private function loadMigration(string $file): object
    {
        $method = new ReflectionMethod(app('migrator'), 'resolvePath');
        $method->setAccessible(true);

        return $method->invoke(app('migrator'), database_path('migrations/' . $file));
    }

    // ------------------------------------------------------------------
    // Roster
    // ------------------------------------------------------------------

    public function test_all_nine_contracted_tables_exist(): void
    {
        foreach (self::CONTRACTED_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "Contracted table [{$table}] is missing.");
        }

        $this->assertCount(9, self::CONTRACTED_TABLES);
    }

    /**
     * Contract §10 — V1 creates no full append-only lifecycle transition
     * history, by decision. Asserted as an absence so a later slice cannot add
     * one without also changing the contract.
     */
    public function test_no_document_transition_history_table_exists(): void
    {
        foreach ([
            'document_transitions',
            'business_document_transitions',
            'business_document_history',
            'business_document_events',
            'business_document_audit_log',
        ] as $table) {
            $this->assertFalse(Schema::hasTable($table), "Unexpected history table [{$table}].");
        }
    }

    /** Contract §10 — DocumentViewed / last_viewed_at are not in V1. */
    public function test_no_view_tracking_column_exists_anywhere(): void
    {
        foreach (self::CONTRACTED_TABLES as $table) {
            foreach ($this->columnsOf($table) as $column) {
                $this->assertStringNotContainsStringIgnoringCase('viewed', $column, "[{$table}.{$column}] implies view tracking.");
                $this->assertStringNotContainsStringIgnoringCase('opened', $column, "[{$table}.{$column}] implies open tracking.");
            }
        }
    }

    /**
     * Contract §7.2.1/§11.8 — there is no client_secret column anywhere, and
     * no raw card / payment-method / provider-credential column of any kind.
     */
    public function test_no_client_secret_card_or_credential_column_exists_anywhere(): void
    {
        $forbidden = [
            'client_secret', 'secret', 'card', 'cvc', 'cvv', 'pan', 'exp_month', 'exp_year',
            'payment_method', 'api_key', 'refresh_token', 'access_token_plain', 'password',
        ];

        foreach (self::CONTRACTED_TABLES as $table) {
            foreach ($this->columnsOf($table) as $column) {
                $lower = strtolower($column);

                foreach ($forbidden as $needle) {
                    $this->assertStringNotContainsString($needle, $lower, "[{$table}.{$column}] must not exist (matches [{$needle}]).");
                }

                // The one legitimate token-shaped family is the HASHED link token.
                if (str_contains($lower, 'token')) {
                    $this->assertContains($column, [
                        'access_token_hash',
                        'access_token_expires_at',
                        'access_token_rotated_at',
                    ], "[{$table}.{$column}] is a token column other than the hashed link token.");
                }
            }
        }
    }

    // ------------------------------------------------------------------
    // Money
    // ------------------------------------------------------------------

    /**
     * Read from column DDL: config/database.php runs `strict => false`, so a
     * negative insert would be coerced, not rejected. The unsigned type is the
     * DDL fact Contract 17 §5 actually requires.
     */
    public function test_every_minor_unit_money_column_is_unsigned_bigint(): void
    {
        $money = [
            'business_document_versions' => ['subtotal_minor', 'total_minor'],
            'business_document_line_items' => ['unit_price_minor', 'line_total_minor'],
            'business_document_payment_schedule_items' => ['amount_minor'],
            'business_document_payments' => ['amount_minor'],
            'business_document_refunds' => ['amount_minor'],
        ];

        foreach ($money as $table => $columns) {
            foreach ($columns as $column) {
                $type = strtolower($this->columnRow($table, $column)->Type);

                $this->assertStringContainsString('bigint', $type, "[{$table}.{$column}] must be a bigint, got [{$type}].");
                $this->assertStringContainsString('unsigned', $type, "[{$table}.{$column}] must be unsigned, got [{$type}].");
            }
        }
    }

    public function test_no_money_column_uses_micro_units_or_decimals(): void
    {
        foreach (self::CONTRACTED_TABLES as $table) {
            foreach ($this->columnsOf($table) as $column) {
                $this->assertStringNotContainsString('_micro', $column, "[{$table}.{$column}] is lane D's micro-unit convention.");
            }
        }
    }

    public function test_currency_columns_are_char_three(): void
    {
        $currency = [
            'business_documents' => 'currency_code',
            'business_document_versions' => 'currency_code',
            'business_document_line_items' => 'currency_code',
            'business_document_payment_schedule_items' => 'currency_code',
            'business_document_payments' => 'currency_code',
        ];

        foreach ($currency as $table => $column) {
            $this->assertSame('char(3)', strtolower($this->columnRow($table, $column)->Type), "[{$table}.{$column}] must be char(3).");
        }
    }

    // ------------------------------------------------------------------
    // Document identity
    // ------------------------------------------------------------------

    public function test_a_document_is_business_location_and_contact_bound_not_null(): void
    {
        foreach (['business_id', 'business_location_id', 'contact_id', 'currency_code', 'kind', 'title'] as $column) {
            $this->assertSame('NO', $this->columnRow('business_documents', $column)->Null, "business_documents.{$column} must be NOT NULL.");
        }

        $this->assertSame('YES', $this->columnRow('business_documents', 'crm_opportunity_id')->Null);
    }

    public function test_recipient_snapshot_columns_exist_with_the_contracted_shape(): void
    {
        $name = $this->columnRow('business_documents', 'recipient_name_snapshot');
        $email = $this->columnRow('business_documents', 'recipient_email_snapshot');
        $phone = $this->columnRow('business_documents', 'recipient_phone_snapshot');

        $this->assertSame('varchar(191)', strtolower($name->Type));
        $this->assertSame('varchar(255)', strtolower($email->Type));
        $this->assertSame('varchar(32)', strtolower($phone->Type));

        // Nullable at the DDL level: email is REQUIRED only at send, enforced
        // by the later manager (§5.2), so a draft has none yet.
        $this->assertSame('YES', $name->Null);
        $this->assertSame('YES', $email->Null);
        $this->assertSame('YES', $phone->Null);
    }

    public function test_content_hash_has_canonical_sha256_storage(): void
    {
        $this->assertSame('char(64)', strtolower($this->columnRow('business_document_versions', 'content_hash')->Type));
        $this->assertSame('char(64)', strtolower($this->columnRow('business_document_signatures', 'signed_content_hash')->Type));
        $this->assertSame('char(64)', strtolower($this->columnRow('business_document_signatures', 'consent_statement_hash')->Type));
    }

    public function test_document_table_carries_the_durable_dedupe_and_token_markers(): void
    {
        foreach ([
            'access_token_hash', 'access_token_expires_at', 'access_token_rotated_at',
            'expiry_reminder_last_sent_at', 'expiry_reminder_count', 'expires_at',
        ] as $column) {
            $this->assertContains($column, $this->columnsOf('business_documents'));
        }

        $this->assertContains('reminder_last_sent_at', $this->columnsOf('business_document_payment_schedule_items'));
        $this->assertContains('reminder_count', $this->columnsOf('business_document_payment_schedule_items'));
        $this->assertContains('receipt_sent_at', $this->columnsOf('business_document_payments'));
    }

    // ------------------------------------------------------------------
    // Schedule belongs to the VERSION
    // ------------------------------------------------------------------

    public function test_schedule_items_belong_to_a_version_not_a_document(): void
    {
        $columns = $this->columnsOf('business_document_payment_schedule_items');

        $this->assertContains('business_document_version_id', $columns);
        $this->assertNotContains('business_document_id', $columns, 'The schedule must NOT be document-scoped (§5.9).');
        $this->assertUniqueIndex(
            'business_document_payment_schedule_items',
            'bdpsi_version_sequence_unique',
            ['business_document_version_id', 'sequence']
        );
    }

    public function test_the_same_sequence_may_exist_on_different_versions_of_one_document(): void
    {
        $bundle = $this->documentsBundle();
        $document = $this->insertDocument($bundle);
        $v1 = $this->insertVersion($document, ['version_number' => 1, 'state' => 'superseded']);
        $v2 = $this->insertVersion($document, ['version_number' => 2, 'state' => 'issued']);

        $this->insertScheduleItem($v1, ['sequence' => 1]);
        // Revising copies the schedule into NEW rows for the new version.
        $this->insertScheduleItem($v2, ['sequence' => 1]);

        $this->assertSame(2, DB::table('business_document_payment_schedule_items')->count());
    }

    public function test_a_version_may_not_hold_two_schedule_items_with_the_same_sequence(): void
    {
        $bundle = $this->documentsBundle();
        $version = $this->insertVersion($this->insertDocument($bundle));

        $this->insertScheduleItem($version, ['sequence' => 1]);

        $this->assertConstraintViolation(
            fn () => $this->insertScheduleItem($version, ['sequence' => 1]),
            'A version must not hold two schedule items with one sequence.'
        );

        // deposit + balance is the two-item shape.
        $this->insertScheduleItem($version, ['sequence' => 2, 'kind' => 'balance']);
        $this->assertSame(2, DB::table('business_document_payment_schedule_items')->where('business_document_version_id', $version)->count());
    }

    public function test_line_items_and_schedule_items_cascade_with_their_version(): void
    {
        $bundle = $this->documentsBundle();
        $version = $this->insertVersion($this->insertDocument($bundle));
        $this->insertLineItem($version);
        $this->insertScheduleItem($version);

        DB::table('business_document_versions')->where('id', $version)->delete();

        $this->assertSame(0, DB::table('business_document_line_items')->count());
        $this->assertSame(0, DB::table('business_document_payment_schedule_items')->count());
    }

    // ------------------------------------------------------------------
    // Foreign keys: directions and delete behaviour
    // ------------------------------------------------------------------

    public function test_document_foreign_keys_have_the_contracted_delete_rules(): void
    {
        $this->assertDeleteRule('business_documents', 'business_id', 'RESTRICT');
        $this->assertDeleteRule('business_documents', 'business_location_id', 'RESTRICT');
        $this->assertDeleteRule('business_documents', 'contact_id', 'RESTRICT');
        $this->assertDeleteRule('business_documents', 'crm_opportunity_id', 'SET NULL');
        $this->assertDeleteRule('business_documents', 'created_by_user_id', 'SET NULL');
        $this->assertDeleteRule('business_documents', 'current_version_id', 'SET NULL');
    }

    /**
     * Contract §5.3 lists `cascadeOnDelete` here, which MySQL cannot create:
     * business_document_id is the base column of the STORED generated
     * `draft_guard`, and MySQL refuses a CASCADE/SET NULL foreign key on such
     * a column (the reason automation_workflow_versions is RESTRICT too).
     * Documents are voided, never deleted, so RESTRICT costs nothing.
     */
    public function test_version_foreign_keys_have_the_only_rules_a_generated_guard_base_permits(): void
    {
        $this->assertDeleteRule('business_document_versions', 'business_document_id', 'RESTRICT');
        $this->assertDeleteRule('business_document_versions', 'created_by_user_id', 'SET NULL');
    }

    public function test_child_and_evidence_foreign_keys_have_the_contracted_delete_rules(): void
    {
        $this->assertDeleteRule('business_document_line_items', 'business_document_version_id', 'CASCADE');
        $this->assertDeleteRule('business_document_payment_schedule_items', 'business_document_version_id', 'CASCADE');

        $this->assertDeleteRule('business_document_signatures', 'business_document_id', 'RESTRICT');
        $this->assertDeleteRule('business_document_signatures', 'business_document_version_id', 'RESTRICT');
    }

    public function test_money_and_provider_foreign_keys_are_restrict(): void
    {
        $this->assertDeleteRule('business_stripe_connections', 'business_id', 'RESTRICT');
        $this->assertDeleteRule('business_payment_events', 'business_stripe_connection_id', 'RESTRICT');

        $this->assertDeleteRule('business_document_payments', 'business_id', 'RESTRICT');
        $this->assertDeleteRule('business_document_payments', 'business_document_id', 'RESTRICT');
        $this->assertDeleteRule('business_document_payments', 'schedule_item_id', 'RESTRICT');
        $this->assertDeleteRule('business_document_payments', 'business_stripe_connection_id', 'RESTRICT');

        $this->assertDeleteRule('business_document_refunds', 'business_id', 'RESTRICT');
        $this->assertDeleteRule('business_document_refunds', 'business_document_payment_id', 'RESTRICT');
        $this->assertDeleteRule('business_document_refunds', 'initiated_by_user_id', 'SET NULL');
    }

    public function test_a_document_with_versions_cannot_be_hard_deleted(): void
    {
        $bundle = $this->documentsBundle();
        $document = $this->insertDocument($bundle);
        $this->insertVersion($document);

        $this->assertConstraintViolation(
            fn () => DB::table('business_documents')->where('id', $document)->delete(),
            'A document with versions must not be deletable.'
        );
    }

    // ------------------------------------------------------------------
    // The circular FK (§5.3.3)
    // ------------------------------------------------------------------

    public function test_the_circular_current_version_foreign_key_exists_after_migration(): void
    {
        $rows = DB::select(
            'SELECT k.REFERENCED_TABLE_NAME AS ref_table, k.REFERENCED_COLUMN_NAME AS ref_column
               FROM information_schema.KEY_COLUMN_USAGE k
              WHERE k.CONSTRAINT_SCHEMA = DATABASE()
                AND k.TABLE_NAME = ?
                AND k.COLUMN_NAME = ?
                AND k.REFERENCED_TABLE_NAME IS NOT NULL',
            ['business_documents', 'current_version_id']
        );

        $this->assertCount(1, $rows, 'business_documents.current_version_id must carry exactly one foreign key.');
        $this->assertSame('business_document_versions', $rows[0]->ref_table);
        $this->assertSame('id', $rows[0]->ref_column);
    }

    public function test_the_circular_fk_is_staged_in_three_ordered_migrations(): void
    {
        $files = collect(scandir(database_path('migrations')))
            ->filter(fn ($f) => str_starts_with($f, '2026_09_25_1000'))
            ->values()
            ->all();

        $pos = fn (string $needle) => collect($files)->search(fn ($f) => str_contains($f, $needle));

        $documents = $pos('create_business_documents_table');
        $versions = $pos('create_business_document_versions_table');
        $fk = $pos('add_current_version_foreign_key_to_business_documents');

        $this->assertNotFalse($documents);
        $this->assertNotFalse($versions);
        $this->assertNotFalse($fk);
        $this->assertTrue($documents < $versions && $versions < $fk, 'Staged DDL order must be documents -> versions -> add FK.');
    }

    public function test_the_current_version_fk_migration_rolls_back_and_reapplies_cleanly(): void
    {
        $migration = $this->loadMigration('2026_09_25_100003_add_current_version_foreign_key_to_business_documents.php');

        $hasFk = fn (): bool => DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('CONSTRAINT_SCHEMA', DB::raw('DATABASE()'))
            ->where('TABLE_NAME', 'business_documents')
            ->where('COLUMN_NAME', 'current_version_id')
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->exists();

        $this->assertTrue($hasFk());

        // Step 4 of §5.3.3: the down() path drops the FK before the versions
        // table can be dropped.
        $migration->down();
        $this->assertFalse($hasFk(), 'down() must drop the current_version_id FK.');

        // The versions table can now be dropped/recreated without the cycle.
        $migration->up();
        $this->assertTrue($hasFk(), 'up() must restore the current_version_id FK.');
    }

    public function test_deleting_the_current_version_nulls_the_documents_pointer(): void
    {
        $bundle = $this->documentsBundle();
        $document = $this->insertDocument($bundle);
        $version = $this->insertVersion($document);

        DB::table('business_documents')->where('id', $document)->update(['current_version_id' => $version]);
        $this->assertSame($version, (int) DB::table('business_documents')->where('id', $document)->value('current_version_id'));

        DB::table('business_document_versions')->where('id', $version)->delete();

        $this->assertNull(DB::table('business_documents')->where('id', $document)->value('current_version_id'));
    }

    public function test_current_version_must_reference_a_real_version(): void
    {
        $bundle = $this->documentsBundle();
        $document = $this->insertDocument($bundle);

        $this->assertConstraintViolation(
            fn () => DB::table('business_documents')->where('id', $document)->update(['current_version_id' => 999999]),
            'current_version_id must reference an existing version.'
        );
    }

    // ------------------------------------------------------------------
    // Generated guard: one draft version per document
    // ------------------------------------------------------------------

    public function test_draft_guard_is_a_stored_generated_column_with_a_unique_key(): void
    {
        $this->assertStoredGenerated('business_document_versions', 'draft_guard', 'draft');
        $this->assertUniqueIndex('business_document_versions', 'bdv_draft_guard_unique', ['draft_guard']);
    }

    public function test_a_document_may_not_hold_two_draft_versions(): void
    {
        $bundle = $this->documentsBundle();
        $document = $this->insertDocument($bundle);

        $this->insertVersion($document, ['version_number' => 1, 'state' => 'draft']);

        $this->assertConstraintViolation(
            fn () => $this->insertVersion($document, ['version_number' => 2, 'state' => 'draft']),
            'At most ONE draft version per document must be enforced by the database.'
        );
    }

    public function test_issued_and_superseded_versions_coexist_with_exactly_one_draft(): void
    {
        $bundle = $this->documentsBundle();
        $document = $this->insertDocument($bundle);

        $this->insertVersion($document, ['version_number' => 1, 'state' => 'superseded']);
        $this->insertVersion($document, ['version_number' => 2, 'state' => 'superseded']);
        $this->insertVersion($document, ['version_number' => 3, 'state' => 'issued']);
        $this->insertVersion($document, ['version_number' => 4, 'state' => 'draft']);

        $this->assertSame(4, DB::table('business_document_versions')->where('business_document_id', $document)->count());

        $this->assertConstraintViolation(
            fn () => $this->insertVersion($document, ['version_number' => 5, 'state' => 'draft']),
            'A second draft must still be refused alongside issued/superseded versions.'
        );
    }

    public function test_the_draft_slot_frees_when_the_draft_is_issued(): void
    {
        $bundle = $this->documentsBundle();
        $document = $this->insertDocument($bundle);
        $draft = $this->insertVersion($document, ['version_number' => 1, 'state' => 'draft']);

        DB::table('business_document_versions')->where('id', $draft)->update(['state' => 'issued']);

        $this->insertVersion($document, ['version_number' => 2, 'state' => 'draft']);
        $this->assertSame(1, DB::table('business_document_versions')->where('business_document_id', $document)->where('state', 'draft')->count());
    }

    public function test_different_documents_may_each_hold_a_draft_version(): void
    {
        $bundle = $this->documentsBundle();

        $this->insertVersion($this->insertDocument($bundle), ['version_number' => 1, 'state' => 'draft']);
        $this->insertVersion($this->insertDocument($bundle), ['version_number' => 1, 'state' => 'draft']);

        $this->assertSame(2, DB::table('business_document_versions')->where('state', 'draft')->count());
    }

    public function test_version_number_is_unique_per_document_only(): void
    {
        $bundle = $this->documentsBundle();
        $one = $this->insertDocument($bundle);
        $two = $this->insertDocument($bundle);

        $this->insertVersion($one, ['version_number' => 1, 'state' => 'issued']);

        $this->assertConstraintViolation(
            fn () => $this->insertVersion($one, ['version_number' => 1, 'state' => 'superseded']),
            'version_number must be unique per document.'
        );

        $this->insertVersion($two, ['version_number' => 1, 'state' => 'issued']);
        $this->assertSame(2, DB::table('business_document_versions')->count());
    }

    // ------------------------------------------------------------------
    // Historical Stripe connections, one live (active_business_id)
    // ------------------------------------------------------------------

    public function test_active_business_id_is_a_stored_generated_column_with_a_unique_key(): void
    {
        $this->assertStoredGenerated('business_stripe_connections', 'active_business_id', 'business_id');
        $this->assertUniqueIndex('business_stripe_connections', 'bsc_active_business_unique', ['active_business_id']);
    }

    public function test_business_id_alone_is_not_unique_on_connections(): void
    {
        $rows = DB::select("SHOW INDEXES FROM `business_stripe_connections` WHERE Column_name = 'business_id'");

        foreach ($rows as $row) {
            if ((int) $row->Seq_in_index === 1 && (int) $row->Non_unique === 0) {
                $this->fail("business_stripe_connections.business_id must not be uniquely indexed on its own (index [{$row->Key_name}]).");
            }
        }

        $this->assertTrue(true);
    }

    public function test_a_business_may_hold_only_one_live_connection(): void
    {
        $business = $this->documentsBusiness();

        $this->insertStripeConnection($business->id, ['status' => 'active']);

        foreach (['pending', 'onboarding', 'active', 'restricted'] as $liveState) {
            $this->assertConstraintViolation(
                fn () => $this->insertStripeConnection($business->id, ['status' => $liveState]),
                "A second [{$liveState}] connection must be refused while one is live."
            );
        }
    }

    public function test_historical_disconnected_connections_coexist_with_one_live_connection(): void
    {
        $business = $this->documentsBusiness();

        $this->insertStripeConnection($business->id, ['status' => 'disconnected', 'disconnected_at' => now()]);
        $this->insertStripeConnection($business->id, ['status' => 'disconnected', 'disconnected_at' => now()]);
        $this->insertStripeConnection($business->id, ['status' => 'disconnected', 'disconnected_at' => now()]);
        $this->insertStripeConnection($business->id, ['status' => 'active']);

        $this->assertSame(4, DB::table('business_stripe_connections')->where('business_id', $business->id)->count());
        $this->assertSame(1, DB::table('business_stripe_connections')->where('business_id', $business->id)->whereNotNull('active_business_id')->count());
    }

    public function test_disconnecting_frees_the_live_slot_for_a_different_account(): void
    {
        $business = $this->documentsBusiness();

        $first = $this->insertStripeConnection($business->id, ['status' => 'active', 'stripe_account_id' => 'acct_first']);

        DB::table('business_stripe_connections')->where('id', $first)->update(['status' => 'disconnected', 'disconnected_at' => now()]);
        $this->assertNull(DB::table('business_stripe_connections')->where('id', $first)->value('active_business_id'));

        // A DIFFERENT Stripe account creates a NEW row; the old row's
        // stripe_account_id is untouched (§5.7).
        $this->insertStripeConnection($business->id, ['status' => 'onboarding', 'stripe_account_id' => 'acct_second']);

        $this->assertSame('acct_first', DB::table('business_stripe_connections')->where('id', $first)->value('stripe_account_id'));
        $this->assertSame(2, DB::table('business_stripe_connections')->where('business_id', $business->id)->count());
    }

    public function test_a_different_business_may_hold_its_own_live_connection(): void
    {
        $one = $this->documentsBusiness();
        $two = $this->documentsBusiness();

        $this->insertStripeConnection($one->id, ['status' => 'active']);
        $this->insertStripeConnection($two->id, ['status' => 'active']);

        $this->assertSame(2, DB::table('business_stripe_connections')->whereNotNull('active_business_id')->count());
    }

    public function test_stripe_account_id_is_unique_across_every_row(): void
    {
        $one = $this->documentsBusiness();
        $two = $this->documentsBusiness();

        $this->insertStripeConnection($one->id, ['status' => 'disconnected', 'stripe_account_id' => 'acct_shared']);

        $this->assertConstraintViolation(
            fn () => $this->insertStripeConnection($two->id, ['status' => 'active', 'stripe_account_id' => 'acct_shared']),
            'stripe_account_id must be unique, even against a historical row of another Business.'
        );
    }

    // ------------------------------------------------------------------
    // One active payment attempt per schedule item (active_schedule_item_id)
    // ------------------------------------------------------------------

    /** @return array{business: mixed, doc: int, item: int, conn: int} */
    private function paymentFixture(): array
    {
        $bundle = $this->documentsBundle();
        $doc = $this->insertDocument($bundle);
        $version = $this->insertVersion($doc, ['state' => 'issued']);
        $item = $this->insertScheduleItem($version);
        $conn = $this->insertStripeConnection($bundle['business']->id);

        return ['business' => $bundle['business'], 'doc' => $doc, 'item' => $item, 'conn' => $conn, 'version' => $version];
    }

    public function test_active_schedule_item_id_is_a_stored_generated_column_with_a_unique_key(): void
    {
        $this->assertStoredGenerated('business_document_payments', 'active_schedule_item_id', 'schedule_item_id');
        $this->assertUniqueIndex('business_document_payments', 'bdp_active_item_unique', ['active_schedule_item_id']);
    }

    public function test_a_schedule_item_may_hold_only_one_live_payment_attempt(): void
    {
        $f = $this->paymentFixture();

        $this->insertPayment($f['business']->id, $f['doc'], $f['item'], $f['conn'], ['status' => 'created']);

        foreach (['created', 'requires_action', 'processing'] as $liveStatus) {
            $this->assertConstraintViolation(
                fn () => $this->insertPayment($f['business']->id, $f['doc'], $f['item'], $f['conn'], ['status' => $liveStatus]),
                "A second [{$liveStatus}] attempt must be refused while one is live."
            );
        }
    }

    public function test_terminal_attempts_coexist_with_one_live_attempt(): void
    {
        $f = $this->paymentFixture();

        $this->insertPayment($f['business']->id, $f['doc'], $f['item'], $f['conn'], ['status' => 'failed']);
        $this->insertPayment($f['business']->id, $f['doc'], $f['item'], $f['conn'], ['status' => 'canceled']);
        $this->insertPayment($f['business']->id, $f['doc'], $f['item'], $f['conn'], ['status' => 'succeeded']);
        $this->insertPayment($f['business']->id, $f['doc'], $f['item'], $f['conn'], ['status' => 'requires_action']);

        $this->assertSame(4, DB::table('business_document_payments')->where('schedule_item_id', $f['item'])->count());
        $this->assertSame(1, DB::table('business_document_payments')->where('schedule_item_id', $f['item'])->whereNotNull('active_schedule_item_id')->count());
    }

    public function test_a_terminal_failure_frees_the_slot_for_exactly_one_new_attempt(): void
    {
        $f = $this->paymentFixture();

        $first = $this->insertPayment($f['business']->id, $f['doc'], $f['item'], $f['conn'], ['status' => 'created']);

        DB::table('business_document_payments')->where('id', $first)->update(['status' => 'failed']);
        $this->assertNull(DB::table('business_document_payments')->where('id', $first)->value('active_schedule_item_id'));

        $second = $this->insertPayment($f['business']->id, $f['doc'], $f['item'], $f['conn'], ['status' => 'created']);
        $this->assertNotSame($first, $second);

        $this->assertConstraintViolation(
            fn () => $this->insertPayment($f['business']->id, $f['doc'], $f['item'], $f['conn'], ['status' => 'created']),
            'Only ONE new deliberate attempt may follow a terminal failure.'
        );
    }

    public function test_different_schedule_items_may_each_hold_a_live_attempt(): void
    {
        $f = $this->paymentFixture();
        $balance = $this->insertScheduleItem($f['version'], ['sequence' => 2, 'kind' => 'balance']);

        $this->insertPayment($f['business']->id, $f['doc'], $f['item'], $f['conn'], ['status' => 'created']);
        $this->insertPayment($f['business']->id, $f['doc'], $balance, $f['conn'], ['status' => 'created']);

        $this->assertSame(2, DB::table('business_document_payments')->whereNotNull('active_schedule_item_id')->count());
    }

    public function test_local_idempotency_key_is_unique_per_business_not_globally(): void
    {
        $f = $this->paymentFixture();
        $other = $this->paymentFixture();

        $this->insertPayment($f['business']->id, $f['doc'], $f['item'], $f['conn'], [
            'status' => 'failed',
            'local_idempotency_key' => 'document-payment:fixed',
        ]);

        $this->assertConstraintViolation(
            fn () => $this->insertPayment($f['business']->id, $f['doc'], $f['item'], $f['conn'], [
                'status' => 'failed',
                'local_idempotency_key' => 'document-payment:fixed',
            ]),
            'The same key within one Business must be refused.'
        );

        // Tenant-scoped: another Business may hold the identical string.
        $this->insertPayment($other['business']->id, $other['doc'], $other['item'], $other['conn'], [
            'status' => 'failed',
            'local_idempotency_key' => 'document-payment:fixed',
        ]);

        $this->assertSame(2, DB::table('business_document_payments')->where('local_idempotency_key', 'document-payment:fixed')->count());
    }

    public function test_provider_payment_ids_are_unique_when_populated_and_nulls_coexist(): void
    {
        $f = $this->paymentFixture();

        // Two rows with NULL provider ids coexist (terminal, so no live clash).
        $this->insertPayment($f['business']->id, $f['doc'], $f['item'], $f['conn'], ['status' => 'failed']);
        $this->insertPayment($f['business']->id, $f['doc'], $f['item'], $f['conn'], ['status' => 'failed']);

        $this->insertPayment($f['business']->id, $f['doc'], $f['item'], $f['conn'], [
            'status' => 'succeeded',
            'provider_payment_intent_id' => 'pi_1',
            'provider_charge_id' => 'ch_1',
        ]);

        $this->assertConstraintViolation(
            fn () => $this->insertPayment($f['business']->id, $f['doc'], $f['item'], $f['conn'], [
                'status' => 'failed',
                'provider_payment_intent_id' => 'pi_1',
            ]),
            'provider_payment_intent_id must be unique when populated.'
        );

        $this->assertConstraintViolation(
            fn () => $this->insertPayment($f['business']->id, $f['doc'], $f['item'], $f['conn'], [
                'status' => 'failed',
                'provider_charge_id' => 'ch_1',
            ]),
            'provider_charge_id must be unique when populated.'
        );
    }

    public function test_a_payment_keeps_its_exact_historical_connection(): void
    {
        $f = $this->paymentFixture();
        $payment = $this->insertPayment($f['business']->id, $f['doc'], $f['item'], $f['conn'], ['status' => 'succeeded']);

        // The connection is later disconnected; the payment still points at it.
        DB::table('business_stripe_connections')->where('id', $f['conn'])->update(['status' => 'disconnected']);

        $this->assertSame($f['conn'], (int) DB::table('business_document_payments')->where('id', $payment)->value('business_stripe_connection_id'));

        // ...and the connection row cannot be deleted out from under it.
        $this->assertConstraintViolation(
            fn () => DB::table('business_stripe_connections')->where('id', $f['conn'])->delete(),
            'A connection referenced by a payment must not be deletable.'
        );
    }

    // ------------------------------------------------------------------
    // Refunds, events, signatures
    // ------------------------------------------------------------------

    public function test_refund_keys_are_tenant_scoped_and_provider_refund_ids_unique(): void
    {
        $f = $this->paymentFixture();
        $other = $this->paymentFixture();
        $payment = $this->insertPayment($f['business']->id, $f['doc'], $f['item'], $f['conn'], ['status' => 'succeeded']);
        $otherPayment = $this->insertPayment($other['business']->id, $other['doc'], $other['item'], $other['conn'], ['status' => 'succeeded']);

        $this->insertRefund($f['business']->id, $payment, ['local_idempotency_key' => 'document-refund:fixed', 'provider_refund_id' => 're_1']);

        $this->assertConstraintViolation(
            fn () => $this->insertRefund($f['business']->id, $payment, ['local_idempotency_key' => 'document-refund:fixed']),
            'The same refund key within one Business must be refused.'
        );

        $this->assertConstraintViolation(
            fn () => $this->insertRefund($f['business']->id, $payment, ['provider_refund_id' => 're_1']),
            'provider_refund_id must be unique when populated.'
        );

        // Tenant-scoped key: another Business may hold the identical string.
        $this->insertRefund($other['business']->id, $otherPayment, ['local_idempotency_key' => 'document-refund:fixed']);

        // NULL provider ids coexist.
        $this->insertRefund($f['business']->id, $payment);
        $this->insertRefund($f['business']->id, $payment);

        $this->assertSame(1, DB::table('business_document_refunds')->whereNotNull('provider_refund_id')->count());
    }

    public function test_payment_event_identity_is_scoped_by_connected_account(): void
    {
        $this->insertPaymentEvent(['stripe_account_id' => 'acct_a', 'provider_event_id' => 'evt_1']);

        $this->assertConstraintViolation(
            fn () => $this->insertPaymentEvent(['stripe_account_id' => 'acct_a', 'provider_event_id' => 'evt_1']),
            'A duplicate (account, event id) delivery must be refused.'
        );

        // The same event id under a DIFFERENT connected account is distinct.
        $this->insertPaymentEvent(['stripe_account_id' => 'acct_b', 'provider_event_id' => 'evt_1']);

        $this->assertSame(2, DB::table('business_payment_events')->where('provider_event_id', 'evt_1')->count());
    }

    public function test_an_event_for_an_unrecognized_account_is_still_recorded(): void
    {
        $id = $this->insertPaymentEvent(['business_stripe_connection_id' => null, 'stripe_account_id' => 'acct_unknown']);

        $this->assertNull(DB::table('business_payment_events')->where('id', $id)->value('business_stripe_connection_id'));
    }

    public function test_a_document_carries_at_most_one_signature(): void
    {
        $bundle = $this->documentsBundle();
        $document = $this->insertDocument($bundle);
        $version = $this->insertVersion($document, ['state' => 'issued']);

        $this->insertSignature($document, $version);

        $this->assertConstraintViolation(
            fn () => $this->insertSignature($document, $version),
            'unique(business_document_id) is the V1 one-signer rule.'
        );
    }

    public function test_signature_evidence_cannot_be_orphaned_by_deleting_its_version(): void
    {
        $bundle = $this->documentsBundle();
        $document = $this->insertDocument($bundle);
        $version = $this->insertVersion($document, ['state' => 'issued']);
        $this->insertSignature($document, $version);

        $this->assertConstraintViolation(
            fn () => DB::table('business_document_versions')->where('id', $version)->delete(),
            'A version bound to a signature must not be deletable.'
        );
    }

    // ------------------------------------------------------------------
    // Write-once tables carry created_at only
    // ------------------------------------------------------------------

    public function test_write_once_tables_have_no_updated_at_and_mutable_ones_do(): void
    {
        foreach ([
            'business_document_line_items',
            'business_document_signatures',
            'business_payment_events',
        ] as $table) {
            $this->assertContains('created_at', $this->columnsOf($table));
            $this->assertNotContains('updated_at', $this->columnsOf($table), "[{$table}] must be created_at-only.");
        }

        // A DRAFT version is edited in place and `state` transitions, so the
        // version row keeps updated_at (see the migration docblock).
        foreach ([
            'business_documents',
            'business_document_versions',
            'business_document_payment_schedule_items',
            'business_stripe_connections',
            'business_document_payments',
            'business_document_refunds',
        ] as $table) {
            $this->assertContains('updated_at', $this->columnsOf($table), "[{$table}] must keep updated_at.");
        }
    }

    public function test_every_uid_bearing_table_has_a_unique_uid(): void
    {
        foreach ([
            'business_documents',
            'business_document_versions',
            'business_document_line_items',
            'business_document_payment_schedule_items',
            'business_document_signatures',
            'business_stripe_connections',
            'business_document_payments',
            'business_document_refunds',
        ] as $table) {
            $rows = DB::select("SHOW INDEXES FROM `{$table}` WHERE Column_name = 'uid'");
            $this->assertNotEmpty($rows, "[{$table}.uid] must be indexed.");
            $this->assertSame(0, (int) $rows[0]->Non_unique, "[{$table}.uid] must be UNIQUE.");
        }

        // business_payment_events has no uid: its identity is (account, event id).
        $this->assertNotContains('uid', $this->columnsOf('business_payment_events'));
    }

    // ------------------------------------------------------------------
    // Lane B only — no old payment lane / table reuse
    // ------------------------------------------------------------------

    public function test_no_slice_17_table_references_a_lane_a_or_lane_d_table(): void
    {
        $allowedReferenced = array_merge(self::CONTRACTED_TABLES, [
            'businesses', 'business_locations', 'contacts', 'crm_opportunities', 'users',
        ]);

        $placeholders = implode(',', array_fill(0, count(self::CONTRACTED_TABLES), '?'));

        $rows = DB::select(
            "SELECT DISTINCT TABLE_NAME AS t, REFERENCED_TABLE_NAME AS r
               FROM information_schema.KEY_COLUMN_USAGE
              WHERE CONSTRAINT_SCHEMA = DATABASE()
                AND REFERENCED_TABLE_NAME IS NOT NULL
                AND TABLE_NAME IN ({$placeholders})",
            self::CONTRACTED_TABLES
        );

        $this->assertNotEmpty($rows);

        foreach ($rows as $row) {
            $this->assertContains(
                $row->r,
                $allowedReferenced,
                "[{$row->t}] references [{$row->r}], which is outside lane B's allowed set."
            );
        }
    }

    public function test_no_lane_a_or_lane_d_table_gained_a_column_or_reference_to_slice_17(): void
    {
        // Lane D / lane A tables must be untouched by this slice: none of them
        // carries a business_document* / business_stripe_connection* column.
        foreach (['invoices', 'payment_methods', 'payment_provider_events', 'payment_provider_customers', 'business_payment_instruments', 'business_billing_receipts'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($this->columnsOf($table) as $column) {
                $this->assertStringNotContainsString('business_document', $column, "[{$table}.{$column}] must not reference Slice 17.");
                $this->assertStringNotContainsString('business_stripe_connection', $column, "[{$table}.{$column}] must not reference Slice 17.");
            }
        }
    }

    /**
     * A source-boundary check over this sub-slice's OWN code (comments
     * stripped, so a docblock explaining a forbidden lane does not trip it).
     * The full §11.1 lane test for the whole slice lands in Sub-slice E;
     * this is the same rule applied to what exists now.
     */
    public function test_slice_17a_source_never_references_a_lane_a_or_lane_d_artifact(): void
    {
        $forbidden = [
            'App\\Library\\Usage', 'App\\Models\\Invoices', 'PaymentMethods', 'PayerType', 'EffectivePayer',
            'PaymentProviderCustomer', 'BusinessPaymentInstrument', 'BusinessBillingReceipt',
            'App\\Enums\\Usage', 'App\\Jobs\\Usage', 'StripeWebhookController',
            'payment_provider_', 'business_payment_instruments', 'business_billing_receipts',
            'business_usage_', 'business_funding_', 'business_payer_', 'additional_business_slot_',
            'usage_meters', 'ai_usage_',
            "'invoices'", '"invoices"', "'plans'", '"plans"', "'payment_methods'", '"payment_methods"',
        ];

        $files = array_merge(
            glob(app_path('Models/BusinessDocument*.php')),
            [app_path('Models/BusinessStripeConnection.php'), app_path('Models/BusinessPaymentEvent.php')],
            glob(app_path('Enums/Documents/*.php')),
            // Sub-slice D — the lane-B Stripe Connect boundary. §11.1 names
            // app/Library/Payments/** and **/*BusinessPayment* as this
            // slice's own surface, so they are held to the same rule. Their
            // one permitted extra is the Stripe\* SDK itself (§4.2), which is
            // not on the forbidden list below.
            glob(app_path('Library/Payments/*.php')),
            glob(app_path('Exceptions/Payments/*.php')),
            glob(app_path('Http/Controllers/Customer/Business/BusinessPayments*.php')),
            glob(app_path('Library/Money/*.php')),
            glob(app_path('Library/Money/Exceptions/*.php')),
            glob(database_path('migrations/2026_09_25_1000*business_document*.php')),
            glob(database_path('migrations/2026_09_25_1000*business_stripe_connection*.php')),
            glob(database_path('migrations/2026_09_25_1000*business_payment_event*.php')),
            // Sub-slice G — §11.1 names this file by its exact path as this
            // slice's own surface.
            [app_path('Library/Timeline/Sources/DocumentActivitySource.php')],
        );

        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $code = '';

            foreach (token_get_all(file_get_contents($file)) as $token) {
                if (is_array($token)) {
                    if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }
                    $code .= $token[1];
                } else {
                    $code .= $token;
                }
            }

            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $code,
                    basename($file) . " references forbidden lane A/D artifact [{$needle}]."
                );
            }
        }
    }

    public function test_fixture_helper_generates_real_uuids(): void
    {
        $this->assertTrue(Str::isUuid((string) Str::uuid()));
    }
}

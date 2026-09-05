<?php

namespace Tests\Feature\Business;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Business Data Tenancy Foundation, Pass 1 — schema proof for the 11
 * authorized tables: each carries a nullable business_id, a foreign key to
 * businesses.id with a RESTRICT delete rule, and an index on business_id.
 * No NOT NULL enforcement in this pass — see
 * database/migrations/2026_09_05_120001_add_nullable_business_id_to_tenancy_tables.php.
 */
class BusinessDataTenancyForeignKeySchemaTest extends TestCase
{
    use RefreshDatabase;

    private const TABLES = [
        'campaigns', 'reports', 'tracking_logs', 'templates', 'phone_numbers',
        'senderid', 'customer_based_sending_servers', 'contacts', 'contact_groups',
        'blacklists', 'keywords',
    ];

    #[DataProvider('tableProvider')]
    public function test_business_id_column_is_nullable(string $table): void
    {
        $column = DB::selectOne(
            'SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, 'business_id']
        );

        $this->assertNotNull($column, "{$table}.business_id must exist.");
        $this->assertSame('YES', $column->IS_NULLABLE, "{$table}.business_id must remain nullable in Pass 1.");
    }

    #[DataProvider('tableProvider')]
    public function test_business_id_has_a_restrict_foreign_key_to_businesses(string $table): void
    {
        $fk = DB::selectOne(
            "SELECT rc.DELETE_RULE, kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME
             FROM information_schema.KEY_COLUMN_USAGE kcu
             JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
               ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
             WHERE kcu.TABLE_SCHEMA = DATABASE() AND kcu.TABLE_NAME = ? AND kcu.COLUMN_NAME = ?",
            [$table, 'business_id']
        );

        $this->assertNotNull($fk, "{$table}.business_id must have a foreign key.");
        $this->assertSame('businesses', $fk->REFERENCED_TABLE_NAME, "{$table}.business_id must reference businesses.");
        $this->assertSame('id', $fk->REFERENCED_COLUMN_NAME);
        $this->assertSame('RESTRICT', $fk->DELETE_RULE, "{$table}.business_id must use RESTRICT on delete, never CASCADE.");
    }

    #[DataProvider('tableProvider')]
    public function test_business_id_has_an_index(string $table): void
    {
        $index = DB::selectOne(
            "SELECT COUNT(*) AS count FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$table, 'business_id']
        );

        $this->assertGreaterThan(0, $index->count, "{$table}.business_id must have at least one index.");
    }

    public function test_no_legacy_owner_column_or_foreign_key_was_removed(): void
    {
        $legacyOwnerColumn = [
            'campaigns' => 'user_id',
            'reports' => 'user_id',
            'tracking_logs' => 'customer_id',
            'templates' => 'user_id',
            'phone_numbers' => 'user_id',
            'senderid' => 'user_id',
            'customer_based_sending_servers' => 'user_id',
            'contacts' => 'customer_id',
            'contact_groups' => 'customer_id',
            'blacklists' => 'user_id',
            'keywords' => 'user_id',
        ];

        foreach ($legacyOwnerColumn as $table => $column) {
            $exists = DB::selectOne(
                'SELECT COUNT(*) AS count FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, $column]
            );

            $this->assertSame(1, (int) $exists->count, "{$table}.{$column} must remain untouched.");
        }
    }

    public static function tableProvider(): array
    {
        return array_combine(self::TABLES, array_map(fn ($t) => [$t], self::TABLES));
    }
}

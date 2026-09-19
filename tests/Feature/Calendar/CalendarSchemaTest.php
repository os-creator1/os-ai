<?php

namespace Tests\Feature\Calendar;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\Calendar\Concerns\CreatesCalendarTestData;
use Tests\TestCase;

/**
 * Implementation Contract 15 §5, §7.2, §12.A — the exact schema shape of
 * Sub-slice A's ten tables.
 *
 * No application behaviour is exercised here: this sub-slice ships no
 * service, controller or route, so this file proves only what the DDL
 * itself enforces. Structural facts are read from information_schema /
 * SHOW COLUMNS rather than inferred from insert behaviour, because this
 * connection runs with `strict => false` (config/database.php) and would
 * silently coerce rather than throw.
 */
class CalendarSchemaTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCalendarTestData;

    /**
     * Contract §12.A's roster, verbatim. Ten is the number.
     */
    private const CONTRACTED_TABLES = [
        'booking_types',
        'booking_type_staff',
        'booking_type_round_robin_state',
        'staff_availability_rules',
        'staff_time_off',
        'appointments',
        'external_calendar_connections',
        'external_calendar_busy_blocks',
        'staff_booking_locks',
        'booking_contact_identity_locks',
    ];

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

    // --- roster ---

    public function test_all_ten_contracted_tables_exist(): void
    {
        foreach (self::CONTRACTED_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "Contracted table [{$table}] is missing.");
        }

        $this->assertCount(10, self::CONTRACTED_TABLES);
    }

    /**
     * Contract §5.4, §10, §15 — V1 ships no durable Appointment history, by
     * decision. This asserts the absence so a later slice cannot add one
     * without also changing the contract.
     */
    public function test_no_appointment_transitions_table_exists(): void
    {
        $this->assertFalse(Schema::hasTable('appointment_transitions'));
        $this->assertFalse(Schema::hasTable('appointment_history'));
    }

    // --- booking_types ---

    public function test_booking_types_has_the_contracted_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('booking_types', [
            'id', 'uid', 'public_booking_uuid', 'business_location_id', 'name',
            'description', 'duration_minutes', 'color', 'is_active',
            'created_by_user_id', 'created_at', 'updated_at',
        ]));
    }

    /**
     * §5.1 — the public address is a SEPARATE column from `uid`, is NOT
     * NULL, and is unique. Both are uuid-typed, and neither may be dropped
     * in favour of the other.
     */
    public function test_public_booking_uuid_is_a_not_null_unique_uuid_column_distinct_from_uid(): void
    {
        $uidColumn = $this->columnRow('booking_types', 'uid');
        $publicColumn = $this->columnRow('booking_types', 'public_booking_uuid');

        $this->assertSame('char(36)', strtolower($publicColumn->Type));
        $this->assertSame('NO', $publicColumn->Null, 'public_booking_uuid must be NOT NULL.');
        $this->assertSame('char(36)', strtolower($uidColumn->Type));

        $this->assertUniqueIndex('booking_types', 'booking_types_public_booking_uuid_unique', ['public_booking_uuid']);
        $this->assertUniqueIndex('booking_types', 'booking_types_uid_unique', ['uid']);
    }

    public function test_booking_types_public_booking_uuid_rejects_a_duplicate(): void
    {
        $business = $this->calendarBusiness();
        $location = $this->calendarLocation($business);
        $shared = (string) Str::uuid();

        $this->insertBookingType($location, ['public_booking_uuid' => $shared]);

        $this->expectException(QueryException::class);
        $this->insertBookingType($location, ['public_booking_uuid' => $shared]);
    }

    public function test_booking_types_location_restricts_deletion(): void
    {
        $this->assertDeleteRule('booking_types', 'business_location_id', 'RESTRICT');
        $this->assertDeleteRule('booking_types', 'created_by_user_id', 'SET NULL');
    }

    // --- booking_type_staff ---

    public function test_booking_type_staff_is_unique_per_pair_and_cascades_on_both_parents(): void
    {
        $this->assertUniqueIndex(
            'booking_type_staff',
            'booking_type_staff_type_user_unique',
            ['booking_type_id', 'staff_user_id']
        );

        $this->assertDeleteRule('booking_type_staff', 'booking_type_id', 'CASCADE');
        $this->assertDeleteRule('booking_type_staff', 'staff_user_id', 'CASCADE');
    }

    /**
     * §5.1 — configuration intent carries no audit value, so a deleted
     * staff User must not remain attached AND must not be blocked from
     * deletion by the nomination.
     */
    public function test_deleting_a_staff_user_cascades_the_booking_type_staff_row_away(): void
    {
        $business = $this->calendarBusiness();
        $location = $this->calendarLocation($business);
        $bookingTypeId = $this->insertBookingType($location);
        $staff = $this->staffUser();

        DB::table('booking_type_staff')->insert([
            'booking_type_id' => $bookingTypeId,
            'staff_user_id' => $staff->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->where('id', $staff->id)->delete();

        $this->assertDatabaseMissing('booking_type_staff', ['staff_user_id' => $staff->id]);
        $this->assertDatabaseHas('booking_types', ['id' => $bookingTypeId]);
    }

    // --- booking_type_round_robin_state ---

    public function test_round_robin_state_is_one_row_per_booking_type(): void
    {
        $this->assertUniqueIndex(
            'booking_type_round_robin_state',
            'btrrs_booking_type_unique',
            ['booking_type_id']
        );

        $this->assertDeleteRule('booking_type_round_robin_state', 'booking_type_id', 'CASCADE');
        $this->assertDeleteRule('booking_type_round_robin_state', 'last_assigned_staff_user_id', 'SET NULL');
    }

    /**
     * §5.1.1 — a null cursor is a DEFINED state (rotation starts at the
     * lowest eligible id), so deleting the last-assigned User must null the
     * cursor rather than block or cascade.
     */
    public function test_deleting_the_last_assigned_staff_user_nulls_the_cursor(): void
    {
        $business = $this->calendarBusiness();
        $location = $this->calendarLocation($business);
        $bookingTypeId = $this->insertBookingType($location);
        $staff = $this->staffUser();

        $stateId = DB::table('booking_type_round_robin_state')->insertGetId([
            'booking_type_id' => $bookingTypeId,
            'last_assigned_staff_user_id' => $staff->id,
            'last_assigned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->where('id', $staff->id)->delete();

        $this->assertDatabaseHas('booking_type_round_robin_state', [
            'id' => $stateId,
            'last_assigned_staff_user_id' => null,
        ]);
    }

    public function test_round_robin_state_rejects_a_second_row_for_one_booking_type(): void
    {
        $business = $this->calendarBusiness();
        $location = $this->calendarLocation($business);
        $bookingTypeId = $this->insertBookingType($location);

        DB::table('booking_type_round_robin_state')->insert([
            'booking_type_id' => $bookingTypeId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('booking_type_round_robin_state')->insert([
            'booking_type_id' => $bookingTypeId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // --- staff_availability_rules / staff_time_off ---

    public function test_staff_availability_rules_restricts_both_parents(): void
    {
        $this->assertTrue(Schema::hasColumns('staff_availability_rules', [
            'id', 'business_location_id', 'staff_user_id', 'day_of_week',
            'start_time', 'end_time', 'created_at', 'updated_at',
        ]));

        $this->assertDeleteRule('staff_availability_rules', 'business_location_id', 'RESTRICT');
        $this->assertDeleteRule('staff_availability_rules', 'staff_user_id', 'RESTRICT');
    }

    public function test_staff_availability_day_of_week_is_an_unsigned_tinyint(): void
    {
        $this->assertStringContainsStringIgnoringCase(
            'tinyint',
            $this->columnRow('staff_availability_rules', 'day_of_week')->Type
        );
        $this->assertStringContainsStringIgnoringCase(
            'unsigned',
            $this->columnRow('staff_availability_rules', 'day_of_week')->Type
        );
    }

    /**
     * §5.2 — split shifts are legitimate, so there must be NO unique
     * constraint on (staff, Location, day).
     */
    public function test_staff_availability_rules_allow_split_shifts_on_one_day(): void
    {
        $business = $this->calendarBusiness();
        $location = $this->calendarLocation($business);
        $staff = $this->staffUser();

        foreach ([['09:00:00', '12:00:00'], ['13:00:00', '17:00:00']] as [$start, $end]) {
            DB::table('staff_availability_rules')->insert([
                'business_location_id' => $location->id,
                'staff_user_id' => $staff->id,
                'day_of_week' => 1,
                'start_time' => $start,
                'end_time' => $end,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->assertSame(2, DB::table('staff_availability_rules')->where('staff_user_id', $staff->id)->count());
    }

    /**
     * §5.3 — time off is deliberately User-global. The ABSENCE of a
     * Location column is the design, and §14's acceptance wording depends
     * on it, so it is asserted rather than assumed.
     */
    public function test_staff_time_off_is_user_global_with_no_location_column(): void
    {
        $this->assertTrue(Schema::hasColumns('staff_time_off', [
            'id', 'staff_user_id', 'start_at', 'end_at', 'reason',
            'created_by_user_id', 'created_at', 'updated_at',
        ]));

        $this->assertFalse(Schema::hasColumn('staff_time_off', 'business_location_id'));
        $this->assertFalse(Schema::hasColumn('staff_time_off', 'location_id'));

        $this->assertDeleteRule('staff_time_off', 'staff_user_id', 'RESTRICT');
        $this->assertDeleteRule('staff_time_off', 'created_by_user_id', 'SET NULL');
    }

    // --- appointments ---

    public function test_appointments_has_the_contracted_columns_and_no_provider_or_timezone_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('appointments', [
            'id', 'uid', 'business_location_id', 'booking_type_id', 'staff_user_id',
            'contact_id', 'crm_opportunity_id', 'created_by_user_id', 'status',
            'start_at', 'end_at', 'reschedule_count', 'resolved_at',
            'resolved_by_user_id', 'cancellation_reason', 'created_at', 'updated_at',
        ]));

        // §5.4: derived via business_location_id -> business.timezone.
        $this->assertFalse(Schema::hasColumn('appointments', 'timezone'));
        // §5.5's boundary rule: no provider-specific column ever lands here.
        $this->assertFalse(Schema::hasColumn('appointments', 'provider_event_id'));
        $this->assertFalse(Schema::hasColumn('appointments', 'external_calendar_connection_id'));
    }

    public function test_appointments_four_operational_parents_restrict_deletion(): void
    {
        $this->assertDeleteRule('appointments', 'business_location_id', 'RESTRICT');
        $this->assertDeleteRule('appointments', 'booking_type_id', 'RESTRICT');
        $this->assertDeleteRule('appointments', 'staff_user_id', 'RESTRICT');
        $this->assertDeleteRule('appointments', 'contact_id', 'RESTRICT');
    }

    public function test_appointments_actor_and_opportunity_columns_null_on_delete(): void
    {
        $this->assertDeleteRule('appointments', 'crm_opportunity_id', 'SET NULL');
        $this->assertDeleteRule('appointments', 'created_by_user_id', 'SET NULL');
        $this->assertDeleteRule('appointments', 'resolved_by_user_id', 'SET NULL');
    }

    /**
     * §7.2's stated, accepted trade: a staff member who has ever been
     * booked genuinely cannot be hard-deleted until the row is dealt with.
     * This is the deliberate opposite of the lock/pivot rows below.
     */
    public function test_a_booked_staff_user_cannot_be_deleted(): void
    {
        $business = $this->calendarBusiness();
        $location = $this->calendarLocation($business);
        $bookingTypeId = $this->insertBookingType($location);
        $staff = $this->staffUser();
        $contactId = $this->insertContact($business, $location);

        $this->insertAppointment($location, $bookingTypeId, $staff->id, $contactId);

        $this->expectException(QueryException::class);
        DB::table('users')->where('id', $staff->id)->delete();
    }

    public function test_appointments_carries_the_two_contracted_composite_indexes(): void
    {
        $staffIndex = $this->indexRows('appointments', 'appointments_staff_status_window_index');
        $this->assertSame(
            ['staff_user_id', 'status', 'start_at', 'end_at'],
            array_map(static fn ($row) => $row->Column_name, $staffIndex)
        );

        $locationIndex = $this->indexRows('appointments', 'appointments_location_window_index');
        $this->assertSame(
            ['business_location_id', 'start_at', 'end_at'],
            array_map(static fn ($row) => $row->Column_name, $locationIndex)
        );
    }

    // --- external_calendar_connections ---

    public function test_external_calendar_connections_has_the_contracted_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('external_calendar_connections', [
            'id', 'uid', 'user_id', 'provider', 'state', 'active_user_id',
            'external_account_email', 'refresh_token_encrypted', 'granted_scopes',
            'sync_cursor', 'last_synced_at', 'last_sync_failure_at',
            'sync_failure_count', 'failure_classification', 'oauth_state_nonce',
            'oauth_state_expires_at', 'connected_at', 'disconnected_at',
            'revoked_at', 'last_refreshed_at', 'lock_version',
            'created_at', 'updated_at',
        ]));
    }

    /**
     * §5.5, the security-load-bearing assertion: there is deliberately NO
     * access-token column, on this table or anywhere else this slice adds.
     */
    public function test_no_access_token_column_exists_anywhere_in_this_slice(): void
    {
        foreach (['access_token', 'access_token_encrypted', 'token'] as $forbidden) {
            $this->assertFalse(
                Schema::hasColumn('external_calendar_connections', $forbidden),
                "external_calendar_connections must never persist [{$forbidden}]."
            );
        }

        foreach (self::CONTRACTED_TABLES as $table) {
            $this->assertFalse(
                Schema::hasColumn($table, 'access_token'),
                "Table [{$table}] must never carry an access_token column."
            );
        }
    }

    /**
     * §5.5 — a row in `pending` has no refresh token yet, so the credential
     * column must be nullable.
     */
    public function test_refresh_token_column_is_nullable_text(): void
    {
        $column = $this->columnRow('external_calendar_connections', 'refresh_token_encrypted');

        $this->assertSame('YES', $column->Null, 'refresh_token_encrypted must be nullable for the pending state.');
        $this->assertStringContainsStringIgnoringCase('text', $column->Type);
    }

    public function test_a_pending_connection_may_be_stored_with_no_credential_at_all(): void
    {
        $staff = $this->staffUser();

        $connectionId = $this->insertConnection($staff->id, [
            'state' => 'pending',
            'refresh_token_encrypted' => null,
            'granted_scopes' => null,
            'connected_at' => null,
        ]);

        $this->assertDatabaseHas('external_calendar_connections', [
            'id' => $connectionId,
            'state' => 'pending',
            'refresh_token_encrypted' => null,
        ]);
    }

    /**
     * §5.5 — `active_user_id` is a GENERATED column, computed by MySQL from
     * `state` and `user_id`, never writable by the application. Asserted
     * structurally: the contract's whole one-connection guarantee rests on
     * it being generated rather than maintained by code.
     */
    public function test_active_user_id_is_a_generated_column_over_state_and_user_id(): void
    {
        $rows = DB::select(
            "SELECT EXTRA AS extra, GENERATION_EXPRESSION AS expression
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'external_calendar_connections'
                AND COLUMN_NAME = 'active_user_id'"
        );

        $this->assertNotEmpty($rows, 'active_user_id column is missing.');
        $this->assertStringContainsStringIgnoringCase('GENERATED', $rows[0]->extra);

        $expression = strtolower((string) $rows[0]->expression);
        $this->assertStringContainsString('pending', $expression);
        $this->assertStringContainsString('active', $expression);
        $this->assertStringContainsString('user_id', $expression);

        $this->assertUniqueIndex('external_calendar_connections', 'ecc_active_user_unique', ['active_user_id']);
    }

    /**
     * §5.5 — exactly ONE pending-or-active connection per User, in total,
     * not one per provider. The database is the authority, not application
     * discipline.
     */
    public function test_a_user_cannot_hold_two_live_connections_even_across_providers(): void
    {
        $staff = $this->staffUser();
        $this->insertConnection($staff->id, ['provider' => 'google', 'state' => 'active']);

        $this->expectException(QueryException::class);
        $this->insertConnection($staff->id, ['provider' => 'outlook', 'state' => 'pending']);
    }

    /**
     * §5.5's pending lifecycle — a terminal row releases the slot, so
     * reconnecting after a disconnect, a revocation or an abandoned attempt
     * is always possible, and terminal history rows are unlimited.
     */
    public function test_terminal_rows_do_not_occupy_the_connection_slot(): void
    {
        $staff = $this->staffUser();

        $this->insertConnection($staff->id, ['state' => 'disconnected', 'disconnected_at' => now()]);
        $this->insertConnection($staff->id, ['state' => 'revoked', 'revoked_at' => now()]);
        $this->insertConnection($staff->id, ['state' => 'disconnected', 'disconnected_at' => now()]);

        // The slot is free, so a live connection is still accepted.
        $liveId = $this->insertConnection($staff->id, ['state' => 'active']);

        $this->assertDatabaseHas('external_calendar_connections', ['id' => $liveId, 'state' => 'active']);
        $this->assertSame(4, DB::table('external_calendar_connections')->where('user_id', $staff->id)->count());

        $occupying = DB::table('external_calendar_connections')
            ->where('user_id', $staff->id)
            ->whereNotNull('active_user_id')
            ->count();

        $this->assertSame(1, $occupying, 'Exactly one row may occupy the connection slot.');
    }

    /**
     * §5.5 — an in-flight connect genuinely holds the slot, which is what
     * refuses a second simultaneous initiation.
     */
    public function test_a_pending_attempt_occupies_the_slot(): void
    {
        $staff = $this->staffUser();
        $this->insertConnection($staff->id, ['state' => 'pending']);

        $this->expectException(QueryException::class);
        $this->insertConnection($staff->id, ['state' => 'pending']);
    }

    /**
     * §5.5 — deleting a User removes their connection outright, so no
     * encrypted credential survives them and the slot is released as a
     * consequence rather than as a separate step.
     */
    public function test_deleting_a_user_cascades_their_connection_and_its_busy_blocks_away(): void
    {
        $staff = $this->staffUser();
        $connectionId = $this->insertConnection($staff->id, ['state' => 'active']);

        DB::table('external_calendar_busy_blocks')->insert([
            'external_calendar_connection_id' => $connectionId,
            'provider_event_id' => 'evt-' . uniqid(),
            'start_at' => now(),
            'end_at' => now()->addHour(),
            'synced_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->where('id', $staff->id)->delete();

        $this->assertDatabaseMissing('external_calendar_connections', ['id' => $connectionId]);
        $this->assertDatabaseMissing('external_calendar_busy_blocks', [
            'external_calendar_connection_id' => $connectionId,
        ]);
    }

    public function test_oauth_state_nonce_is_unique(): void
    {
        $this->assertUniqueIndex(
            'external_calendar_connections',
            'ecc_oauth_state_nonce_unique',
            ['oauth_state_nonce']
        );
    }

    // --- external_calendar_busy_blocks ---

    public function test_busy_blocks_idempotency_key_is_unique_and_cascades(): void
    {
        $this->assertUniqueIndex(
            'external_calendar_busy_blocks',
            'ecbb_connection_provider_event_unique',
            ['external_calendar_connection_id', 'provider_event_id']
        );

        $this->assertDeleteRule(
            'external_calendar_busy_blocks',
            'external_calendar_connection_id',
            'CASCADE'
        );
    }

    /**
     * §5.6 — the unique key IS the idempotency mechanism: processing the
     * same provider notification twice must be impossible to duplicate.
     */
    public function test_the_same_provider_event_cannot_be_stored_twice_for_one_connection(): void
    {
        $staff = $this->staffUser();
        $connectionId = $this->insertConnection($staff->id, ['state' => 'active']);

        $row = [
            'external_calendar_connection_id' => $connectionId,
            'provider_event_id' => 'evt-duplicate',
            'start_at' => now(),
            'end_at' => now()->addHour(),
            'synced_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('external_calendar_busy_blocks')->insert($row);

        $this->expectException(QueryException::class);
        DB::table('external_calendar_busy_blocks')->insert($row);
    }

    // --- staff_booking_locks ---

    /**
     * §7.2 — staff_user_id IS the primary key, which is exactly what makes
     * the contracted `insertOrIgnore` ensure step idempotent. Without a real
     * key, INSERT IGNORE would suppress nothing.
     */
    public function test_staff_booking_locks_is_keyed_on_staff_user_id(): void
    {
        $this->assertTrue(Schema::hasColumns('staff_booking_locks', ['staff_user_id', 'created_at', 'updated_at']));
        $this->assertFalse(Schema::hasColumn('staff_booking_locks', 'id'));

        $this->assertSame('PRI', $this->columnRow('staff_booking_locks', 'staff_user_id')->Key);
        $this->assertDeleteRule('staff_booking_locks', 'staff_user_id', 'CASCADE');
    }

    public function test_insert_or_ignore_on_the_staff_lock_row_is_genuinely_idempotent(): void
    {
        $staff = $this->staffUser();
        $row = ['staff_user_id' => $staff->id, 'created_at' => now(), 'updated_at' => now()];

        DB::table('staff_booking_locks')->insertOrIgnore([$row]);
        DB::table('staff_booking_locks')->insertOrIgnore([$row]);

        $this->assertSame(1, DB::table('staff_booking_locks')->where('staff_user_id', $staff->id)->count());
    }

    /**
     * §7.2's whole point: a pure serialization row must NEVER be the reason
     * a User cannot be deleted.
     */
    public function test_a_staff_lock_row_never_blocks_deleting_the_user(): void
    {
        $staff = $this->staffUser();

        DB::table('staff_booking_locks')->insert([
            'staff_user_id' => $staff->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->where('id', $staff->id)->delete();

        $this->assertDatabaseMissing('staff_booking_locks', ['staff_user_id' => $staff->id]);
        $this->assertDatabaseMissing('users', ['id' => $staff->id]);
    }

    // --- booking_contact_identity_locks ---

    public function test_contact_identity_lock_is_unique_per_location_and_phone(): void
    {
        $this->assertTrue(Schema::hasColumns('booking_contact_identity_locks', [
            'id', 'business_location_id', 'normalized_phone', 'created_at', 'updated_at',
        ]));

        // §5.8.3: no contact_id — a second pointer would be a second source
        // of truth that can drift from `contacts`.
        $this->assertFalse(Schema::hasColumn('booking_contact_identity_locks', 'contact_id'));

        $this->assertUniqueIndex(
            'booking_contact_identity_locks',
            'bcil_location_normalized_phone_unique',
            ['business_location_id', 'normalized_phone']
        );

        $this->assertDeleteRule('booking_contact_identity_locks', 'business_location_id', 'CASCADE');
    }

    /**
     * §5.8.3 — the unique key is what makes §5.8.4's ensure-then-lock
     * sequence idempotent, and the same phone at a DIFFERENT Location is a
     * separate identity (Addendum §5).
     */
    public function test_identity_lock_is_location_local_and_idempotent(): void
    {
        $business = $this->calendarBusiness();
        $first = $this->calendarLocation($business);
        $second = $this->calendarLocation($business);

        $row = static fn (int $locationId): array => [
            'business_location_id' => $locationId,
            'normalized_phone' => '14155551234',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('booking_contact_identity_locks')->insertOrIgnore([$row($first->id)]);
        DB::table('booking_contact_identity_locks')->insertOrIgnore([$row($first->id)]);

        // Same phone, different Location: a separate identity, not a clash.
        DB::table('booking_contact_identity_locks')->insertOrIgnore([$row($second->id)]);

        $this->assertSame(1, DB::table('booking_contact_identity_locks')
            ->where('business_location_id', $first->id)->count());
        $this->assertSame(2, DB::table('booking_contact_identity_locks')
            ->whereIn('business_location_id', [$first->id, $second->id])->count());
    }
}

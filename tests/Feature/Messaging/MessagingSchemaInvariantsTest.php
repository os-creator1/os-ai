<?php

namespace Tests\Feature\Messaging;

use App\Enums\Messaging\BusinessMessagingIdentityStatus;
use App\Enums\Messaging\BusinessMessagingNumberStatus;
use App\Enums\Messaging\MessagingProvider;
use App\Library\Messaging\BusinessMessagingIdentityResolver;
use App\Library\Messaging\Exceptions\MessagingIdentityConflictException;
use App\Models\Business;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

/**
 * Customer Experience Slice 3 §4.2 — T-MSG-1, 2, 5, 6, 7, 14, 39, 43, 44,
 * 45, 46, 63, 64.
 *
 * Every uniqueness assertion here is made with a RAW DB::table() insert or
 * update, deliberately bypassing every application layer, because the
 * contract's claim is that MySQL itself rejects these rows — the resolver's
 * pre-checks are a courtesy, not the mechanism. If a test here passed only
 * because application code intervened, the claim would be untested.
 *
 * These run against the repository's real configured MySQL connection, not a
 * driver-agnostic in-memory substitute, since generated columns and MySQL's
 * NULL-tolerant unique-index semantics are exactly what is being proven.
 */
class MessagingSchemaInvariantsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    /**
     * Customer Experience Slice 3's five migrations plus Lane E's one,
     * named exactly rather than located by position.
     *
     * WHY THIS EXISTS (pre-merge correction to PR #244). This test used to
     * roll back "the last 6 migrations" by `--step`, which is only correct
     * while these six happen to be the newest migrations on the tree. The
     * Legacy Provider Webhook Measurement contract's S0 slice legitimately
     * adds a migration after them (per that contract's own §3.4 timestamp
     * rule: always strictly after whatever is currently latest), which
     * immediately broke the position-based assumption — rolling back "the
     * last 6" then reached only 5 of these six and one unrelated migration
     * from a different slice instead.
     *
     * Naming the exact files here, and rolling back by `--path` instead of
     * `--step` (see test_the_slice_three_schema_survives_forward_rollback_and_replay()),
     * means this test keeps exercising precisely Slice 3 + Lane E's own
     * migrations no matter how many further migrations land after them —
     * this slice's, or any later one's.
     */
    private const SLICE_THREE_AND_LANE_E_MIGRATIONS = [
        'database/migrations/2026_09_12_100001_create_business_messaging_identities_table.php',
        'database/migrations/2026_09_12_100002_create_business_messaging_numbers_table.php',
        'database/migrations/2026_09_12_100003_create_business_messaging_operations_table.php',
        'database/migrations/2026_09_12_100004_create_business_usage_measurements_table.php',
        'database/migrations/2026_09_12_100005_create_messaging_webhook_rejections_table.php',
        'database/migrations/2026_09_12_100006_complete_legacy_ai_messaging_schema.php',
    ];

    private function business(): Business
    {
        return $this->createBusinessWithWorkspace($this->createCustomer(), $this->businessAttributes());
    }

    /**
     * @return array{0: int, 1: Business}
     */
    private function identityRow(Business $business, string $status = 'active', ?string $profileId = null): array
    {
        $id = DB::table('business_messaging_identities')->insertGetId([
            'uid' => (string) Str::uuid(),
            'business_id' => $business->id,
            'provider' => MessagingProvider::Telnyx->value,
            'status' => $status,
            'messaging_profile_id' => $profileId ?? ('mp_' . Str::random(12)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$id, $business];
    }

    // ---------------------------------------------------------------
    // T-MSG-5 — schema shape
    // ---------------------------------------------------------------

    public function test_all_four_tables_exist_with_their_generated_guard_columns(): void
    {
        foreach ([
            'business_messaging_identities',
            'business_messaging_numbers',
            'business_messaging_operations',
            'business_usage_measurements',
            'messaging_webhook_rejections',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table [{$table}].");
        }

        $this->assertTrue(Schema::hasColumn('business_messaging_identities', 'active_or_pending_business_id'));
        $this->assertTrue(Schema::hasColumn('business_messaging_numbers', 'active_or_pending_phone_number'));
        $this->assertTrue(Schema::hasColumn('business_messaging_numbers', 'active_primary_identity_id'));

        // The guard columns must be STORED generated, not ordinary columns —
        // an ordinary column would not recompute on an archival UPDATE.
        $generated = DB::table('information_schema.COLUMNS')
            ->select('TABLE_NAME', 'COLUMN_NAME')
            ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
            ->where('EXTRA', 'STORED GENERATED')
            ->whereIn('TABLE_NAME', ['business_messaging_identities', 'business_messaging_numbers'])
            ->get()
            ->map(fn ($r) => $r->TABLE_NAME . '.' . $r->COLUMN_NAME)
            ->sort()
            ->values()
            ->all();

        $this->assertSame([
            'business_messaging_identities.active_or_pending_business_id',
            'business_messaging_numbers.active_or_pending_phone_number',
            'business_messaging_numbers.active_primary_identity_id',
        ], $generated);
    }

    public function test_no_identity_or_number_column_can_hold_a_credential(): void
    {
        // T-MSG-4 at the schema level: there is no column a credential could
        // even be written into, and no Managed-Account-shaped identifier.
        foreach (['business_messaging_identities', 'business_messaging_numbers'] as $table) {
            foreach (Schema::getColumnListing($table) as $column) {
                $this->assertDoesNotMatchRegularExpression(
                    '/(secret|token|api_key|password|credential|auth|private_key|managed_account)/i',
                    $column,
                    "Column [{$table}.{$column}] is credential-shaped.",
                );
            }
        }
    }

    // ---------------------------------------------------------------
    // T-MSG-1 / T-MSG-39 — one active-or-pending identity per Business
    // ---------------------------------------------------------------

    public function test_a_second_active_identity_for_one_business_is_rejected_by_mysql(): void
    {
        $business = $this->business();
        $this->identityRow($business, 'active');

        $this->expectException(QueryException::class);
        $this->identityRow($business, 'active');
    }

    public function test_pending_and_active_conflict_in_both_directions(): void
    {
        $businessA = $this->business();
        $this->identityRow($businessA, 'active');

        $conflicted = false;
        try {
            $this->identityRow($businessA, 'pending');
        } catch (QueryException) {
            $conflicted = true;
        }
        $this->assertTrue($conflicted, 'A pending row must conflict with an existing active row.');

        // …and the reverse ordering conflicts identically.
        $businessB = $this->business();
        $this->identityRow($businessB, 'pending');

        $reverseConflicted = false;
        try {
            $this->identityRow($businessB, 'active');
        } catch (QueryException) {
            $reverseConflicted = true;
        }
        $this->assertTrue($reverseConflicted, 'An active row must conflict with an existing pending row.');
    }

    public function test_suspended_and_archived_rows_never_collide(): void
    {
        $business = $this->business();

        // Any number of historical rows may accumulate, because their guard
        // column is NULL and MySQL permits unlimited NULLs.
        $this->identityRow($business, 'archived');
        $this->identityRow($business, 'archived');
        $this->identityRow($business, 'suspended');
        $this->identityRow($business, 'active');

        $this->assertSame(4, DB::table('business_messaging_identities')->where('business_id', $business->id)->count());
    }

    // ---------------------------------------------------------------
    // T-MSG-45 / T-MSG-46 — archival, replacement, reactivation
    // ---------------------------------------------------------------

    public function test_archiving_frees_the_slot_and_a_replacement_succeeds(): void
    {
        $business = $this->business();
        [$identityA] = $this->identityRow($business, 'active');

        DB::table('business_messaging_identities')->where('id', $identityA)
            ->update(['status' => 'archived', 'archived_at' => now()]);

        [$identityB] = $this->identityRow($business, 'pending');

        $this->assertNotSame($identityA, $identityB);
        // A's row is neither deleted nor altered beyond its own status.
        $rowA = DB::table('business_messaging_identities')->where('id', $identityA)->first();
        $this->assertSame('archived', $rowA->status);
        $this->assertNull($rowA->active_or_pending_business_id);
    }

    public function test_reactivation_re_runs_the_same_invariant(): void
    {
        $business = $this->business();
        [$identityA] = $this->identityRow($business, 'active');
        DB::table('business_messaging_identities')->where('id', $identityA)->update(['status' => 'archived']);
        [$identityB] = $this->identityRow($business, 'active');

        // Reactivating A while B is active must be rejected by the very same
        // index that would have blocked a fresh creation.
        $blocked = false;
        try {
            DB::table('business_messaging_identities')->where('id', $identityA)->update(['status' => 'active']);
        } catch (QueryException) {
            $blocked = true;
        }
        $this->assertTrue($blocked, 'Reactivation must re-run the uniqueness invariant.');

        // With B archived first, the identical reactivation succeeds.
        DB::table('business_messaging_identities')->where('id', $identityB)->update(['status' => 'archived']);
        DB::table('business_messaging_identities')->where('id', $identityA)->update(['status' => 'active']);

        $this->assertSame('active', DB::table('business_messaging_identities')->where('id', $identityA)->value('status'));
    }

    // ---------------------------------------------------------------
    // T-MSG-6 / T-MSG-7 / T-MSG-43 — number ownership
    // ---------------------------------------------------------------

    public function test_one_business_may_hold_several_active_numbers(): void
    {
        [$identityId] = $this->identityRow($this->business(), 'active');

        foreach (['+14155550001', '+14155550002', '+14155550003'] as $number) {
            DB::table('business_messaging_numbers')->insert([
                'business_messaging_identity_id' => $identityId,
                'phone_number' => $number,
                'status' => BusinessMessagingNumberStatus::Active->value,
                'is_primary' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->assertSame(3, DB::table('business_messaging_numbers')
            ->where('business_messaging_identity_id', $identityId)->count());
    }

    public function test_one_number_cannot_belong_to_two_businesses(): void
    {
        [$identityA] = $this->identityRow($this->business(), 'active');
        [$identityB] = $this->identityRow($this->business(), 'active');

        DB::table('business_messaging_numbers')->insert([
            'business_messaging_identity_id' => $identityA,
            'phone_number' => '+14155559999',
            'status' => 'active',
            'is_primary' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // A different Business's identity claiming the same E.164 value is
        // rejected by MySQL, not by application code.
        $this->expectException(QueryException::class);
        DB::table('business_messaging_numbers')->insert([
            'business_messaging_identity_id' => $identityB,
            'phone_number' => '+14155559999',
            'status' => 'pending',
            'is_primary' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_a_released_number_frees_its_claim_without_being_deleted(): void
    {
        [$identityA] = $this->identityRow($this->business(), 'active');
        [$identityB] = $this->identityRow($this->business(), 'active');

        $releasedId = DB::table('business_messaging_numbers')->insertGetId([
            'business_messaging_identity_id' => $identityA,
            'phone_number' => '+14155558888',
            'status' => 'active',
            'is_primary' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('business_messaging_numbers')->where('id', $releasedId)
            ->update(['status' => 'released', 'released_at' => now()]);

        DB::table('business_messaging_numbers')->insert([
            'business_messaging_identity_id' => $identityB,
            'phone_number' => '+14155558888',
            'status' => 'active',
            'is_primary' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // The released row is retained, never deleted to "free" the index.
        $this->assertDatabaseHas('business_messaging_numbers', ['id' => $releasedId, 'status' => 'released']);
        $this->assertSame(2, DB::table('business_messaging_numbers')->where('phone_number', '+14155558888')->count());
    }

    // ---------------------------------------------------------------
    // T-MSG-14 / T-MSG-44 — at most one active primary per identity
    // ---------------------------------------------------------------

    public function test_a_second_active_primary_number_is_rejected_by_mysql(): void
    {
        [$identityId] = $this->identityRow($this->business(), 'active');

        DB::table('business_messaging_numbers')->insert([
            'business_messaging_identity_id' => $identityId,
            'phone_number' => '+14155557001',
            'status' => 'active',
            'is_primary' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $secondId = DB::table('business_messaging_numbers')->insertGetId([
            'business_messaging_identity_id' => $identityId,
            'phone_number' => '+14155557002',
            'status' => 'active',
            'is_primary' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('business_messaging_numbers')->where('id', $secondId)->update(['is_primary' => true]);
    }

    // ---------------------------------------------------------------
    // T-MSG-63 / T-MSG-64 — ordinary NULL-tolerant unique indexes
    // ---------------------------------------------------------------

    public function test_operation_key_is_unique_but_null_tolerant(): void
    {
        $business = $this->business();

        // Two inbound-shaped rows both leaving operation_key NULL coexist.
        foreach (['pm_a', 'pm_b'] as $providerMessageId) {
            DB::table('business_messaging_operations')->insert([
                'business_id' => $business->id,
                'transport_mode' => 'managed',
                'provider' => MessagingProvider::Telnyx->value,
                'direction' => 'inbound',
                'message_type' => 'sms',
                'operation_key' => null,
                'provider_message_id' => $providerMessageId,
                'status' => 'delivered',
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $this->assertSame(2, DB::table('business_messaging_operations')->whereNull('operation_key')->count());

        DB::table('business_messaging_operations')->insert([
            'business_id' => $business->id,
            'transport_mode' => 'managed',
            'provider' => MessagingProvider::Telnyx->value,
            'direction' => 'outbound',
            'message_type' => 'sms',
            'operation_key' => 'op_dup',
            'status' => 'attempted',
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('business_messaging_operations')->insert([
            'business_id' => $business->id,
            'transport_mode' => 'managed',
            'provider' => MessagingProvider::Telnyx->value,
            'direction' => 'outbound',
            'message_type' => 'sms',
            'operation_key' => 'op_dup',
            'status' => 'attempted',
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_provider_message_id_uniqueness_is_composite_and_null_tolerant(): void
    {
        $business = $this->business();

        $insert = function (?string $providerMessageId, string $provider = 'telnyx') use ($business): void {
            DB::table('business_messaging_operations')->insert([
                'business_id' => $business->id,
                'transport_mode' => 'managed',
                'provider' => $provider,
                'direction' => 'outbound',
                'message_type' => 'sms',
                'operation_key' => 'op_' . Str::random(10),
                'provider_message_id' => $providerMessageId,
                'status' => 'attempted',
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        };

        // Several not-yet-accepted rows share provider with a NULL id.
        $insert(null);
        $insert(null);
        $insert(null);
        $this->assertSame(3, DB::table('business_messaging_operations')->whereNull('provider_message_id')->count());

        $insert('pm_shared');

        // The same id under a DIFFERENT provider does not conflict, proving
        // the index is composite rather than single-column.
        $insert('pm_shared', 'other_provider');
        $this->assertSame(2, DB::table('business_messaging_operations')->where('provider_message_id', 'pm_shared')->count());

        $this->expectException(QueryException::class);
        $insert('pm_shared');
    }

    // ---------------------------------------------------------------
    // Rejection-audit idempotency key
    // ---------------------------------------------------------------

    public function test_rejection_rows_are_unique_per_reason_provider_and_hash(): void
    {
        $row = [
            'reason' => 'unknown_mapping',
            'provider' => MessagingProvider::Telnyx->value,
            'payload_hash' => hash('sha256', 'body'),
            'occurrence_count' => 1,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'created_at' => now(),
        ];

        DB::table('messaging_webhook_rejections')->insert($row);

        // A different reason over the same body is a genuinely different
        // rejection and is allowed.
        DB::table('messaging_webhook_rejections')->insert(array_merge($row, ['reason' => 'invalid_signature']));

        $this->expectException(QueryException::class);
        DB::table('messaging_webhook_rejections')->insert($row);
    }

    public function test_the_rejection_table_cannot_attribute_or_store_a_body(): void
    {
        $columns = Schema::getColumnListing('messaging_webhook_rejections');

        // No business_id at all: a row here is by definition unattributable.
        $this->assertNotContains('business_id', $columns);

        foreach ($columns as $column) {
            $this->assertDoesNotMatchRegularExpression(
                '/(body|payload_raw|content|message|secret|token|credential)/i',
                $column,
                "Column [messaging_webhook_rejections.{$column}] could retain a body or credential.",
            );
        }
    }

    public function test_measurement_rows_are_idempotent_by_key(): void
    {
        $business = $this->business();

        $row = [
            'business_id' => $business->id,
            'feature_key' => 'messaging_transport',
            'quantity' => '1',
            'unit' => 'segment',
            'transport_marker' => 'managed',
            'idempotency_key' => 'op_measure_1',
            'occurred_at' => now(),
            'created_at' => now(),
        ];

        DB::table('business_usage_measurements')->insert($row);

        $this->expectException(QueryException::class);
        DB::table('business_usage_measurements')->insert($row);
    }

    // ---------------------------------------------------------------
    // T-MSG-2 — both uniqueness conflicts, at BOTH layers
    // ---------------------------------------------------------------

    /**
     * The contract asks for the same invariant proven twice over: through
     * the resolver, which must convert MySQL's refusal into the contracted
     * MessagingIdentityConflictException; and through a raw insert that
     * bypasses the resolver entirely, which must still be refused, by MySQL
     * itself, as an uncaught QueryException.
     *
     * The second half is the one that matters most. It is what proves the
     * guarantee lives in the database rather than in application code that a
     * future caller could forget to go through.
     */
    public function test_a_duplicate_messaging_profile_id_conflicts_at_both_layers(): void
    {
        $businessA = $this->business();
        $businessB = $this->business();
        $profileId = 'mp_shared_' . Str::random(10);

        $first = $this->resolver()->create($businessA, $profileId);
        $this->assertNotNull($first->id);

        // (a) Through the resolver: the contracted exception, not a silent
        //     overwrite and not a raw QueryException leaking out.
        try {
            $this->resolver()->create($businessB, $profileId);
            $this->fail('A second identity must never take an already-used messaging_profile_id.');
        } catch (MessagingIdentityConflictException $e) {
            $this->assertNoCredentialShapedValue($e->getMessage());
        }

        // (b) Bypassing the resolver: MySQL refuses it directly.
        try {
            DB::table('business_messaging_identities')->insert($this->rawIdentityRow($businessB, $profileId));
            $this->fail('The database itself must refuse a duplicate messaging_profile_id.');
        } catch (QueryException $e) {
            $this->assertStringContainsStringIgnoringCase('messaging_profile_id', $e->getMessage());
        }

        // Neither path overwrote or duplicated anything.
        $rows = DB::table('business_messaging_identities')->where('messaging_profile_id', $profileId)->get();
        $this->assertCount(1, $rows);
        $this->assertSame((int) $businessA->id, (int) $rows->first()->business_id);
        $this->assertSame((int) $first->id, (int) $rows->first()->id);
    }

    public function test_a_second_active_or_pending_identity_for_one_business_conflicts_at_both_layers(): void
    {
        $business = $this->business();

        $first = $this->resolver()->create($business, 'mp_first_' . Str::random(8));

        // (a) Through the resolver.
        try {
            $this->resolver()->create($business, 'mp_second_' . Str::random(8));
            $this->fail('A Business must never hold two active-or-pending identities.');
        } catch (MessagingIdentityConflictException $e) {
            $this->assertNoCredentialShapedValue($e->getMessage());
        }

        // (b) Bypassing the resolver — this is the assertion that proves the
        //     guard column plus UNIQUE index, not the application lock above,
        //     is what actually enforces it.
        try {
            DB::table('business_messaging_identities')->insert(
                $this->rawIdentityRow($business, 'mp_raw_' . Str::random(8)),
            );
            $this->fail('The database itself must refuse a second active-or-pending identity.');
        } catch (QueryException $e) {
            $this->assertStringContainsStringIgnoringCase('bmi_provider_active_or_pending_business_unique', $e->getMessage());
        }

        $rows = DB::table('business_messaging_identities')->where('business_id', $business->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame((int) $first->id, (int) $rows->first()->id);
        $this->assertSame($first->messaging_profile_id, $rows->first()->messaging_profile_id);
    }

    private function resolver(): BusinessMessagingIdentityResolver
    {
        return app(BusinessMessagingIdentityResolver::class);
    }

    /**
     * A pending row identical in every way that matters to what create()
     * would have written, so the only thing under test is the constraint.
     */
    private function rawIdentityRow(\App\Models\Business $business, string $profileId): array
    {
        return [
            'uid' => (string) Str::uuid(),
            'business_id' => (int) $business->id,
            'provider' => MessagingProvider::Telnyx->value,
            'status' => BusinessMessagingIdentityStatus::Pending->value,
            'messaging_profile_id' => $profileId,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function assertNoCredentialShapedValue(string $message): void
    {
        foreach (['api_key', 'auth_token', 'secret', 'password', 'Bearer', 'AC_', 'sk_live', 'sk_test'] as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $message,
                "A conflict exception must never carry a credential-shaped value; found [{$needle}].",
            );
        }
    }
    public function test_identity_statuses_cover_exactly_the_contracted_set(): void
    {
        $this->assertSame(
            ['pending', 'active', 'suspended', 'archived'],
            array_map(fn (BusinessMessagingIdentityStatus $c) => $c->value, BusinessMessagingIdentityStatus::cases()),
        );

        $this->assertSame(
            ['pending', 'active', 'suspended', 'released'],
            array_map(fn (BusinessMessagingNumberStatus $c) => $c->value, BusinessMessagingNumberStatus::cases()),
        );
    }

    // ---------------------------------------------------------------
    // T-MSG-47 — forward, rollback and replay, automated
    // ---------------------------------------------------------------

    /**
     * The contract asks for this cycle to run against the repository's real
     * configured MySQL, not a driver-agnostic substitute, because generated
     * guard columns and MySQL's NULL-tolerant unique-index semantics are
     * exactly what is under test. It also must not run against the canonical
     * database or against this lane's own primary one, since it drops
     * schema.
     *
     * So it builds a THIRD, disposable database whose name comes from
     * `TestDatabaseSafety::derivedName()` — the repository's single
     * database-name authority, whose own docblock says it exists for
     * "suites that create their own derived disposable database". No second
     * authority is introduced here: this test decides nothing about what a
     * safe name is, it asks.
     *
     * `TemporaryTestDatabase` is deliberately NOT extended. That class
     * exposes exactly two closed-purpose entry points and documents that it
     * has "deliberately no generic raw-name creation method, so every
     * caller's intent is explicit"; bolting a third purpose onto it would
     * undo the property it was written to have, and it is outside this
     * lane's allowlist besides.
     *
     * Every DDL statement runs on a SEPARATE connection. MySQL implicitly
     * commits on DDL, so issuing CREATE/DROP DATABASE on the default
     * connection would silently end RefreshDatabase's surrounding
     * transaction and leak this test's fixtures into the lane database.
     */
    public function test_the_slice_three_schema_survives_forward_rollback_and_replay(): void
    {
        $database = TestDatabaseSafety::derivedName('s3replay');

        // Belt and braces: the name we are about to CREATE and DROP is
        // re-validated here, against the same single authority, immediately
        // before it is used.
        TestDatabaseSafety::assertSafeTestDatabaseName($database);
        $this->assertNotSame(TestDatabaseSafety::activeTestDatabase(), $database, 'Never the lane\'s own database.');
        $this->assertStringNotContainsString('prod', $database);

        $admin = $this->adminConnection();

        DB::connection($admin)->statement("DROP DATABASE IF EXISTS `{$database}`");
        DB::connection($admin)->statement("CREATE DATABASE `{$database}`");

        $target = $this->targetConnection($database);

        try {
            // --- 1. Forward ------------------------------------------
            Artisan::call('migrate', ['--database' => $target, '--force' => true]);

            $this->assertSliceThreeAndLaneESchemaPresent($target, 'after the forward migration');
            $this->assertExactSchemaShapes($target, $database);
            $this->assertDatabaseRejectsTheDocumentedDuplicates($target, 'after the forward migration');

            // A control: something that is NOT Slice 3 schema, so the
            // rollback assertion below means "only the relevant schema
            // went" rather than "the database emptied".
            $this->assertTrue(Schema::connection($target)->hasTable('businesses'));

            // --- 2. Rollback, reverse dependency order ----------------
            // Named exactly, not "the last 6 migrations" (see
            // SLICE_THREE_AND_LANE_E_MIGRATIONS's docblock) — this must
            // roll back precisely Slice 3 + Lane E's own six migrations,
            // never more and never fewer, regardless of what else has been
            // migrated on this disposable database before or after them.
            //
            // Omitting --step here is deliberate: without it, Laravel's
            // rollback targets the last migration BATCH from the repository
            // (every migration this test's own forward `migrate` call just
            // ran landed in that one batch, on this fresh disposable
            // database) — but --path restricts which of those the command
            // actually loads a file for, so only the six named here ever
            // have down() invoked; everything else in that batch is walked
            // and silently skipped as "not found" for this path set. That
            // is what makes this robust to more migrations arriving later:
            // an unrelated migration is never a name in this list, so it is
            // never touched, no matter where it sorts.
            Artisan::call('migrate:rollback', [
                '--database' => $target,
                '--path' => self::SLICE_THREE_AND_LANE_E_MIGRATIONS,
                '--realpath' => false,
                '--force' => true,
            ]);

            foreach ([
                'business_messaging_identities',
                'business_messaging_numbers',
                'business_messaging_operations',
                'business_usage_measurements',
                'messaging_webhook_rejections',
                'ai_box_campaign_map',
            ] as $table) {
                $this->assertFalse(
                    Schema::connection($target)->hasTable($table),
                    "[{$table}] should have been removed by the rollback.",
                );
            }

            $this->assertFalse(Schema::connection($target)->hasColumn('chat_boxes', 'ai_stage'));
            $this->assertFalse(Schema::connection($target)->hasColumn('chat_boxes', 'ai_replied'));

            // Only the relevant schema went.
            $this->assertTrue(Schema::connection($target)->hasTable('businesses'));
            $this->assertTrue(Schema::connection($target)->hasTable('chat_boxes'));
            $this->assertTrue(Schema::connection($target)->hasTable('campaigns'));

            // --- 3. Replay -------------------------------------------
            Artisan::call('migrate', ['--database' => $target, '--force' => true]);

            $this->assertSliceThreeAndLaneESchemaPresent($target, 'after the replay');
            $this->assertExactSchemaShapes($target, $database);

            // --- 4. The same violations still occur after replay ------
            $this->assertDatabaseRejectsTheDocumentedDuplicates($target, 'after the replay');
        } finally {
            // Dropped whether the assertions passed, failed or threw.
            DB::connection($admin)->statement("DROP DATABASE IF EXISTS `{$database}`");
            DB::purge($target);
            DB::purge($admin);
        }

        $this->assertSame(
            0,
            (int) DB::connection($this->adminConnection())
                ->selectOne('SELECT COUNT(*) AS n FROM information_schema.schemata WHERE schema_name = ?', [$database])->n,
            'The disposable database must not survive the test.',
        );
    }

    private function adminConnection(): string
    {
        $name = 'mysql_s3replay_admin';

        config(['database.connections.' . $name => array_merge(
            config('database.connections.mysql'),
            ['database' => null],
        )]);

        return $name;
    }

    private function targetConnection(string $database): string
    {
        $name = 'mysql_s3replay';

        config(['database.connections.' . $name => array_merge(
            config('database.connections.mysql'),
            ['database' => $database],
        )]);

        DB::purge($name);

        return $name;
    }

    private function assertSliceThreeAndLaneESchemaPresent(string $connection, string $phase): void
    {
        foreach ([
            'business_messaging_identities',
            'business_messaging_numbers',
            'business_messaging_operations',
            'business_usage_measurements',
            'messaging_webhook_rejections',
            'ai_box_campaign_map',
        ] as $table) {
            $this->assertTrue(
                Schema::connection($connection)->hasTable($table),
                "[{$table}] missing {$phase}.",
            );
        }

        $this->assertTrue(Schema::connection($connection)->hasColumn('chat_boxes', 'ai_stage'), "chat_boxes.ai_stage missing {$phase}.");
        $this->assertTrue(Schema::connection($connection)->hasColumn('chat_boxes', 'ai_replied'), "chat_boxes.ai_replied missing {$phase}.");
    }

    /**
     * Generated guard columns, unique indexes and foreign keys, by exact
     * shape — so a replay that recreated the tables with subtly different
     * definitions would fail here rather than pass on table existence alone.
     */
    private function assertExactSchemaShapes(string $connection, string $database): void
    {
        $generated = DB::connection($connection)
            ->table('information_schema.COLUMNS')
            ->select('TABLE_NAME', 'COLUMN_NAME')
            ->where('TABLE_SCHEMA', $database)
            ->where('EXTRA', 'STORED GENERATED')
            ->whereIn('TABLE_NAME', ['business_messaging_identities', 'business_messaging_numbers'])
            ->get()
            ->map(fn ($r) => $r->TABLE_NAME . '.' . $r->COLUMN_NAME)
            ->sort()
            ->values()
            ->all();

        $this->assertSame([
            'business_messaging_identities.active_or_pending_business_id',
            'business_messaging_numbers.active_or_pending_phone_number',
            'business_messaging_numbers.active_primary_identity_id',
        ], $generated);

        $uniques = DB::connection($connection)
            ->table('information_schema.STATISTICS')
            ->select('INDEX_NAME')
            ->where('TABLE_SCHEMA', $database)
            ->where('TABLE_NAME', 'business_messaging_identities')
            ->where('NON_UNIQUE', 0)
            ->distinct()
            ->pluck('INDEX_NAME')
            ->all();

        $this->assertContains('bmi_provider_active_or_pending_business_unique', $uniques);

        $foreignKeys = DB::connection($connection)
            ->table('information_schema.REFERENTIAL_CONSTRAINTS')
            ->select('CONSTRAINT_NAME', 'DELETE_RULE')
            ->where('CONSTRAINT_SCHEMA', $database)
            ->where('TABLE_NAME', 'ai_box_campaign_map')
            ->pluck('DELETE_RULE', 'CONSTRAINT_NAME')
            ->all();

        $this->assertCount(2, $foreignKeys, 'The mapping table carries exactly two foreign keys.');
        foreach ($foreignKeys as $name => $rule) {
            $this->assertSame('CASCADE', $rule, "Foreign key [{$name}] must cascade on delete.");
        }
    }

    /**
     * The documented uniqueness violations, asserted against the real
     * constraints on the disposable database rather than the lane's own.
     */
    private function assertDatabaseRejectsTheDocumentedDuplicates(string $connection, string $phase): void
    {
        // The disposable database is schema-only — it has no Business rows,
        // and building the whole workspace/customer/user chain just to hang
        // a probe row off it would test the fixture rather than the
        // constraint. Foreign-key checks are suspended for this probe ALONE,
        // on this connection alone, because the subject here is the UNIQUE
        // index; the foreign keys themselves are asserted by shape in
        // assertExactSchemaShapes() and exercised for real against the
        // lane's own database elsewhere in this file.
        DB::connection($connection)->statement('SET FOREIGN_KEY_CHECKS = 0');

        $row = [
            'uid' => (string) Str::uuid(),
            'business_id' => 424242,
            'provider' => MessagingProvider::Telnyx->value,
            'status' => BusinessMessagingIdentityStatus::Active->value,
            'messaging_profile_id' => 'mp_replay_' . Str::random(8),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::connection($connection)->table('business_messaging_identities')->insert($row);

        // (a) a second active-or-pending identity for the same Business
        $second = array_merge($row, [
            'uid' => (string) Str::uuid(),
            'messaging_profile_id' => 'mp_replay_other_' . Str::random(8),
        ]);

        $conflicted = false;
        try {
            DB::connection($connection)->table('business_messaging_identities')->insert($second);
        } catch (QueryException) {
            $conflicted = true;
        }
        $this->assertTrue($conflicted, "A second active identity must be refused {$phase}.");

        // (b) a duplicate messaging_profile_id under a different Business
        $duplicateProfile = array_merge($row, [
            'uid' => (string) Str::uuid(),
            'business_id' => 525252,
        ]);

        $profileConflicted = false;
        try {
            DB::connection($connection)->table('business_messaging_identities')->insert($duplicateProfile);
        } catch (QueryException) {
            $profileConflicted = true;
        }
        $this->assertTrue($profileConflicted, "A duplicate messaging_profile_id must be refused {$phase}.");

        // Leave the disposable database as we found it for the next phase,
        // and put the foreign-key enforcement back before anything else
        // touches this connection.
        DB::connection($connection)->table('business_messaging_identities')->delete();
        DB::connection($connection)->statement('SET FOREIGN_KEY_CHECKS = 1');
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Customer Experience Slice 1A — Business capacity versus physical-location
 * capacity (RFC-004 v1.4 §33; CUSTOMER-EXPERIENCE-MANAGED-MESSAGING-
 * AUTOMATIONS-CONTRACT.md §7, §23.2, §23.3).
 *
 * WHAT IT CORRECTS. Milestone 1 seeded Core and Growth with
 * business_slot_included = 3 / business_slot_max = 5 / ratio 0.5000. Those
 * numbers were meant for PHYSICAL LOCATIONS, not separate client companies.
 * The authorized product rule is:
 *
 *   Businesses:  Core 1, Growth 1, Agency unlimited;
 *   Locations:   Core/Growth 3 active included, a 4th and 5th each need an
 *                additional-location allocation, 6+ requires Agency;
 *                Agency unlimited.
 *
 * ADDITIVE ONLY. 2026_08_13_120007_seed_workspace_plan_catalog_and_features
 * is merged history and is not edited. No existing column is repurposed:
 * business_slot_* keeps meaning Business capacity, and locations get their
 * own columns. No SoftDeletes — an archived location must stay readable
 * (its Google binding cascades on DELETE, so a delete would destroy it).
 *
 * PREFLIGHT (before ANY mutation). up() aborts, changing nothing, when:
 *   - a live (nonterminal) additional-Business-slot agreement exists. The
 *     corrected catalog makes such capacity inert, and Slice 1A may not
 *     cancel, stop or remediate billing; that is a separate Billing
 *     decision;
 *   - Core/Growth Business-slot values are not the Milestone 1 values this
 *     migration corrects from, so down() can always restore them exactly.
 *
 * SEMANTICS (contract §23.3). A migration runs once under the migrations
 * table; the DDL below would fail on a second execution. Only
 * backfillGrandfathering() is idempotent, so a repair invocation or a
 * rollback-then-reapply converges without duplicate audit rows.
 *
 * ROLLBACK. A pristine rollback (migrate, no runtime use, rollback) always
 * succeeds: the migration-owned audit rows are uniquely identified by type,
 * null actor and payload source, and are the only rows down() removes.
 * down() fails closed — reporting every reason, mutating nothing — only
 * when post-migration runtime state makes reversal unsafe: archived
 * locations, runtime allocation or grandfathering changes, Core/Growth
 * locations the old schema cannot bound, runtime audit rows of the new
 * types, or operator catalog edits (compare-and-swap).
 *
 * Makes no provider, Stripe or wallet call. Deletes, archives, hides or
 * deactivates no Business and no location.
 */
return new class extends Migration
{
    /** Identifies the audit rows this migration owns (payload.source). */
    public const BACKFILL_SOURCE = 'slice_1a_capacity_correction_v1';

    private const GRANDFATHERED_TYPE = 'capacity_grandfathered';

    private const ALLOCATION_TYPE = 'additional_location_slots_changed';

    private const BACKFILL_REASON = 'Slice 1A capacity correction — existing Businesses and active physical locations above the corrected plan capacity are grandfathered as complimentary; nothing was deleted, archived or charged.';

    /** Agreement states that can no longer charge, renew or allocate. */
    private const TERMINAL_AGREEMENT_STATES = ['canceled', 'refunded', 'payment_failed'];

    /** The Milestone 1 Core/Growth Business-slot values (the CAS source). */
    private const HISTORICAL_BUSINESS_CAPACITY = [
        'business_slot_included' => 3,
        'business_slot_max' => 5,
        'unlimited_business_slots' => false,
        'additional_business_slot_price_ratio' => '0.5000',
    ];

    /** What up() writes for Core/Growth Business capacity (the CAS target). */
    private const CORRECTED_BUSINESS_CAPACITY = [
        'business_slot_included' => 1,
        'business_slot_max' => 1,
        'unlimited_business_slots' => false,
        'additional_business_slot_price_ratio' => null,
    ];

    /** What up() writes for physical-location capacity, per tier. */
    private const LOCATION_CAPACITY = [
        'core' => ['location_slot_included' => 3, 'location_slot_max' => 5, 'unlimited_location_slots' => false, 'additional_location_slot_price_ratio' => '0.5000'],
        'growth' => ['location_slot_included' => 3, 'location_slot_max' => 5, 'unlimited_location_slots' => false, 'additional_location_slot_price_ratio' => '0.5000'],
        'agency' => ['location_slot_included' => 3, 'location_slot_max' => null, 'unlimited_location_slots' => true, 'additional_location_slot_price_ratio' => null],
    ];

    private const CORRECTED_TIERS = ['core', 'growth'];

    public function up(): void
    {
        $this->assertNoLiveAdditionalBusinessSlotAgreements();
        $this->assertCatalogHoldsHistoricalBusinessCapacity();

        // Step 1 — physical-location capacity on the plan catalog.
        Schema::table('workspace_plan_catalog', function (Blueprint $table) {
            $table->unsignedTinyInteger('location_slot_included')->default(3)->after('additional_business_slot_price_ratio');
            $table->unsignedTinyInteger('location_slot_max')->nullable()->after('location_slot_included');
            $table->boolean('unlimited_location_slots')->default(false)->after('location_slot_max');
            $table->decimal('additional_location_slot_price_ratio', 6, 4)->nullable()->after('unlimited_location_slots');
        });

        // Step 2 — per-BUSINESS counters (the location limit is per Business).
        // Paid allocations are reusable; complimentary grandfathered excess
        // is not, so they are kept apart (§7.5.3).
        Schema::table('businesses', function (Blueprint $table) {
            $table->unsignedTinyInteger('additional_location_slots')->default(0)->after('status');
            $table->unsignedSmallInteger('grandfathered_location_slots')->default(0)->after('additional_location_slots');
        });

        // Step 2a — location lifecycle. Default `active` keeps every existing
        // row's present meaning.
        Schema::table('business_locations', function (Blueprint $table) {
            $table->string('lifecycle_state', 16)->default('active')->after('is_primary');
            $table->timestamp('archived_at')->nullable()->after('lifecycle_state');
            $table->index(['business_id', 'lifecycle_state'], 'bl_business_lifecycle_index');
        });

        // Step 2b — one nullable, immutable JSON payload on the existing
        // Workspace-level audit record. Historical rows keep payload NULL.
        Schema::table('workspace_entitlement_transitions', function (Blueprint $table) {
            $table->json('payload')->nullable()->after('reason');
        });

        // Step 3 — seed physical-location capacity.
        foreach (self::LOCATION_CAPACITY as $tier => $values) {
            DB::table('workspace_plan_catalog')->where('tier', $tier)->update($values + ['updated_at' => now()]);
        }

        // Step 4 — corrected Business capacity: exactly one Business on
        // Core/Growth, and no priced additional Business slot at all.
        DB::table('workspace_plan_catalog')
            ->whereIn('tier', self::CORRECTED_TIERS)
            ->update(self::CORRECTED_BUSINESS_CAPACITY + ['updated_at' => now()]);

        // Step 5 — grandfather before any tightened rule can bite.
        $this->backfillGrandfathering();
    }

    /**
     * Contract §7.5.4 — the only idempotent part of this migration.
     *
     * For every Core/Growth Workspace: every Business keeps existing, each
     * Business's complimentary grandfathered location excess is recomputed
     * from its ACTIVE locations, and one migration-owned audit row per
     * affected Workspace names each affected Business and its exact
     * counts. A second invocation writes the same counters and no
     * duplicate row.
     */
    public function backfillGrandfathering(): void
    {
        $catalogs = DB::table('workspace_plan_catalog')
            ->get(['id', 'tier', 'business_slot_included', 'business_slot_max', 'unlimited_location_slots', 'location_slot_included'])
            ->keyBy('id');

        DB::table('workspace_plan_assignments')->orderBy('id')->chunkById(500, function ($assignments) use ($catalogs) {
            foreach ($assignments as $assignment) {
                $catalog = $catalogs->get($assignment->workspace_plan_catalog_id);

                if ($catalog === null || ! in_array($catalog->tier, self::CORRECTED_TIERS, true) || (bool) $catalog->unlimited_location_slots) {
                    continue;
                }

                DB::transaction(fn () => $this->grandfatherWorkspace((int) $assignment->workspace_id, $catalog));
            }
        });
    }

    private function grandfatherWorkspace(int $workspaceId, object $catalog): void
    {
        $workspace = DB::table('workspaces')->where('id', $workspaceId)->lockForUpdate()->first(['id']);

        if ($workspace === null) {
            return;
        }

        $businesses = DB::table('businesses')
            ->where('workspace_id', $workspaceId)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'additional_location_slots']);

        $included = (int) $catalog->location_slot_included;
        $activeCounts = $this->activeLocationCounts($businesses->pluck('id')->all());
        $locations = [];

        foreach ($businesses as $business) {
            $active = $activeCounts[(int) $business->id] ?? 0;

            $grandfathered = max(0, $active - ($included + (int) $business->additional_location_slots));

            DB::table('businesses')->where('id', $business->id)->update(['grandfathered_location_slots' => $grandfathered]);

            if ($grandfathered > 0) {
                $locations[] = [
                    'business_id' => (int) $business->id,
                    'active_locations_before' => $active,
                    'active_locations_after' => $active,
                    'included' => $included,
                    'additional_location_slots' => (int) $business->additional_location_slots,
                    'grandfathered_location_slots' => $grandfathered,
                ];
            }
        }

        $businessCount = $businesses->count();
        $overBusinessCapacity = $businessCount > (int) $catalog->business_slot_included;

        if (! $overBusinessCapacity && $locations === []) {
            return;
        }

        $alreadyRecorded = $this->migrationOwnedAuditRows()->where('workspace_id', $workspaceId)->exists();

        if ($alreadyRecorded) {
            return;
        }

        DB::table('workspace_entitlement_transitions')->insert([
            'workspace_id' => $workspaceId,
            'transition_type' => self::GRANDFATHERED_TYPE,
            'actor_user_id' => null,
            'reason' => self::BACKFILL_REASON,
            'payload' => json_encode([
                'source' => self::BACKFILL_SOURCE,
                'tier' => $catalog->tier,
                'businesses' => [
                    'business_ids' => $businesses->pluck('id')->map(fn ($id) => (int) $id)->all(),
                    'count' => $businessCount,
                    'included' => (int) $catalog->business_slot_included,
                    'max' => (int) $catalog->business_slot_max,
                    'grandfathered_over_capacity' => $overBusinessCapacity,
                ],
                'locations' => $locations,
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }

    public function down(): void
    {
        $reasons = $this->rollbackBlockers();

        if ($reasons !== []) {
            throw new RuntimeException(
                "Slice 1A capacity migration rollback refused; nothing was changed:\n - " . implode("\n - ", $reasons)
            );
        }

        $this->migrationOwnedAuditRows()->delete();

        DB::table('workspace_plan_catalog')
            ->whereIn('tier', self::CORRECTED_TIERS)
            ->update(self::HISTORICAL_BUSINESS_CAPACITY + ['updated_at' => now()]);

        Schema::table('workspace_entitlement_transitions', function (Blueprint $table) {
            $table->dropColumn('payload');
        });

        Schema::table('business_locations', function (Blueprint $table) {
            $table->dropIndex('bl_business_lifecycle_index');
            $table->dropColumn(['lifecycle_state', 'archived_at']);
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['additional_location_slots', 'grandfathered_location_slots']);
        });

        Schema::table('workspace_plan_catalog', function (Blueprint $table) {
            $table->dropColumn(['location_slot_included', 'location_slot_max', 'unlimited_location_slots', 'additional_location_slot_price_ratio']);
        });
    }

    /**
     * Every reason reversal would be unsafe, collected before any write.
     *
     * @return array<int, string>
     */
    private function rollbackBlockers(): array
    {
        $reasons = [];

        $archived = DB::table('business_locations')->where('lifecycle_state', '!=', 'active')->orderBy('business_id')->pluck('business_id')->unique()->values()->all();

        if ($archived !== []) {
            $reasons[] = 'archived locations exist (Businesses ' . implode(', ', $archived) . '); dropping lifecycle_state would silently make them active again';
        }

        $allocated = DB::table('businesses')->where('additional_location_slots', '!=', 0)->orderBy('id')->pluck('id')->all();

        if ($allocated !== []) {
            $reasons[] = 'additional-location allocations exist (Businesses ' . implode(', ', $allocated) . '); the old schema cannot hold them';
        }

        $expected = $this->migrationOwnedGrandfathering();
        $actual = DB::table('businesses')->where('grandfathered_location_slots', '!=', 0)->pluck('grandfathered_location_slots', 'id')
            ->map(fn ($value) => (int) $value)->all();

        $changed = [];

        foreach (array_unique(array_merge(array_keys($expected), array_keys($actual))) as $businessId) {
            if (($expected[$businessId] ?? 0) !== ($actual[$businessId] ?? 0)) {
                $changed[] = $businessId;
            }
        }

        if ($changed !== []) {
            sort($changed);
            $reasons[] = 'grandfathered location allowances changed after the migration (Businesses ' . implode(', ', $changed) . ')';
        }

        $unbounded = $this->correctedTierBusinessesAboveMigrationCapacity($expected);

        if ($unbounded !== []) {
            $reasons[] = 'Core/Growth Businesses hold more active locations than the old schema can bound (Businesses ' . implode(', ', $unbounded) . ')';
        }

        $slice1aAudit = DB::table('workspace_entitlement_transitions')
            ->where(function ($query) {
                $query->whereIn('transition_type', [self::GRANDFATHERED_TYPE, self::ALLOCATION_TYPE])
                    ->orWhereNotNull('payload');
            })
            ->count();

        $runtimeAudit = $slice1aAudit - $this->migrationOwnedAuditRows()->count();

        if ($runtimeAudit > 0) {
            $reasons[] = "{$runtimeAudit} runtime audit row(s) of the Slice 1A types exist; they are genuine history and are never deleted";
        }

        foreach ($this->catalogDrift() as $drift) {
            $reasons[] = $drift;
        }

        return $reasons;
    }

    /**
     * @return array<int, int> business id => grandfathered count this migration wrote
     */
    private function migrationOwnedGrandfathering(): array
    {
        $expected = [];

        $this->migrationOwnedAuditRows()
            ->orderBy('id')
            ->pluck('payload')
            ->each(function ($payload) use (&$expected) {
                foreach (json_decode((string) $payload, true)['locations'] ?? [] as $entry) {
                    $expected[(int) $entry['business_id']] = (int) $entry['grandfathered_location_slots'];
                }
            });

        return $expected;
    }

    /**
     * Core/Growth Businesses whose active locations exceed what they held
     * capacity for when this migration ran: the three included plus the
     * migration's own grandfathered excess. Anything above that was created
     * at runtime against capacity the old schema cannot express.
     *
     * @param  array<int, int>  $expected
     * @return array<int, int>
     */
    private function correctedTierBusinessesAboveMigrationCapacity(array $expected): array
    {
        $businesses = DB::table('businesses')
            ->join('workspace_plan_assignments', 'workspace_plan_assignments.workspace_id', '=', 'businesses.workspace_id')
            ->join('workspace_plan_catalog', 'workspace_plan_catalog.id', '=', 'workspace_plan_assignments.workspace_plan_catalog_id')
            ->whereIn('workspace_plan_catalog.tier', self::CORRECTED_TIERS)
            ->orderBy('businesses.id')
            ->pluck('workspace_plan_catalog.location_slot_included', 'businesses.id');

        $activeCounts = $this->activeLocationCounts($businesses->keys()->all());
        $unbounded = [];

        foreach ($businesses as $businessId => $included) {
            if (($activeCounts[(int) $businessId] ?? 0) > (int) $included + ($expected[(int) $businessId] ?? 0)) {
                $unbounded[] = (int) $businessId;
            }
        }

        return $unbounded;
    }

    /**
     * Compare-and-swap: every catalog value this migration owns must still
     * be exactly what it wrote, or a later operator edit would be lost.
     *
     * @return array<int, string>
     */
    private function catalogDrift(): array
    {
        $drift = [];
        $rows = DB::table('workspace_plan_catalog')->get()->keyBy('tier');

        foreach (self::LOCATION_CAPACITY as $tier => $values) {
            $expected = $values;

            if (in_array($tier, self::CORRECTED_TIERS, true)) {
                $expected += self::CORRECTED_BUSINESS_CAPACITY;
            }

            $row = $rows->get($tier);

            if ($row === null) {
                continue;
            }

            foreach ($expected as $column => $value) {
                if (! $this->sameValue($row->{$column}, $value)) {
                    $drift[] = "workspace_plan_catalog [{$tier}].{$column} is " . var_export($row->{$column}, true) . ', not the migration-written ' . var_export($value, true) . '; a later operator edit is never overwritten';
                }
            }
        }

        return $drift;
    }

    /**
     * Preflight — Slice 1A may not cancel, stop or remediate billing, so a
     * live agreement for now-inert Business capacity must be resolved first.
     */
    private function assertNoLiveAdditionalBusinessSlotAgreements(): void
    {
        if (! Schema::hasTable('additional_business_slot_agreements')) {
            return;
        }

        $live = DB::table('additional_business_slot_agreements')
            ->whereNotIn('state', self::TERMINAL_AGREEMENT_STATES)
            ->orderBy('id')
            ->get(['id', 'workspace_id', 'state']);

        if ($live->isEmpty()) {
            return;
        }

        $list = $live->map(fn ($row) => "agreement {$row->id} (Workspace {$row->workspace_id}, {$row->state})")->implode(', ');

        throw new RuntimeException(
            "Slice 1A capacity migration aborted before any change: {$live->count()} live additional-Business-slot agreement(s) exist ({$list}). "
            . 'Core and Growth no longer offer additional Business slots, so this capacity would become inert while its billing continued. '
            . 'Resolve these agreements through a separate Billing remediation first; this migration does not cancel, refund or stop them.'
        );
    }

    /**
     * Preflight — the migration corrects FROM the Milestone 1 values, so
     * down()'s restore is always exact. Anything else was changed outside
     * any supported path and needs a human decision.
     */
    private function assertCatalogHoldsHistoricalBusinessCapacity(): void
    {
        $rows = DB::table('workspace_plan_catalog')->whereIn('tier', self::CORRECTED_TIERS)->get()->keyBy('tier');
        $drift = [];

        foreach ($rows as $tier => $row) {
            foreach (self::HISTORICAL_BUSINESS_CAPACITY as $column => $value) {
                if (! $this->sameValue($row->{$column}, $value)) {
                    $drift[] = "[{$tier}].{$column} is " . var_export($row->{$column}, true) . ', expected ' . var_export($value, true);
                }
            }
        }

        if ($drift !== []) {
            throw new RuntimeException(
                'Slice 1A capacity migration aborted before any change: Core/Growth Business capacity is not the Milestone 1 value this correction replaces (' . implode('; ', $drift) . ').'
            );
        }
    }

    /**
     * The audit rows this migration owns and nothing else: its own type,
     * no actor, and its own payload source. Runtime rows always carry an
     * actor and a different source.
     */
    private function migrationOwnedAuditRows(): \Illuminate\Database\Query\Builder
    {
        return DB::table('workspace_entitlement_transitions')
            ->where('transition_type', self::GRANDFATHERED_TYPE)
            ->whereNull('actor_user_id')
            ->where('payload->source', self::BACKFILL_SOURCE);
    }

    /**
     * @param  array<int, int|string>  $businessIds
     * @return array<int, int> business id => active location count
     */
    private function activeLocationCounts(array $businessIds): array
    {
        if ($businessIds === []) {
            return [];
        }

        return DB::table('business_locations')
            ->whereIn('business_id', $businessIds)
            ->where('lifecycle_state', 'active')
            ->groupBy('business_id')
            ->pluck(DB::raw('COUNT(*) as active_count'), 'business_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    private function sameValue(mixed $actual, mixed $expected): bool
    {
        if ($expected === null || $actual === null) {
            return $expected === $actual;
        }

        if (is_bool($expected)) {
            return (bool) $actual === $expected;
        }

        if (is_int($expected)) {
            return (int) $actual === $expected;
        }

        return bccomp((string) $actual, (string) $expected, 4) === 0;
    }
};

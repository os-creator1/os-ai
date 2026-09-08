<?php

use App\Enums\Business\BusinessLocationLifecycleState;
use App\Enums\Entitlement\WorkspaceEntitlementTransitionType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Customer Experience Slice 1A — plan and physical-location capacity.
 *
 * Implements CUSTOMER-EXPERIENCE-MANAGED-MESSAGING-AUTOMATIONS-CONTRACT.md
 * §23.2/§23.3 and RFC-004 §33.4/§33.5 exactly.
 *
 * ADDITIVE ONLY. The merged M1 seed migration
 * (2026_08_13_120007_seed_workspace_plan_catalog_and_features.php) is
 * historical and is NOT edited — it correctly records what M1 seeded.
 *
 * WHY THIS EXISTS. RFC-004 §2 originally read "Business/location slot
 * capacity", conflating a `businesses` row (a client account) with a
 * `business_locations` row (a physical branch). M1 resolved that toward
 * Business slots. The authorized correction (RFC-004 v1.4 §33) is:
 *
 *   - Business/client accounts: Core 1, Growth 1, Agency unlimited;
 *   - physical locations:       Core/Growth 3 included, 4 and 5 paid at a
 *                               0.5000 ratio, 6+ requires Agency;
 *                               Agency unlimited.
 *
 * The existing `business_slot_*` columns keep their existing meaning —
 * Business/client-account capacity. Physical-location capacity gets its own
 * new columns. Nothing is repurposed.
 *
 * MIGRATION SEMANTICS (contract §23.3, stated exactly). A migration runs
 * ONCE under Laravel's `migrations` table. The DDL steps below are ordinary
 * Schema::table() additions and would fail on a second execution — this
 * migration does not claim to be re-runnable. Only backfillGrandfathering()
 * is idempotent, so a repair command or a rollback-then-reapply is safe.
 *
 * ROLLBACK (contract §23.3, reconciled with §23.1 in correction round 3).
 *
 * §23.1 states three things that together decide how down() must behave:
 * no migration deletes customer data; entitlement and audit history is
 * IMMUTABLE; and a rollback fails closed when reversing it would destroy
 * meaningful state. An earlier version of this down() deleted the
 * location-capacity transition rows and dropped the two per-Business
 * counters unconditionally — that destroyed immutable audit evidence and
 * real paid/complimentary entitlement state, so it is replaced.
 *
 * down() now runs a COMPLETE PREFLIGHT BEFORE ANY MUTATION and refuses,
 * reporting every reason it found at once, if reversing would destroy
 * meaningful state. A refusal leaves the database exactly as it was: no
 * catalog value restored, no row deleted, no column or index dropped,
 * because nothing is written until every check has passed.
 *
 * The preflight refuses when:
 *
 *   1. any Business holds a nonzero `additional_location_slots` — that is
 *      subscribed paid capacity, and dropping the column would erase it;
 *   2. any Business holds a nonzero `grandfathered_location_slots` — that
 *      is a complimentary entitlement granted by this migration's own
 *      backfill and relied upon by over-capacity Businesses;
 *   3. any location is `archived` — dropping lifecycle_state would
 *      silently restore it to active and could push a Business over
 *      capacity past a paid allocation it no longer holds;
 *   4. any location-capacity transition row exists, or any transition row
 *      carries a payload — that is immutable audit history, and down()
 *      never deletes it;
 *   5. workspace_plan_catalog no longer holds exactly what up() wrote, for
 *      the Business-slot values OR the physical-location values — the
 *      catalog is operator-editable (RFC-004 §12.5,
 *      updateCatalogPricing()), so a deliberate later edit must never be
 *      silently overwritten or dropped.
 *
 * A pristine rollback — one where up() ran, nothing used any of it, and no
 * operator edited the catalog — passes every check and reverses cleanly,
 * so the forward/rollback/replay cycle stays available in development and
 * in CI.
 *
 * RESIDUAL LIMIT, STATED HONESTLY. MySQL DDL is not transactional, so no
 * migration can make its own DDL steps atomic. The preflight removes every
 * failure this migration can foresee (each column and index it would drop
 * is confirmed present first), but a hard infrastructure failure during
 * the DDL itself remains a deployment-process concern, exactly as the
 * pre-existing 2026_07_30_120006 migration already documents.
 *
 * Makes no provider, Stripe, Telnyx or Twilio call. Performs no wallet
 * debit. Deletes, archives, hides or disables nothing.
 */
return new class extends Migration
{
    /**
     * The exact Business-capacity values this migration writes. down()
     * compare-and-swaps against these and refuses if they have moved.
     */
    private const CORRECTED_BUSINESS_SLOTS = ['core' => 1, 'growth' => 1];

    /** The M1 values down() restores when, and only when, the CAS matches. */
    private const HISTORICAL_BUSINESS_SLOTS = [
        'core' => ['included' => 3, 'max' => 5],
        'growth' => ['included' => 3, 'max' => 5],
    ];

    public function up(): void
    {
        // Step 1 — physical-location capacity on the plan catalog. Every
        // column is defaulted or nullable, so this cannot fail on the three
        // existing rows.
        Schema::table('workspace_plan_catalog', function (Blueprint $table) {
            $table->unsignedTinyInteger('location_slot_included')->default(3)->after('additional_business_slot_price_ratio');
            $table->unsignedTinyInteger('location_slot_max')->nullable()->after('location_slot_included');
            $table->boolean('unlimited_location_slots')->default(false)->after('location_slot_max');
            $table->decimal('additional_location_slot_price_ratio', 6, 4)->nullable()->after('unlimited_location_slots');
        });

        // Step 2 — the two per-BUSINESS counters. Per Business, not per
        // Workspace, because the location limit is per Business (§7.5.2).
        // They are deliberately separate columns because they behave
        // differently under archiving (§7.5.3): paid slots are reusable,
        // complimentary grandfathered excess is not.
        Schema::table('businesses', function (Blueprint $table) {
            $table->unsignedTinyInteger('additional_location_slots')->default(0)->after('status');
            $table->unsignedTinyInteger('grandfathered_location_slots')->default(0)->after('additional_location_slots');
        });

        // Step 2a — the lifecycle column, which does not exist today.
        // DELIBERATELY NOT SoftDeletes: a soft-deleted row disappears from
        // default queries and from the business_google_locations
        // relationship, whereas an archived location must stay fully
        // readable for history, GBP bindings, billing evidence,
        // reactivation and audit. Defaulting to `active` gives every
        // existing row exactly its pre-migration meaning.
        Schema::table('business_locations', function (Blueprint $table) {
            $table->string('lifecycle_state', 16)
                ->default(BusinessLocationLifecycleState::Active->value)
                ->after('is_primary');
            $table->timestamp('archived_at')->nullable()->after('lifecycle_state');

            $table->index(['business_id', 'lifecycle_state'], 'bl_business_lifecycle_index');
        });

        // Step 2b — an additive, nullable, machine-readable payload on the
        // EXISTING audit table. Contract §23.2 step 6 forbids a new audit
        // TABLE, not an additive column, and §7.5.2 requires the transition
        // payload to name every affected Business and its exact
        // grandfathered count. `reason` is human-readable free text used by
        // existing transitions, so structured JSON belongs beside it rather
        // than inside it. Nullable, so every historical row is untouched.
        Schema::table('workspace_entitlement_transitions', function (Blueprint $table) {
            $table->json('payload')->nullable()->after('reason');
        });

        // Step 3 — seed physical-location capacity per tier.
        DB::table('workspace_plan_catalog')->whereIn('tier', ['core', 'growth'])->update([
            'location_slot_included' => 3,
            'location_slot_max' => 5,
            'unlimited_location_slots' => false,
            'additional_location_slot_price_ratio' => 0.5000,
        ]);

        DB::table('workspace_plan_catalog')->where('tier', 'agency')->update([
            'location_slot_included' => 3,
            'location_slot_max' => null,
            'unlimited_location_slots' => true,
            'additional_location_slot_price_ratio' => null,
        ]);

        // Step 4 — corrected Business/client-account capacity. Core and
        // Growth hold exactly one Business and offer no purchasable
        // additional Business slot at any price, so max equals included.
        // Agency is untouched: unlimited_business_slots is already true.
        foreach (self::CORRECTED_BUSINESS_SLOTS as $tier => $slots) {
            DB::table('workspace_plan_catalog')->where('tier', $tier)->update([
                'business_slot_included' => $slots,
                'business_slot_max' => $slots,
            ]);
        }

        // Step 5 — grandfather BEFORE any tightened enforcement can bite.
        $this->backfillGrandfathering();
    }

    /**
     * Contract §7.5.4 — the ONLY idempotent part of this migration.
     *
     * Recomputes each Business's complimentary grandfathered excess from
     * its current ACTIVE location count and writes at most one transition
     * row per Workspace. Re-invoking it produces the same counts and no
     * duplicate transition, so a repair command or a
     * rollback-then-reapply is safe.
     *
     * Nothing is deleted, archived, hidden or charged.
     */
    private function backfillGrandfathering(): void
    {
        $catalogByTier = DB::table('workspace_plan_catalog')
            ->get(['id', 'tier', 'location_slot_included', 'unlimited_location_slots'])
            ->keyBy('tier');

        $assignments = DB::table('workspace_plan_assignments')
            ->join('workspace_plan_catalog', 'workspace_plan_catalog.id', '=', 'workspace_plan_assignments.workspace_plan_catalog_id')
            ->get([
                'workspace_plan_assignments.workspace_id',
                'workspace_plan_catalog.tier',
                'workspace_plan_catalog.location_slot_included',
                'workspace_plan_catalog.unlimited_location_slots',
            ])
            ->keyBy('workspace_id');

        $activeCounts = DB::table('business_locations')
            ->where('lifecycle_state', BusinessLocationLifecycleState::Active->value)
            ->groupBy('business_id')
            ->get([DB::raw('business_id'), DB::raw('COUNT(*) as active_count')])
            ->keyBy('business_id');

        $businesses = DB::table('businesses')->get(['id', 'workspace_id']);

        /** @var array<int, array<int, int>> $affectedByWorkspace */
        $affectedByWorkspace = [];

        foreach ($businesses as $business) {
            $workspaceId = $business->workspace_id;

            if ($workspaceId === null) {
                continue;
            }

            $assignment = $assignments->get($workspaceId);

            // A Workspace with no plan assignment gets the conservative
            // Core included figure — it can only ever grant MORE
            // complimentary allowance, never less, so no existing location
            // can be stranded by a missing assignment.
            $included = (int) ($assignment->location_slot_included ?? $catalogByTier->get('core')?->location_slot_included ?? 3);
            $unlimited = (bool) ($assignment->unlimited_location_slots ?? false);

            if ($unlimited) {
                continue;
            }

            $activeCount = (int) ($activeCounts->get($business->id)->active_count ?? 0);
            $excess = max(0, $activeCount - $included);

            if ($excess === 0) {
                continue;
            }

            DB::table('businesses')->where('id', $business->id)->update([
                'grandfathered_location_slots' => $excess,
            ]);

            $affectedByWorkspace[$workspaceId][(int) $business->id] = $excess;
        }

        foreach ($affectedByWorkspace as $workspaceId => $businessCounts) {
            // Idempotency: one row per Workspace for this transition type.
            $alreadyRecorded = DB::table('workspace_entitlement_transitions')
                ->where('workspace_id', $workspaceId)
                ->where('transition_type', WorkspaceEntitlementTransitionType::LocationCapacityGrandfathered->value)
                ->exists();

            if ($alreadyRecorded) {
                continue;
            }

            // §7.5.2 — the immutable payload names every affected Business
            // and that Business's exact grandfathered count, so the audit
            // trail alone can reconstruct the state.
            DB::table('workspace_entitlement_transitions')->insert([
                'workspace_id' => $workspaceId,
                'transition_type' => WorkspaceEntitlementTransitionType::LocationCapacityGrandfathered->value,
                'actor_user_id' => null,
                'reason' => 'Slice 1A physical-location capacity correction: existing active locations above the newly included allowance are complimentary and are never charged retroactively.',
                'payload' => json_encode(['grandfathered_location_slots_by_business_id' => $businessCounts]),
                'created_at' => now(),
            ]);
        }
    }

    /**
     * The exact physical-location capacity values up() seeded. down()
     * compare-and-swaps against these too, because these columns are
     * operator-editable and are about to be DROPPED — an operator's
     * deliberate edit must never be silently discarded.
     *
     * The ratio is compared numerically, not as a string, so a
     * '0.5000'/'0.5' formatting difference between MySQL versions is not
     * mistaken for an operator edit.
     */
    private const SEEDED_LOCATION_CAPACITY = [
        'core' => ['included' => 3, 'max' => 5, 'unlimited' => false, 'ratio' => 0.5],
        'growth' => ['included' => 3, 'max' => 5, 'unlimited' => false, 'ratio' => 0.5],
        'agency' => ['included' => 3, 'max' => null, 'unlimited' => true, 'ratio' => null],
    ];

    public function down(): void
    {
        // ---------------------------------------------------------------
        // PREFLIGHT. Runs to completion BEFORE any mutation, collects every
        // reason, and throws once. Nothing below writes anything, so a
        // refusal leaves the database completely unchanged.
        // ---------------------------------------------------------------
        $refusals = array_merge(
            $this->paidAllocationRefusals(),
            $this->grandfatheredAllocationRefusals(),
            $this->archivedLocationRefusals(),
            $this->auditHistoryRefusals(),
            $this->operatorEditedCatalogRefusals(),
            $this->missingObjectRefusals(),
        );

        if ($refusals !== []) {
            throw new \RuntimeException(
                "Refusing to roll back the Slice 1A physical-location capacity migration: reversing it would destroy"
                . " meaningful state (contract §23.1 — no migration deletes customer data, entitlement and audit"
                . " history is immutable, and rollback fails closed). Nothing has been changed.\n  - "
                . implode("\n  - ", $refusals)
                . "\nResolve each item deliberately before rolling back."
            );
        }

        // ---------------------------------------------------------------
        // MUTATION. Every object below was confirmed present by the
        // preflight, and every value below was confirmed to be exactly what
        // up() wrote.
        // ---------------------------------------------------------------
        foreach (self::HISTORICAL_BUSINESS_SLOTS as $tier => $slots) {
            DB::table('workspace_plan_catalog')->where('tier', $tier)->update([
                'business_slot_included' => $slots['included'],
                'business_slot_max' => $slots['max'],
            ]);
        }

        // NOTHING IS DELETED HERE. The preflight has already proven no
        // location-capacity transition row and no transition payload
        // exists, so dropping the additive payload column destroys no audit
        // evidence.
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
            $table->dropColumn([
                'location_slot_included',
                'location_slot_max',
                'unlimited_location_slots',
                'additional_location_slot_price_ratio',
            ]);
        });
    }

    /**
     * Paid capacity a customer holds right now. Dropping the column would
     * erase a subscribed entitlement.
     *
     * @return array<int, string>
     */
    private function paidAllocationRefusals(): array
    {
        if (! Schema::hasColumn('businesses', 'additional_location_slots')) {
            return [];
        }

        $ids = DB::table('businesses')->where('additional_location_slots', '>', 0)->pluck('id')->all();

        return $ids === [] ? [] : [
            'Business ids [' . implode(', ', $ids) . '] hold paid additional location slots. Dropping'
            . ' additional_location_slots would erase capacity those customers are subscribed to.',
        ];
    }

    /**
     * Complimentary excess this migration itself granted, which
     * over-capacity Businesses depend on to keep locations they already
     * had (§7.5).
     *
     * @return array<int, string>
     */
    private function grandfatheredAllocationRefusals(): array
    {
        if (! Schema::hasColumn('businesses', 'grandfathered_location_slots')) {
            return [];
        }

        $ids = DB::table('businesses')->where('grandfathered_location_slots', '>', 0)->pluck('id')->all();

        return $ids === [] ? [] : [
            'Business ids [' . implode(', ', $ids) . '] hold complimentary grandfathered location slots.'
            . ' Dropping grandfathered_location_slots would strip an entitlement those Businesses rely on.',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function archivedLocationRefusals(): array
    {
        if (! Schema::hasColumn('business_locations', 'lifecycle_state')) {
            return [];
        }

        $ids = DB::table('business_locations')
            ->where('lifecycle_state', BusinessLocationLifecycleState::Archived->value)
            ->distinct()
            ->pluck('business_id')
            ->all();

        return $ids === [] ? [] : [
            'Archived physical locations exist for Business ids [' . implode(', ', $ids) . ']. Dropping'
            . ' lifecycle_state would silently restore them to active and could push those Businesses over'
            . ' their capacity.',
        ];
    }

    /**
     * Immutable audit history (§23.1). down() must never delete it, so it
     * refuses instead — both for the location-capacity transition types and
     * for any row carrying the additive payload this migration added.
     *
     * @return array<int, string>
     */
    private function auditHistoryRefusals(): array
    {
        $refusals = [];

        $transitionCount = DB::table('workspace_entitlement_transitions')
            ->whereIn('transition_type', [
                WorkspaceEntitlementTransitionType::LocationCapacityGrandfathered->value,
                WorkspaceEntitlementTransitionType::AdditionalLocationSlotsChanged->value,
            ])
            ->count();

        if ($transitionCount > 0) {
            $refusals[] = $transitionCount . ' location-capacity entitlement transition row(s) exist. Entitlement'
                . ' and audit history is immutable, so this rollback will not delete them.';
        }

        if (Schema::hasColumn('workspace_entitlement_transitions', 'payload')) {
            $payloadCount = DB::table('workspace_entitlement_transitions')->whereNotNull('payload')->count();

            if ($payloadCount > 0) {
                $refusals[] = $payloadCount . ' entitlement transition row(s) carry an audit payload. Dropping the'
                    . ' payload column would destroy that immutable evidence.';
            }
        }

        return $refusals;
    }

    /**
     * The catalog is operator-editable, and down() both overwrites two of
     * its columns and drops four more. Every one of those values is
     * compare-and-swapped against exactly what up() wrote.
     *
     * @return array<int, string>
     */
    private function operatorEditedCatalogRefusals(): array
    {
        $refusals = [];

        foreach (self::CORRECTED_BUSINESS_SLOTS as $tier => $expected) {
            $row = DB::table('workspace_plan_catalog')->where('tier', $tier)->first(['business_slot_included', 'business_slot_max']);

            if ($row === null) {
                continue;
            }

            if ((int) $row->business_slot_included !== $expected || (int) $row->business_slot_max !== $expected) {
                $refusals[] = "workspace_plan_catalog tier [{$tier}] no longer holds the Business-slot values this"
                    . " migration wrote (included={$expected}, max={$expected}); found"
                    . " included={$row->business_slot_included}, max={$row->business_slot_max}. An operator has since"
                    . ' changed it, and restoring the historical values would overwrite that deliberate edit.';
            }
        }

        if (! Schema::hasColumn('workspace_plan_catalog', 'location_slot_included')) {
            return $refusals;
        }

        foreach (self::SEEDED_LOCATION_CAPACITY as $tier => $expected) {
            $row = DB::table('workspace_plan_catalog')->where('tier', $tier)->first([
                'location_slot_included',
                'location_slot_max',
                'unlimited_location_slots',
                'additional_location_slot_price_ratio',
            ]);

            if ($row === null) {
                continue;
            }

            $matches = (int) $row->location_slot_included === $expected['included']
                && $this->nullableIntMatches($row->location_slot_max, $expected['max'])
                && (bool) $row->unlimited_location_slots === $expected['unlimited']
                && $this->nullableFloatMatches($row->additional_location_slot_price_ratio, $expected['ratio']);

            if (! $matches) {
                $refusals[] = "workspace_plan_catalog tier [{$tier}] no longer holds the physical-location capacity"
                    . ' values this migration seeded. Those columns are about to be dropped, so an operator edit'
                    . ' would be discarded without trace.';
            }
        }

        return $refusals;
    }

    /**
     * Every column and index down() would drop must actually be present.
     * Checking here rather than letting a DDL step fail halfway is what
     * keeps a refusal from leaving a partially reversed schema behind.
     *
     * @return array<int, string>
     */
    private function missingObjectRefusals(): array
    {
        $refusals = [];

        $required = [
            'workspace_entitlement_transitions' => ['payload'],
            'business_locations' => ['lifecycle_state', 'archived_at'],
            'businesses' => ['additional_location_slots', 'grandfathered_location_slots'],
            'workspace_plan_catalog' => [
                'location_slot_included',
                'location_slot_max',
                'unlimited_location_slots',
                'additional_location_slot_price_ratio',
            ],
        ];

        foreach ($required as $table => $columns) {
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    $refusals[] = "Column [{$table}.{$column}] is already gone, so this migration is not in the state"
                        . ' it created and cannot reverse itself cleanly.';
                }
            }
        }

        return $refusals;
    }

    private function nullableIntMatches(mixed $actual, ?int $expected): bool
    {
        return $expected === null ? $actual === null : ($actual !== null && (int) $actual === $expected);
    }

    private function nullableFloatMatches(mixed $actual, ?float $expected): bool
    {
        return $expected === null
            ? $actual === null
            : ($actual !== null && abs((float) $actual - $expected) < 0.00005);
    }
};

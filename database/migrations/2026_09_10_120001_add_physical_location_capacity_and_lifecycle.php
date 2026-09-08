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
 * ROLLBACK (contract §23.3). down() reverses only what up() introduced and
 * FAILS CLOSED rather than destroying data:
 *
 *   - Core/Growth Business capacity is restored only by compare-and-swap
 *     against the exact values this migration wrote (1/1). If an operator
 *     has since changed them, down() aborts instead of overwriting a
 *     deliberate edit — workspace_plan_catalog is operator-editable
 *     (RFC-004 §12.5, updateCatalogPricing()).
 *   - If any location is `archived`, down() aborts rather than silently
 *     resurrecting it as active, which could push a Business over capacity
 *     past a paid allocation it no longer holds.
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

    public function down(): void
    {
        // Fail closed rather than resurrecting archived locations as
        // active: dropping lifecycle_state would lose the distinction, and
        // a silently reactivated location could push a Business over
        // capacity past a paid allocation it no longer holds.
        if (Schema::hasColumn('business_locations', 'lifecycle_state')) {
            $archivedBusinessIds = DB::table('business_locations')
                ->where('lifecycle_state', BusinessLocationLifecycleState::Archived->value)
                ->distinct()
                ->pluck('business_id')
                ->all();

            if ($archivedBusinessIds !== []) {
                throw new RuntimeException(
                    'Refusing to roll back: archived physical locations exist for Business ids ['
                    . implode(', ', $archivedBusinessIds)
                    . ']. Dropping lifecycle_state would silently restore them to active and could push those'
                    . ' Businesses over their capacity. Resolve those locations deliberately first.'
                );
            }
        }

        // Compare-and-swap: restore the M1 Business-slot values only if the
        // catalog still holds exactly what up() wrote. workspace_plan_catalog
        // is operator-editable, so a later deliberate change must never be
        // silently overwritten.
        foreach (self::CORRECTED_BUSINESS_SLOTS as $tier => $expected) {
            $row = DB::table('workspace_plan_catalog')->where('tier', $tier)->first(['business_slot_included', 'business_slot_max']);

            if ($row === null) {
                continue;
            }

            if ((int) $row->business_slot_included !== $expected || (int) $row->business_slot_max !== $expected) {
                throw new RuntimeException(
                    "Refusing to roll back: workspace_plan_catalog tier [{$tier}] no longer holds the values this"
                    . " migration wrote (business_slot_included={$expected}, business_slot_max={$expected}); found"
                    . " included={$row->business_slot_included}, max={$row->business_slot_max}. An operator has since"
                    . ' changed it. Resolve the intended value deliberately before rolling back.'
                );
            }
        }

        foreach (self::HISTORICAL_BUSINESS_SLOTS as $tier => $slots) {
            DB::table('workspace_plan_catalog')->where('tier', $tier)->update([
                'business_slot_included' => $slots['included'],
                'business_slot_max' => $slots['max'],
            ]);
        }

        DB::table('workspace_entitlement_transitions')
            ->whereIn('transition_type', [
                WorkspaceEntitlementTransitionType::LocationCapacityGrandfathered->value,
                WorkspaceEntitlementTransitionType::AdditionalLocationSlotsChanged->value,
            ])
            ->delete();

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
};

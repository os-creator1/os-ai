<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * B4 Business Automations — contract §3.4. Data-only, no DDL.
 *
 * A legacy automation row is mapped to a Business ONLY when
 * deterministically resolvable:
 *
 *     automations.contact_list_id -> contact_groups.business_id
 *
 * and only if that group's business_id is non-NULL AND the group's
 * customer_id equals the automation's user_id (owner consistency).
 * Everything else is left NULL — never a primary-Business guess, never
 * LegacyBusinessResolver, never "the first" Business. Mirrors
 * BusinessDataTenancyBackfillV1's never-fail / leave-NULL / log-summary
 * discipline exactly.
 *
 * Idempotent: scoped to `business_id IS NULL`, so an already-populated row
 * (valid or intentionally left NULL on a prior run) is never revisited.
 */
return new class extends Migration {
    public function up(): void
    {
        $candidates = DB::table('automations as a')
            ->leftJoin('contact_groups as g', 'g.id', '=', 'a.contact_list_id')
            ->whereNull('a.business_id')
            ->select(['a.id as automation_id', 'a.user_id as owner_user_id', 'g.business_id as group_business_id', 'g.customer_id as group_customer_id'])
            ->orderBy('a.id')
            ->get();

        $resolved = 0;
        $unresolved = 0;

        foreach ($candidates as $row) {
            $deterministic = $row->group_business_id !== null
                && (int) $row->group_customer_id === (int) $row->owner_user_id;

            if (! $deterministic) {
                $unresolved++;

                continue;
            }

            DB::table('automations')
                ->where('id', $row->automation_id)
                ->whereNull('business_id')
                ->update(['business_id' => (int) $row->group_business_id]);

            $resolved++;
        }

        logger()->info("business_id backfill [automations]: resolved={$resolved}, unresolved={$unresolved}");

        if ($unresolved > 0) {
            logger()->info("business_id backfill [automations]: {$unresolved} row(s) remain NULL (no deterministic contact-group -> Business mapping — expected for legacy data; these automations are inert until manually remediated).");
        }
    }

    /**
     * Contract §3.6 — documented no-op. This migration never deletes an
     * automation row and must not null out business_id on rollback: the
     * column itself is removed by the DDL migration's own down(), and a
     * value that was deterministically correct going forward is not made
     * incorrect by rolling the feature back.
     */
    public function down(): void
    {
        // Intentionally empty.
    }
};

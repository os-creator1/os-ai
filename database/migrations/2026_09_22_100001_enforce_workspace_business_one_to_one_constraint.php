<?php

use App\Exceptions\Workspace\MultipleBusinessesPerWorkspaceException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Implementation Contract 13 — the final schema backstop after Contracts
 * 10 and 12: at most one Business per Workspace, enforced at the database
 * layer. Mirrors 2026_07_30_120006_enforce_business_workspace_constraint.php's
 * own precondition-then-DDL discipline exactly (§3): a plain SELECT-based
 * precondition runs before any DDL and throws a specific, typed exception
 * on failure — never an opaque MySQL constraint-addition error as the
 * primary signal.
 *
 * This precondition is deliberately INDEPENDENT of Contract 10/12's own
 * internal verification steps (§8) — a fresh, direct query against
 * `businesses`/`workspace_id` immediately before this migration's DDL, not
 * merely trusting that Contract 10/12 "reported success" earlier.
 *
 * This migration does not itself close the window between its own
 * precondition query and the subsequent DDL: MySQL DDL is not
 * transactional, so a Business write landing in that window is a
 * deployment-process concern, not something this migration can
 * self-enforce. Business-write traffic must be quiesced for the duration
 * of this migration when it is applied to a live database — identical to
 * the precedent migration's own operational requirement, and to this
 * same table's exact same non-transactional-DDL race window.
 *
 * INDEX/FK ORDERING (mechanically verified, not copied from the planning
 * contract's own sample without proof — see Contract 13 §4/§5's
 * corrected note). `businesses.workspace_id` currently carries TWO
 * indexes that each independently satisfy InnoDB's "the FK's referencing
 * column must be covered by some index" requirement: the plain
 * `businesses_workspace_id_index` (single-column) and the composite
 * `businesses_workspace_id_status_index` (`[workspace_id, status]`,
 * workspace_id leading). Because the composite index is never touched by
 * this migration and always remains as a second qualifying index, EITHER
 * add-then-drop or drop-then-add ordering is safe against the FK on the
 * CURRENT schema — proven empirically against a throwaway table
 * reproducing the exact same index/FK shape, not assumed. This migration
 * still chooses the strictly-safer add-then-drop ordering (never briefly
 * reducing FK-supporting indexes before the replacement exists), as
 * defense in depth against a future schema change removing the composite
 * index — not because the original drop-then-add order was proven unsafe
 * here.
 */
return new class extends Migration
{
    public function up(): void
    {
        $violatingWorkspaceIds = DB::table('businesses')
            ->select('workspace_id')
            ->groupBy('workspace_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('workspace_id')
            ->all();

        if ($violatingWorkspaceIds !== []) {
            throw new MultipleBusinessesPerWorkspaceException($violatingWorkspaceIds);
        }

        Schema::table('businesses', function (Blueprint $table) {
            $table->unique('workspace_id', 'businesses_workspace_id_unique');
        });

        Schema::table('businesses', function (Blueprint $table) {
            // Now redundant — the unique index above already serves every
            // lookup this plain index did, and businesses_workspace_id_status_index
            // remains untouched as the FK's other qualifying index.
            $table->dropIndex('businesses_workspace_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->index('workspace_id', 'businesses_workspace_id_index');
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->dropUnique('businesses_workspace_id_unique');
        });
    }
};

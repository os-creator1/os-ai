<?php

use App\Exceptions\Workspace\WorkspaceMembershipLocationAccessScopeBackfillIncompleteException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Implementation Contract 02 (Location ACL Foundation) §8, Migration C —
 * mirrors the exact zero-violation-assertion discipline
 * 2026_07_30_120006_enforce_business_workspace_constraint.php already
 * uses for businesses.workspace_id: a plain precondition SELECT runs
 * before any DDL, never relying on an opaque database constraint-addition
 * error as the primary signal. The precondition has NO `is_active`
 * filter, for the same reason the backfill migration has none — an
 * inactive row left NULL must fail this precondition exactly like an
 * active one would.
 *
 * Only run once the backfill migration above has been verified (by test,
 * per this contract's own instruction) to leave zero NULL rows across
 * every existing WorkspaceMembership, active and inactive alike.
 */
return new class extends Migration
{
    public function up(): void
    {
        $remainingNullCount = DB::table('workspace_memberships')->whereNull('location_access_scope')->count();

        if ($remainingNullCount > 0) {
            throw new WorkspaceMembershipLocationAccessScopeBackfillIncompleteException($remainingNullCount);
        }

        Schema::table('workspace_memberships', function (Blueprint $table): void {
            $table->string('location_access_scope', 16)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('workspace_memberships', function (Blueprint $table): void {
            $table->string('location_access_scope', 16)->nullable()->change();
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Implementation Contract 02 (Location ACL Foundation) §8, Migration A —
 * three-step pattern mirroring RFC-003's own businesses.workspace_id
 * precedent (add nullable -> backfill -> enforce NOT NULL) exactly:
 * database/migrations/2026_07_30_120005_backfill_business_workspaces.php
 * and 2026_07_30_120006_enforce_business_workspace_constraint.php.
 *
 * Nullable here, deliberately: no existing WorkspaceMembership row can
 * satisfy a NOT NULL constraint until the very next migration backfills
 * every one of them (active and inactive alike).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_memberships', function (Blueprint $table): void {
            $table->string('location_access_scope', 16)->nullable()->after('business_access_scope');
        });
    }

    public function down(): void
    {
        Schema::table('workspace_memberships', function (Blueprint $table): void {
            $table->dropColumn('location_access_scope');
        });
    }
};

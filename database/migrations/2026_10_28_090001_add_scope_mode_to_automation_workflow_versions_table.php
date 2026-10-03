<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Automations Location run-scope — the THIRD scope, "Selected locations".
 *
 * `business_location_id` alone can say "Business-wide" (NULL) or "one Location",
 * but not "these Locations". A NULL there means Business-wide to every reader that
 * exists, so a list could not be squeezed into it without making a selected-scope
 * workflow read as Business-wide — fail-open. The mode is therefore its own
 * column, and the Locations of a `selected` version live in
 * `automation_workflow_version_locations` (the next migration).
 *
 *   business   every Location; `business_location_id` NULL, no list rows
 *   one        exactly one Location; `business_location_id` set (unchanged)
 *   selected   a list of Locations; `business_location_id` NULL, list rows
 *
 * Existing rows: a version with a `business_location_id` was published as "one
 * Location", everything else as Business-wide, so the backfill is exact.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('automation_workflow_versions', 'scope_mode')) {
            Schema::table('automation_workflow_versions', function (Blueprint $table): void {
                $table->string('scope_mode', 16)->default('business')->after('business_location_id');
            });
        }

        DB::table('automation_workflow_versions')
            ->whereNotNull('business_location_id')
            ->where('scope_mode', 'business')
            ->update(['scope_mode' => 'one']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('automation_workflow_versions', 'scope_mode')) {
            Schema::table('automation_workflow_versions', function (Blueprint $table): void {
                $table->dropColumn('scope_mode');
            });
        }
    }
};

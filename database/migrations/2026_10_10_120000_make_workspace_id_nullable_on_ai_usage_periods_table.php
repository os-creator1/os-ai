<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Implementation Contract 19 §8, §5.7a E, sub-slice 19.H0 — the one required
 * schema change `ai_usage_periods` needs for a Platform-scope period row:
 * `workspace_id` becomes nullable, so a Platform row carries no Workspace at
 * all rather than a fabricated one (R-19). No FK exists on this column
 * today (`2026_09_16_100002_create_ai_usage_periods_table.php`'s own
 * docblock is explicit about that), so this is a column-nullability change
 * with no referential consequence.
 *
 * The unique key is already `(scope_type, scope_id, period_key)` —
 * deliberately scope-generic since the table's very first migration — and
 * is left unchanged (§5.7a E). Additive only, no backfill: existing rows
 * already carry a real `workspace_id` and keep it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_usage_periods', function (Blueprint $table): void {
            $table->unsignedBigInteger('workspace_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // A Platform-scope row (workspace_id = NULL) cannot be represented
        // by the pre-19.H0 schema, so it is removed rather than given a
        // fabricated Workspace id.
        DB::table('ai_usage_periods')->whereNull('workspace_id')->delete();

        Schema::table('ai_usage_periods', function (Blueprint $table): void {
            $table->unsignedBigInteger('workspace_id')->nullable(false)->change();
        });
    }
};

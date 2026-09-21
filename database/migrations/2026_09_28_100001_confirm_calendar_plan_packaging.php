<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Ensure existing Core, Growth and Agency catalogs carry Calendar at activation. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['core', 'growth', 'agency'] as $tier) {
            $catalogId = DB::table('workspace_plan_catalog')->where('tier', $tier)->value('id');
            if ($catalogId === null) {
                continue;
            }
            if (! DB::table('workspace_plan_features')
                ->where('workspace_plan_catalog_id', $catalogId)
                ->where('feature_key', 'calendar')->exists()) {
                DB::table('workspace_plan_features')->insert([
                    'workspace_plan_catalog_id' => $catalogId,
                    'feature_key' => 'calendar',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Catalog rows may already predate this migration; never remove them.
    }
};

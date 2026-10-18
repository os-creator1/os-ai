<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automations Location run-scope — the Locations a `selected`-scope version was
 * published with.
 *
 * One row per (version, Location). Written once, inside the publish transaction,
 * for a version whose `scope_mode` is `selected`, and never updated: a published
 * version is immutable, so changing which Locations a workflow listens to is a
 * new draft and a new publish. Enrollments pin their version, so a journey keeps
 * the scope it started under.
 *
 * `business_id` is carried so every read of the list can be tenant-scoped without
 * a join, like the graph tables beside it. There is no `updated_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('automation_workflow_version_locations')) {
            return;
        }

        Schema::create('automation_workflow_version_locations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('version_id');
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('business_location_id');
            $table->timestamp('created_at')->nullable();

            $table->unique(['version_id', 'business_location_id'], 'awvl_version_location_unique');
            $table->index('business_location_id', 'awvl_location_index');
            $table->index('business_id', 'awvl_business_index');

            $table->foreign('version_id', 'awvl_version_foreign')
                ->references('id')->on('automation_workflow_versions')->cascadeOnDelete();
            $table->foreign('business_location_id', 'awvl_location_foreign')
                ->references('id')->on('business_locations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_workflow_version_locations');
    }
};

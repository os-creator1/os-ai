<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automations Location run-scope — the SCOPE a published version was published
 * with.
 *
 * NULL means Business-wide (every version that exists today, which is why there
 * is no backfill); a Location id means the workflow listens only to facts of that
 * one Location. It lives on the VERSION, beside `trigger_type` and the policies,
 * because a version is immutable once published and enrollments pin their
 * version: changing a workflow's scope is therefore a new draft and a new publish,
 * never a mutation of history, and a journey already running keeps the scope it
 * started under.
 *
 * The draft carries the choice inside its trigger node's config
 * (`business_location_id`), like every other trigger setting, and publish promotes
 * it here after proving the Location belongs to the Business and is active. The
 * runtime and the trigger sources read ONLY this column, never node config.
 */
return new class extends Migration
{
    private const INDEX = 'awv_business_location_index';

    private const FOREIGN_KEY = 'awv_business_location_foreign';

    public function up(): void
    {
        if (! Schema::hasColumn('automation_workflow_versions', 'business_location_id')) {
            Schema::table('automation_workflow_versions', function (Blueprint $table): void {
                $table->unsignedBigInteger('business_location_id')->nullable()->after('failure_policy');
            });
        }

        if (! Schema::hasIndex('automation_workflow_versions', self::INDEX)) {
            Schema::table('automation_workflow_versions', function (Blueprint $table): void {
                $table->index('business_location_id', self::INDEX);
            });
        }

        if (! $this->hasForeignKey()) {
            Schema::table('automation_workflow_versions', function (Blueprint $table): void {
                $table->foreign('business_location_id', self::FOREIGN_KEY)
                    ->references('id')->on('business_locations')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if ($this->hasForeignKey()) {
            Schema::table('automation_workflow_versions', function (Blueprint $table): void {
                $table->dropForeign(self::FOREIGN_KEY);
            });
        }

        if (Schema::hasIndex('automation_workflow_versions', self::INDEX)) {
            Schema::table('automation_workflow_versions', function (Blueprint $table): void {
                $table->dropIndex(self::INDEX);
            });
        }

        if (Schema::hasColumn('automation_workflow_versions', 'business_location_id')) {
            Schema::table('automation_workflow_versions', function (Blueprint $table): void {
                $table->dropColumn('business_location_id');
            });
        }
    }

    private function hasForeignKey(): bool
    {
        return collect(Schema::getForeignKeys('automation_workflow_versions'))
            ->contains(fn (array $foreignKey): bool => $foreignKey['name'] === self::FOREIGN_KEY);
    }
};

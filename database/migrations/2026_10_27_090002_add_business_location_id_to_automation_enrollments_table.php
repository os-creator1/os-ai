<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automations Location run-scope — the Location a journey is PINNED to.
 *
 * Written once, at enrollment, by the one enrollment door
 * (WorkflowEnrollmentService), from the triggering fact's own Location, and never
 * reassigned: a journey keeps its Location through waits, resumes and recovery
 * even if its Contact later moves. NULL means the fact had no Location (a Contact
 * with none, an ambiguous conversation) and the workflow was Business-wide — a
 * Location-bound workflow never produces a NULL here. Every enrollment that
 * exists today predates the column and stays NULL (unattributed): all existing
 * workflows are Business-wide, and a Location is never guessed after the fact, so
 * there is no backfill.
 *
 * The one index serves "this workflow's journeys at this Location". The Business
 * match is enforced by the service, not a composite foreign key, because
 * `business_locations` has no (id, business_id) unique key to reference.
 */
return new class extends Migration
{
    private const INDEX = 'aen_workflow_location_index';

    private const FOREIGN_KEY = 'aen_business_location_foreign';

    public function up(): void
    {
        if (! Schema::hasColumn('automation_enrollments', 'business_location_id')) {
            Schema::table('automation_enrollments', function (Blueprint $table): void {
                $table->unsignedBigInteger('business_location_id')->nullable()->after('contact_id');
            });
        }

        if (! Schema::hasIndex('automation_enrollments', self::INDEX)) {
            Schema::table('automation_enrollments', function (Blueprint $table): void {
                $table->index(['workflow_id', 'business_location_id'], self::INDEX);
            });
        }

        if (! $this->hasForeignKey()) {
            Schema::table('automation_enrollments', function (Blueprint $table): void {
                $table->foreign('business_location_id', self::FOREIGN_KEY)
                    ->references('id')->on('business_locations')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if ($this->hasForeignKey()) {
            Schema::table('automation_enrollments', function (Blueprint $table): void {
                $table->dropForeign(self::FOREIGN_KEY);
            });
        }

        if (Schema::hasIndex('automation_enrollments', self::INDEX)) {
            Schema::table('automation_enrollments', function (Blueprint $table): void {
                $table->dropIndex(self::INDEX);
            });
        }

        if (Schema::hasColumn('automation_enrollments', 'business_location_id')) {
            Schema::table('automation_enrollments', function (Blueprint $table): void {
                $table->dropColumn('business_location_id');
            });
        }
    }

    private function hasForeignKey(): bool
    {
        return collect(Schema::getForeignKeys('automation_enrollments'))
            ->contains(fn (array $foreignKey): bool => $foreignKey['name'] === self::FOREIGN_KEY);
    }
};

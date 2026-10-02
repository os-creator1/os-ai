<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automations V1 completion — Location run-scope foundation, lane contract
 * §6/§7: the immutable run Location. Every NEW enrollment
 * (`WorkflowEnrollmentService::enroll()`) sets this once and it is never
 * reassigned afterwards — no code path updates it after INSERT (proved by
 * test). It is the one column the whole lane exists to add.
 *
 * NULLABLE, deliberately, matching `contacts.location_id`'s own precedent —
 * but for a different, narrower reason here: existing enrollments may
 * predate this invariant entirely, and a historical row whose Location
 * cannot be proven (see AutomationEnrollmentLocationBackfillV1) must stay
 * NULL rather than have one fabricated. The APPLICATION invariant — every
 * enrollment WorkflowEnrollmentService creates from here on has exactly one
 * Location — is enforced and proved in code and tests, not by a DB NOT NULL
 * constraint that would also have to hold for un-provable legacy rows.
 *
 * RESTRICT, not cascade, matching `contacts.location_id`/
 * `chat_boxes.location_id`'s own precedent (§19 of the lane's contract): a
 * Location is archived, never hard-deleted, so run-history attribution must
 * never vanish silently.
 *
 * Indexed as `(workflow_id, business_location_id)` — the read pattern
 * Location-ACL-filtered enrollment history/logs actually uses
 * (`AutomationWorkflowEnrollmentsController`, §12D of the lane's contract):
 * this workflow's enrollments, narrowed to the Locations one staff member
 * may see.
 *
 * Idempotent, guarded shape — mirrors
 * `2026_09_21_100001_add_location_id_to_contacts_table.php`.
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

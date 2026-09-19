<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Implementation Contract 15 §7.2, Sub-slice A — the per-staff serialization
 * row. It holds NO data of its own; its only purpose is to be locked with
 * SELECT ... FOR UPDATE so that two overlapping booking attempts for one
 * staff member cannot both succeed (§7.1 tier 2, §7.4).
 *
 * Created here, in Sub-slice A, so Sub-slice C needs no schema change of its
 * own (§12.A).
 *
 * `staff_user_id` IS the primary key — one row per staff member — and that
 * is what makes §7.2's `insertOrIgnore` ensure step genuinely idempotent:
 * INSERT IGNORE suppresses a duplicate-key error, so it prevents a duplicate
 * only where a real unique key exists. (This is precisely the property
 * `contacts` lacks, which is why Location-local Contact identity needs its
 * own dedicated lock table instead — §5.8.1, §5.8.3.)
 *
 * `cascadeOnDelete`, DELIBERATELY UNLIKE the historically meaningful user
 * FKs in this schema (§7.2). This row carries no audit value and means
 * nothing once the staff member is gone; restrictOnDelete would make a User
 * undeletable for the sole reason that someone once booked them. This
 * repository hard-deletes Users — there is no SoftDeletes on `users` and ten
 * distinct hard-delete paths exist in app/ — and its canonical stated rule
 * is that infrastructure "must never block a legitimate user-deletion
 * feature elsewhere in the system"
 * (2026_07_31_120001_create_workspace_transitions_table.php:15-19); RFC-003
 * §17's no-hard-delete policy names only Workspace, WorkspaceMembership and
 * Business. The closest existing analogue, business_home_visits, cascades on
 * both parents (2026_09_16_100001:31-32).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('staff_booking_locks', function (Blueprint $table): void {
            $table->unsignedBigInteger('staff_user_id')->primary();
            $table->timestamps();

            $table->foreign('staff_user_id', 'staff_booking_locks_staff_user_id_foreign')
                ->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_booking_locks');
    }
};

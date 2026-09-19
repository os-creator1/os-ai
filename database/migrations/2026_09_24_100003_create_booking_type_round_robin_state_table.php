<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Implementation Contract 15 §5.1.1, Sub-slice A — the DURABLE round-robin
 * rotation cursor.
 *
 * Blueprint §12 requires round-robin distribution across available staff at
 * a Location, and §5.1.1 rejects every non-durable way of holding that
 * cursor: an in-memory pointer, PHP process state and a cache entry do not
 * survive a request or a second worker, and "count each staff member's
 * historical appointments and pick the lowest" silently re-derives the
 * cursor from an unrelated, mutable history (cancellations, manual bookings
 * and imported rows would all move it).
 *
 * One row per Booking Type, holding exactly one fact: which staff member
 * received the last successfully committed round-robin assignment. The
 * cursor advances only on commit (§7.3), and this row is tier 1 of §7.4's
 * single canonical lock order.
 *
 * `cascadeOnDelete` on booking_type_id — like booking_type_staff, this row
 * is meaningless once its Booking Type is gone and carries no independent
 * audit value. `nullOnDelete` on last_assigned_staff_user_id — a deleted
 * User must not block the Booking Type, and a null cursor is a DEFINED
 * state, not an error: §7.3 step 2 simply starts rotation at the lowest
 * eligible id.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('booking_type_round_robin_state', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('booking_type_id');
            $table->unsignedBigInteger('last_assigned_staff_user_id')->nullable();
            $table->timestamp('last_assigned_at')->nullable();
            $table->timestamps();

            // Every constraint here is explicitly named: this table's name
            // alone leaves too little of MySQL's 64-character identifier
            // budget for Laravel's generated names, which the unnamed form
            // proved by failing outright. Same reason as
            // 2026_09_12_100001:57-58 and 2026_09_15_100001's `aw_*` names.
            $table->unique('booking_type_id', 'btrrs_booking_type_unique');

            $table->foreign('booking_type_id', 'btrrs_booking_type_foreign')
                ->references('id')->on('booking_types')->cascadeOnDelete();

            $table->foreign('last_assigned_staff_user_id', 'btrrs_last_assigned_staff_foreign')
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_type_round_robin_state');
    }
};

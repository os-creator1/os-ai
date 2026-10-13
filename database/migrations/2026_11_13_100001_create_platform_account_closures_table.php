<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform Owner — customer account deletion / closure lifecycle.
 *
 * One durable row per customer account that a Platform Owner has asked to
 * delete. It is the retryable state machine for work that cannot be one
 * database transaction (provider cancellations, then local retirement), and
 * it is the tombstone that remains after the user row itself is gone.
 *
 * `user_id` is deliberately NOT a foreign key: a completed deletion removes
 * the users row, and this record (identified by `user_uid`) must outlive it.
 * `requested_by_user_id` is likewise a plain column so an audit tombstone can
 * never block anything, in either direction.
 *
 * `user_uid` is UNIQUE: that is the idempotency anchor — two requests for the
 * same account converge on one row.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('platform_account_closures', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->uuid('user_uid')->unique();
            $table->string('user_email', 191);
            $table->string('user_name', 191)->nullable();
            $table->unsignedBigInteger('requested_by_user_id')->index();
            $table->text('reason');
            $table->string('state', 16)->index();
            $table->string('outcome', 24)->nullable();
            $table->json('preflight')->nullable();
            $table->json('steps')->nullable();
            $table->json('pending_items')->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            // Lease: claim_token identifies the ONE run that may act; claimed_at is
            // renewed by that run as it works (see PlatformAccountClosureManager).
            $table->uuid('claim_token')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_account_closures');
    }
};

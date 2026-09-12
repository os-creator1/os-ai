<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automations V2-B — the table Laravel's `database` notification channel needs.
 *
 * WHY THIS EXISTS AT ALL. The V2 contract (§10) assumed "the notifications table
 * and Laravel Notification exist", and half of that is true: the class exists,
 * but `notifications` in this repository is NOT Laravel's table. It is a bespoke
 * product table — `user_id`, `notification_for`, `notification_type`, `message`,
 * `mark_read` — created in 2021 and used by the app's own in-app notices. It has
 * none of the columns the framework channel writes (`notifiable_type`,
 * `notifiable_id`, `data`, `read_at`, a uuid primary key), which is why the
 * channel fails with a QueryException the moment it is used. Nothing in the
 * codebase used the `database` channel before this slice, so the gap had never
 * shown.
 *
 * Three options existed. Reshaping `notifications` would break the existing
 * product feature. Dropping the `database` channel would quietly deliver half
 * of a contracted requirement. This is the third: a correctly-shaped table under
 * its own name, reached through `User::routeNotificationForDatabase()`, which is
 * the framework's own documented extension point for exactly this situation. The
 * legacy table is left completely untouched.
 *
 * The schema below is Laravel's standard notifications schema, unmodified.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('platform_database_notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');

            // morphs() would derive an index name longer than MySQL's 64-character
            // identifier limit from this table name, so the two columns and their
            // lookup index are declared explicitly. The shape is identical to what
            // morphs() produces.
            $table->string('notifiable_type');
            $table->unsignedBigInteger('notifiable_id');
            $table->index(['notifiable_type', 'notifiable_id'], 'pdn_notifiable_index');

            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_database_notifications');
    }
};

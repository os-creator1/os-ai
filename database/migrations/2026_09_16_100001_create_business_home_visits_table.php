<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unified Business Home §2.3 (Slice H-2) — the per-user, per-Business record
 * of when this customer last used this Business's Home.
 *
 * It exists because nothing in the schema answers that question:
 * `users.last_access_at` is written at login and on a portal switch, for the
 * user across every Business, and the customer context preference is session
 * only. Home needs a marker that is per Business, survives a new device, and
 * is never moved by simply refreshing a page.
 *
 * Additive: one new table, no existing column changes meaning, and `down()`
 * drops only what `up()` created. No backfill — a Business with no row is a
 * first visit, which renders no activity window at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('business_home_visits')) {
            return;
        }

        Schema::create('business_home_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();

            // End of the PREVIOUS visit: the lower bound the activity window
            // is derived from. Null until a second visit exists.
            $table->timestamp('window_start_at')->nullable();

            // The current visit: when it began, and the latest Home view in
            // it. A refresh moves only the latter, and only once a minute.
            $table->timestamp('current_visit_started_at');
            $table->timestamp('current_visit_last_seen_at');

            $table->timestamps();

            $table->unique(['user_id', 'business_id'], 'business_home_visits_user_business_unique');
            $table->index(['business_id'], 'business_home_visits_business_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_home_visits');
    }
};

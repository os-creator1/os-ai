<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Implementation Contract 15 §5.6/§11/§12.F, review correction — the
 * minimum durable state needed to run a periodic ROLLING full sync, on top
 * of the incremental-cursor mechanism §5.6 already specifies.
 *
 * Neither `sync_cursor` nor `last_synced_at` can answer "when did the
 * FULL [now, now+window] read last actually complete" — `last_synced_at`
 * advances on every ordinary INCREMENTAL sync too (§5.6 rule 4), so
 * overloading it would make the rolling-window decision indistinguishable
 * from "an incremental sync happened recently," and a connection could sit
 * on an increasingly stale full-sync window forever as long as its
 * incremental deltas kept succeeding. A provider event created directly
 * inside the product's configured future horizon, but outside whatever
 * bounded window the LAST full read covered, would then never be
 * discovered by delta processing alone (delta only reports true content
 * *changes*, not "this pre-existing event is now within a wider window
 * than before").
 *
 * `last_full_synced_at` is written ONLY inside ExternalCalendarSyncService's
 * existing full-sync transaction, alongside `sync_cursor`/`last_synced_at`,
 * and only when that full read's entire paginated fetch actually completed
 * — never on a failed or partial one, matching the same all-or-nothing
 * discipline every other write in that transaction already follows.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('external_calendar_connections', function (Blueprint $table): void {
            $table->timestamp('last_full_synced_at')->nullable()->after('last_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('external_calendar_connections', function (Blueprint $table): void {
            $table->dropColumn('last_full_synced_at');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Implementation Contract 15 §5.6, Sub-slice A — the external busy/free sync
 * cache. ADVISORY ONLY.
 *
 * `cascadeOnDelete`, unlike every Location FK in this schema: this table is
 * purely disposable synced-derived data with no independent audit value, so
 * it is deleted along with its connection (and therefore, transitively,
 * along with the User — §5.5 — so no synced cache is ever orphaned).
 *
 * THE UNIQUE KEY IS THE IDEMPOTENCY MECHANISM (§5.6, §7, §12.F). Every sync
 * — full, delta or webhook-triggered — is an upsert keyed by
 * (connection_id, provider_event_id), so processing the same notification
 * twice is a no-op by construction rather than by a separate dedup ledger.
 * Sub-slice F additionally applies deletions and reconciliation; upsert
 * alone is insufficient, because a busy block that outlives its source event
 * is a permanent false conflict nobody can see or clear from inside this
 * product.
 *
 * NO PROVIDER-SPECIFIC COLUMN EVER APPEARS ON `appointments` (§5.5's
 * boundary rule): this table is consulted only as one additional read-only
 * source unioned into the conflict-check query (§7.6), and the canonical
 * Appointment record's authority never depends on it being present, current,
 * or even ever having existed.
 *
 * start_at/end_at are UTC timestamps (§5's opening rule).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('external_calendar_busy_blocks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('external_calendar_connection_id');
            $table->string('provider_event_id', 255);
            $table->timestamp('start_at');
            $table->timestamp('end_at');
            $table->string('busy_type', 24)->nullable();
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(
                ['external_calendar_connection_id', 'provider_event_id'],
                'ecbb_connection_provider_event_unique'
            );
            $table->index(
                ['external_calendar_connection_id', 'start_at', 'end_at'],
                'ecbb_connection_window_index'
            );

            // Explicitly named: the generated name for this column pair is
            // 69 characters, past MySQL's 64-character identifier limit.
            $table->foreign('external_calendar_connection_id', 'ecbb_connection_foreign')
                ->references('id')->on('external_calendar_connections')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_calendar_busy_blocks');
    }
};

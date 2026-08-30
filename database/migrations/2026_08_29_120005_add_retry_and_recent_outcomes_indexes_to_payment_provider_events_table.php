<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RFC-005 Remediation #6 §19 — one index per retry branch, each matching
 * that branch's own exact ORDER BY column sequence (never ORDER BY id
 * alone after a range predicate): received-recovery (state, received_at,
 * id); failed-recovery (state, attempts, id); stale-processing-recovery,
 * queried per attempt-bucket equality (state, attempts, lease_expires_at,
 * id). Plus (normalized_recorded_at, id) for recentOutcomes().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_provider_events', function (Blueprint $table) {
            $table->index(['state', 'received_at', 'id'], 'ppe_state_received_at_id_index');
            $table->index(['state', 'attempts', 'id'], 'ppe_state_attempts_id_index');
            $table->index(['state', 'attempts', 'lease_expires_at', 'id'], 'ppe_state_attempts_lease_expires_id_index');
            $table->index(['normalized_recorded_at', 'id'], 'ppe_normalized_recorded_at_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('payment_provider_events', function (Blueprint $table) {
            $table->dropIndex('ppe_state_received_at_id_index');
            $table->dropIndex('ppe_state_attempts_id_index');
            $table->dropIndex('ppe_state_attempts_lease_expires_id_index');
            $table->dropIndex('ppe_normalized_recorded_at_id_index');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B4 Business Automations — contract §4. The authoritative per-run
 * execution ledger (adapted from opportunity_action_executions, not
 * copied).
 *
 * `idempotency_key` UNIQUE is the race-proof claim guarantee (§5.2): the
 * claim is a single INSERT, and a concurrent/duplicate attempt loses on
 * the constraint. `attempt_number` is deliberately absent (§4.2): under
 * the at-most-once policy the row IS the one automatic attempt.
 *
 * `business_id` is denormalised here on purpose (unlike `automations`,
 * §3.3) so history queries and the scoped-history endpoint never join
 * through a possibly-deleted automation.
 *
 * Never stores provider response bodies, credentials, or raw payloads —
 * only bounded, human-safe summaries.
 *
 * Indexes (§4.1): `business_id`, `automation_id`, `(automation_id,
 * created_at)` for the history view, `status`, and — per the B4/B5 index
 * coordination decision (Correction 1) — the explicitly named composite
 * `automation_executions_business_id_created_at_index` on
 * `(business_id, created_at)`, because B5 Business Analytics reads this
 * table by Business over a bounded created_at range. Index only: no
 * analytics code and no further analytics-oriented indexes live here.
 *
 * ROLLBACK IS DESTRUCTIVE OF RUN HISTORY (§3.6): this table is the only
 * record of B4-era executions; `down()` drops it entirely, which removes
 * every index above (the composite included) with it.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('automation_executions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->foreignId('automation_id')->constrained('automations')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('trigger_type', 32);
            $table->string('idempotency_key', 191);
            $table->string('status', 16)->default('pending');
            $table->timestamp('action_claimed_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('safe_result_summary', 255)->nullable();
            $table->string('safe_error_summary', 255)->nullable();
            $table->timestamps();

            $table->unique('idempotency_key');
            $table->index('business_id');
            $table->index('automation_id');
            $table->index(['automation_id', 'created_at']);
            $table->index('status');
            $table->index(['business_id', 'created_at'], 'automation_executions_business_id_created_at_index');
        });
    }

    /**
     * Dropping the table drops all of its indexes, including the explicitly
     * named `automation_executions_business_id_created_at_index`; no
     * separate dropIndex is needed or performed.
     */
    public function down(): void
    {
        Schema::dropIfExists('automation_executions');
    }
};

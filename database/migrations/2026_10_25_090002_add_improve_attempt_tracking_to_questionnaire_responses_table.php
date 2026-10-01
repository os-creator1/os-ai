<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Independent-review correction round 4 (item 2) — `custom_section_
 * improve_key` (added round 3) identifies the LOGICAL submission
 * (response + observed revision + exact title/body/layout) and must stay
 * stable across a retry of that same submission. A separate, ALWAYS-
 * FRESH-per-attempt ledger key is needed for the actual AiGateway call: a
 * failed attempt reusing the identical ledger idempotency key on retry
 * risks AiUsageLedgerManager's own unique constraint on `idempotency_key`
 * (AiGateway's own documented UniqueConstraintViolationException risk).
 *
 * `custom_section_improve_attempt_ordinal` counts attempts for the
 * current logical key (1 on the first attempt, incremented on each
 * genuine retry after a failure or an abandoned pending attempt) and
 * `custom_section_improve_ledger_key` is derived from the logical key
 * plus this ordinal — distinct per attempt, stable for a given attempt
 * across the begin/complete pair.
 *
 * `custom_section_improve_pending_started_at` is the short lease an
 * abandoned (crashed/timed-out) pending attempt is recovered against —
 * separate from `custom_section_improve_started_revision`, which is a
 * compare-and-swap value, not a timestamp.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questionnaire_responses', function (Blueprint $table): void {
            $table->string('custom_section_improve_ledger_key', 64)->nullable()->after('custom_section_improve_key');
            $table->unsignedInteger('custom_section_improve_attempt_ordinal')->nullable()->after('custom_section_improve_ledger_key');
            $table->timestamp('custom_section_improve_pending_started_at')->nullable()->after('custom_section_improve_started_revision');
        });
    }

    public function down(): void
    {
        Schema::table('questionnaire_responses', function (Blueprint $table): void {
            $table->dropColumn([
                'custom_section_improve_ledger_key',
                'custom_section_improve_attempt_ordinal',
                'custom_section_improve_pending_started_at',
            ]);
        });
    }
};

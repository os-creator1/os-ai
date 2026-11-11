<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable "what the customer saw" for an approved profile-field execution: the SHA-256 of the Business field value at
 * approval time. The queued worker compares it to the authoritative current value (under a row lock) immediately before
 * writing, so a newer manual edit is never overwritten. Null = no expectation (every pre-existing and non-Home flow).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('opportunity_action_executions', function (Blueprint $table) {
            $table->char('expected_state_hash', 64)->nullable()->after('completion_policy');
        });
    }

    public function down(): void
    {
        Schema::table('opportunity_action_executions', function (Blueprint $table) {
            $table->dropColumn('expected_state_hash');
        });
    }
};

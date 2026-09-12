<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    /**
     * Unified Business Home and COO Decision Engine contract §17 (A-2) —
     * additive nullable column only. `intent` durably stores the exact
     * `AgencyProspectAiDecision::INTENTS` value already produced by the
     * existing classifier; no new AI, no vocabulary, no backfill. Every
     * existing row keeps a null intent, which stays valid indefinitely (a
     * message classified before this column existed, or where the
     * classification path failed/AI was unavailable). 16 chars matches the
     * table's own `direction`/`status` column convention and comfortably
     * fits the longest INTENTS value ("qualification", "hard_negative",
     * "soft_negative").
     */
    return new class extends Migration {
        public function up(): void
        {
            Schema::table('agency_prospect_messages', function (Blueprint $table) {
                $table->string('intent', 16)->nullable()->after('status');
            });
        }

        public function down(): void
        {
            Schema::table('agency_prospect_messages', function (Blueprint $table) {
                $table->dropColumn('intent');
            });
        }
    };

<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    /**
     * RFC-005 Amendment 1 §G, Slice 1 EXPAND — purely additive meter_key
     * column, nullable permanently (never scheduled for tightening in any
     * future slice). The composite FK is nullable-safe for the legitimate
     * ReservationRelease shape (meter_key populated, rate_id NULL) via
     * MySQL/InnoDB MATCH SIMPLE semantics. No existing historical row is
     * modified (RFC-005 Amendment 1 Slice 1 EXPAND Implementation Contract
     * §4.G).
     */
    return new class extends Migration {
        public function up(): void
        {
            Schema::table('business_usage_ledger_entries', function (Blueprint $table) {
                $table->string('meter_key', 128)->nullable()->after('feature_key');
                $table->index('meter_key');
            });

            Schema::table('business_usage_ledger_entries', function (Blueprint $table) {
                $table->foreign('meter_key')->references('meter_key')->on('usage_meters')->restrictOnDelete();
                $table->foreign(['meter_key', 'rate_id'], 'ledger_meter_rate_foreign')
                    ->references(['meter_key', 'id'])->on('business_usage_rates')
                    ->restrictOnDelete();
            });
        }

        public function down(): void
        {
            Schema::table('business_usage_ledger_entries', function (Blueprint $table) {
                $table->dropForeign(['meter_key']);
                $table->dropForeign('ledger_meter_rate_foreign');
            });

            Schema::table('business_usage_ledger_entries', function (Blueprint $table) {
                $table->dropIndex(['meter_key']);
            });

            Schema::table('business_usage_ledger_entries', function (Blueprint $table) {
                $table->dropColumn('meter_key');
            });
        }
    };

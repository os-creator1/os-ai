<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    /**
     * RFC-005 Amendment 1 §F, Slice 1 EXPAND — purely additive nullable
     * shadow meter_key column. feature_key remains the design's permanent
     * owning-feature snapshot on this table — never renamed, relaxed, or
     * dropped in any slice, and left completely untouched here (RFC-005
     * Amendment 1 Slice 1 EXPAND Implementation Contract §4.F).
     */
    return new class extends Migration {
        public function up(): void
        {
            Schema::table('business_usage_reservations', function (Blueprint $table) {
                $table->string('meter_key', 128)->nullable()->after('feature_key');
                $table->index('meter_key');
            });

            Schema::table('business_usage_reservations', function (Blueprint $table) {
                $table->foreign('meter_key')->references('meter_key')->on('usage_meters')->restrictOnDelete();
                $table->foreign(['meter_key', 'rate_id'], 'reservations_meter_rate_foreign')
                    ->references(['meter_key', 'id'])->on('business_usage_rates')
                    ->restrictOnDelete();
            });
        }

        public function down(): void
        {
            Schema::table('business_usage_reservations', function (Blueprint $table) {
                $table->dropForeign(['meter_key']);
                $table->dropForeign('reservations_meter_rate_foreign');
            });

            Schema::table('business_usage_reservations', function (Blueprint $table) {
                $table->dropIndex(['meter_key']);
            });

            Schema::table('business_usage_reservations', function (Blueprint $table) {
                $table->dropColumn('meter_key');
            });
        }
    };

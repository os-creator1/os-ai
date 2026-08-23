<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    /**
     * RFC-005 Amendment 1 §D/§O, Slice 1 EXPAND — the composite same-meter
     * active-rate FK, staged after both usage_meters and
     * business_usage_rates.meter_key exist (business_usage_rates_meter_key_id
     * _unique is this FK's own target index, created in the prior migration).
     * Nullable-safe: every fresh meter's active_rate_id starts NULL (RFC-005
     * Amendment 1 Slice 1 EXPAND Implementation Contract §4.C).
     */
    return new class extends Migration {
        public function up(): void
        {
            Schema::table('usage_meters', function (Blueprint $table) {
                $table->foreign(['meter_key', 'active_rate_id'], 'meters_active_rate_foreign')
                    ->references(['meter_key', 'id'])->on('business_usage_rates')
                    ->restrictOnDelete();
            });
        }

        public function down(): void
        {
            Schema::table('usage_meters', function (Blueprint $table) {
                $table->dropForeign('meters_active_rate_foreign');
            });
        }
    };

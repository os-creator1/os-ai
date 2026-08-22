<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    /**
     * RFC-005 Amendment 1 §E, Slice 1 EXPAND — purely additive nullable
     * shadow meter_key column. feature_key and its existing index
     * (business_usage_rate_activations_feature_key_index) are left
     * completely untouched throughout Slice 1 and Slice 2 (RFC-005
     * Amendment 1 Slice 1 EXPAND Implementation Contract §4.D).
     */
    return new class extends Migration {
        public function up(): void
        {
            Schema::table('business_usage_rate_activations', function (Blueprint $table) {
                $table->string('meter_key', 128)->nullable()->after('feature_key');
                $table->index('meter_key', 'business_usage_rate_activations_meter_key_index');
            });

            Schema::table('business_usage_rate_activations', function (Blueprint $table) {
                $table->foreign('meter_key')->references('meter_key')->on('usage_meters')->restrictOnDelete();
                $table->foreign(['meter_key', 'rate_id'], 'activations_meter_rate_foreign')
                    ->references(['meter_key', 'id'])->on('business_usage_rates')
                    ->restrictOnDelete();
            });
        }

        public function down(): void
        {
            Schema::table('business_usage_rate_activations', function (Blueprint $table) {
                $table->dropForeign(['meter_key']);
                $table->dropForeign('activations_meter_rate_foreign');
            });

            Schema::table('business_usage_rate_activations', function (Blueprint $table) {
                $table->dropIndex('business_usage_rate_activations_meter_key_index');
            });

            Schema::table('business_usage_rate_activations', function (Blueprint $table) {
                $table->dropColumn('meter_key');
            });
        }
    };

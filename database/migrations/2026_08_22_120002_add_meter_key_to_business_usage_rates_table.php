<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    /**
     * RFC-005 Amendment 1 §D, Slice 1 EXPAND — purely additive nullable
     * shadow meter_key column. feature_key and its existing unique index
     * (business_usage_rates_feature_key_version_unique) are left completely
     * untouched: UsageWalletManager::setActiveRate() still reads/writes only
     * feature_key, unmodified, throughout Slice 1 and Slice 2 (RFC-005
     * Amendment 1 Slice 1 EXPAND Implementation Contract §4.B).
     */
    return new class extends Migration {
        public function up(): void
        {
            Schema::table('business_usage_rates', function (Blueprint $table) {
                $table->string('meter_key', 128)->nullable()->after('feature_key');
            });

            Schema::table('business_usage_rates', function (Blueprint $table) {
                $table->unique(['meter_key', 'version'], 'business_usage_rates_meter_key_version_unique');
                $table->unique(['meter_key', 'id'], 'business_usage_rates_meter_key_id_unique');
            });

            Schema::table('business_usage_rates', function (Blueprint $table) {
                $table->foreign(['meter_key', 'currency_id'], 'rates_meter_currency_foreign')
                    ->references(['meter_key', 'currency_id'])->on('usage_meters')
                    ->restrictOnDelete();
            });
        }

        public function down(): void
        {
            Schema::table('business_usage_rates', function (Blueprint $table) {
                $table->dropForeign('rates_meter_currency_foreign');
            });

            Schema::table('business_usage_rates', function (Blueprint $table) {
                $table->dropUnique('business_usage_rates_meter_key_version_unique');
                $table->dropUnique('business_usage_rates_meter_key_id_unique');
            });

            Schema::table('business_usage_rates', function (Blueprint $table) {
                $table->dropColumn('meter_key');
            });
        }
    };

<?php

    use App\Exceptions\Usage\UsageMeterBackfillIncompleteException;
    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;

    /**
     * RFC-005 Amendment 1 Slice 3 CONTRACT §D-4/D-5, step 20 — final
     * schema contraction for business_usage_rates. Owns the global
     * forward preflight for all three Slice 3 tables (Slice 3 CONTRACT
     * §4.1/§6): this is the first migration Laravel runs up() for in the
     * batch, so it is the only correct place for a check that must
     * complete, for every table, before any Slice 3 DDL runs anywhere.
     */
    return new class extends Migration {
        public function up(): void
        {
            foreach ([
                'business_usage_rates',
                'business_usage_rate_activations',
                'business_usage_reservations',
            ] as $table) {
                $remaining = DB::table($table)->whereNull('meter_key')->count();
                if ($remaining > 0) {
                    throw new UsageMeterBackfillIncompleteException($table, $remaining);
                }
            }

            Schema::table('business_usage_rates', function (Blueprint $table) {
                $table->string('meter_key', 128)->nullable(false)->change();
                $table->dropUnique('business_usage_rates_feature_key_version_unique');
                $table->dropColumn('feature_key');
            });
        }

        public function down(): void
        {
            // Global rollback Preflights A and B already ran in
            // business_usage_reservations' down() (Slice 3 CONTRACT
            // §4.3/§6), which Laravel executes first in a batch rollback
            // (down() runs in the reverse of up() order). This method
            // performs only this table's own restoration DDL.
            Schema::table('business_usage_rates', function (Blueprint $table) {
                $table->string('feature_key', 64)->nullable()->after('meter_key');
            });

            DB::statement(
                'UPDATE business_usage_rates r JOIN usage_meters m ON r.meter_key = m.meter_key SET r.feature_key = m.feature_key'
            );

            Schema::table('business_usage_rates', function (Blueprint $table) {
                $table->unique(['feature_key', 'version'], 'business_usage_rates_feature_key_version_unique');
            });

            Schema::table('business_usage_rates', function (Blueprint $table) {
                $table->string('feature_key', 64)->nullable(false)->change();
                $table->string('meter_key', 128)->nullable()->change();
            });
        }
    };

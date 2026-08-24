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

            // Correction Round 2: change(), dropUnique(), and dropColumn()
            // are kept in separate Schema::table() calls, matching every
            // other migration in this codebase (none of which mixes a
            // column change with a structural drop in one blueprint) —
            // combining them let Doctrine DBAL's schema-diffing generate
            // an invalid ALTER TABLE on a completely fresh, empty
            // database, breaking migrate:fresh for the entire suite
            // before any test body ever ran.
            //
            // Exceptional Correction (PR #121): rates_meter_currency_foreign
            // — the composite (meter_key, currency_id) FK to usage_meters
            // — is dropped and recreated, with its exact original name,
            // columns, and restrictOnDelete() semantics, around the bare
            // nullability change.
            //
            // Exceptional Correction 2 (PR #122): PR #121 alone was
            // proven incomplete at runtime — MySQL 1833: meter_key is
            // also the target of an INCOMING composite FK,
            // usage_meters.meters_active_rate_foreign (defined by the
            // already-merged Slice 1 migration
            // 2026_08_22_120003_add_active_rate_foreign_to_usage_meters_table.php,
            // not modified here), which must also be dropped and
            // recreated around the change() call. usage_meters' schema
            // is touched transiently, at runtime, for exactly this one
            // named foreign key — no column, row, or other constraint on
            // usage_meters is altered. business_usage_rates_meter_key_id_unique
            // (this FK's own target index) is not touched at all.
            Schema::table('usage_meters', function (Blueprint $table) {
                $table->dropForeign('meters_active_rate_foreign');
            });

            Schema::table('business_usage_rates', function (Blueprint $table) {
                $table->dropForeign('rates_meter_currency_foreign');
            });

            Schema::table('business_usage_rates', function (Blueprint $table) {
                $table->string('meter_key', 128)->nullable(false)->change();
            });

            Schema::table('business_usage_rates', function (Blueprint $table) {
                $table->foreign(['meter_key', 'currency_id'], 'rates_meter_currency_foreign')
                    ->references(['meter_key', 'currency_id'])->on('usage_meters')
                    ->restrictOnDelete();
            });

            Schema::table('usage_meters', function (Blueprint $table) {
                $table->foreign(['meter_key', 'active_rate_id'], 'meters_active_rate_foreign')
                    ->references(['meter_key', 'id'])->on('business_usage_rates')
                    ->restrictOnDelete();
            });

            Schema::table('business_usage_rates', function (Blueprint $table) {
                $table->dropUnique('business_usage_rates_feature_key_version_unique');
            });

            Schema::table('business_usage_rates', function (Blueprint $table) {
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
            });

            // Exceptional Correction (PR #121): rates_meter_currency_foreign
            // dropped and recreated, exact name/columns/semantics, around
            // the bare nullability loosen — reached only after both
            // rollback preflights (business_usage_reservations' own
            // down(), which runs first in a batch rollback) have already
            // passed.
            //
            // Exceptional Correction 2 (PR #122): the incoming
            // usage_meters.meters_active_rate_foreign is also dropped
            // and recreated here, for the same MySQL-1833 reason as
            // up() above.
            Schema::table('usage_meters', function (Blueprint $table) {
                $table->dropForeign('meters_active_rate_foreign');
            });

            Schema::table('business_usage_rates', function (Blueprint $table) {
                $table->dropForeign('rates_meter_currency_foreign');
            });

            Schema::table('business_usage_rates', function (Blueprint $table) {
                $table->string('meter_key', 128)->nullable()->change();
            });

            Schema::table('business_usage_rates', function (Blueprint $table) {
                $table->foreign(['meter_key', 'currency_id'], 'rates_meter_currency_foreign')
                    ->references(['meter_key', 'currency_id'])->on('usage_meters')
                    ->restrictOnDelete();
            });

            Schema::table('usage_meters', function (Blueprint $table) {
                $table->foreign(['meter_key', 'active_rate_id'], 'meters_active_rate_foreign')
                    ->references(['meter_key', 'id'])->on('business_usage_rates')
                    ->restrictOnDelete();
            });
        }
    };

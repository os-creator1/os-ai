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
            // Exceptional Correction 3: every incoming composite FK that
            // targets business_usage_rates(meter_key, id), plus the local
            // rates_meter_currency_foreign, must be removed before MySQL
            // can alter business_usage_rates.meter_key. The six incoming
            // constraints are recreated byte-for-byte in semantics after
            // the bare change(): exact names, local/target columns, and
            // restrictOnDelete(). No other schema object on those tables
            // is modified, and business_usage_rates_meter_key_id_unique is
            // intentionally left untouched.
            Schema::table('usage_meters', function (Blueprint $table) {
                $table->dropForeign('meters_active_rate_foreign');
            });

            Schema::table('business_usage_rate_activations', function (Blueprint $table) {
                $table->dropForeign('activations_meter_rate_foreign');
            });

            Schema::table('business_usage_reservations', function (Blueprint $table) {
                $table->dropForeign('reservations_meter_rate_foreign');
            });

            Schema::table('usage_meter_transitions', function (Blueprint $table) {
                $table->dropForeign('umt_from_rate_same_meter_foreign');
            });

            Schema::table('usage_meter_transitions', function (Blueprint $table) {
                $table->dropForeign('umt_to_rate_same_meter_foreign');
            });

            Schema::table('business_usage_ledger_entries', function (Blueprint $table) {
                $table->dropForeign('ledger_meter_rate_foreign');
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

            Schema::table('business_usage_rate_activations', function (Blueprint $table) {
                $table->foreign(['meter_key', 'rate_id'], 'activations_meter_rate_foreign')
                    ->references(['meter_key', 'id'])->on('business_usage_rates')
                    ->restrictOnDelete();
            });

            Schema::table('business_usage_reservations', function (Blueprint $table) {
                $table->foreign(['meter_key', 'rate_id'], 'reservations_meter_rate_foreign')
                    ->references(['meter_key', 'id'])->on('business_usage_rates')
                    ->restrictOnDelete();
            });

            Schema::table('usage_meter_transitions', function (Blueprint $table) {
                $table->foreign(['meter_key', 'from_active_rate_id'], 'umt_from_rate_same_meter_foreign')
                    ->references(['meter_key', 'id'])->on('business_usage_rates')
                    ->restrictOnDelete();
            });

            Schema::table('usage_meter_transitions', function (Blueprint $table) {
                $table->foreign(['meter_key', 'to_active_rate_id'], 'umt_to_rate_same_meter_foreign')
                    ->references(['meter_key', 'id'])->on('business_usage_rates')
                    ->restrictOnDelete();
            });

            Schema::table('business_usage_ledger_entries', function (Blueprint $table) {
                $table->foreign(['meter_key', 'rate_id'], 'ledger_meter_rate_foreign')
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

            // Exceptional Correction 3: rollback reaches this point only
            // after 120003's rollback preflights have passed. Drop the
            // complete six-FK incoming set and the local outgoing FK,
            // loosen meter_key in isolation, then restore every FK with
            // its exact original definition.
            Schema::table('usage_meters', function (Blueprint $table) {
                $table->dropForeign('meters_active_rate_foreign');
            });

            Schema::table('business_usage_rate_activations', function (Blueprint $table) {
                $table->dropForeign('activations_meter_rate_foreign');
            });

            Schema::table('business_usage_reservations', function (Blueprint $table) {
                $table->dropForeign('reservations_meter_rate_foreign');
            });

            Schema::table('usage_meter_transitions', function (Blueprint $table) {
                $table->dropForeign('umt_from_rate_same_meter_foreign');
            });

            Schema::table('usage_meter_transitions', function (Blueprint $table) {
                $table->dropForeign('umt_to_rate_same_meter_foreign');
            });

            Schema::table('business_usage_ledger_entries', function (Blueprint $table) {
                $table->dropForeign('ledger_meter_rate_foreign');
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

            Schema::table('business_usage_rate_activations', function (Blueprint $table) {
                $table->foreign(['meter_key', 'rate_id'], 'activations_meter_rate_foreign')
                    ->references(['meter_key', 'id'])->on('business_usage_rates')
                    ->restrictOnDelete();
            });

            Schema::table('business_usage_reservations', function (Blueprint $table) {
                $table->foreign(['meter_key', 'rate_id'], 'reservations_meter_rate_foreign')
                    ->references(['meter_key', 'id'])->on('business_usage_rates')
                    ->restrictOnDelete();
            });

            Schema::table('usage_meter_transitions', function (Blueprint $table) {
                $table->foreign(['meter_key', 'from_active_rate_id'], 'umt_from_rate_same_meter_foreign')
                    ->references(['meter_key', 'id'])->on('business_usage_rates')
                    ->restrictOnDelete();
            });

            Schema::table('usage_meter_transitions', function (Blueprint $table) {
                $table->foreign(['meter_key', 'to_active_rate_id'], 'umt_to_rate_same_meter_foreign')
                    ->references(['meter_key', 'id'])->on('business_usage_rates')
                    ->restrictOnDelete();
            });

            Schema::table('business_usage_ledger_entries', function (Blueprint $table) {
                $table->foreign(['meter_key', 'rate_id'], 'ledger_meter_rate_foreign')
                    ->references(['meter_key', 'id'])->on('business_usage_rates')
                    ->restrictOnDelete();
            });
        }
    };

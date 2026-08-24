<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;

    /**
     * RFC-005 Amendment 1 Slice 3 CONTRACT §E-3/E-4, step 21 — final
     * schema contraction for business_usage_rate_activations. No
     * preflight of its own: the global forward preflight already ran in
     * business_usage_rates' up() (migration 120001), which Laravel runs
     * first in this batch (Slice 3 CONTRACT §4.2/§6).
     */
    return new class extends Migration {
        public function up(): void
        {
            // Correction Round 2: change(), dropIndex(), and dropColumn()
            // are kept in separate Schema::table() calls, matching every
            // other migration in this codebase — combining them let
            // Doctrine DBAL's schema-diffing generate an invalid ALTER
            // TABLE on a completely fresh, empty database, breaking
            // migrate:fresh for the entire suite before any test body
            // ever ran.
            //
            // Exceptional Correction (PR #121): both FKs on meter_key —
            // the plain FK to usage_meters.meter_key (dropped/recreated
            // via the array form, exactly mirroring how Slice 1's own
            // migration 120004 drops it in its down()) and
            // activations_meter_rate_foreign — are dropped and recreated
            // around the bare nullability change, with their exact
            // original names/columns/restrictOnDelete() semantics.
            // usage_meters itself is never touched.
            Schema::table('business_usage_rate_activations', function (Blueprint $table) {
                $table->dropForeign(['meter_key']);
            });

            Schema::table('business_usage_rate_activations', function (Blueprint $table) {
                $table->dropForeign('activations_meter_rate_foreign');
            });

            Schema::table('business_usage_rate_activations', function (Blueprint $table) {
                $table->string('meter_key', 128)->nullable(false)->change();
            });

            Schema::table('business_usage_rate_activations', function (Blueprint $table) {
                $table->foreign('meter_key')->references('meter_key')->on('usage_meters')->restrictOnDelete();
            });

            Schema::table('business_usage_rate_activations', function (Blueprint $table) {
                $table->foreign(['meter_key', 'rate_id'], 'activations_meter_rate_foreign')
                    ->references(['meter_key', 'id'])->on('business_usage_rates')
                    ->restrictOnDelete();
            });

            Schema::table('business_usage_rate_activations', function (Blueprint $table) {
                $table->dropIndex('business_usage_rate_activations_feature_key_index');
            });

            Schema::table('business_usage_rate_activations', function (Blueprint $table) {
                $table->dropColumn('feature_key');
            });
        }

        public function down(): void
        {
            // Global rollback Preflights A and B already ran in
            // business_usage_reservations' down() (Slice 3 CONTRACT
            // §4.3/§6), which Laravel executes first in a batch rollback.
            // This method performs only this table's own restoration DDL.
            Schema::table('business_usage_rate_activations', function (Blueprint $table) {
                $table->string('feature_key', 64)->nullable()->after('meter_key');
            });

            DB::statement(
                'UPDATE business_usage_rate_activations a JOIN usage_meters m ON a.meter_key = m.meter_key SET a.feature_key = m.feature_key'
            );

            Schema::table('business_usage_rate_activations', function (Blueprint $table) {
                $table->index('feature_key', 'business_usage_rate_activations_feature_key_index');
            });

            Schema::table('business_usage_rate_activations', function (Blueprint $table) {
                $table->string('feature_key', 64)->nullable(false)->change();
            });

            // Exceptional Correction (PR #121): both meter_key FKs
            // dropped and recreated, exact names/columns/semantics,
            // around the bare nullability loosen — reached only after
            // both rollback preflights (business_usage_reservations'
            // own down(), which runs first in a batch rollback) have
            // already passed.
            Schema::table('business_usage_rate_activations', function (Blueprint $table) {
                $table->dropForeign(['meter_key']);
            });

            Schema::table('business_usage_rate_activations', function (Blueprint $table) {
                $table->dropForeign('activations_meter_rate_foreign');
            });

            Schema::table('business_usage_rate_activations', function (Blueprint $table) {
                $table->string('meter_key', 128)->nullable()->change();
            });

            Schema::table('business_usage_rate_activations', function (Blueprint $table) {
                $table->foreign('meter_key')->references('meter_key')->on('usage_meters')->restrictOnDelete();
            });

            Schema::table('business_usage_rate_activations', function (Blueprint $table) {
                $table->foreign(['meter_key', 'rate_id'], 'activations_meter_rate_foreign')
                    ->references(['meter_key', 'id'])->on('business_usage_rates')
                    ->restrictOnDelete();
            });
        }
    };

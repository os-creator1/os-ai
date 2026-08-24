<?php

    use App\Exceptions\Usage\UsageMeterBackfillIncompleteException;
    use App\Exceptions\Usage\UsageMeterRollbackVersionCollisionException;
    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;

    /**
     * RFC-005 Amendment 1 Slice 3 CONTRACT §F, step 22 — final meter_key
     * tightening for business_usage_reservations. feature_key is NOT
     * dropped here — it is the permanent owning-feature snapshot.
     *
     * This migration's down() owns the global rollback Preflights A and
     * B (Slice 3 CONTRACT §4.3/§6). Laravel runs a batch's down() methods
     * in the exact reverse of its up() order; since this migration
     * (_120003) is the last to run up(), it is the first to run down()
     * — the only correct place for a rollback check that must complete,
     * for both business_usage_rates and business_usage_rate_activations,
     * before either table's restoration DDL runs.
     */
    return new class extends Migration {
        public function up(): void
        {
            Schema::table('business_usage_reservations', function (Blueprint $table) {
                $table->string('meter_key', 128)->nullable(false)->change();
            });
        }

        public function down(): void
        {
            // Preflight A — meter resolution, both affected tables,
            // read-only, zero DDL.
            foreach ([
                'business_usage_rates',
                'business_usage_rate_activations',
            ] as $table) {
                $unresolved = DB::table($table)
                    ->leftJoin('usage_meters', "{$table}.meter_key", '=', 'usage_meters.meter_key')
                    ->whereNull('usage_meters.meter_key')
                    ->count();

                if ($unresolved > 0) {
                    throw new UsageMeterBackfillIncompleteException($table, $unresolved);
                }
            }

            // Preflight B — legacy-uniqueness collision, business_usage_rates
            // only, read-only, zero DDL. business_usage_rate_activations'
            // own legacy index is a plain (non-unique) index and can never
            // collide.
            $collision = DB::table('business_usage_rates')
                ->join('usage_meters', 'business_usage_rates.meter_key', '=', 'usage_meters.meter_key')
                ->select('usage_meters.feature_key', 'business_usage_rates.version', DB::raw('COUNT(*) as row_count'))
                ->groupBy('usage_meters.feature_key', 'business_usage_rates.version')
                ->havingRaw('COUNT(*) > 1')
                ->first();

            if ($collision !== null) {
                throw new UsageMeterRollbackVersionCollisionException(
                    $collision->feature_key,
                    (int) $collision->version,
                    (int) $collision->row_count,
                );
            }

            // This table's own (trivial) restoration DDL — reached only
            // once both preflights above pass.
            Schema::table('business_usage_reservations', function (Blueprint $table) {
                $table->string('meter_key', 128)->nullable()->change();
            });
        }
    };

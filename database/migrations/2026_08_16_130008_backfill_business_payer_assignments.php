<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Support\Facades\DB;

    /**
     * RFC-005 §32, M2 contract §11 migration 8 — a plain data-operation
     * migration (query-builder-only, no Eloquent model dependency,
     * mirroring M1's own classification-backfill precedent), creating a
     * business_payer_assignments row for every existing Business lacking
     * one, covering the gap for any Business created between M1's deploy
     * and M2's deploy.
     *
     * Default resolution (M2 contract §6.E, Core/Growth -> workspace,
     * Agency -> business, unassigned Workspace -> workspace) is
     * reimplemented here via a raw join, rather than calling
     * BillingProfileManager/EntitlementManager, since a migration in this
     * repository never depends on an Eloquent model or an application
     * service — the identical constraint M1's own migrations already
     * observe. This is the one narrow, explicitly authorized exception to
     * "resolve the tier via the presentation seam only" (M2 contract audit
     * item 6), which governs BillingProfileManager's own method for NEW
     * Businesses, not this immutable backfill script.
     *
     * Idempotent under rerun: a Business already assigned is never
     * re-inserted or overwritten. down() is a non-destructive no-op,
     * mirroring M1 migration 9's own established rationale.
     */
    return new class extends Migration {
        protected const CHUNK_SIZE = 500;

        public function up(): void
        {
            $created = 0;
            $lastSeenBusinessId = 0;

            while (true) {
                $businessIds = DB::table('businesses')
                    ->leftJoin('business_payer_assignments', 'business_payer_assignments.business_id', '=', 'businesses.id')
                    ->whereNull('business_payer_assignments.id')
                    ->where('businesses.id', '>', $lastSeenBusinessId)
                    ->orderBy('businesses.id')
                    ->limit(static::CHUNK_SIZE)
                    ->pluck('businesses.id');

                if ($businessIds->isEmpty()) {
                    break;
                }

                foreach ($businessIds as $businessId) {
                    $created += $this->assignDefaultPayer((int) $businessId) ? 1 : 0;
                }

                $lastSeenBusinessId = (int) $businessIds->last();
            }

            logger()->info("business_payer_assignments backfill: created {$created} row(s).");
        }

        private function assignDefaultPayer(int $businessId): bool
        {
            $now = now();

            $business = DB::table('businesses')->where('id', $businessId)->first(['id', 'workspace_id']);

            if ($business === null || $business->workspace_id === null) {
                return false;
            }

            $tier = DB::table('workspace_plan_assignments')
                ->join('workspace_plan_catalog', 'workspace_plan_catalog.id', '=', 'workspace_plan_assignments.workspace_plan_catalog_id')
                ->where('workspace_plan_assignments.workspace_id', $business->workspace_id)
                ->value('workspace_plan_catalog.tier');

            $payerType = $tier === 'agency' ? 'business' : 'workspace';

            try {
                DB::table('business_payer_assignments')->insert([
                    'business_id' => $businessId,
                    'payer_type' => $payerType,
                    'effective_payment_instrument_id' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                $driverErrorCode = (int) ($e->errorInfo[1] ?? 0);

                if ($driverErrorCode === 1062 && str_contains($e->getMessage(), 'business_payer_assignments_business_id_unique')) {
                    return false;
                }

                throw $e;
            }

            return true;
        }

        public function down(): void
        {
            // Intentionally a non-destructive no-op — a backfilled
            // business_payer_assignments row may already be read by
            // dashboard code by the time a rollback is attempted (M2
            // contract §11's own established rollback-safety rationale,
            // mirroring M1 migration 9 exactly).
        }
    };

<?php

    use App\Exceptions\Usage\UsageWalletBackfillIncompleteException;
    use App\Library\Usage\Migration\UsageWalletBackfillV1;
    use Illuminate\Database\Migrations\Migration;

    /**
     * RFC-005 §12, M1 contract §9.2 — directly instantiates and invokes
     * UsageWalletBackfillV1, never a console-command wrapper, mirroring
     * RFC-003 migration 5 / RFC-004 migration 8's exact precedent.
     *
     * A currency-resolution failure for one Business (Correction Round 1,
     * §9.2) is not swallowed here — UsageWalletBackfillV1::run() itself
     * only throws when Businesses remain genuinely unwalleted after a full
     * pass, carrying the exact failure list for operator remediation.
     */
    return new class extends Migration {
        public function up(): void
        {
            try {
                (new UsageWalletBackfillV1())->run();
            } catch (UsageWalletBackfillIncompleteException $e) {
                // Re-thrown as-is: a partial backfill is a failed
                // migration run, never a silent success. The exact
                // remaining count and currency-resolution failure list
                // are already carried on the exception itself.
                throw $e;
            }
        }

        public function down(): void
        {
            // Intentionally a non-destructive no-op — a backfilled
            // business_usage_wallets row may already be referenced by
            // business_usage_reservations/business_usage_ledger_entries
            // via the composite FK (§6.8); deleting it here would risk
            // exactly the partial-rollback failure RFC-004 migration 7's
            // own down() rationale already documents. Physical removal of
            // the table is migration 1's own down() responsibility during
            // a complete rollback.
        }
    };

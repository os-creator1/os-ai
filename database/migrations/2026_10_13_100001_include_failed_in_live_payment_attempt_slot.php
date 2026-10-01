<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payments & Invoices V1 correction — ONE canonical definition of "the live
 * attempt for a schedule item", shared by the application and the database.
 *
 * WHY. `payment_intent.payment_failed` does NOT end a PaymentIntent: Stripe
 * leaves it in `requires_payment_method` and the customer can retry the SAME
 * intent and succeed. The finalizer therefore treats a local `failed` row as
 * retryable (failed -> succeeded). The slot column however freed the item on
 * `failed`, so a customer pressing Pay again got a SECOND row and a SECOND
 * intent while the first could still be completed — two simultaneous charge
 * opportunities for one schedule item.
 *
 * WHAT. `failed` joins `created / requires_action / processing` as a LIVE
 * status. The slot is now released only by a status the provider can no longer
 * act on: `succeeded` (the item is settled) or `canceled` (the intent is dead).
 * A customer retry after a decline re-drives the same row and the same intent;
 * only a provider-confirmed cancellation lets a new attempt start.
 *
 * Nothing else about the table changes. The generated column's expression
 * cannot be altered in place portably, so it is dropped and re-added with the
 * same name, position and unique key.
 *
 * DATA SAFETY. A database that already holds a `failed` row next to a newer
 * live row for the same item (the old behavior) cannot satisfy the new key.
 * That is two possibly-completable intents for one item; deciding which one is
 * "real" is a provider question, not a migration's, so this refuses to run
 * rather than rewrite payment history.
 */
return new class extends Migration {
    public function up(): void
    {
        $conflicts = DB::table('business_document_payments')
            ->whereIn('status', ['created', 'requires_action', 'processing', 'failed'])
            ->groupBy('schedule_item_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('schedule_item_id');

        if ($conflicts->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot make `failed` a live payment attempt: schedule item(s) ['
                . $conflicts->implode(', ')
                . '] already hold more than one non-final payment row. Resolve each against the provider '
                . '(cancel or settle the extra intent) and re-run this migration.'
            );
        }

        $this->replaceSlot("CASE WHEN status IN ('created','requires_action','processing','failed') THEN schedule_item_id ELSE NULL END");
    }

    public function down(): void
    {
        // Restoring the narrower definition cannot conflict: it only ever
        // frees slots.
        $this->replaceSlot("CASE WHEN status IN ('created','requires_action','processing') THEN schedule_item_id ELSE NULL END");
    }

    private function replaceSlot(string $expression): void
    {
        Schema::table('business_document_payments', function (Blueprint $table) {
            $table->dropUnique('bdp_active_item_unique');
        });

        Schema::table('business_document_payments', function (Blueprint $table) {
            $table->dropColumn('active_schedule_item_id');
        });

        Schema::table('business_document_payments', function (Blueprint $table) use ($expression) {
            $table->unsignedBigInteger('active_schedule_item_id')
                ->nullable()
                ->storedAs($expression)
                ->after('status');
        });

        Schema::table('business_document_payments', function (Blueprint $table) {
            $table->unique('active_schedule_item_id', 'bdp_active_item_unique');
        });
    }
};

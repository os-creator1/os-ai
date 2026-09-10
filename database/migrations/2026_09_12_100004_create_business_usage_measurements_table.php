<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer Experience Slice 3 §4.2/§4.8 — an RFC-005-owned, additive,
 * generic measurement table: quantity only, never a price.
 *
 * Its sole writer is EloquentBusinessUsageMeasurementRepository, reached
 * only through UsageWalletManager::recordMeasurement() — keeping
 * UsageWalletManager the single write authority for usage-billing-adjacent
 * state, exactly as RFC-005 already requires.
 *
 * It has deliberately no relationship to business_usage_reservations,
 * business_usage_rates, business_usage_rate_activations or any ledger-entry
 * table: recording that something happened is not charging for it.
 *
 * quantity is a decimal-safe string column, following UsageWalletManager's
 * own existing ?string $estimatedQuantity convention — never a native float.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_usage_measurements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->string('feature_key', 64);
            $table->decimal('quantity', 20, 6);
            $table->string('unit', 32);
            $table->string('transport_marker', 32)->nullable();
            $table->string('idempotency_key', 191);
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();

            // Scoped by Business AND feature, for the same reason the
            // operations table's client key is scoped by Business: the key
            // is chosen by the caller, so two Businesses can legitimately
            // produce the same string, and under a global index the second
            // Business's measurement resolved to the FIRST Business's row —
            // silently attributing one tenant's usage to another and losing
            // the second measurement entirely.
            //
            // The feature is in the key too because one send can be measured
            // against more than one feature, and those measurements are not
            // duplicates of each other.
            $table->unique(
                ['business_id', 'feature_key', 'idempotency_key'],
                'bum_business_feature_idempotency_unique',
            );
            $table->index(['business_id', 'feature_key', 'occurred_at'], 'bum_business_feature_occurred_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_usage_measurements');
    }
};

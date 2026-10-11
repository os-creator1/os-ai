<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The registry of immutable Stripe Prices MotionGrove has created itself.
 *
 * A Stripe Price can never be edited, so "the Price for 79.00 USD every 28
 * days on the Growth product" is a fact that, once true, stays true. Recording
 * it means two offers at the same agreed amount share ONE Price (no duplicate
 * clutter in the owner's Stripe account), and means every Price MotionGrove
 * sells on has been created and VERIFIED by MotionGrove, not typed in by hand.
 *
 * The unique key is the commercial identity of the Price, which is what makes
 * concurrent provisioning of the same amount converge on one row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_stripe_prices', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_plan_catalog_id');
            $table->char('currency_code', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->string('interval', 8);
            $table->unsignedSmallInteger('interval_count');
            $table->string('provider_product_id', 191);
            $table->string('provider_price_id', 191)->unique();
            $table->string('purpose', 16)->default('offer');
            $table->boolean('livemode')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->foreign('workspace_plan_catalog_id', 'psp_catalog_foreign')
                ->references('id')->on('workspace_plan_catalog')->restrictOnDelete();
            $table->unique(
                ['workspace_plan_catalog_id', 'currency_code', 'amount_minor', 'interval', 'interval_count', 'livemode'],
                'psp_commercial_identity_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_stripe_prices');
    }
};

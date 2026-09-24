<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lane C §C3.2/§C3.3 — the AGENCY's own resale catalog, and its price history.
 *
 * WHY NOT `workspace_plan_catalog`. That table is the PLATFORM OWNER's
 * commercial catalog — the Core/Growth/Agency tiers we sell, at the prices we
 * set. An Agency's resale plan is the Agency's product, priced by the Agency,
 * sold to the Agency's client, billed through the Agency's Stripe account. One
 * table for both would mean an Agency editing our price list, or us silently
 * repricing their product.
 *
 * `tier` is the bridge between the two worlds: an Agency chooses the name, the
 * story and the price, but the capability set must be one the product actually
 * knows how to entitle, so every resale plan maps onto a canonical
 * WorkspacePlanTier. `agency` is refused at the application layer (§C3.2) —
 * reselling the Agency tier would let a client manage its own clients on
 * somebody else's lane-A subscription.
 *
 * PRICE CHANGES ARE VERSIONED, NEVER RETROACTIVE. `agency_saas_plan_pricing_
 * changes` is the audit of what the Agency published and when; an existing
 * subscriber keeps the snapshot taken on their own row when they bought
 * (§C3.4), so repricing a plan tomorrow cannot silently reprice them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_saas_plans', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();

            $table->unsignedBigInteger('agency_workspace_id');
            $table->unsignedBigInteger('agency_stripe_connection_id')->nullable();

            // What the CLIENT sees.
            $table->string('name', 191);
            $table->text('description')->nullable();

            // What the PRODUCT entitles. Core or Growth only.
            $table->string('tier', 20);

            // Commercial terms.
            $table->decimal('price', 16, 2)->nullable();
            $table->unsignedBigInteger('currency_id')->nullable();
            $table->char('currency_code', 3)->nullable();
            $table->string('billing_cycle', 20)->default('monthly');
            $table->boolean('trial_enabled')->default(false);
            $table->unsignedSmallInteger('trial_days')->nullable();

            // The immutable Stripe Price on the AGENCY's connected account.
            $table->string('provider_price_id', 191)->nullable();

            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();

            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();

            $table->foreign('agency_workspace_id', 'asp_agency_workspace_foreign')
                ->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('agency_stripe_connection_id', 'asp_connection_foreign')
                ->references('id')->on('agency_stripe_connections')->nullOnDelete();
            $table->foreign('currency_id', 'asp_currency_foreign')
                ->references('id')->on('currencies')->restrictOnDelete();
            $table->foreign('created_by_user_id', 'asp_created_by_foreign')
                ->references('id')->on('users')->nullOnDelete();

            $table->index(['agency_workspace_id', 'is_published'], 'asp_agency_published_index');
        });

        Schema::create('agency_saas_plan_pricing_changes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();

            $table->unsignedBigInteger('agency_saas_plan_id');
            $table->unsignedBigInteger('changed_by_user_id')->nullable();

            $table->decimal('from_price', 16, 2)->nullable();
            $table->decimal('to_price', 16, 2)->nullable();
            $table->char('from_currency_code', 3)->nullable();
            $table->char('to_currency_code', 3)->nullable();
            $table->string('from_billing_cycle', 20)->nullable();
            $table->string('to_billing_cycle', 20)->nullable();
            $table->string('from_provider_price_id', 191)->nullable();
            $table->string('to_provider_price_id', 191)->nullable();
            $table->string('reason', 500)->nullable();

            $table->timestamps();

            $table->foreign('agency_saas_plan_id', 'aspc_plan_foreign')
                ->references('id')->on('agency_saas_plans')->cascadeOnDelete();
            $table->foreign('changed_by_user_id', 'aspc_changed_by_foreign')
                ->references('id')->on('users')->nullOnDelete();

            $table->index('agency_saas_plan_id', 'aspc_plan_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_saas_plan_pricing_changes');
        Schema::dropIfExists('agency_saas_plans');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RFC-005 §17.B, M3 contract §21.1 — provider-customer ownership, exactly
 * one of business_id/workspace_id set. active_business_id/active_workspace_id
 * are generated/stored columns freeing the unique-index slot once a customer
 * is detached, so a new one may later be created for the same owner.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_provider_customers', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 16)->default('stripe');
            $table->unsignedBigInteger('business_id')->nullable();
            $table->unsignedBigInteger('workspace_id')->nullable();
            $table->string('provider_customer_id', 191);
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->restrictOnDelete();

            $table->unique(['provider', 'provider_customer_id']);
            $table->unique(['id', 'provider']);
        });

        // Generated/stored columns computed after table creation (Laravel's
        // fluent generated-column builder targets a fresh column add, not
        // create-time definition alongside a foreign key in the same
        // statement in every MySQL/Laravel version combination) — added via
        // raw statements immediately after, matching this repository's own
        // established pattern for computed columns.
        Schema::table('payment_provider_customers', function (Blueprint $table) {
            $table->unsignedBigInteger('active_business_id')
                ->nullable()
                ->storedAs("CASE WHEN status = 'active' THEN business_id ELSE NULL END")
                ->after('status');
            $table->unsignedBigInteger('active_workspace_id')
                ->nullable()
                ->storedAs("CASE WHEN status = 'active' THEN workspace_id ELSE NULL END")
                ->after('active_business_id');
        });

        Schema::table('payment_provider_customers', function (Blueprint $table) {
            $table->unique(['provider', 'active_business_id']);
            $table->unique(['provider', 'active_workspace_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_provider_customers');
    }
};

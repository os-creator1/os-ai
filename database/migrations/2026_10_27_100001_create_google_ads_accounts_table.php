<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Google Ads Module V1 contract 23 §3 — the SELECTED Google Ads customer and
 * the Business-level Ads configuration. Purpose: nothing else stores which
 * Ads customer a Business reads, its login (manager) customer id, currency,
 * time zone, budget/CPL targets or sync bookkeeping.
 *
 * One row per Business (unique business_id). The composite FK
 * (business_google_connection_id, business_id) -> business_google_connections
 * (id, business_id) means an account row can NEVER reference another
 * Business's connection, whatever the application code does.
 *
 * No token is stored here: the only authority is the encrypted refresh token
 * on business_google_connections (product = google_ads).
 *
 * login_customer_id, currency_code and time_zone are taken from the
 * freshly-derived candidate at selection time, never from a request.
 * monthly_budget_target_micros / target_cpl_micros are in the account
 * currency and are NULL until the owner sets them (NULL = "no target").
 *
 * unique (id, business_id) exists solely so every Ads child table can
 * composite-FK to this row and carry the same business_id.
 *
 * ROLLBACK WARNING: dropping this table drops the account choice; the
 * connection itself (and its token) is untouched and the owner must
 * re-select the account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_ads_accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('business_google_connection_id');
            $table->string('customer_id', 10);
            $table->string('login_customer_id', 10)->nullable();
            $table->string('descriptive_name', 191)->nullable();
            $table->char('currency_code', 3);
            $table->string('time_zone', 64);
            $table->boolean('is_test_account')->default(false);
            $table->timestamp('selected_at');
            $table->unsignedBigInteger('selected_by_user_id')->nullable();
            $table->unsignedBigInteger('monthly_budget_target_micros')->nullable();
            $table->unsignedBigInteger('target_cpl_micros')->nullable();
            $table->timestamp('last_sync_started_at')->nullable();
            $table->timestamp('last_successful_sync_at')->nullable();
            $table->date('data_through_date')->nullable();
            $table->string('last_sync_failure_code', 32)->nullable();
            $table->timestamp('sync_claimed_at')->nullable();
            $table->timestamp('manual_refresh_requested_at')->nullable();
            $table->timestamps();

            $table->foreign('selected_by_user_id', 'gads_acct_selected_by_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('business_id', 'gads_acct_business_fk')
                ->references('id')->on('businesses')->cascadeOnDelete();
            $table->foreign(['business_google_connection_id', 'business_id'], 'gads_acct_connection_business_fk')
                ->references(['id', 'business_id'])->on('business_google_connections')->cascadeOnDelete();

            $table->unique('business_id', 'gads_acct_business_unique');
            $table->unique(['id', 'business_id'], 'gads_acct_id_business_unique');
            $table->index(['business_google_connection_id', 'business_id'], 'gads_acct_connection_business_idx');
            $table->index('last_successful_sync_at', 'gads_acct_last_sync_idx');
            $table->index('selected_by_user_id', 'gads_acct_selected_by_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_ads_accounts');
    }
};

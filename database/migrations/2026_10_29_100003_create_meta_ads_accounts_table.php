<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meta Ads Module V1 contract 24 §3 / §4 / §5 — the SELECTED Meta ad account
 * and the Business-level Meta Ads configuration. Purpose: nothing else stores
 * which ad account a Business reads, its currency / time zone, the owner's
 * chosen result type, the provider-specific targets or sync bookkeeping.
 *
 * One row per Business (unique business_id). The composite FK
 * (business_meta_connection_id, business_id) -> business_meta_connections
 * (id, business_id) means an account row can NEVER reference another
 * Business's connection, whatever the application code does.
 *
 * No token is stored here: the only authority is the encrypted token on
 * business_meta_connections.
 *
 * ad_account_id is the digits-only Meta account id (the `act_` prefix is
 * stripped). currency_code, time_zone, name and account_status come from the
 * freshly derived candidate at selection time, never from a request.
 * selected_at is NULLABLE: disconnect keeps the row and its facts for history
 * but unselects the account (NULL = "no selected account").
 * selected_meta_user_id records which Meta identity the selection was made
 * under so a sync can refuse (connection_mismatch) after a re-authorisation
 * as a different Meta user.
 *
 * result_action_type is the owner-chosen result type (contract §5.2); NULL =
 * "not chosen", which makes results UNAVAILABLE rather than zero.
 * monthly_budget_target_micros / target_cost_per_result_micros are in the
 * account currency; NULL = "no target" (contract M6: provider-specific).
 *
 * unique (id, business_id) exists solely so every child table can
 * composite-FK to this row and carry the same business_id.
 *
 * ROLLBACK WARNING: dropping this table drops the account choice and the
 * targets; the connection (and its token) is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_ads_accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('business_meta_connection_id');
            $table->string('ad_account_id', 32);
            $table->string('name', 191)->nullable();
            $table->char('currency_code', 3);
            $table->string('time_zone', 64);
            $table->unsignedSmallInteger('account_status')->nullable();
            $table->string('result_action_type', 64)->nullable();
            $table->timestamp('selected_at')->nullable();
            $table->unsignedBigInteger('selected_by_user_id')->nullable();
            $table->string('selected_meta_user_id', 64)->nullable();
            $table->unsignedBigInteger('monthly_budget_target_micros')->nullable();
            $table->unsignedBigInteger('target_cost_per_result_micros')->nullable();
            $table->timestamp('last_sync_started_at')->nullable();
            $table->timestamp('last_successful_sync_at')->nullable();
            $table->date('data_through_date')->nullable();
            $table->string('last_sync_failure_code', 32)->nullable();
            $table->timestamp('sync_claimed_at')->nullable();
            $table->timestamp('manual_refresh_requested_at')->nullable();
            $table->timestamps();

            $table->foreign('selected_by_user_id', 'mads_acct_selected_by_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('business_id', 'mads_acct_business_fk')
                ->references('id')->on('businesses')->cascadeOnDelete();
            $table->foreign(['business_meta_connection_id', 'business_id'], 'mads_acct_connection_business_fk')
                ->references(['id', 'business_id'])->on('business_meta_connections')->cascadeOnDelete();

            $table->unique('business_id', 'mads_acct_business_unique');
            $table->unique(['id', 'business_id'], 'mads_acct_id_business_unique');
            $table->index(['business_meta_connection_id', 'business_id'], 'mads_acct_connection_business_idx');
            $table->index('last_successful_sync_at', 'mads_acct_last_sync_idx');
            $table->index('selected_by_user_id', 'mads_acct_selected_by_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_ads_accounts');
    }
};

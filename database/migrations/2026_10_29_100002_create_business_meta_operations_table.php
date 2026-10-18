<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meta Ads Module V1 contract 24 §3 / §5 — the Meta operation and audit
 * ledger. Purpose: the durable "an operation is about to / did call Meta"
 * record: operation key, status, ambiguity (`unknown`), deferral, actor and
 * the per-Business provider call count. It is separate from
 * business_google_operations on purpose (contract 24 M2): the Google call
 * budget sums every row of a Business, so Meta rows there would eat Google's
 * budget.
 *
 * ROLLBACK WARNING: rolling back this migration destroys the Meta audit
 * trail and the Meta call-budget history.
 *
 * business_id is denormalised, NOT NULL and carries NO foreign key (same as
 * the Google ledger): disconnecting or deleting a connection must not erase
 * the audit record of that disconnect. There is no location column; Meta has
 * no location concept.
 *
 * local_operation_key is UNIQUE and generated BEFORE any provider call.
 * provider_operation_reference is UNIQUE once set and is written only when
 * Meta actually supplied one; no synthetic value is invented.
 * provider_call_count records ACTUAL outbound requests; the Meta hourly call
 * budget sums only this table, using index (business_id, created_at).
 * index (business_id, operation_type, created_at) serves the sync circuit
 * breaker ("last N meta_ads_sync operations of this Business").
 *
 * summary and failure_classification are bounded own-vocabulary strings. No
 * token, authorisation code, raw state, raw provider payload or error text
 * may ever reach this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_meta_operations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('business_id');
            $table->string('operation_type', 40);
            $table->string('local_operation_key', 191);
            $table->string('request_fingerprint', 64)->nullable();
            $table->unsignedInteger('provider_call_count')->default(0);
            $table->string('provider_operation_reference', 191)->nullable();
            $table->string('status', 16)->default('pending');
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('summary', 255)->nullable();
            $table->string('failure_classification', 32)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('actor_user_id', 'bmo_actor_fk')->references('id')->on('users')->nullOnDelete();

            $table->unique('local_operation_key', 'bmo_local_operation_key_unique');
            $table->unique('provider_operation_reference', 'bmo_provider_reference_unique');

            $table->index(['business_id', 'created_at'], 'bmo_business_created_index');
            $table->index(['business_id', 'operation_type', 'created_at'], 'bmo_business_type_created_index');
            $table->index(['status', 'created_at'], 'bmo_status_created_index');
            $table->index('actor_user_id', 'bmo_actor_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_meta_operations');
    }
};

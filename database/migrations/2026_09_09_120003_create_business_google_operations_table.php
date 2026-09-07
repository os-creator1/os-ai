<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GBP Slice A contract §11.3 / §12 (C-6, C-7) — the safe operation and
 * audit ledger. This table IS the GBP audit trail (contract §27); no
 * separate GBP events table exists, because one would duplicate it.
 *
 * ROLLBACK WARNING (contract §29.4): rolling back this migration destroys
 * the GBP audit trail.
 *
 * business_id is deliberately denormalized, NOT NULL, and carries NO
 * foreign key to `businesses` (contract §11.3.1). Disconnect deletes the
 * connection and cascades the binding; if the ledger reached the Business
 * only through the connection, disconnect would erase the audit trail of
 * the disconnect itself.
 *
 * local_operation_key is UNIQUE and is generated BEFORE any provider call
 * (contract §24.4), exactly like
 * business_funding_attempts.local_idempotency_key.
 * provider_operation_reference is UNIQUE once populated, mirroring
 * bfa_provider_session_or_intent_reference_unique — and is written ONLY
 * when the provider actually supplied one; no synthetic value is ever
 * invented.
 *
 * summary and failure_classification are bounded, own-vocabulary strings.
 * No token, authorization code, raw state, raw provider payload, raw
 * provider error, Google Content value, street address or reviewer PII may
 * ever reach this table (contract §11.3.4, §27).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_google_operations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('business_google_location_id')->nullable();
            $table->string('operation_type', 40);
            $table->string('local_operation_key', 191);
            $table->string('request_fingerprint', 64)->nullable();
            $table->string('provider_operation_reference', 191)->nullable();
            $table->string('status', 16)->default('pending');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('summary', 255)->nullable();
            $table->string('failure_classification', 32)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique('local_operation_key', 'bgo_local_operation_key_unique');
            $table->unique('provider_operation_reference', 'bgo_provider_reference_unique');

            $table->foreign('business_google_location_id', 'bgo_location_foreign')
                ->references('id')
                ->on('business_google_locations')
                ->nullOnDelete();

            $table->index(['business_id', 'created_at'], 'bgo_business_created_index');
            $table->index(['status', 'created_at'], 'bgo_status_created_index');
            $table->index('business_google_location_id', 'bgo_location_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_google_operations');
    }
};

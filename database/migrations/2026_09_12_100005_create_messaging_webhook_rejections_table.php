<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer Experience Slice 3 §4.2/§4.6 — a bounded, minimized, retention-
 * governed security/rejection audit, distinct from both usage measurement
 * and operational transport state.
 *
 * Payload minimization by design: no raw webhook body is ever stored, only a
 * SHA-256 fingerprint plus a small set of already-low-sensitivity
 * identifiers. The table has no column capable of holding a credential.
 *
 * There is deliberately no business_id column: a row here is, by definition,
 * a case where authoritative attribution could not be established, so no
 * attribution is recorded — only two non-authoritative candidate identifiers
 * for debugging a conflicting case, never joined into conversation data.
 *
 * UNIQUE(reason, provider, payload_hash) is the idempotent-upsert key: a
 * repeat of the identical rejection increments occurrence_count rather than
 * inserting a new row, bounding growth under replay/retry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messaging_webhook_rejections', function (Blueprint $table): void {
            $table->id();
            $table->string('reason', 32);
            $table->string('provider', 32);
            $table->string('payload_hash', 64);
            $table->string('messaging_profile_id', 191)->nullable();
            $table->string('destination_number', 32)->nullable();
            $table->unsignedBigInteger('profile_resolved_identity_id')->nullable();
            $table->unsignedBigInteger('number_resolved_identity_id')->nullable();
            $table->unsignedInteger('occurrence_count')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('created_at')->nullable();

            $table->unique(['reason', 'provider', 'payload_hash'], 'mwr_reason_provider_hash_unique');
            $table->index('last_seen_at', 'mwr_last_seen_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messaging_webhook_rejections');
    }
};

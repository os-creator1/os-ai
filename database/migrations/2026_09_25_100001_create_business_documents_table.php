<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Implementation Contract 17 §5.2, Sub-slice A — the one versioned document
 * that is a Proposal, a signed Contract (the signed STATE of a proposal-kind
 * document, never a third kind) and an Invoice (§5.1). Lane B only (§4): no
 * reference to any lane-A/lane-D payment artifact.
 *
 * STAGED CIRCULAR-FK DDL (§5.3.3). `current_version_id` references
 * business_document_versions, whose business_document_id references THIS
 * table; neither can be declared inline. This migration therefore creates
 * `current_version_id` as a nullable scalar plus an index and NO foreign
 * key; 2026_09_25_100002 creates the versions table with its FK to here; and
 * 2026_09_25_100003 adds the current_version_id FK afterwards. Do not merge
 * or reorder them.
 *
 * Every fact frozen at first send (business_id, business_location_id,
 * contact_id, kind, currency_code, recipient_*_snapshot) is enforced by the
 * later canonical DocumentManager, not by DDL (§5.3.1). Lifecycle and token
 * columns are truth owned by that manager and are not mass-assignable on the
 * model.
 *
 * `business_location_id` is NOT NULL (Blueprint §18, Addendum §5): a
 * transactional document is Location-bound from creation.
 *
 * There is deliberately NO last_viewed_at column (§10: DocumentViewed is not
 * in V1 — the public GET is side-effect-free).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('business_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->foreignId('business_location_id')->constrained('business_locations')->restrictOnDelete();
            $table->foreignId('contact_id')->constrained('contacts')->restrictOnDelete();
            $table->foreignId('crm_opportunity_id')->nullable()->constrained('crm_opportunities')->nullOnDelete();
            $table->string('kind', 16);
            $table->string('status', 16)->default('draft');
            $table->boolean('requires_signature')->default(true);
            $table->string('title', 200);
            $table->char('currency_code', 3);
            // Scalar + index only; the FK is added by 2026_09_25_100003 (§5.3.3).
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->string('recipient_name_snapshot', 191)->nullable();
            $table->string('recipient_email_snapshot', 255)->nullable();
            $table->string('recipient_phone_snapshot', 32)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->string('access_token_hash')->nullable();
            $table->timestamp('access_token_expires_at')->nullable();
            $table->timestamp('access_token_rotated_at')->nullable();
            $table->timestamp('expiry_reminder_last_sent_at')->nullable();
            $table->unsignedTinyInteger('expiry_reminder_count')->default(0);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'status'], 'bd_business_status_index');
            $table->index(['business_location_id', 'status'], 'bd_location_status_index');
            $table->index('contact_id', 'bd_contact_index');
            $table->index('crm_opportunity_id', 'bd_opportunity_index');
            $table->index('current_version_id', 'bd_current_version_index');
            $table->index('expires_at', 'bd_expires_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_documents');
    }
};

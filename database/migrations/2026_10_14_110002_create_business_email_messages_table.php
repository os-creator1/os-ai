<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Business Email foundation — the canonical Business↔Contact email ledger.
 *
 * ONE ROW PER LOGICAL SEND, identified by (business_id, operation_key) under
 * a UNIQUE index. That index IS the idempotency boundary: a retried job or a
 * double-clicked form reaches the same row and never produces a second
 * provider call. The row is also the state machine (queued → sending →
 * accepted | failed | unconfirmed), so no separate operation table exists.
 *
 * `direction` exists now so the later inbound slice appends rows to the same
 * ledger instead of inventing a second history; only `outbound` is written
 * by this slice.
 *
 * LOCATION is a durable snapshot taken at send time (nullable — attributed
 * only where provable, exactly like chat_boxes/contacts). It is never
 * re-derived from the Contact later. RESTRICT on delete: audit-relevant.
 *
 * `from_email` / `to_email` are snapshots too: a reconnect to a different
 * mailbox must not rewrite who an old message was sent from.
 *
 * `failure_provider_code` is an OPERATOR-ONLY short code (an HTTP status or
 * a provider error token such as `invalid_grant`); it is never rendered to a
 * customer and never holds a message body, token or payload.
 *
 * `automation_step_run_id` is plain nullable (no FK) and is written only by
 * the later Automations slice; it is how an Automation-sent email will be
 * told apart from a person's manual send (and from a customer reply).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_email_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->onDelete('cascade');
            $table->unsignedBigInteger('location_id')->nullable();
            $table->unsignedBigInteger('business_email_account_id')->nullable();
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->string('direction', 16)->default('outbound');
            $table->string('source', 16)->default('manual');
            $table->unsignedBigInteger('automation_step_run_id')->nullable();
            $table->foreignId('sent_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('operation_key', 191);
            $table->string('provider', 16);
            $table->string('provider_message_id', 191)->nullable();
            $table->string('provider_thread_id', 191)->nullable();
            $table->string('internet_message_id', 255)->nullable();
            $table->string('from_email', 191);
            $table->string('to_email', 191);
            $table->string('subject', 255);
            $table->text('body_text');
            $table->string('status', 16)->default('queued');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->string('failure_category', 32)->nullable();
            $table->string('failure_provider_code', 64)->nullable();
            // The provider ACCEPTED the send request (not "delivered").
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'operation_key'], 'bem_business_operation_unique');
            $table->index(['business_id', 'contact_id', 'created_at'], 'bem_business_contact_created_index');
            $table->index(['business_id', 'created_at'], 'bem_business_created_index');
            $table->index(['business_id', 'status'], 'bem_business_status_index');

            $table->foreign('location_id', 'bem_location_foreign')->references('id')->on('business_locations')->restrictOnDelete();
            $table->foreign('business_email_account_id', 'bem_account_foreign')->references('id')->on('business_email_accounts')->nullOnDelete();
            $table->foreign('contact_id', 'bem_contact_foreign')->references('id')->on('contacts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_email_messages');
    }
};

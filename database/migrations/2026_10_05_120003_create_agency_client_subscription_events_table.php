<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lane C §C3.5/§C4.1 — durable intake for lane-C Connect webhook events.
 *
 * `unique(provider_event_id)` is what makes duplicate delivery a no-op at the
 * DATABASE rather than at the application's discretion. Stripe retries; a
 * second delivery of one event must be structurally incapable of charging,
 * activating or locking anything twice.
 *
 * `connected_account_id` IS RECORDED AT INTAKE, before anything is resolved.
 * These are Connect events: every body carries its own `account`, and §C4.1
 * requires that account to be proven to belong to a known Agency connection AND
 * to match the subscription the event claims to be about. Storing it on the
 * event row means the check is against durable evidence rather than against a
 * value re-read from a payload later.
 *
 * Intake is deliberately permissive and processing is deliberately strict: an
 * event we cannot place is still recorded (nullable subscription id) so it can
 * be investigated, and is then failed closed with a reason code rather than
 * guessed at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_client_subscription_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();

            // Resolved during processing; null until then.
            $table->unsignedBigInteger('agency_client_subscription_id')->nullable();
            $table->unsignedBigInteger('agency_workspace_id')->nullable();

            // The Connect account the provider says this event belongs to.
            $table->string('connected_account_id', 191)->nullable();

            $table->string('provider_event_id', 191)->unique();
            $table->string('event_type', 120);
            $table->string('provider_customer_id', 191)->nullable();
            $table->string('provider_subscription_id', 191)->nullable();

            $table->string('state', 16)->default('received');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('last_error', 120)->nullable();

            $table->longText('payload_encrypted')->nullable();
            $table->char('payload_hash', 64);
            $table->timestamp('payload_purged_at')->nullable();

            // The provider's own creation time, so out-of-order delivery can be
            // recognised rather than merely hoped against.
            $table->timestamp('provider_created_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('agency_client_subscription_id', 'acse_subscription_foreign')
                ->references('id')->on('agency_client_subscriptions')->restrictOnDelete();
            $table->foreign('agency_workspace_id', 'acse_agency_workspace_foreign')
                ->references('id')->on('workspaces')->nullOnDelete();

            $table->index(['state', 'lease_expires_at'], 'acse_state_lease_index');
            $table->index('provider_subscription_id', 'acse_provider_subscription_index');
            $table->index('connected_account_id', 'acse_connected_account_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_client_subscription_events');
    }
};

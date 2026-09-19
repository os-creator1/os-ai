<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Implementation Contract 17 §5.8, Sub-slice A — lane-B webhook ingestion.
 *
 * This is deliberately NOT payment_provider_events (lane D, §4.4): that table
 * carries lane-D wallet columns and a hardcoded platform webhook secret, so a
 * lane-B event routed there would put end-customer money into the usage-wallet
 * accounting path. This table mirrors only the PATTERN (unique provider event
 * identity, claim/lease processing, raw-body-verified intake).
 *
 * `unique(stripe_account_id, provider_event_id)` scopes event identity by the
 * event's own connected account — never global — following the codebase's own
 * corrections where caller-chosen keys collided across tenants.
 * `business_stripe_connection_id` is nullable so an event for an unrecognized
 * account is still recorded and ignored rather than lost.
 *
 * `payload_encrypted` is nullable and longText, matching lane D's
 * payment_provider_events precedent: `payload_purged_at` implies the payload
 * is cleared after retention, and Stripe event bodies can exceed a `text`
 * column. The `encrypted` cast lives on the model. `last_error` stores an
 * exception CLASS or reason code, never a provider message.
 *
 * Append/state-machine row: only `created_at`, no `updated_at` (state changes
 * are timestamped by the processing/lease/completed columns).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('business_payment_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_stripe_connection_id')->nullable();
            $table->string('stripe_account_id', 64);
            $table->string('provider_event_id', 191);
            $table->string('event_type', 120);
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
            $table->timestamp('created_at')->nullable();

            $table->foreign('business_stripe_connection_id', 'bpe_connection_foreign')
                ->references('id')->on('business_stripe_connections')->restrictOnDelete();

            $table->unique(['stripe_account_id', 'provider_event_id'], 'bpe_account_event_unique');
            $table->index(['state', 'lease_expires_at'], 'bpe_state_lease_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_payment_events');
    }
};

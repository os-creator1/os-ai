<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Implementation Contract 21 §12 — durable, replay-safe intake for lane A's
 * OWN webhook endpoint (`stripe/webhook/platform-subscriptions`).
 *
 * LANE A'S OWN TABLE, deliberately not lane B's `business_payment_events` and
 * not lane D's `payment_provider_events` (§2). The shape follows Contract 17
 * §8.2 because that claim/lease pattern is proven here, but the rows are
 * lane A's alone: a lane-A event must never be resolvable against a Business
 * document or a usage wallet, and an accident of shared storage is exactly how
 * that would happen.
 *
 * NO ACCOUNT COLUMN. Lane B scopes uniqueness by connected account because one
 * platform endpoint receives events for every connected account. Lane A has no
 * connected account at all — every event belongs to the Platform Owner's own
 * account — so `provider_event_id` is globally unique on its own, and a
 * duplicate delivery is caught by that key and answered 200 with zero
 * reprocessing.
 *
 * The raw payload is stored ENCRYPTED (the `encrypted` cast lives on the
 * model) and is purgeable. `last_error` stores an exception CLASS or a reason
 * CODE, never a provider message, because a message can carry customer
 * identifiers and request detail.
 *
 * Append/state-machine row: only `created_at`. State changes are timestamped
 * by the processing/lease/completed columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_subscription_events', function (Blueprint $table): void {
            $table->id();
            // Resolved during processing, nullable because intake happens
            // BEFORE resolution — an event we cannot place must still be
            // durably recorded rather than dropped.
            $table->unsignedBigInteger('platform_subscription_id')->nullable();
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
            // §12.6 — the provider's own creation time, so out-of-order
            // delivery can be recognised rather than merely hoped against.
            $table->timestamp('provider_created_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('platform_subscription_id', 'pse_subscription_foreign')
                ->references('id')->on('platform_subscriptions')->restrictOnDelete();

            $table->index(['state', 'lease_expires_at'], 'pse_state_lease_index');
            $table->index('provider_subscription_id', 'pse_provider_subscription_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_subscription_events');
    }
};

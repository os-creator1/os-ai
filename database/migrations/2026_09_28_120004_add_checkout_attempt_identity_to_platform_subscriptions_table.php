<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Implementation Contract 21 §7 correction — a durable CHECKOUT ATTEMPT
 * identity, distinct from the subscription's own identity.
 *
 * WHY ONE KEY WAS NOT ENOUGH. `local_idempotency_key` is
 * `platform-subscription:{uid}`, derived from the subscription row and reused
 * forever. That is exactly right for RETRYING one uncertain provider request,
 * and exactly wrong for a DELIBERATE SECOND ATTEMPT, because Stripe's
 * idempotency layer "compares incoming parameters to those of the original
 * request and errors if they're not the same". So a customer who opens Growth
 * checkout, cancels, and then picks Agency would resend the same key with a
 * different Price and be rejected by the provider.
 *
 * The naive fix — a fresh random key per click — is worse: it can leave two
 * simultaneously payable Checkout Sessions for one Workspace, and therefore
 * two subscriptions.
 *
 * So an ATTEMPT is modelled durably:
 *
 *   `checkout_attempt_uid`      the attempt's own identity; the provider
 *                               idempotency key is derived from it
 *   `checkout_attempt_price_id` the Price this attempt was opened for, which
 *                               is how "same request" is distinguished from
 *                               "different request" without asking Stripe
 *   `checkout_attempt_started_at` when it was minted, for operator visibility
 *
 * A NEW attempt is only ever minted after the previous session has been proven
 * terminal or has been explicitly expired at the provider, so at most one
 * payable session exists per Workspace at any moment.
 *
 * `local_idempotency_key` is KEPT and unchanged: it remains the subscription's
 * durable identity, and the attempt key is derived from it plus the attempt
 * uid, so an attempt key can never collide across Workspaces.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_subscriptions', function (Blueprint $table): void {
            $table->uuid('checkout_attempt_uid')->nullable()->after('provider_checkout_session_id');
            $table->string('checkout_attempt_price_id', 191)->nullable()->after('checkout_attempt_uid');
            $table->timestamp('checkout_attempt_started_at')->nullable()->after('checkout_attempt_price_id');

            $table->index('checkout_attempt_uid', 'ps_checkout_attempt_index');
        });
    }

    public function down(): void
    {
        Schema::table('platform_subscriptions', function (Blueprint $table): void {
            $table->dropIndex('ps_checkout_attempt_index');
            $table->dropColumn(['checkout_attempt_uid', 'checkout_attempt_price_id', 'checkout_attempt_started_at']);
        });
    }
};

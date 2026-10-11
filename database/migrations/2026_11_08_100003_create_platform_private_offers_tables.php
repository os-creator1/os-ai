<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Private Offers: special terms for ONE customer, built on the existing lane-A
 * platform-subscription infrastructure (no second subscription system).
 *
 *   platform_private_offers             what the Platform Owner created.
 *   platform_private_offer_redemptions  one customer's acceptance of it, with
 *                                       the EXACT terms they accepted frozen.
 *   platform_trial_claims               who has had a free trial, so a trial
 *                                       cannot be repeated across cancelled
 *                                       Checkouts, resubscriptions or aliases.
 *   platform_subscription_term_snapshots  append-only history of every set of
 *                                       commercial terms a subscription has
 *                                       been on.
 *
 * The link token is NEVER stored: only its SHA-256. A database read therefore
 * cannot reconstruct a working link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_private_offers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            // new_subscription: a customer subscribes through Stripe Checkout.
            // billing_change:   an EXISTING subscription moves onto agreed terms.
            $table->string('kind', 24)->default('new_subscription');
            $table->string('label', 120);

            $table->unsignedBigInteger('workspace_plan_catalog_id');
            $table->char('token_hash', 64)->unique();
            $table->timestamp('token_rotated_at')->nullable();

            // Who may redeem it. At least one is always set (enforced in the
            // manager); when both are, BOTH must hold.
            $table->string('customer_email', 191)->nullable();
            $table->unsignedBigInteger('target_workspace_id')->nullable();

            // The commercial terms, as entered by the owner.
            $table->unsignedSmallInteger('trial_days')->nullable();
            $table->unsignedBigInteger('currency_id');
            $table->decimal('custom_price', 16, 2)->nullable();
            $table->string('discount_type', 8)->nullable();          // fixed | percent
            $table->decimal('discount_value', 16, 2)->nullable();
            $table->string('discount_duration', 8)->nullable();      // forever | once | periods
            $table->unsignedSmallInteger('discount_periods')->nullable();

            $table->timestamp('expires_at');
            $table->unsignedSmallInteger('redemption_limit')->default(1);

            $table->unsignedBigInteger('created_by_user_id');
            $table->string('created_reason', 500);
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by_user_id')->nullable();
            $table->string('revoked_reason', 500)->nullable();
            $table->timestamps();

            $table->foreign('workspace_plan_catalog_id', 'ppo_catalog_foreign')
                ->references('id')->on('workspace_plan_catalog')->restrictOnDelete();
            $table->foreign('currency_id', 'ppo_currency_foreign')
                ->references('id')->on('currencies')->restrictOnDelete();
            $table->foreign('target_workspace_id', 'ppo_workspace_foreign')
                ->references('id')->on('workspaces')->restrictOnDelete();
            $table->foreign('created_by_user_id', 'ppo_creator_foreign')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('revoked_by_user_id', 'ppo_revoker_foreign')
                ->references('id')->on('users')->restrictOnDelete();
            $table->index('customer_email', 'ppo_customer_email_index');
            $table->index(['target_workspace_id', 'kind'], 'ppo_workspace_kind_index');
            $table->index('expires_at', 'ppo_expires_index');
        });

        Schema::create('platform_private_offer_redemptions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('platform_private_offer_id');
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('actor_user_id');

            // reserved: accepted, payment/application in progress
            // redeemed: provider-confirmed
            // released: abandoned, expired, revoked before payment, or superseded
            $table->string('state', 12)->default('reserved');
            // Set while the row occupies a slot; NULL once released. The unique
            // key below is what makes "one claim per Workspace per offer" a
            // property of the schema rather than of a timing window.
            $table->string('active_slot', 64)->nullable();

            $table->json('terms_snapshot');
            $table->char('terms_hash', 64);
            $table->timestamp('accepted_at');
            $table->string('accepted_email', 191);
            $table->timestamp('reserved_until');

            $table->unsignedBigInteger('platform_subscription_id')->nullable();
            $table->string('provider_checkout_session_id', 191)->nullable();
            $table->string('provider_subscription_id', 191)->nullable();
            $table->string('initial_price_id', 191)->nullable();
            $table->string('standard_price_id', 191)->nullable();

            $table->timestamp('promo_ends_at')->nullable();
            // none: no later phase | pending: provider still to be told |
            // applied: confirmed on the provider | failed: needs attention
            $table->string('schedule_state', 12)->default('none');
            $table->string('provider_schedule_id', 191)->nullable();
            $table->string('reconcile_state', 12)->default('ok');       // ok | drift
            $table->timestamp('last_reconciled_at')->nullable();
            $table->boolean('trial_granted')->default(false);

            $table->timestamp('redeemed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->string('release_reason', 64)->nullable();
            $table->timestamps();

            $table->foreign('platform_private_offer_id', 'ppor_offer_foreign')
                ->references('id')->on('platform_private_offers')->restrictOnDelete();
            $table->foreign('workspace_id', 'ppor_workspace_foreign')
                ->references('id')->on('workspaces')->restrictOnDelete();
            $table->foreign('actor_user_id', 'ppor_actor_foreign')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('platform_subscription_id', 'ppor_subscription_foreign')
                ->references('id')->on('platform_subscriptions')->nullOnDelete();
            $table->unique(['platform_private_offer_id', 'active_slot'], 'ppor_active_slot_unique');
            $table->index('provider_checkout_session_id', 'ppor_checkout_index');
            $table->index('provider_subscription_id', 'ppor_subscription_id_index');
            $table->index(['state', 'reserved_until'], 'ppor_state_reserved_index');
            $table->index(['schedule_state'], 'ppor_schedule_state_index');
        });

        Schema::create('platform_trial_claims', function (Blueprint $table): void {
            $table->id();
            // The three identities a free trial must not be repeatable across.
            // Each is unique on its own; NULLs never collide.
            $table->unsignedBigInteger('workspace_id')->nullable();
            $table->string('email_key', 191)->nullable();
            $table->string('provider_customer_id', 191)->nullable();

            $table->string('state', 10)->default('reserved');          // reserved | granted
            $table->string('source', 16);                              // public | offer
            $table->unsignedBigInteger('redemption_id')->nullable();
            $table->timestamp('reserved_until')->nullable();
            $table->timestamp('granted_at')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id', 'ptc_workspace_foreign')
                ->references('id')->on('workspaces')->restrictOnDelete();
            $table->unique('workspace_id', 'ptc_workspace_unique');
            $table->unique('email_key', 'ptc_email_unique');
            $table->unique('provider_customer_id', 'ptc_customer_unique');
        });

        Schema::create('platform_subscription_term_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('platform_subscription_id');
            $table->unsignedBigInteger('redemption_id')->nullable();
            // checkout_started | checkout_confirmed | plan_change | offer_redeemed
            // | promotion_ended | billing_change | resubscribe
            $table->string('source', 32);
            $table->json('terms');
            $table->timestamp('effective_at');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('platform_subscription_id', 'psts_subscription_foreign')
                ->references('id')->on('platform_subscriptions')->cascadeOnDelete();
            $table->index(['platform_subscription_id', 'id'], 'psts_subscription_index');
        });

        Schema::table('platform_subscriptions', function (Blueprint $table): void {
            // The private-offer agreement this subscription currently sits on.
            $table->unsignedBigInteger('offer_redemption_id')->nullable()->after('pending_effective_at');
            $table->string('provider_schedule_id', 191)->nullable()->after('offer_redemption_id');
            // What the open Checkout attempt was built from (price + trial +
            // offer), so a DIFFERENT set of terms can never reuse an attempt —
            // and an idempotency key — that was spent on other parameters.
            $table->char('checkout_attempt_terms_hash', 64)->nullable()->after('checkout_attempt_price_id');

            $table->foreign('offer_redemption_id', 'ps_offer_redemption_foreign')
                ->references('id')->on('platform_private_offer_redemptions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('platform_subscriptions', function (Blueprint $table): void {
            $table->dropForeign('ps_offer_redemption_foreign');
            $table->dropColumn(['offer_redemption_id', 'provider_schedule_id', 'checkout_attempt_terms_hash']);
        });

        Schema::dropIfExists('platform_subscription_term_snapshots');
        Schema::dropIfExists('platform_trial_claims');
        Schema::dropIfExists('platform_private_offer_redemptions');
        Schema::dropIfExists('platform_private_offers');
    }
};

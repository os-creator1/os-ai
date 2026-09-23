<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Implementation Contract 21 §7/§10 corrections — three durable facts the
 * previous shape could not express.
 *
 * 1. `checkout_attempt_generation` — a COMPARE-AND-SWAP token for checkout
 *    attempt replacement.
 *
 *    Locking the row inside the mint did not make replacement concurrency
 *    safe, because the DECISION was taken earlier, against provider state read
 *    outside the lock. Two concurrent requests could both inspect the same old
 *    session, both expire it, and both mint an attempt — leaving two payable
 *    Checkout Sessions and therefore two possible subscriptions, which is
 *    exactly what §7 says cannot happen. A monotonically advancing generation
 *    lets a writer prove the world it inspected is still the world it is
 *    writing to, without holding a transaction across a Stripe call.
 *
 * 2. `pending_*` — a DURABLE PLAN-CHANGE OPERATION, persisted BEFORE the
 *    provider is touched.
 *
 *    An immediate upgrade previously changed the Price at Stripe and only then
 *    wrote locally. A lost response or a local exception in between left the
 *    customer PAYING for Growth while RECEIVING Core, with nothing to repair
 *    it: the webhook finalizer mirrors provider price and status but has no
 *    idea which local tier that was supposed to mean. Recording the intended
 *    target first turns that gap into a convergent operation both the
 *    synchronous response and a later webhook can finish, exactly once.
 *
 *    `pending_operation_uid` is also the provider idempotency key's source.
 *    Keying an upgrade on the target CATALOG id was wrong: the same catalog can
 *    later carry a different immutable Stripe Price, and Stripe errors when one
 *    key is reused with different parameters.
 *
 *    The `pending_price_snapshot` / `_currency_*` / `_billing_cycle` columns
 *    also fix a commercial defect in scheduled downgrades: the terms are now
 *    captured when the customer REQUESTS the change, so someone who scheduled
 *    a 97.00 Core plan is not silently moved onto a repriced 147.00 Core when
 *    the period boundary arrives weeks later.
 *
 * 3. `retired_provider_subscription_ids` — the audit trail that makes
 *    re-subscribing safe.
 *
 *    One row per Workspace means a customer who cancels and later returns
 *    reuses that row. Without recording which provider subscriptions it has
 *    finished with, a late event from the OLD subscription could resolve
 *    through our own metadata and take ownership of the NEW relationship.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_subscriptions', function (Blueprint $table): void {
            $table->unsignedInteger('checkout_attempt_generation')->default(0)->after('checkout_attempt_started_at');

            $table->uuid('pending_operation_uid')->nullable()->after('pending_effective_at');
            $table->string('pending_kind', 16)->nullable()->after('pending_operation_uid');
            $table->string('pending_price_id', 191)->nullable()->after('pending_kind');
            $table->decimal('pending_price_snapshot', 16, 2)->nullable()->after('pending_price_id');
            $table->unsignedBigInteger('pending_currency_id')->nullable()->after('pending_price_snapshot');
            $table->char('pending_currency_code', 3)->nullable()->after('pending_currency_id');
            $table->string('pending_billing_cycle', 20)->nullable()->after('pending_currency_code');

            $table->json('retired_provider_subscription_ids')->nullable()->after('provider_subscription_id');

            $table->foreign('pending_currency_id', 'ps_pending_currency_foreign')
                ->references('id')->on('currencies')->restrictOnDelete();

            $table->index('pending_operation_uid', 'ps_pending_operation_index');
        });
    }

    public function down(): void
    {
        Schema::table('platform_subscriptions', function (Blueprint $table): void {
            $table->dropForeign('ps_pending_currency_foreign');
            $table->dropIndex('ps_pending_operation_index');
            $table->dropColumn([
                'checkout_attempt_generation',
                'pending_operation_uid',
                'pending_kind',
                'pending_price_id',
                'pending_price_snapshot',
                'pending_currency_id',
                'pending_currency_code',
                'pending_billing_cycle',
                'retired_provider_subscription_ids',
            ]);
        });
    }
};

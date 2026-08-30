<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * RFC-005 Remediation #6 §3/§21 — for AutoRecharge, the existing
 * provider_session_or_intent_reference already IS the PaymentIntent
 * reference (RFC-005 §11), so it can be copied directly with no provider
 * call. ManualTopUp/AddonPurchase attempts are Checkout-Session-backed —
 * provider_session_or_intent_reference is a Session id there, never a
 * PaymentIntent id, so this backfill never touches them.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('business_funding_attempts')
            ->where('purpose', 'auto_recharge')
            ->whereNotNull('provider_session_or_intent_reference')
            ->whereNull('provider_payment_intent_reference')
            ->update([
                'provider_payment_intent_reference' => DB::raw('provider_session_or_intent_reference'),
            ]);
    }

    public function down(): void
    {
        // Intentionally irreversible — reverting would discard a backfill
        // that is itself an exact, lossless copy of already-existing data.
    }
};

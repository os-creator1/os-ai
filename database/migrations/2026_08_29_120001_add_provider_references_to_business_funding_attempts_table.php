<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RFC-005 Remediation #6 §3 — two independent, nullable, unique-when-
 * populated provider references, so a refund/dispute/refund-object
 * webhook can resolve unambiguously to exactly one local funding attempt
 * without a new provider round-trip.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_funding_attempts', function (Blueprint $table) {
            $table->string('provider_payment_intent_reference', 191)->nullable()->after('provider_session_or_intent_reference');
            $table->string('provider_charge_reference', 191)->nullable()->after('provider_payment_intent_reference');

            $table->unique('provider_payment_intent_reference', 'bfa_provider_payment_intent_reference_unique');
            $table->unique('provider_charge_reference', 'bfa_provider_charge_reference_unique');
        });
    }

    public function down(): void
    {
        Schema::table('business_funding_attempts', function (Blueprint $table) {
            $table->dropUnique('bfa_provider_payment_intent_reference_unique');
            $table->dropUnique('bfa_provider_charge_reference_unique');
            $table->dropColumn(['provider_payment_intent_reference', 'provider_charge_reference']);
        });
    }
};

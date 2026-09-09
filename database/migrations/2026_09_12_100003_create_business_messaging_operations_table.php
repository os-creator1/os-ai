<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer Experience Slice 3 §4.2 — operational transport state only.
 *
 * Explicitly non-financial: no wallet balance, retail amount, debit/credit,
 * rate, reservation, payer, invoice or spending-cap state, no webhook
 * rejection detail, and no measurement quantity. It answers exactly one
 * question — did this send/receive happen, was it accepted, what is its
 * delivery state, and how do we find it again.
 *
 * Both unique indexes are ordinary MySQL UNIQUE indexes, not generated-column
 * guards (§4.2, corrected Round 3): neither column carries a *conditional*
 * uniqueness rule, and MySQL already treats each NULL as distinct, so a
 * nullable column under a plain UNIQUE index permits unlimited NULLs while
 * rejecting duplicate non-null values. Adding a computed column here would
 * encode a condition that does not exist.
 *
 * UNIQUE(provider, provider_message_id) is what makes locating the one
 * correct operation race-free for a delivery-status callback; its mere
 * existence is never read as "this is a replay" (§4.6.3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_messaging_operations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('business_messaging_identity_id')->nullable();
            $table->string('transport_mode', 16);
            $table->string('provider', 32);
            $table->string('direction', 16);
            $table->string('message_type', 16);
            $table->string('operation_key', 191)->nullable();
            $table->string('provider_message_id', 191)->nullable();
            $table->string('status', 16);
            $table->string('error_category', 32)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();
            $table->foreign('business_messaging_identity_id', 'bmo_identity_foreign')
                ->references('id')->on('business_messaging_identities')->nullOnDelete();

            $table->unique('operation_key', 'bmo_operation_key_unique');
            $table->unique(['provider', 'provider_message_id'], 'bmo_provider_message_id_unique');
            $table->index(['business_id', 'occurred_at'], 'bmo_business_occurred_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_messaging_operations');
    }
};

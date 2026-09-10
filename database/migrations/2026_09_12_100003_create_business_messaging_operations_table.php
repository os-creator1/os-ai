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

            // The durable operation-to-Report correlation.
            //
            // A delivery callback has to update the customer-visible record
            // as well as the operation row, and the only alternative to a
            // real foreign key here is guessing by (Business, phone number,
            // recent-ish timestamp) — which is ambiguous the moment a
            // Business sends the same recipient twice, and is exactly the
            // "ambiguous Business/phone lookup" this must not use.
            //
            // Nullable because an INBOUND operation has no outbound report,
            // and because an outbound row is written before the legacy
            // persistence layer returns its Reports row.
            $table->unsignedBigInteger('report_id')->nullable();

            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();
            $table->foreign('business_messaging_identity_id', 'bmo_identity_foreign')
                ->references('id')->on('business_messaging_identities')->nullOnDelete();
            $table->foreign('report_id', 'bmo_report_foreign')
                ->references('id')->on('reports')->nullOnDelete();

            // CLIENT IDEMPOTENCY IS SCOPED BY BUSINESS.
            //
            // `operation_key` was globally unique. The key is chosen by the
            // CALLER — a campaign id and recipient, or a client-supplied
            // token — so two Businesses can legitimately produce the same
            // string. Under a global index the second Business's send either
            // collided outright or, worse, resolved to the FIRST Business's
            // recorded row and returned its provider message id: one tenant
            // reading another's send. Scoping by business_id makes the key
            // mean what its callers always assumed it meant.
            $table->unique(['business_id', 'operation_key'], 'bmo_business_operation_key_unique');

            // …but provider attribution stays GLOBAL, deliberately.
            //
            // A webhook arrives carrying only a provider message id. If that
            // pair were scoped per Business it could match several rows and
            // attribution would become ambiguous — the opposite of what
            // §4.6 requires. The provider's own identifier is globally
            // unique in the provider's namespace, and this index keeps it
            // globally unique here.
            $table->unique(['provider', 'provider_message_id'], 'bmo_provider_message_id_unique');
            $table->index(['business_id', 'occurred_at'], 'bmo_business_occurred_index');
            $table->index('report_id', 'bmo_report_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_messaging_operations');
    }
};

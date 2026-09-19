<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Implementation Contract 17 §5.5, Sub-slice A — first-party, provider-neutral
 * TYPED electronic-signature evidence. Write-once: `created_at` only.
 *
 * This is a TECHNICAL signing record (§6.5): one signer, no countersignature,
 * bound to an exact immutable issued version and its content hash. It makes
 * no legal-sufficiency claim and is never to be described as a qualified,
 * advanced or identity-verified signature. `unique(business_document_id)` is
 * the V1 one-signer rule; a countersignature outcome would cost this key
 * (named in §6.5 so the cost is visible).
 *
 * `signer_name`/`signer_email` are signer-ENTERED evidence and may
 * legitimately differ from the document's recipient snapshot.
 * `consent_statement` is stored VERBATIM with its hash — never by reference
 * to a template that could later change.
 *
 * Both FKs are RESTRICT: signature evidence must never silently disappear.
 * Nothing here executes signing; that is Sub-slice C.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('business_document_signatures', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('business_document_id');
            $table->unsignedBigInteger('business_document_version_id');
            $table->char('signed_content_hash', 64);
            $table->string('signer_name', 160);
            $table->string('signer_email', 255);
            $table->string('typed_name', 160);
            $table->string('signature_method', 16)->default('typed');
            $table->text('consent_statement');
            $table->char('consent_statement_hash', 64);
            $table->string('ip_address', 45);
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('signed_at');
            $table->timestamp('created_at')->nullable();

            $table->foreign('business_document_id', 'bdsig_document_foreign')
                ->references('id')->on('business_documents')->restrictOnDelete();
            $table->foreign('business_document_version_id', 'bdsig_version_foreign')
                ->references('id')->on('business_document_versions')->restrictOnDelete();

            $table->unique('business_document_id', 'bdsig_document_unique');
            $table->index('business_document_version_id', 'bdsig_version_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_document_signatures');
    }
};

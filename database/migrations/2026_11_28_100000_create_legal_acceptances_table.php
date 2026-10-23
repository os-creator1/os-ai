<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only evidence that a person affirmatively accepted an exact version of a legal document.
 *
 * One row per (person, document version, context). It records WHAT was accepted (slug, version, sha256 of the
 * canonical text), WHO (user, plus an email snapshot), WHERE (workspace/business and the acceptance context), WHEN, and
 * against which private offer (if any). There are deliberately no foreign keys: an account deletion must not silently
 * erase or block evidence, and the row stays meaningful on its own. Rows are never updated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_acceptances', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('user_email', 255);
            $table->unsignedBigInteger('workspace_id')->nullable()->index();
            $table->unsignedBigInteger('business_id')->nullable();
            $table->string('document_slug', 64);
            $table->string('document_version', 64);
            $table->char('content_hash', 64);
            // signup_checkout | agency_signup_checkout | private_offer | ...
            $table->string('context', 48);
            // The private offer (PlatformPrivateOffer.uid) the acceptance belongs to, when there is one.
            $table->string('offer_uid', 64)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('accepted_at');
            $table->timestamps();

            $table->index(['document_slug', 'document_version'], 'legal_acceptances_document_idx');
            $table->index(['workspace_id', 'document_slug'], 'legal_acceptances_workspace_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_acceptances');
    }
};

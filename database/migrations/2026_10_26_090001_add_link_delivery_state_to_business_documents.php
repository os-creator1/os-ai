<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Implementation Contract 17 §7.1/§11.3, V1 completion — the minimum durable
 * state needed to make a failed link email RETRYABLE and HONEST.
 *
 * `status = sent` / `sent_at` record that the draft was frozen into an issued
 * version and a secure link was minted. They cannot record that the email
 * carrying that link was actually handed to the mail provider: delivery runs
 * after commit on a queue (SendDocumentLinkEmail), and the plaintext token
 * exists only inside that one job. Without a durable marker a provider outage
 * leaves a document that reads "sent" while the customer holds no link, and
 * nothing the owner can see says so.
 *
 *   link_delivered_at        the CURRENT link's email reached the provider.
 *   link_delivery_failed_at  the CURRENT link's email failed; the owner must
 *                            re-send it (DocumentManager::resendLink()).
 *
 * Both describe the current link only: rotating the token (re-send, or a new
 * version) clears both before the new email is queued. Neither is a viewed /
 * opened marker (§10) — they say nothing about the recipient.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('business_documents', function (Blueprint $table): void {
            $table->timestamp('link_delivered_at')->nullable()->after('access_token_rotated_at');
            $table->timestamp('link_delivery_failed_at')->nullable()->after('link_delivered_at');
        });
    }

    public function down(): void
    {
        Schema::table('business_documents', function (Blueprint $table): void {
            $table->dropColumn(['link_delivered_at', 'link_delivery_failed_at']);
        });
    }
};

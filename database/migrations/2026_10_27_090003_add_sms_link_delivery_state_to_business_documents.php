<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Implementation Contract 17B §7 — per-channel send outcome.
 *
 * link_delivered_at / link_delivery_failed_at already record the EMAIL
 * channel of the current link. The send dialog can now also deliver the same
 * link by SMS (through CampaignRepository::quickSend); the two channels are
 * independent attempts of ONE send, so the SMS outcome needs its own markers
 * or a failed text would be invisible behind a successful email (and vice
 * versa). Like their email siblings they describe the CURRENT link only and
 * are cleared whenever the token rotates.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('business_documents', function (Blueprint $table): void {
            $table->timestamp('sms_link_delivered_at')->nullable()->after('link_delivery_failed_at');
            $table->timestamp('sms_link_delivery_failed_at')->nullable()->after('sms_link_delivered_at');
        });
    }

    public function down(): void
    {
        Schema::table('business_documents', function (Blueprint $table): void {
            $table->dropColumn(['sms_link_delivered_at', 'sms_link_delivery_failed_at']);
        });
    }
};

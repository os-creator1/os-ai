<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A BYO operation has no managed identity, so the number it was sent from and
 * the channel that carried it are recorded on the operation itself. That keeps
 * a message's history attached to the sender it actually used, however the
 * agency's default sender is changed later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_messaging_operations', function (Blueprint $table): void {
            $table->string('sender_number', 32)->nullable()->after('provider_message_id');
            $table->unsignedBigInteger('agency_prospecting_channel_id')->nullable()->after('sender_number');
            $table->foreign('agency_prospecting_channel_id', 'bmo_agency_channel_foreign')
                ->references('id')->on('agency_prospecting_channels')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('business_messaging_operations', function (Blueprint $table): void {
            $table->dropForeign('bmo_agency_channel_foreign');
            $table->dropColumn(['sender_number', 'agency_prospecting_channel_id']);
        });
    }
};

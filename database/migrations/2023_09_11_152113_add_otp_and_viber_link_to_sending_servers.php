<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sending_servers', function (Blueprint $table) {
            $table->longText('voice_api_link')->after('api_link')->nullable();
            $table->longText('mms_api_link')->after('voice_api_link')->nullable();
            $table->longText('whatsapp_api_link')->after('mms_api_link')->nullable();
            $table->longText('viber_api_link')->after('whatsapp_api_link')->nullable();
            $table->longText('otp_api_link')->after('viber_api_link')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sending_servers', function (Blueprint $table) {
            $table->dropColumn('voice_api_link');
            $table->dropColumn('viber_api_link');
            $table->dropColumn('mms_api_link');
            $table->dropColumn('whatsapp_api_link');
            $table->dropColumn('otp_api_link');
        });
    }
};

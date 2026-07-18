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
        Schema::table('tracking_logs', function (Blueprint $table) {
            $table->integer('sms_count')->after('status')->nullable();
            $table->string('cost')->after('sms_count')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tracking_logs', function (Blueprint $table) {
            $table->dropColumn('cost');
            $table->dropColumn('sms_count');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('chat_boxes', function (Blueprint $table) {
            $table->boolean('reply_by_customer')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chat_boxes', function (Blueprint $table) {
            $table->dropColumn('reply_by_customer');
        });
    }
};

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
        Schema::table('chat_boxes', function (Blueprint $table) {
            $table->index('reply_by_customer');
            $table->index('updated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chat_boxes', function (Blueprint $table) {
            $table->dropIndex(config('database.connections.mysql.prefix').'chat_boxes_reply_by_customer_index');
            $table->dropIndex(config('database.connections.mysql.prefix').'chat_boxes_updated_at_index');
        });
    }
};

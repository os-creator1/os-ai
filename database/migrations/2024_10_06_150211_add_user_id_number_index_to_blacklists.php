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
        Schema::table('blacklists', function (Blueprint $table) {
            // Adding a composite index on 'user_id' and 'number'
            $table->index(['user_id', 'number'], 'idx_user_id_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('blacklists', function (Blueprint $table) {
            // Drop foreign key first (adjust the name if different)
            $table->dropForeign(['user_id']);

            // Now drop the index safely
            $table->dropIndex('idx_user_id_number');
        });
    }

};

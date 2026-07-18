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
        Schema::table('campaigns', function (Blueprint $table) {
            $table->unsignedBigInteger('sending_server_id')->after('user_id')->nullable();
            $table->text('last_error')->nullable();

            $table->foreign('sending_server_id')->references('id')->on('sending_servers')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropForeign(config('database.connections.mysql.prefix').'campaigns_sending_server_id_foreign');
            $table->dropColumn('sending_server_id');
            $table->dropColumn('last_error');
        });
    }
};

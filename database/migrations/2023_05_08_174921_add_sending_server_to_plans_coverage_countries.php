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
        Schema::table('plans_coverage_countries', function (Blueprint $table) {
            $table->unsignedBigInteger('sending_server')->nullable();
            // foreign
            $table->foreign('sending_server')->references('id')->on('sending_servers')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('plans_coverage_countries', function (Blueprint $table) {
            $table->dropForeign(config('database.connections.mysql.prefix').'plans_coverage_countries_sending_server_foreign');
            $table->dropColumn('sending_server');
        });
    }
};

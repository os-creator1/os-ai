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
        if ( ! Schema::hasColumn('automations', 'sending_server_id')) {
            Schema::table('automations', function (Blueprint $table) {
                $table->unsignedBigInteger('sending_server_id')->after('contact_list_id')->nullable();

                $table->foreign('sending_server_id')->references('id')->on('sending_servers')->onDelete('cascade');
            });
        }


    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('automations', function (Blueprint $table) {
            //
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('tracking_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('automation_id')->after('campaign_id')->nullable();

            $table->foreign('automation_id')->references('id')->on('automations')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('tracking_logs', function (Blueprint $table) {
            $table->dropForeign(['automation_id']);
            $table->dropColumn('automation_id');
        });
    }
};

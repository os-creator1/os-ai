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
        Schema::table('templates', function (Blueprint $table) {
            $table->string('sender_id')->nullable();
            $table->string('dlt_template_id')->nullable();
            $table->string('dlt_category')->nullable();
            $table->enum('approved', ['approved', 'block', 'in_review'])->default('in_review');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('templates', function (Blueprint $table) {
            $table->dropColumn('sender_id');
            $table->dropColumn('dlt_template_id');
            $table->dropColumn('dlt_category');
            $table->dropColumn('approved');
        });
    }
};

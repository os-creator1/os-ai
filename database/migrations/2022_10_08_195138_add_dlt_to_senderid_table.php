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
        Schema::table('senderid', function (Blueprint $table) {
            $table->text('description')->nullable();
            $table->string('entity_id')->nullable();
            $table->text('document')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('senderid', function (Blueprint $table) {
            $table->dropColumn('description');
            $table->dropColumn('entity_id');
            $table->dropColumn('document');
        });
    }
};

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
        Schema::create('automations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid');
            $table->string('name');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('contact_list_id');
            $table->unsignedBigInteger('sending_server_id');
            $table->string('timezone')->nullable();
            $table->longText('sender_id')->nullable();
            $table->longText('message')->nullable();
            $table->longText('media_url')->nullable();
            $table->string('language', 20)->nullable();
            $table->string('gender', 10)->nullable();
            $table->string('sms_type', 15);
            $table->string('status')->nullable();
            $table->text('reason')->nullable();
            $table->text('cache')->nullable();
            $table->longText('data');
            $table->integer('running_pid')->nullable();
            $table->string('dlt_template_id')->nullable();

            $table->timestamps();


            // foreign
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('contact_list_id')->references('id')->on('contact_groups')->onDelete('cascade');
            $table->foreign('sending_server_id')->references('id')->on('sending_servers')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('automations');
    }
};

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
        Schema::create('schedule_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid');
            $table->unsignedBigInteger('user_id');
            $table->string('from')->nullable();
            $table->string('to', 20);
            $table->longText('message')->nullable();
            $table->longText('media_url')->nullable();
            $table->string('sms_type', 15);
            $table->enum('send_by', ['from', 'to', 'api'])->nullable();
            $table->string('cost')->default(1);
            $table->string('api_key')->nullable();
            $table->string('language')->nullable();
            $table->string('gender')->nullable();
            $table->text('status')->nullable();
            $table->unsignedBigInteger('sending_server')->nullable();
            $table->dateTime('schedule_on')->nullable();
            $table->string('dlt_template_id')->nullable();

            $table->timestamps();

            // foreign
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('sending_server')->references('id')->on('sending_servers')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('schedule_messages');
    }
};

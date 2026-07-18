<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('tracking_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid');
            $table->string('runtime_message_id')->unique()->nullable();
            $table->string('message_id')->unique()->nullable();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('sending_server_id');
            $table->unsignedBigInteger('campaign_id');
            $table->unsignedBigInteger('contact_id');
            $table->unsignedBigInteger('contact_group_id');
            $table->string('status')->nullable();
            $table->string('error')->nullable();

            $table->timestamps();

            // foreign
            $table->foreign('customer_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('sending_server_id')->references('id')->on('sending_servers')->onDelete('cascade');
            $table->foreign('campaign_id')->references('id')->on('campaigns')->onDelete('cascade');
            $table->foreign('contact_id')->references('id')->on('contacts')->onDelete('cascade');
            $table->foreign('contact_group_id')->references('id')->on('contact_groups')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('tracking_logs');
    }
};

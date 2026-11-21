<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per (device, occurrence) a push was attempted for. The UNIQUE key is the duplicate guard: a webhook redelivery
 * or a retried queue job reaches the same (device, dedupe_key) and is turned away before any provider call.
 * Holds no message content, number or name.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('mobile_push_deliveries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('mobile_device_id');
            $table->string('dedupe_key', 120);
            $table->string('status', 12)->default('pending');
            $table->timestamps();

            $table->unique(['mobile_device_id', 'dedupe_key'], 'mobile_push_device_dedupe_unique');
            $table->foreign('mobile_device_id')->references('id')->on('mobile_devices')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_push_deliveries');
    }
};

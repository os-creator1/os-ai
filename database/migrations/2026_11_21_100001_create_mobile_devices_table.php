<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MotionGrove Mobile — Phase 1. One row per installed app instance, bound to the
 * Sanctum token that signed it in. Stores only the PUSH token the OS/Expo gave the
 * app (never a provider API key or telephony secret). Deleting the token deletes the
 * row, so a signed-out phone stops being a push target.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('mobile_devices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('personal_access_token_id')->unique();
            $table->string('installation_id', 64);
            $table->string('platform', 10);
            $table->string('push_provider', 10)->nullable();
            $table->text('push_token')->nullable();
            $table->string('push_token_hash', 64)->nullable()->index();
            $table->string('app_version', 32)->nullable();
            $table->string('device_name', 120)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index(['user_id', 'installation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_devices');
    }
};

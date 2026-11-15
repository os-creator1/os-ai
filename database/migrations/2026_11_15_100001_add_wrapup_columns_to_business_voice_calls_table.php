<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Post-call wrap-up from the call popup: the caller's own note and a short outcome label ("How did it go?").
 * Additive and nullable, so every existing call row is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_voice_calls', function (Blueprint $table): void {
            $table->text('wrapup_note')->nullable();
            $table->string('wrapup_disposition', 24)->nullable();
            $table->timestamp('wrapup_saved_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('business_voice_calls', function (Blueprint $table): void {
            $table->dropColumn(['wrapup_note', 'wrapup_disposition', 'wrapup_saved_at']);
        });
    }
};

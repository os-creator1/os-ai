<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historical conversation items that have no native table yet — email, calls,
 * internal notes and CRM activity brought over from another system. Display
 * only: nothing here can be sent, retried, metered or used as an automation
 * trigger. SMS history is written to the native chat tables instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imported_timeline_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('location_id')->nullable();
            $table->unsignedBigInteger('chat_box_id');
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->string('source_system', 32);
            $table->string('source_id', 64);
            $table->string('kind', 16); // email | call | note | activity
            $table->string('direction', 8)->nullable(); // inbound | outbound
            $table->string('title', 255)->default('');
            $table->longText('body')->nullable();
            $table->string('source_status', 32)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('imported_at')->useCurrent();

            $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
            $table->foreign('chat_box_id')->references('id')->on('chat_boxes')->cascadeOnDelete();
            $table->unique(['business_id', 'source_system', 'source_id'], 'iti_source_unique');
            $table->index(['business_id', 'chat_box_id', 'occurred_at'], 'iti_box_time_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imported_timeline_items');
    }
};

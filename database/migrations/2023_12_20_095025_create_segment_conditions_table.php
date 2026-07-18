<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('segment_conditions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid');
            $table->unsignedBigInteger('segment_id');
            $table->unsignedBigInteger('field_id')->nullable();
            $table->string('operator');
            $table->string('value')->nullable();

            $table->timestamps();

            // foreign
            $table->foreign('segment_id')->references('id')->on('segments')->onDelete('cascade');
            $table->foreign('field_id')->references('id')->on('contact_group_fields')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('segment_conditions');
    }
};

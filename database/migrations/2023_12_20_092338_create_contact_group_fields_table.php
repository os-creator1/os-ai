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
        Schema::create('contact_group_fields', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid');
            $table->unsignedBigInteger('contact_group_id');
            $table->string('label');
            $table->string('type');
            $table->string('tag');
            $table->string('default_value')->nullable();
            $table->boolean('visible')->default(true);
            $table->boolean('required')->default(false);
            $table->boolean('is_phone')->default(false);

            $table->timestamps();

            $table->foreign('contact_group_id')->references('id')->on('contact_groups')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contact_group_fields');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public Marketing Homepage contract. Owner-editable FAQ entries for the
 * public marketing homepage. Ships empty — no invented questions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_faqs', function (Blueprint $table): void {
            $table->id();
            $table->string('question', 255);
            $table->text('answer');
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->timestamps();

            $table->index(['is_visible', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_faqs');
    }
};

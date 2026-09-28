<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public Marketing Homepage contract. Owner-editable video testimonial
 * entries. `business_context_label` exists precisely so a testimonial is
 * always labelled as feedback from an earlier, unrelated business — never
 * presented as a review of this software. Ships with zero rows: no name,
 * wording, or video is invented here. `video_url` is deliberately a plain
 * external link (YouTube/Vimeo/S3/etc.), not a file upload field — this
 * table stores no video media itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_testimonials', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 191);
            $table->string('business_context_label', 255)
                ->default('Feedback from an earlier business (not a review of this software)');
            $table->string('poster_image_path')->nullable();
            $table->string('video_url')->nullable();
            $table->text('transcript_text')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_visible')->default(false);
            $table->timestamps();

            $table->index(['is_visible', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_testimonials');
    }
};

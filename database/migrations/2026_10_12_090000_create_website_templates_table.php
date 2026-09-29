<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Website Generator + Local SEO Completion. An operator-owned,
 * platform-seeded catalog — never customer-editable, never a vector for
 * arbitrary HTML/CSS/JS. `theme` extends the existing bounded
 * `websites.theme` shape (font/colors/button_style/content_width/
 * header_variant/footer_variant) with a small, closed set of additional
 * presentation-variant keys (nav_style, hero_style, card_style,
 * testimonial_style, faq_style, cta_style, footer_style) that the public
 * renderer's existing Blade partials branch on — every value is
 * validated against a fixed allowlist by WebsiteTemplate itself (never
 * free text), so a template can only ever select among presentation
 * variants this codebase already knows how to render. `page_manifest`
 * mirrors the Website Guided Generation contract §7.3 shape: which page
 * types this template supports and which of the existing 8
 * WebsiteSectionType values each page type's sections may use — it is
 * consumed only at generation time, never at render time (the renderer
 * itself gains no new template-awareness beyond the bounded theme keys
 * above).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('display_name', 80);
            $table->string('description', 255)->nullable();
            $table->json('theme');
            $table->json('page_manifest');
            $table->unsignedInteger('manifest_version')->default(1);
            $table->string('preview_image_path', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_templates');
    }
};

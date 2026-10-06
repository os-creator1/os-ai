<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEO Content / Blog Engine V1 — the canonical article record and its slug history.
 *
 * WHY a table of its own and not a Website page: a Website page is a section document frozen into
 * an immutable revision, published and rolled back as a WHOLE site. A blog is the opposite shape —
 * many small documents, each published, scheduled and archived on its own. Forcing every post
 * through a whole-site republish would put the blog in the revision history, bloat every snapshot,
 * and let a rollback silently unpublish posts. So an article is its own publication record, and it
 * is RENDERED by the one Website renderer (same template, header, footer, CTA, canonical host) —
 * there is no second renderer. The published Website revision still decides whether the site is
 * live at all, and supplies the navigation, brand and the pages an article links to.
 *
 * `website_articles`
 *   - `uid` is the stable public-facing identifier (also what the editor routes use).
 *   - `slug` is unique per Website; `website_article_slug_history` keeps every slug a published
 *     article has ever had so an old indexed URL 301s instead of 404ing.
 *   - `status` is draft | scheduled | published | archived. Nothing is ever hard-deleted from here:
 *     "removing" a published article is archiving it (it leaves the index and the sitemap but its
 *     slug and history remain, so the URL cannot be re-used by a different article by accident).
 *   - `supports_page_uid` is the whole "content cluster" idea: the Website page (money page) this
 *     article supports. A page uid is stable across publishes; it is resolved against the
 *     published snapshot at render time, so a deleted page simply stops being linked.
 *   - `topic_signature` is the deterministic normalized topic used for overlap detection.
 *   - `opportunity_key` ties an article to the generated opportunity it came from, so the
 *     Opportunities list can honestly say "Draft exists" / "Published". Opportunities themselves
 *     are computed, never stored.
 *   - `referenced_catalog_uids` records which Packages & Products the article quotes, so a later
 *     change to one of them can be flagged as "may be out of date".
 *   - `noindex` is the OWNER's explicit per-article choice. It can only ever narrow indexing; it can
 *     never override the Website-wide rule (platform path / unpublished site stay noindex).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_articles', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->foreignId('website_id')->constrained('websites')->restrictOnDelete();
            $table->unsignedBigInteger('business_location_id')->nullable();

            $table->string('title', 180);
            $table->string('slug', 80);
            $table->string('excerpt', 400)->nullable();
            $table->mediumText('body')->nullable();              // safe Markdown
            $table->string('status', 16)->default('draft');      // draft|scheduled|published|archived

            $table->foreignId('featured_asset_id')->nullable()->constrained('website_assets')->nullOnDelete();
            $table->string('seo_title', 180)->nullable();
            $table->string('meta_description', 320)->nullable();
            $table->boolean('noindex')->default(false);
            $table->string('author_name', 120)->nullable();

            $table->string('primary_topic', 190)->nullable();
            $table->string('topic_signature', 190)->nullable();
            $table->string('search_intent', 24)->nullable();
            $table->string('supports_page_uid', 64)->nullable();
            $table->string('opportunity_key', 120)->nullable();
            $table->string('source', 16)->default('manual');     // manual|opportunity|ai_draft
            $table->boolean('ai_generated')->default(false);
            $table->json('referenced_catalog_uids')->nullable();

            $table->dateTime('published_at')->nullable();
            $table->dateTime('scheduled_at')->nullable();
            $table->dateTime('archived_at')->nullable();
            $table->dateTime('content_updated_at')->nullable();  // last real edit of the words; drives dateModified
            $table->dateTime('last_reviewed_at')->nullable();

            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['website_id', 'slug'], 'website_articles_slug_unique');
            $table->index(['business_id', 'status', 'published_at'], 'website_articles_business_status_index');
            $table->index(['business_id', 'opportunity_key'], 'website_articles_opportunity_index');
            $table->index(['status', 'scheduled_at'], 'website_articles_due_index');
            $table->index(['business_id', 'topic_signature'], 'website_articles_topic_index');
            $table->foreign(['business_location_id', 'business_id'], 'website_articles_location_fk')
                ->references(['id', 'business_id'])->on('business_locations')->restrictOnDelete();
        });

        Schema::create('website_article_slug_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('website_article_id')->constrained('website_articles')->restrictOnDelete();
            $table->foreignId('website_id')->constrained('websites')->restrictOnDelete();
            $table->string('slug', 80);
            $table->dateTime('created_at')->nullable();

            // A retired slug belongs to exactly one article forever, and can never collide with a live one.
            $table->unique(['website_id', 'slug'], 'website_article_slug_history_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_article_slug_history');
        Schema::dropIfExists('website_articles');
    }
};

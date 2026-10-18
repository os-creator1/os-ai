<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * External Website Audit Mode V1 — the stored result of one bounded, SSRF-safe
 * crawl of a Business's OWN public website, and the audit findings the SAME
 * SeoAuditEvaluator produced over it.
 *
 * Three tables, because a hosted audit is keyed by an immutable Website
 * revision (`seo_audit_runs.website_revision_id`) and an external site has no
 * revision; forcing one into the other would weaken the hosted uniqueness
 * guarantees. The finding VOCABULARY is shared (SeoAuditRuleRegistry), the
 * tables are not.
 *
 *  - external_site_crawls   one row per crawl (queued -> running ->
 *                           completed|failed), the start URL AS CRAWLED, bounded
 *                           counters, the rule-set version and an indexability
 *                           STATUS (never a finding).
 *  - external_site_pages    the normalised FACTS of each fetched page (no body,
 *                           no HTML — only values the audit rules need).
 *  - external_site_findings registry rule key + severity + <=8 scalar facts,
 *                           exactly the shape of seo_audit_findings.
 *
 * Everything is Business-scoped and cascades with the Business; the last few
 * crawls are kept (pruned by the runner), so storage stays bounded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_site_crawls', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('business_id');
            $table->string('start_url', 2048);
            $table->string('host', 255);
            $table->string('status', 16)->default('queued');
            $table->string('trigger', 16)->default('manual');
            $table->string('failure_code', 48)->nullable();
            $table->string('indexability', 24)->nullable();
            $table->unsignedInteger('rule_set_version')->nullable();
            $table->unsignedSmallInteger('pages_discovered')->default(0);
            $table->unsignedSmallInteger('pages_fetched')->default(0);
            $table->unsignedSmallInteger('broken_links')->default(0);
            $table->unsignedSmallInteger('critical_count')->default(0);
            $table->unsignedSmallInteger('warning_count')->default(0);
            $table->unsignedSmallInteger('info_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign('business_id', 'esc_business_fk')->references('id')->on('businesses')->cascadeOnDelete();
            $table->index(['business_id', 'id'], 'esc_business_id_idx');
            $table->index(['status', 'created_at'], 'esc_status_idx');
        });

        Schema::create('external_site_pages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('crawl_id');
            $table->unsignedBigInteger('business_id');
            $table->char('url_hash', 40);
            $table->string('url', 2048);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('error_code', 48)->nullable();
            $table->string('content_type', 100)->nullable();
            $table->string('title', 512)->nullable();
            $table->text('meta_description')->nullable();
            $table->string('canonical_url', 2048)->nullable();
            $table->boolean('noindex')->default(false);
            $table->unsignedSmallInteger('h1_count')->nullable();
            $table->unsignedSmallInteger('internal_link_count')->default(0);
            $table->unsignedSmallInteger('broken_link_count')->default(0);
            $table->unsignedSmallInteger('image_count')->default(0);
            $table->unsignedSmallInteger('images_missing_alt')->default(0);
            $table->boolean('has_open_graph')->default(false);
            $table->boolean('has_json_ld')->default(false);
            $table->unsignedInteger('word_count')->default(0);
            $table->timestamp('fetched_at')->nullable();

            $table->foreign('crawl_id', 'esp_crawl_fk')->references('id')->on('external_site_crawls')->cascadeOnDelete();
            $table->foreign('business_id', 'esp_business_fk')->references('id')->on('businesses')->cascadeOnDelete();
            $table->unique(['crawl_id', 'url_hash'], 'esp_crawl_url_unique');
            $table->index(['business_id', 'crawl_id'], 'esp_business_crawl_idx');
        });

        Schema::create('external_site_findings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('crawl_id');
            $table->unsignedBigInteger('page_id')->nullable();
            $table->string('rule_key', 64);
            $table->string('severity', 16);
            $table->json('facts')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('crawl_id', 'esf_crawl_fk')->references('id')->on('external_site_crawls')->cascadeOnDelete();
            $table->foreign('page_id', 'esf_page_fk')->references('id')->on('external_site_pages')->cascadeOnDelete();
            $table->index(['crawl_id', 'severity'], 'esf_crawl_severity_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_site_findings');
        Schema::dropIfExists('external_site_pages');
        Schema::dropIfExists('external_site_crawls');
    }
};

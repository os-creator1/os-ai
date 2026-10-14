<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * External Website Audit overhaul — the evidence the first version did not keep.
 *
 * Purely ADDITIVE. Every existing column, row and index of the three
 * external_site_* tables is untouched, so a crawl stored by the first version
 * still reads (the reader labels it as an earlier-format check).
 *
 * Why each addition exists:
 *
 *  external_site_crawls   lifecycle + honesty. `active_lock` is a UNIQUE column
 *                         set to the business id while a crawl is queued/running
 *                         and NULL afterwards: the database itself refuses a
 *                         second concurrent crawl (two people clicking "Check
 *                         again" cannot both win). `coverage` is the distinct
 *                         counts (discovered / audited / redirected / excluded /
 *                         blocked / not checked) the screens must explain;
 *                         `summary` the priority counts; `connection_state` and
 *                         `final_url` how the address really behaved;
 *                         `heartbeat_at` lets a stuck run be recovered.
 *  external_site_pages    one row per URL the crawl DECIDED something about
 *                         (audited, redirected, excluded, blocked, not checked),
 *                         not just per audited page, with a bounded `evidence`
 *                         JSON (heading outline, images, structured data, ...).
 *  external_site_links    the internal link graph (source page -> destination,
 *                         anchor text), because "18 broken links" is useless
 *                         without WHERE each one is. Bounded per page and crawl.
 *  external_site_findings stable `finding_key` (so runs can be compared and a
 *                         tracked fix verified), category/kind/priority/confidence
 *                         and a bounded `evidence` JSON.
 *  external_site_plan_items  the owner's own tracking of an improvement
 *                         (open / in progress / waiting on a helper / done /
 *                         won't do) keyed by the stable finding key, so it
 *                         survives from one crawl to the next.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_site_crawls', function (Blueprint $table) {
            $table->string('final_url', 2048)->nullable()->after('host');
            $table->string('connection_state', 24)->nullable()->after('indexability');
            $table->unsignedSmallInteger('connection_http_status')->nullable()->after('connection_state');
            $table->string('stage', 24)->nullable()->after('status');
            $table->string('finish_reason', 48)->nullable()->after('failure_code');
            $table->unsignedSmallInteger('page_budget')->nullable()->after('rule_set_version');
            $table->unsignedSmallInteger('pages_audited')->default(0)->after('pages_fetched');
            $table->unsignedSmallInteger('progress_discovered')->default(0)->after('pages_audited');
            $table->unsignedSmallInteger('progress_checked')->default(0)->after('progress_discovered');
            $table->json('coverage')->nullable();
            $table->json('summary')->nullable();
            $table->unsignedBigInteger('active_lock')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('details_pruned_at')->nullable();

            // At most ONE queued/running crawl per Business, enforced by the database.
            $table->unique('active_lock', 'esc_active_lock_unique');
        });

        Schema::table('external_site_pages', function (Blueprint $table) {
            $table->string('kind', 20)->default('html')->after('url');
            $table->string('final_url', 2048)->nullable()->after('kind');
            $table->json('redirect_chain')->nullable();
            $table->unsignedTinyInteger('depth')->nullable();
            $table->string('source', 12)->nullable();
            $table->boolean('in_sitemap')->default(false);
            $table->string('meta_robots', 255)->nullable();
            $table->string('x_robots', 255)->nullable();
            $table->boolean('indexable')->nullable();
            $table->string('importance', 12)->default('standard');
            $table->unsignedSmallInteger('inlink_count')->default(0);
            $table->json('evidence')->nullable();
        });

        Schema::create('external_site_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('crawl_id');
            $table->unsignedBigInteger('source_page_id');
            $table->string('target_url', 2048);
            $table->char('target_hash', 40);
            $table->string('anchor', 191)->nullable();
            $table->boolean('nofollow')->default(false);

            $table->foreign('crawl_id', 'esl_crawl_fk')->references('id')->on('external_site_crawls')->cascadeOnDelete();
            $table->foreign('source_page_id', 'esl_source_fk')->references('id')->on('external_site_pages')->cascadeOnDelete();
            $table->index(['crawl_id', 'target_hash'], 'esl_crawl_target_idx');
            $table->index(['crawl_id', 'source_page_id'], 'esl_crawl_source_idx');
        });

        Schema::table('external_site_findings', function (Blueprint $table) {
            $table->char('finding_key', 40)->nullable()->after('page_id');
            $table->string('group_key', 40)->nullable()->after('rule_key');
            $table->string('category', 24)->nullable()->after('group_key');
            $table->string('kind', 16)->nullable()->after('category');
            $table->string('priority', 12)->nullable()->after('severity');
            $table->string('confidence', 12)->nullable()->after('priority');
            $table->json('evidence')->nullable();

            $table->index(['crawl_id', 'rule_key', 'group_key'], 'esf_crawl_rule_group_idx');
            $table->index(['crawl_id', 'finding_key'], 'esf_crawl_key_idx');
        });

        Schema::create('external_site_plan_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->char('item_key', 40);
            $table->string('rule_key', 64);
            $table->string('status', 16)->default('open');
            $table->string('note', 500)->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->unsignedBigInteger('first_seen_crawl_id')->nullable();
            $table->unsignedBigInteger('resolved_in_crawl_id')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamps();

            $table->foreign('business_id', 'espi_business_fk')->references('id')->on('businesses')->cascadeOnDelete();
            $table->unique(['business_id', 'item_key'], 'espi_business_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_site_plan_items');

        Schema::table('external_site_findings', function (Blueprint $table) {
            $table->dropIndex('esf_crawl_rule_group_idx');
            $table->dropIndex('esf_crawl_key_idx');
            $table->dropColumn(['finding_key', 'group_key', 'category', 'kind', 'priority', 'confidence', 'evidence']);
        });

        Schema::dropIfExists('external_site_links');

        Schema::table('external_site_pages', function (Blueprint $table) {
            $table->dropColumn(['kind', 'final_url', 'redirect_chain', 'depth', 'source', 'in_sitemap', 'meta_robots', 'x_robots', 'indexable', 'importance', 'inlink_count', 'evidence']);
        });

        Schema::table('external_site_crawls', function (Blueprint $table) {
            $table->dropUnique('esc_active_lock_unique');
            $table->dropColumn(['final_url', 'connection_state', 'connection_http_status', 'stage', 'finish_reason', 'page_budget', 'pages_audited', 'progress_discovered', 'progress_checked', 'coverage', 'summary', 'active_lock', 'heartbeat_at', 'details_pruned_at']);
        });
    }
};

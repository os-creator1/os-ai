<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Implementation Contract 18 §8.7, Sub-slice G — one immutable record per
 * technical audit of ONE published Website revision.
 *
 * IMMUTABLE, like the revision it describes. `UPDATED_AT = null` (§8.7,
 * mirroring `website_revisions`): a run is inserted complete and is never
 * edited afterwards. Re-auditing a revision does not mutate its run — the
 * unique key below simply makes the second attempt a no-op.
 *
 * `unique(website_revision_id, rule_set_version)` IS the idempotency
 * guarantee, in the database rather than in application logic (§8.7,
 * "idempotent per revision"). A duplicate WebsitePublished delivery, a
 * re-queued job and a manual re-run of the same revision all collide on this
 * key, so at most one canonical run exists per (revision, rule set). The
 * rule-set version is part of the key on purpose: a future v2 registry must
 * be able to audit an already-audited revision without destroying the v1
 * result.
 *
 * The three severity counters are denormalised totals of this run's own
 * findings. They exist so the audit index and the Overview can show a summary
 * without counting findings per run — Contract §11.4 requires query count to
 * be independent of the number of findings.
 *
 * SEO NEVER WRITES WEBSITE DATA (§12.1). This table only REFERENCES the
 * revision it read. `website_revision_id` is a plain unsigned column with no
 * foreign key: a Website's revisions are the Website feature's own data and
 * its retention is not SEO's to constrain, and §8.7's pruning must never be
 * able to cascade into it. `business_id` and `website_id` are likewise plain
 * references for the same reason, and the reader always re-derives tenancy
 * through the Business rather than trusting a stored id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_audit_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();

            // Plain references, never FKs — see the class docblock.
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('website_id');
            $table->unsignedBigInteger('website_revision_id');

            $table->unsignedSmallInteger('rule_set_version');
            $table->string('status', 16);

            $table->unsignedInteger('page_count')->default(0);
            $table->unsignedInteger('critical_count')->default(0);
            $table->unsignedInteger('warning_count')->default(0);
            $table->unsignedInteger('info_count')->default(0);

            // No updated_at: the row is written once (§8.7).
            $table->timestamp('created_at')->nullable();

            // §8.7 — idempotent per (revision, rule set).
            $table->unique(['website_revision_id', 'rule_set_version'], 'seo_audit_runs_revision_ruleset_unique');

            // The pruning and "latest run" reads are both (website_id, id).
            $table->index(['website_id', 'id'], 'seo_audit_runs_website_id_index');
            $table->index(['business_id', 'id'], 'seo_audit_runs_business_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_audit_runs');
    }
};

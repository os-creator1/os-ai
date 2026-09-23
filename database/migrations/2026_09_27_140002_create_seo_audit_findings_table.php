<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Implementation Contract 18 §8.7, Sub-slice G — one deterministic finding
 * produced by one closed registry rule against one audited revision.
 *
 * NO USER-VISIBLE TEXT IS STORED HERE. A finding carries a `rule_key` and a
 * small bag of validated scalar `facts`; the sentence the customer reads is
 * composed at render time from the registry's own template plus those facts
 * (§8.7, the RFC-002 discipline). That is the structural reason a finding can
 * never echo page body text, provider text or model output: there is nowhere
 * to put it.
 *
 * `facts` is scalar JSON with at most 8 keys, enforced by SeoAuditRuleRegistry
 * at write time — a bound the registry owns, not a column type.
 *
 * `page_uid` is the SNAPSHOT's page uid, and NULL means the finding is
 * site-level rather than about one page. It is deliberately the uid from the
 * immutable snapshot and not a foreign key to `website_pages`: the audited
 * revision is a frozen document, the live page may since have been renamed or
 * deleted, and SEO must never constrain Website's data (§12.1). Carrying
 * `page_uid` now is also what lets Location-page rules (G-1, deferred until a
 * canonical page<->Location association exists) be added later without a
 * rewrite.
 *
 * `cascadeOnDelete` on the run is the ONLY delete path: §8.7's "latest 5 runs
 * per Website" pruning deletes runs, and their findings follow. Nothing here
 * can reach a Website table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_audit_findings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seo_audit_run_id')->constrained('seo_audit_runs')->cascadeOnDelete();

            // Snapshot page uid; NULL = a site-level finding.
            $table->uuid('page_uid')->nullable();

            $table->string('rule_key', 64);
            $table->string('severity', 16);

            // Validated scalar facts only — never prose (see the docblock).
            $table->json('facts');

            $table->timestamp('created_at')->nullable();

            $table->index(['seo_audit_run_id', 'severity'], 'seo_audit_findings_run_severity_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_audit_findings');
    }
};

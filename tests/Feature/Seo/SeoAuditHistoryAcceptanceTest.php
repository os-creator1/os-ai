<?php

namespace Tests\Feature\Seo;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Seo\SeoAuditRunStatus;
use App\Library\Seo\SeoAuditPageReader;
use App\Library\Seo\SeoAuditRuleRegistry;
use App\Library\Seo\SeoAuditRunner;
use App\Models\Website;
use App\Models\WebsiteRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Seo\Concerns\CreatesSeoFixtures;
use Tests\TestCase;

/**
 * Customer acceptance pass, Contract 18 Sub-slice 18G — the read surface's
 * `SeoAuditPage::$history` field (newest first, per its own docblock) has no
 * existing test exercising it: SeoAuditTest and SeoAuditAuthorityTest only
 * ever audit ONE revision per Business, so `$history` is always a
 * single-element array there and pruning is proved only at the row-count
 * level, never through the read model an owner actually sees.
 *
 * This closes that gap and, in the same flow, proves the stronger form of
 * "rerunning unchanged content is safe": not just that the DATABASE gets no
 * duplicate row (SeoAuditTest::test_auditing_the_same_revision_twice_converges_on_one_run
 * already proves that), but that the READ SURFACE an owner looks at never
 * mixes a fixed revision's clean findings with a prior revision's stale
 * ones, and never re-shows an old finding as if it still applied.
 */
class SeoAuditHistoryAcceptanceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesSeoFixtures;

    private function runner(): SeoAuditRunner
    {
        return app(SeoAuditRunner::class);
    }

    private function reader(): SeoAuditPageReader
    {
        return app(SeoAuditPageReader::class);
    }

    /** A second, immutable revision of the SAME Website — never a new Website. */
    private function publishNextRevision(Website $website, array $pages): WebsiteRevision
    {
        $revision = WebsiteRevision::create([
            'website_id' => $website->id,
            'version_number' => (int) WebsiteRevision::query()->where('website_id', $website->id)->max('version_number') + 1,
            'snapshot' => [
                'schema_version' => 1,
                'website' => ['name' => 'Test Website', 'theme' => []],
                'pages' => $pages,
                'assets' => [],
            ],
            'schema_version' => 1,
            'created_by' => $website->business->customer_id,
        ]);

        $website->update(['published_revision_id' => $revision->id]);

        return $revision;
    }

    public function test_the_read_surface_accumulates_history_and_never_shows_a_fixed_revisions_old_findings(): void
    {
        [, $business] = $this->entitledTenant(WorkspacePlanTier::Growth);

        // Revision 1: a blank SEO title, a real, reproducible finding.
        $website = $this->publishWebsite($business, [
            $this->snapshotPage('home', 'Home', ['seo_title' => '   ', 'meta_description' => str_repeat('a', 80)]),
        ]);

        $run1 = $this->runner()->runForRevision((int) $business->id, (int) $website->id, (int) $website->published_revision_id);

        $this->assertSame(SeoAuditRunStatus::Completed, $run1->status);
        $this->assertContains(SeoAuditRuleRegistry::SEO_TITLE_BLANK, $run1->findings()->pluck('rule_key')->all());

        $pageAfterFirstRun = $this->reader()->read($business);

        $this->assertTrue($pageAfterFirstRun->hasRun());
        $this->assertSame($run1->id, $pageAfterFirstRun->latestRun->id);
        $this->assertCount(1, $pageAfterFirstRun->history, 'History must carry the one run so far.');
        $this->assertSame($run1->id, $pageAfterFirstRun->history[0]->id);
        $this->assertNotEmpty($pageAfterFirstRun->findings, 'The blank-title finding must be visible on the read surface.');

        // Rerunning the SAME, still-unfixed revision must not duplicate the
        // run in the database OR change what the read surface shows.
        $reRun = $this->runner()->runForRevision((int) $business->id, (int) $website->id, (int) $website->published_revision_id);

        $this->assertSame($run1->id, $reRun->id, 'A rerun of an unchanged revision must converge on the same run.');
        $this->assertSame(1, \App\Models\SeoAuditRun::query()->where('website_id', $website->id)->count());

        $pageAfterRerun = $this->reader()->read($business);
        $this->assertCount(1, $pageAfterRerun->history, 'Rerunning unchanged content must not grow history.');
        $this->assertSame($run1->id, $pageAfterRerun->history[0]->id);

        // Revision 2: the owner fixes the title. A NEW revision, a NEW run.
        $this->publishNextRevision($website, [
            $this->snapshotPage('home', 'Home', ['seo_title' => 'A perfectly reasonable title', 'meta_description' => str_repeat('a', 80)]),
        ]);

        $run2 = $this->runner()->runForRevision((int) $business->id, (int) $website->id, (int) $website->fresh()->published_revision_id);

        $this->assertSame(SeoAuditRunStatus::Completed, $run2->status);
        $this->assertNotSame($run1->id, $run2->id);
        $this->assertSame(0, $run2->totalFindings(), 'The fixed revision must produce no findings.');

        $pageAfterFix = $this->reader()->read($business);

        // History accumulates, newest first — the owner can see the site
        // used to have a problem and now does not.
        $this->assertCount(2, $pageAfterFix->history, 'History must now carry both runs.');
        $this->assertSame($run2->id, $pageAfterFix->history[0]->id, 'History must be newest first.');
        $this->assertSame($run1->id, $pageAfterFix->history[1]->id);

        // The CURRENT read is the latest run, and only the latest run: the
        // fixed page's clean result, with zero findings — never the old
        // blank-title finding re-shown as if it still applied.
        $this->assertSame($run2->id, $pageAfterFix->latestRun->id);
        $this->assertSame([], $pageAfterFix->findings, 'A fixed revision must show no findings, not the prior revision\'s stale ones.');

        // The prior run's own historical counts are untouched — history is a
        // record, not something the newer run silently overwrites.
        $this->assertSame(1, $pageAfterFix->history[1]->totalFindings());
        $this->assertSame(0, $pageAfterFix->history[0]->totalFindings());
    }
}

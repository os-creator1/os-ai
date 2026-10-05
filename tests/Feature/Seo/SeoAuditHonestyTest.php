<?php

namespace Tests\Feature\Seo;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Seo\SeoAuditRunStatus;
use App\Http\Controllers\Customer\Business\SeoAuditController;
use App\Jobs\Seo\RunSeoAuditForRevision;
use App\Library\Growth\GrowthThresholds;
use App\Library\Growth\Readers\GrowthSeoFactReader;
use App\Library\Seo\SeoAuditPage;
use App\Library\Seo\SeoAuditPageReader;
use App\Library\Seo\SeoAuditRuleRegistry;
use App\Library\Seo\SeoAuditRunner;
use App\Models\SeoAuditFinding;
use App\Models\SeoAuditRun;
use App\Models\Website;
use App\Models\WebsiteRevision;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Feature\Seo\Concerns\CreatesSeoFixtures;
use Tests\TestCase;

/**
 * SEO V1 final (A2, A12, A13) — the Website check never lies.
 *
 *  - a FAILED check is never shown as "nothing to fix", and "Check again" can
 *    actually complete it;
 *  - the run shown (and the one Growth trusts) is the run for the PUBLISHED
 *    revision, so a rollback never shows another version's findings;
 *  - the title-length rule measures the real <title> (page title + business
 *    name), and no customer-facing word is SEO jargon;
 *  - a re-run request with nothing published does not burn the cooldown.
 */
class SeoAuditHonestyTest extends TestCase
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

    private function auditUrl($workspace, $business): string
    {
        return route('customer.workspaces.businesses.seo.audit.index', [$workspace->uid, $business->uid]);
    }

    private function audit(Website $website): SeoAuditRun
    {
        return $this->runner()->runForRevision((int) $website->business_id, (int) $website->id, (int) $website->fresh()->published_revision_id);
    }

    /** A new immutable revision of the same Website; $publish decides whether it goes live. */
    private function nextRevision(Website $website, array $pages, bool $publish = true): WebsiteRevision
    {
        $revision = WebsiteRevision::create([
            'website_id' => $website->id,
            'version_number' => (int) WebsiteRevision::query()->where('website_id', $website->id)->max('version_number') + 1,
            'snapshot' => ['schema_version' => 1, 'website' => ['name' => 'Test Website', 'theme' => []], 'pages' => $pages, 'assets' => []],
            'schema_version' => 1,
            'created_by' => $website->business->customer_id,
        ]);

        if ($publish) {
            $website->update(['published_revision_id' => $revision->id]);
        }

        return $revision;
    }

    private function cleanPage(string $uid = 'home'): array
    {
        return $this->snapshotPage($uid, 'Home', [
            'seo_title' => 'A perfectly reasonable title',
            'meta_description' => str_repeat('a', 80),
        ], [], true);
    }

    private function brokenPage(string $uid = 'home'): array
    {
        return $this->snapshotPage($uid, 'Home', ['seo_title' => null, 'meta_description' => null], [], true);
    }

    private function breakSnapshot(Website $website): void
    {
        DB::table('website_revisions')->where('id', $website->published_revision_id)->update(['snapshot' => json_encode('not-an-object')]);
    }

    // -----------------------------------------------------------------
    // A2 — a failed check is not a clean one.
    // -----------------------------------------------------------------

    public function test_a_failed_check_says_so_and_never_claims_there_is_nothing_to_fix(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $website = $this->publishWebsite($business, [$this->cleanPage()]);
        $this->breakSnapshot($website);
        $run = $this->audit($website);
        $this->assertSame(SeoAuditRunStatus::Failed, $run->status);

        $this->authenticateAsSeoCustomer($customer);
        $html = $this->get($this->auditUrl($workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString("We couldn't check your site this time", $html);
        $this->assertStringContainsString('try again', $html);
        $this->assertStringNotContainsString('We found nothing to fix', $html);
        $this->assertStringContainsString('data-state="failed"', $html);
        $this->assertSame(SeoAuditPage::STATE_FAILED, $this->reader()->read($business)->state());
    }

    public function test_a_completed_clean_check_still_says_nothing_to_fix(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $website = $this->publishWebsite($business, [$this->cleanPage()]);
        $this->audit($website);

        $this->authenticateAsSeoCustomer($customer);
        $html = $this->get($this->auditUrl($workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('We found nothing to fix on your published pages.', $html);
        $this->assertStringNotContainsString("We couldn't check", $html);
        $this->assertSame(SeoAuditPage::STATE_CLEAN, $this->reader()->read($business)->state());
    }

    public function test_check_again_completes_a_failed_run_in_place_once_the_snapshot_is_readable(): void
    {
        [, $business] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $website = $this->publishWebsite($business, [$this->brokenPage()]);
        $good = DB::table('website_revisions')->where('id', $website->published_revision_id)->value('snapshot');
        $this->breakSnapshot($website);

        $failed = $this->audit($website);
        $this->assertSame(SeoAuditRunStatus::Failed, $failed->status);

        // Still unreadable: the retry changes nothing and invents nothing.
        $again = $this->audit($website);
        $this->assertSame($failed->id, $again->id);
        $this->assertSame(SeoAuditRunStatus::Failed, $again->status);
        $this->assertSame(0, SeoAuditFinding::query()->count());

        // Readable now: the SAME run row is completed, with its findings.
        DB::table('website_revisions')->where('id', $website->published_revision_id)->update(['snapshot' => $good]);
        $completed = $this->audit($website);

        $this->assertSame($failed->id, $completed->id, 'The unique key leaves one run per revision: it is completed in place.');
        $this->assertSame(SeoAuditRunStatus::Completed, $completed->fresh()->status);
        $this->assertSame(1, SeoAuditRun::query()->count());
        $this->assertGreaterThan(0, SeoAuditFinding::query()->where('seo_audit_run_id', $completed->id)->count());
        $this->assertSame(SeoAuditPage::STATE_FINDINGS, $this->reader()->read($business)->state());

        // And a completed run is canonical: running again adds no findings.
        $before = SeoAuditFinding::query()->count();
        $this->audit($website);
        $this->assertSame($before, SeoAuditFinding::query()->count());
    }

    public function test_growth_trusts_only_a_completed_run_of_the_published_revision(): void
    {
        [, $business] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $read = fn () => app(GrowthSeoFactReader::class)->read($business->fresh(), CarbonImmutable::now(), new GrowthThresholds())->get('audit_ran');

        // No website at all.
        $this->assertFalse($read());

        // Published but never audited.
        $website = $this->publishWebsite($business, [$this->brokenPage()]);
        $this->assertFalse($read(), 'Never audited is not a pass.');

        // A FAILED run is not a pass either (this used to read as hasRun() = true).
        $original = DB::table('website_revisions')->where('id', $website->published_revision_id)->value('snapshot');
        $this->breakSnapshot($website);
        $this->audit($website);
        $this->assertFalse($read(), 'A failed audit must not count as one that ran.');

        // Completed run for the published revision: now it ran.
        DB::table('website_revisions')->where('id', $website->published_revision_id)->update(['snapshot' => $original]);
        $this->audit($website);
        $this->assertTrue($read());

        // Publish a newer revision that has NOT been audited: the old run no longer describes the site.
        $first = (int) $website->fresh()->published_revision_id;
        $this->nextRevision($website, [$this->cleanPage()]);
        $this->assertFalse($read(), 'Another revision\'s run is not evidence about the site that is live now.');

        // Roll back to the audited revision: it ran again.
        $website->update(['published_revision_id' => $first]);
        $this->assertTrue($read());

        // Unpublished: nothing live to have audited.
        $website->update(['status' => \App\Enums\Website\WebsiteStatus::Draft]);
        $this->assertFalse($read());
    }

    public function test_findings_are_not_reported_to_growth_from_a_failed_run(): void
    {
        [, $business] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $website = $this->publishWebsite($business, [$this->brokenPage()]);
        $this->breakSnapshot($website);
        $this->audit($website);

        $facts = app(GrowthSeoFactReader::class)->read($business->fresh(), CarbonImmutable::now(), new GrowthThresholds());

        $this->assertFalse($facts->get('audit_ran'));
        $this->assertSame(['critical' => 0, 'warning' => 0, 'rules' => []], $facts->get('audit_findings'));
    }

    // -----------------------------------------------------------------
    // A2 — the run shown is the one for the published revision.
    // -----------------------------------------------------------------

    public function test_after_a_rollback_the_page_shows_the_published_revisions_run_not_the_newest_row(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Growth);

        // Revision 1: clean. Revision 2: broken. Both audited; revision 2 is the newest row.
        $website = $this->publishWebsite($business, [$this->cleanPage()]);
        $revisionOne = (int) $website->published_revision_id;
        $runOne = $this->audit($website);

        $this->nextRevision($website, [$this->brokenPage()]);
        $runTwo = $this->audit($website);
        $this->assertGreaterThan($runOne->id, $runTwo->id);
        $this->assertGreaterThan(0, $runTwo->totalFindings());

        // The owner rolls back to revision 1.
        $website->update(['published_revision_id' => $revisionOne]);

        $page = $this->reader()->read($business);
        $this->assertSame($runOne->id, $page->latestRun->id, 'The run for the PUBLISHED revision, not the newest run.');
        $this->assertSame([], $page->findings, 'Revision 2\'s findings must not describe the live revision 1.');
        $this->assertSame(SeoAuditPage::STATE_CLEAN, $page->state());
        $this->assertCount(2, $page->history, 'History still lists both runs.');

        $this->authenticateAsSeoCustomer($customer);
        $html = $this->get($this->auditUrl($workspace, $business))->assertOk()->getContent();
        $this->assertStringContainsString('We found nothing to fix on your published pages.', $html);
    }

    public function test_a_published_revision_with_no_run_is_not_checked_yet_for_the_current_version(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $website = $this->publishWebsite($business, [$this->brokenPage()]);
        $this->audit($website);

        // Roll forward to a revision nobody has audited.
        $this->nextRevision($website, [$this->cleanPage()]);

        $page = $this->reader()->read($business);
        $this->assertNull($page->latestRun, 'The older revision\'s run must not be passed off as this version\'s.');
        $this->assertSame([], $page->findings);
        $this->assertSame(SeoAuditPage::STATE_NOT_CHECKED, $page->state());

        $this->authenticateAsSeoCustomer($customer);
        $html = $this->get($this->auditUrl($workspace, $business))->assertOk()->getContent();
        $this->assertStringContainsString('This version of your website has not been checked yet', $html);
        $this->assertStringNotContainsString('We found nothing to fix', $html);
        $this->assertStringContainsString('Check again', $html);
    }

    public function test_an_unpublished_website_shows_no_findings_and_offers_no_check(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $website = $this->publishWebsite($business, [$this->brokenPage()]);
        $this->audit($website);
        $website->update(['status' => \App\Enums\Website\WebsiteStatus::Draft]);

        $this->authenticateAsSeoCustomer($customer);
        $html = $this->get($this->auditUrl($workspace, $business))->assertOk()->getContent();

        $this->assertSame(SeoAuditPage::STATE_NO_WEBSITE, $this->reader()->read($business)->state());
        $this->assertStringContainsString('You have no published website yet', $html);
        $this->assertStringNotContainsString('Check again', $html);
        $this->assertStringNotContainsString('No search result description set', $html);
    }

    public function test_a_read_only_viewer_is_not_offered_a_check_and_told_who_can_run_one(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $website = $this->publishWebsite($business, [$this->cleanPage()]);
        $this->audit($website);
        $this->nextRevision($website, [$this->cleanPage('home2')]);
        $this->authenticateAsSeoCustomer($customer, ['view_seo']);

        $html = $this->get($this->auditUrl($workspace, $business))->assertOk()->getContent();

        $this->assertStringNotContainsString('Check again', $html);
        $this->assertStringNotContainsString('data-role="audit-rerun"', $html);
        $this->assertStringContainsString('someone who can manage SEO can start a check', $html);
    }

    // -----------------------------------------------------------------
    // A13 — the cooldown is not consumed by a request that queues nothing.
    // -----------------------------------------------------------------

    public function test_a_rerun_with_no_published_website_refuses_clearly_and_does_not_start_the_cooldown(): void
    {
        Queue::fake();
        [$customer, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $this->authenticateAsSeoCustomer($customer);

        $rerun = fn () => $this->post(route('customer.workspaces.businesses.seo.audit.rerun', [$workspace->uid, $business->uid]));

        $rerun()->assertRedirect()->assertSessionHas('message', 'There is no published website to check yet. Publish your website first.');
        Queue::assertNotPushed(RunSeoAuditForRevision::class);
        $this->assertSame(0, RateLimiter::attempts(SeoAuditController::rerunLimiterKey((int) $customer->user_id, (int) $business->id)), 'Nothing was queued, so no cooldown may start.');

        // The owner publishes straight away and checks at once: not blocked by the earlier refusal.
        $this->publishWebsite($business, [$this->cleanPage()]);
        $rerun()->assertRedirect()->assertSessionHas('status', 'success');
        Queue::assertPushed(RunSeoAuditForRevision::class, 1);

        // ...and only now is the cooldown real.
        $rerun()->assertRedirect()->assertSessionHas('status', 'error');
        Queue::assertPushed(RunSeoAuditForRevision::class, 1);
    }

    // -----------------------------------------------------------------
    // A12 — the title length is the REAL <title>; the words are plain.
    // -----------------------------------------------------------------

    private function titleLengthFinding(array $page, string $siteName = 'Test Website'): ?SeoAuditFinding
    {
        [, $business] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $website = $this->publishWebsite($business, [$page]);
        DB::table('website_revisions')->where('id', $website->published_revision_id)->update([
            'snapshot' => json_encode(['schema_version' => 1, 'website' => ['name' => $siteName, 'theme' => []], 'pages' => [$page], 'assets' => []]),
        ]);

        $run = $this->audit($website);

        return SeoAuditFinding::query()->where('seo_audit_run_id', $run->id)->where('rule_key', SeoAuditRuleRegistry::SEO_TITLE_OVER_RECOMMENDED)->first();
    }

    public function test_the_title_rule_measures_the_page_title_plus_the_business_name(): void
    {
        // 46 + " | Test Website" (15) = 61 > 60: the SEO title alone (46) would have passed.
        $over = $this->titleLengthFinding($this->snapshotPage('p1', 'Home', ['seo_title' => str_repeat('x', 46), 'meta_description' => str_repeat('a', 80)]));
        $this->assertNotNull($over);
        $this->assertSame(61, $over->facts['length']);
        $this->assertSame(60, $over->facts['recommended_max']);

        // 45 + 15 = 60 is fine (the rule is "> 60").
        $this->assertNull($this->titleLengthFinding($this->snapshotPage('p1', 'Home', ['seo_title' => str_repeat('x', 45), 'meta_description' => str_repeat('a', 80)])));
    }

    public function test_a_title_that_already_contains_the_business_name_is_not_double_counted(): void
    {
        // WebsiteHeadMeta::title() leaves it as written: 53 characters, not 53 + 15.
        $title = 'Wedding photo booths by Test Website in Naperville IL';
        $this->assertNull($this->titleLengthFinding($this->snapshotPage('p1', 'Home', ['seo_title' => $title, 'meta_description' => str_repeat('a', 80)])));
    }

    public function test_a_page_with_no_seo_title_is_measured_on_its_page_name_plus_the_business_name(): void
    {
        $long = str_repeat('y', 50);
        $finding = $this->titleLengthFinding($this->snapshotPage('p1', $long, ['seo_title' => null, 'meta_description' => str_repeat('a', 80)]));

        $this->assertNotNull($finding, 'The title searchers see is the page name plus the business name even without an SEO title.');
        $this->assertSame(50 + 15, $finding->facts['length']);
    }

    public function test_no_customer_facing_audit_word_is_seo_jargon(): void
    {
        $jargon = ['noindex', 'meta description', 'seo title', 'alternative text', 'alt text'];

        foreach (SeoAuditRuleRegistry::ruleKeys() as $key) {
            $words = strtolower(SeoAuditRuleRegistry::titleFor($key) . ' ' . SeoAuditRuleRegistry::describe($key, []));

            foreach ($jargon as $term) {
                $this->assertStringNotContainsString($term, $words, "[{$key}] must say it in plain words, not [{$term}].");
            }
        }

        $this->assertSame('Page hidden from search', SeoAuditRuleRegistry::titleFor(SeoAuditRuleRegistry::PAGE_MARKED_NOINDEX));
        $this->assertSame('No search result description set', SeoAuditRuleRegistry::titleFor(SeoAuditRuleRegistry::META_DESCRIPTION_BLANK));
        $this->assertSame('Images without a description', SeoAuditRuleRegistry::titleFor(SeoAuditRuleRegistry::ASSET_MISSING_ALT));
    }

    public function test_the_overview_counts_use_plain_words_too(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $this->publishWebsite($business, [$this->cleanPage()]);
        $this->authenticateAsSeoCustomer($customer);

        $html = $this->get($this->seoUrl($workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('with a search result description', $html);
        $this->assertStringContainsString('with a page title', $html);
        $this->assertStringContainsString('hidden from search', $html);
        $this->assertStringNotContainsString('meta description', $html);
        $this->assertStringNotContainsString('marked to be hidden from search engines', $html);
    }
}

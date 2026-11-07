<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Entitlement\PlatformFeature;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Jobs\Seo\RunSeoAuditForRevision;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Seo\SeoAuditPageReader;
use App\Library\Seo\SeoAuditRuleRegistry;
use App\Library\Seo\SeoAuditRunner;
use App\Library\Seo\SeoConfig;
use App\Models\Business;
use App\Models\Website;
use Illuminate\Support\Facades\DB;
use App\Models\WebsitePage;
use App\Models\Workspace;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Contract 18 Sub-slice G — the Website SEO / technical audit surface.
 *
 * REPORT-ONLY. It shows findings the audit already computed from the
 * immutable published snapshot and lets the customer ask for a re-run. It
 * writes nothing to `websites`, `website_pages`, `website_revisions` or
 * `website_assets` (§12.1), fetches no URL, runs no crawler and calls no AI
 * or provider. There is no auto-fix: a finding links to the existing Website
 * page editor, where the customer edits and publishes through Website's own
 * authorization, unchanged (§8.7).
 *
 * The chain (§10.1): Workspace by uid -> Business inside it ->
 * userCanAccessBusiness() -> Business Active -> the entitlement decision for
 * PlatformFeature::SeoModule -> the capability gate (`view_seo` to read,
 * `manage_seo` to re-run). Every mismatch is `abort(404)`, never 403, and no
 * implicit route-model binding is used.
 *
 * ENTITLEMENT. SeoModule is Available (Sub-slice H flipped it): Growth and
 * Agency reach these routes, Core and every foreign or unentitled caller gets
 * the same 404.
 */
class SeoAuditController extends CustomerBaseController
{
    use ResolvesBusinessTenancy;

    public function __construct(
        private readonly SeoAuditPageReader $reader,
        private readonly SeoAuditRunner $runner,
        private readonly SeoConfig $config,
    ) {
    }

    public function audit(string $workspaceUid, string $businessUid): View
    {
        [$workspace, $business] = $this->resolveAuditTenancy($workspaceUid, $businessUid);

        $this->authorize('view_seo');

        $auditPage = $this->reader->read($business);

        return view('customer.business.seo.audit', [
            'resolved' => $this->resolvedByFreshAudit($business, $auditPage),
            'publishedPending' => $this->publishedPendingFreshAudit($business, $auditPage),
            'draftFixes' => $this->draftFixes($workspace, $business, $workspaceUid, $businessUid, $auditPage->findings),
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'business' => $business,
            'page' => $auditPage,
            'canManage' => auth()->user()->can('manage_seo'),
        ]);
    }

    /**
     * §8.7 — the throttled manual re-run.
     *
     * It queues an audit of the CURRENTLY published revision. Because audit
     * identity is `(website_revision_id, rule_set_version)`, re-running an
     * already-audited revision converges on the existing run instead of
     * duplicating it — so this button is safe to press repeatedly and cannot
     * inflate history.
     *
     * THE THROTTLE IS REQUEST-ABUSE PROTECTION, NOT CORRECTNESS. Correctness
     * is the database UNIQUE key above; this only stops one actor pushing
     * repeated queue work for one Business. It is therefore keyed by ACTOR
     * AND BUSINESS, never globally: one Business (or one impatient user)
     * must never be able to block another's re-run.
     *
     * It is also consumed LAST. Tenancy, entitlement and the capability gate
     * all run first, so an unauthorized or unentitled request is refused
     * without ever touching the limiter — otherwise a stranger's 404s could
     * burn the real customer's cooldown. For the same reason it is consumed
     * only once there is a published website to check: a request that queues
     * nothing must not start a cooldown on a real one.
     */
    public function rerun(string $workspaceUid, string $businessUid): RedirectResponse
    {
        [, $business] = $this->resolveAuditTenancy($workspaceUid, $businessUid);

        $this->authorize('manage_seo');

        $back = redirect()->route('customer.workspaces.businesses.seo.audit.index', [$workspaceUid, $businessUid]);

        $target = $this->runner->publishedTargetFor((int) $business->id);

        if ($target === null) {
            return $back->with([
                'status' => 'error',
                'message' => 'There is no published website to check yet. Publish your website first.',
            ]);
        }

        $limiterKey = $this->rerunLimiterKey((int) Auth::id(), (int) $business->id);

        if (RateLimiter::tooManyAttempts($limiterKey, 1)) {
            return $back->with([
                'status' => 'error',
                'message' => 'We are already checking your website. Try again in a moment.',
            ]);
        }

        RateLimiter::hit($limiterKey, $this->config->auditManualRerunCooldownSeconds());

        RunSeoAuditForRevision::dispatch((int) $business->id, $target['website_id'], $target['revision_id']);

        return $back->with([
            'status' => 'success',
            'message' => 'Checking your published website. Findings appear here shortly.',
        ]);
    }

    /**
     * One cooldown bucket per (actor, Business). Public so a test can assert
     * the exact key rather than guess it.
     */
    public static function rerunLimiterKey(int $actorUserId, int $businessId): string
    {
        return 'seo-audit-rerun:' . $actorUserId . ':' . $businessId;
    }

    /**
     * The chain through entitlement. Its own method so a test-only subclass
     * can replace exactly and only this step.
     *
     * @return array{0: Workspace, 1: Business}
     */
    protected function resolveAuditTenancy(string $workspaceUid, string $businessUid): array
    {
        return $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, PlatformFeature::SeoModule->value);
    }

    /** Page-level findings the existing Website page editor can repair in the DRAFT. */
    private const DRAFT_FIXABLE = [
        SeoAuditRuleRegistry::SEO_TITLE_BLANK => 'seo_title',
        SeoAuditRuleRegistry::META_DESCRIPTION_BLANK => 'meta_description',
        SeoAuditRuleRegistry::META_DESCRIPTION_SHORT => 'meta_description',
    ];

    /**
     * For each fixable finding, a link to the EXISTING Website draft page editor — and only when this actor may edit the website, the
     * Business is entitled to Website, and the audited page still exists in this Business's draft. The draft is re-read so the link
     * never claims a problem the draft has already fixed ("fixed" = the field is now non-blank in the draft; nothing is published).
     * Nothing is written here: the owner edits and saves in the editor (the same authorized, locked draft update), then publishes themselves.
     *
     * @param  array<int, \App\Library\Seo\SeoAuditFindingView>  $findings
     * @return array<int, array{url: string, label: string, draftFixed: bool, live: bool}>  keyed by finding index
     */
    private function draftFixes(Workspace $workspace, Business $business, string $workspaceUid, string $businessUid, array $findings): array
    {
        $uids = [];

        foreach ($findings as $finding) {
            if ($finding->pageUid !== null && isset(self::DRAFT_FIXABLE[$finding->ruleKey])) {
                $uids[] = $finding->pageUid;
            }
        }

        if ($uids === []
            || ! Auth::user()->can('website')
            || ! app(EntitlementManager::class)->decide($workspace, $business, PlatformFeature::WebsiteGeneration->value, (int) Auth::id())->allowed) {
            return [];
        }

        $draft = WebsitePage::query()
            ->whereIn('website_id', Website::query()->where('business_id', $business->id)->select('id'))
            ->whereIn('uid', array_unique($uids))
            ->get()->keyBy('uid');

        // What the PUBLISHED revision now says for these pages: the only evidence that a fix is live. One read of the current published snapshot.
        $published = [];
        $revisionId = Website::query()->where('business_id', $business->id)->value('published_revision_id');

        if ($revisionId !== null) {
            $snapshot = DB::table('website_revisions')->where('id', $revisionId)->value('snapshot');

            foreach ((json_decode((string) $snapshot, true)['pages'] ?? []) as $snapPage) {
                if (isset($snapPage['uid'])) {
                    $published[(string) $snapPage['uid']] = $snapPage;
                }
            }
        }

        $fixes = [];

        foreach ($findings as $i => $finding) {
            $field = self::DRAFT_FIXABLE[$finding->ruleKey] ?? null;
            $page = $finding->pageUid === null ? null : $draft->get($finding->pageUid);

            if ($field === null || $page === null) {
                continue;
            }

            // Only a blank field is provably fixed by a non-blank draft value; a "too short" description is never claimed fixed.
            $fixed = $finding->ruleKey !== SeoAuditRuleRegistry::META_DESCRIPTION_SHORT && trim((string) $page->{$field}) !== '';

            // live = the current published version has the value; drafted = only the draft has it; open = neither. A value that is merely
            // saved or published is never described as improved rankings.
            $live = $finding->ruleKey !== SeoAuditRuleRegistry::META_DESCRIPTION_SHORT && trim((string) ($published[$page->uid]['seo'][$field] ?? '')) !== '';

            $fixes[$i] = [
                'live' => $live,
                'url' => route('customer.workspaces.businesses.website.pages.edit', [$workspaceUid, $businessUid, $page->uid]) . '#' . $field,
                'label' => $field === 'seo_title' ? 'Set the page title in your draft' : 'Prepare fix',
                'draftFixed' => $fixed,
            ];
        }

        return $fixes;
    }
    /**
     * Conservative interim evidence only when the CURRENT published revision has no audit yet:
     * a prior completed audit found a blank field, and the new immutable published revision
     * contains a nonblank value for the SAME page. This is not a resolved finding.
     * The reader's current-revision-only findings must never be replaced with stale findings.
     *
     * @return array<int, array{title: string, what: string}>
     */
    private function publishedPendingFreshAudit(Business $business, \App\Library\Seo\SeoAuditPage $auditPage): array
    {
        if ($auditPage->state() !== \App\Library\Seo\SeoAuditPage::STATE_NOT_CHECKED) {
            return [];
        }

        $website = Website::query()->where('business_id', $business->id)->first(['id', 'published_revision_id']);

        if ($website === null || $website->published_revision_id === null) {
            return [];
        }

        $prior = collect($auditPage->history)->first(
            fn ($run) => $run->status === \App\Enums\Seo\SeoAuditRunStatus::Completed
                && (int) $run->rule_set_version === SeoAuditRuleRegistry::VERSION
                && (int) $run->website_revision_id !== (int) $website->published_revision_id
        );

        if ($prior === null) {
            return [];
        }

        $raw = DB::table('website_revisions')
            ->where('website_id', $website->id)
            ->where('id', $website->published_revision_id)
            ->value('snapshot');
        $decoded = json_decode((string) $raw, true);
        $pages = [];

        foreach (($decoded['pages'] ?? []) as $page) {
            if (is_array($page) && isset($page['uid'])) {
                $pages[(string) $page['uid']] = $page;
            }
        }

        $blank = [SeoAuditRuleRegistry::SEO_TITLE_BLANK, SeoAuditRuleRegistry::META_DESCRIPTION_BLANK];
        $findings = DB::table('seo_audit_findings')
            ->where('seo_audit_run_id', $prior->id)
            ->whereIn('rule_key', $blank)
            ->whereNotNull('page_uid')
            ->orderBy('id')
            ->limit(100)
            ->get(['page_uid', 'rule_key']);
        $result = [];

        foreach ($findings as $finding) {
            $page = $pages[(string) $finding->page_uid] ?? null;
            $field = $finding->rule_key === SeoAuditRuleRegistry::SEO_TITLE_BLANK ? 'seo_title' : 'meta_description';

            if ($page === null || trim((string) ($page['seo'][$field] ?? '')) === '') {
                continue;
            }

            $result[] = [
                'title' => (string) ($page['title'] ?? 'A page'),
                'what' => $field === 'seo_title' ? 'page title' : 'description',
            ];

            if (count($result) >= 5) {
                break;
            }
        }

        return $result;
    }

    /**
     * Blank title / description findings that the PREVIOUS completed audit of this Website had and the latest audit no longer has: the
     * only evidence that a fix is resolved. Publishing alone never produces an entry. Two bounded reads, on this page only.
     *
     * @return array<int, array{title: string, what: string}>
     */
    private function resolvedByFreshAudit(Business $business, \App\Library\Seo\SeoAuditPage $auditPage): array
    {
        $latest = $auditPage->latestRun;

        if ($latest === null || $latest->status !== \App\Enums\Seo\SeoAuditRunStatus::Completed) {
            return [];
        }

        $previous = \App\Models\SeoAuditRun::query()
            ->where('business_id', $business->id)
            ->where('website_id', $latest->website_id)
            ->where('id', '<', $latest->id)
            ->where('status', \App\Enums\Seo\SeoAuditRunStatus::Completed->value)
            ->orderByDesc('id')
            ->first();

        if ($previous === null) {
            return [];
        }

        $blank = [SeoAuditRuleRegistry::SEO_TITLE_BLANK, SeoAuditRuleRegistry::META_DESCRIPTION_BLANK];
        $rows = DB::table('seo_audit_findings')
            ->whereIn('seo_audit_run_id', [$previous->id, $latest->id])
            ->whereIn('rule_key', $blank)
            ->whereNotNull('page_uid')
            ->get(['seo_audit_run_id', 'rule_key', 'page_uid']);

        $now = [];
        $before = [];

        foreach ($rows as $row) {
            $key = $row->rule_key . '|' . $row->page_uid;
            ((int) $row->seo_audit_run_id === (int) $latest->id) ? $now[$key] = true : $before[$key] = $row;
        }

        // A finding can disappear because its page was REMOVED. Only call it fixed when
        // the current published snapshot still contains the same page and the field is nonblank.
        // This replaces the old draft-page title lookup with one bounded revision read.
        $raw = DB::table('website_revisions')
            ->where('website_id', $latest->website_id)
            ->where('id', $latest->website_revision_id)
            ->value('snapshot');
        $snapshot = json_decode((string) $raw, true);
        $pages = [];

        foreach (($snapshot['pages'] ?? []) as $snapPage) {
            if (is_array($snapPage) && isset($snapPage['uid'])) {
                $pages[(string) $snapPage['uid']] = $snapPage;
            }
        }

        $resolved = [];

        foreach ($before as $key => $row) {
            $page = $pages[(string) $row->page_uid] ?? null;
            $field = $row->rule_key === SeoAuditRuleRegistry::SEO_TITLE_BLANK ? 'seo_title' : 'meta_description';

            if (isset($now[$key]) || $page === null || trim((string) ($page['seo'][$field] ?? '')) === '') {
                continue;
            }

            $resolved[] = [
                'title' => (string) ($page['title'] ?? 'A page'),
                'what' => $field === 'seo_title' ? 'page title' : 'description',
            ];

            if (count($resolved) >= 5) {
                break;
            }
        }

        return $resolved;
    }
}

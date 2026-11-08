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
            'auditRunUid' => $auditPage->latestRun?->uid,
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
     * @return array<int, array{url: string, label: string, draftFixed: bool, live: bool, proposal: ?string, currentDescription: string, replacesDescription: bool, pageUid: string, pageTitle: string, draftToken: string}>  keyed by finding index
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

            // A blank field is fixed by a non-blank draft value. A "too short" description counts as updated in the draft only once it
            // reaches the audit's own recommended minimum; either way, only a fresh audit ever calls it resolved.
            $isShort = $finding->ruleKey === SeoAuditRuleRegistry::META_DESCRIPTION_SHORT;
            $minDescription = $this->config->auditMetaDescriptionMinRecommended();
            $fixed = $isShort ? mb_strlen(trim((string) $page->{$field})) >= $minDescription : trim((string) $page->{$field}) !== '';

            // live = the current published version has the value; drafted = only the draft has it; open = neither. A value that is merely
            // saved or published is never described as improved rankings.
            $publishedValue = trim((string) ($published[$page->uid]['seo'][$field] ?? ''));
            $live = $isShort ? mb_strlen($publishedValue) >= $minDescription : $publishedValue !== '';

            // A blank title also gets a deterministic proposal the owner can edit and approve right here (nothing is generated or saved).
            $proposal = $finding->ruleKey === SeoAuditRuleRegistry::SEO_TITLE_BLANK && ! $fixed && ! $live
                ? \App\Library\Seo\SeoTitleProposal::for((string) $page->title, (string) $business->name, (bool) $page->is_home, $this->config->auditSeoTitleMaxRecommended())
                : null;

            // A blank description gets an editable field and an optional, explicitly requested suggestion (existing read-only endpoint).
            // A too-short one gets the same form, pre-filled with the current text so it can be reviewed and replaced.
            $descriptionForm = in_array($finding->ruleKey, [SeoAuditRuleRegistry::META_DESCRIPTION_BLANK, SeoAuditRuleRegistry::META_DESCRIPTION_SHORT], true) && ! $fixed && ! $live;

            $fixes[$i] = [
                'descriptionForm' => $descriptionForm,
                'currentDescription' => $isShort ? trim((string) $page->meta_description) : '',
                'replacesDescription' => $isShort,
                'suggestUrl' => $descriptionForm ? route('customer.workspaces.businesses.website.pages.suggestDescription', [$workspaceUid, $businessUid, $page->uid]) : null,
                'proposal' => $proposal,
                'pageUid' => $page->uid,
                'pageTitle' => (string) $page->title,
                'draftToken' => $page->draftToken(),
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

        $blank = [SeoAuditRuleRegistry::SEO_TITLE_BLANK, SeoAuditRuleRegistry::META_DESCRIPTION_BLANK, SeoAuditRuleRegistry::META_DESCRIPTION_SHORT];
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

            if ($page === null || ! $this->satisfiesRule($finding->rule_key, (string) ($page['seo'][$field] ?? ''))) {
                continue;
            }

            $result[] = [
                'title' => (string) ($page['title'] ?? 'A page'),
                'what' => $this->whatFor($finding->rule_key),
            ];

            if (count($result) >= 5) {
                break;
            }
        }

        return $result;
    }

    /** A blank title/description finding is satisfied by any non-blank value; a too-short description only once it reaches the audit's own minimum. */
    private function satisfiesRule(string $ruleKey, string $value): bool
    {
        $value = trim($value);

        return $ruleKey === SeoAuditRuleRegistry::META_DESCRIPTION_SHORT
            ? mb_strlen($value) >= $this->config->auditMetaDescriptionMinRecommended()
            : $value !== '';
    }

    private function whatFor(string $ruleKey): string
    {
        return match ($ruleKey) {
            SeoAuditRuleRegistry::SEO_TITLE_BLANK => 'page title',
            SeoAuditRuleRegistry::META_DESCRIPTION_SHORT => 'fuller description',
            default => 'description',
        };
    }

    /**
     * The customer's explicit approval of a proposed SEO title for ONE page whose blank title the latest completed audit reported.
     * See applyDraftField(): saves only to the Website DRAFT, never publishes, never overwrites, idempotent, refused when stale.
     */
    public function applyTitle(\Illuminate\Http\Request $request, string $workspaceUid, string $businessUid, string $pageUid): RedirectResponse
    {
        return $this->applyDraftField($request, $workspaceUid, $businessUid, $pageUid, [
            'input' => 'seo_title', 'column' => 'seo_title', 'rule' => SeoAuditRuleRegistry::SEO_TITLE_BLANK,
            'noun' => 'title', 'blank' => 'Enter a page title to save.', 'min' => 1, 'max' => 70,
        ]);
    }

    /**
     * The same approval for a blank META DESCRIPTION (70-160 characters, the editor's and the suggester's own limits). The text may come
     * from the existing read-only "Suggest description" endpoint (an explicit click, never on page load) or be typed by hand.
     */
    public function applyDescription(\Illuminate\Http\Request $request, string $workspaceUid, string $businessUid, string $pageUid): RedirectResponse
    {
        return $this->applyDraftField($request, $workspaceUid, $businessUid, $pageUid, [
            'input' => 'meta_description', 'column' => 'meta_description', 'rule' => SeoAuditRuleRegistry::META_DESCRIPTION_BLANK,
            'replaces' => SeoAuditRuleRegistry::META_DESCRIPTION_SHORT,
            'noun' => 'description', 'blank' => 'Enter a description to save.',
            'min' => \App\Library\Website\WebsiteMetaDescriptionSuggester::MIN, 'max' => \App\Library\Website\WebsiteMetaDescriptionSuggester::MAX,
        ]);
    }

    /**
     * Shared by both approvals. Saves exactly the approved text to that page's Website DRAFT through the canonical draft-page service,
     * under the Website lock, and nothing else: it never publishes, never overwrites an existing value, and is a no-op when repeated.
     * Rejected when the audit evidence is gone or has moved on, the page is not this Business's, or the draft changed since the form
     * was shown (WebsitePage::draftToken(), the editor's own state fingerprint).
     *
     * @param  array{input: string, column: string, rule: string, noun: string, blank: string, min: int, max: int, replaces?: string}  $field
     */
    private function applyDraftField(\Illuminate\Http\Request $request, string $workspaceUid, string $businessUid, string $pageUid, array $field): RedirectResponse
    {
        [$workspace, $business] = $this->resolveAuditTenancy($workspaceUid, $businessUid);

        $this->authorize('view_seo');
        $this->authorize('website');

        $back = redirect()->route('customer.workspaces.businesses.seo.audit.index', [$workspaceUid, $businessUid]);
        $refuse = fn (string $message): RedirectResponse => $back->with(['status' => 'error', 'message' => $message]);

        if (! app(EntitlementManager::class)->decide($workspace, $business, PlatformFeature::WebsiteGeneration->value, (int) Auth::id())->allowed) {
            abort(404);
        }

        $data = $request->validate([
            $field['input'] => ['required', 'string', 'max:5000'],
            'audit_run' => ['required', 'string'],
            'draft_token' => ['required', 'string'],
        ]);
        $value = trim($data[$field['input']]);

        if ($value === '') {
            return $refuse($field['blank']);
        }

        if (mb_strlen($value) < $field['min'] || mb_strlen($value) > $field['max']) {
            return $refuse('The ' . $field['noun'] . ' must be between ' . $field['min'] . ' and ' . $field['max'] . ' characters. Nothing was saved.');
        }

        // Evidence: the latest COMPLETED audit must still report this blank for this page, and must be the audit the form was shown for.
        $auditPage = $this->reader->read($business);
        $run = $auditPage->latestRun;
        $reported = $run !== null && $run->status === \App\Enums\Seo\SeoAuditRunStatus::Completed && $run->uid === $data['audit_run']
            && collect($auditPage->findings)->contains(fn ($f) => $f->pageUid === $pageUid && in_array($f->ruleKey, [$field['rule'], $field['replaces'] ?? $field['rule']], true));
        // Which finding it is decides whether an existing value may be replaced: only a too-short one is a replacement the owner reviewed.
        $replacing = isset($field['replaces']) && collect($auditPage->findings)->contains(fn ($f) => $f->pageUid === $pageUid && $f->ruleKey === $field['replaces']);

        if (! $reported) {
            return $refuse('That finding is no longer in your latest audit, so nothing was saved. Review the audit and try again.');
        }

        $website = Website::query()->where('business_id', $business->id)->firstOrFail();
        WebsitePage::query()->where('website_id', $website->id)->where('uid', $pageUid)->firstOrFail();

        try {
            $outcome = app(\App\Library\Website\GuidedGeneration\WebsiteGenerationCoordinator::class)->runExclusive($website, function () use ($website, $pageUid, $value, $data, $field, $replacing, $business) {
                $locked = WebsitePage::query()->where('website_id', $website->id)->where('uid', $pageUid)->first();

                if ($locked === null) {
                    return 'gone';
                }

                $existing = trim((string) $locked->{$field['column']});

                if ($existing === $value) {
                    return 'already';
                }

                // A blank may only be filled; a too-short description may be replaced, but only if it is still short (the audit's own
                // minimum) and the draft is exactly as shown (token below). Anything else is never overwritten.
                if ($existing !== '' && (! $replacing || mb_strlen($existing) >= $this->config->auditMetaDescriptionMinRecommended())) {
                    return 'has_value';
                }

                if ($locked->draftToken() !== $data['draft_token']) {
                    return 'stale';
                }

                app(\App\Library\Website\WebsiteDraftPageService::class)->updatePage($website, $locked, [
                    'title' => $locked->title,
                    'slug' => $locked->slug,
                    'is_home' => (bool) $locked->is_home,
                    'sections' => $locked->sections ?? [],
                    'seo_title' => $locked->seo_title,
                    'meta_description' => $locked->meta_description,
                    'noindex' => (bool) $locked->noindex,
                    'noindex_explicit' => (bool) $locked->noindex_explicit,
                    $field['column'] => $value,
                ]);

                return 'saved';
            });
        } catch (\App\Library\Website\Setup\Exceptions\GenerationInProgressException $e) {
            return $refuse($e->getMessage());
        }

        $noun = $field['noun'];

        return match ($outcome) {
            'saved' => $back->with(['status' => 'success', 'message' => ucfirst($noun) . ' saved to your draft. It is not live until you publish your website.']),
            'already' => $back->with(['status' => 'success', 'message' => 'That ' . $noun . ' is already in your draft. Nothing changed. Publish your website to make it live.']),
            'has_value' => $refuse('This page already has a ' . $noun . ' in your draft, so nothing was overwritten.'),
            'stale' => $refuse('This page changed after the proposal was shown, so nothing was saved. Review it and try again.'),
            default => $refuse('That page no longer exists, so nothing was saved.'),
        };
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

        $blank = [SeoAuditRuleRegistry::SEO_TITLE_BLANK, SeoAuditRuleRegistry::META_DESCRIPTION_BLANK, SeoAuditRuleRegistry::META_DESCRIPTION_SHORT];
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

            if (isset($now[$key]) || $page === null || ! $this->satisfiesRule($row->rule_key, (string) ($page['seo'][$field] ?? ''))) {
                continue;
            }

            $resolved[] = [
                'title' => (string) ($page['title'] ?? 'A page'),
                'what' => $this->whatFor($row->rule_key),
            ];

            if (count($resolved) >= 5) {
                break;
            }
        }

        return $resolved;
    }
}

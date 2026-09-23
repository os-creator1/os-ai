<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Entitlement\PlatformFeature;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Jobs\Seo\RunSeoAuditForRevision;
use App\Library\Seo\SeoAuditPageReader;
use App\Library\Seo\SeoAuditRunner;
use App\Models\Business;
use App\Models\Workspace;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

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
 * FAIL-CLOSED WHILE `Planned`. SeoModule is Planned until Sub-slice H, so
 * every route here answers 404 today.
 */
class SeoAuditController extends CustomerBaseController
{
    use ResolvesBusinessTenancy;

    public function __construct(
        private readonly SeoAuditPageReader $reader,
        private readonly SeoAuditRunner $runner,
    ) {
    }

    public function audit(string $workspaceUid, string $businessUid): View
    {
        [, $business] = $this->resolveAuditTenancy($workspaceUid, $businessUid);

        $this->authorize('view_seo');

        return view('customer.business.seo.audit', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'business' => $business,
            'page' => $this->reader->read($business),
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
                'message' => 'There is no published website to check yet.',
            ]);
        }

        RunSeoAuditForRevision::dispatch((int) $business->id, $target['website_id'], $target['revision_id']);

        return $back->with([
            'status' => 'success',
            'message' => 'Checking your published website. Findings appear here shortly.',
        ]);
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
}

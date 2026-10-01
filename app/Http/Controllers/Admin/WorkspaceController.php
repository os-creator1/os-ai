<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Entitlement\PlatformFeature;
use App\Exceptions\Workspace\BusinessWorkspaceMismatchException;
use App\Exceptions\Workspace\WorkspaceBusinessNotFoundException;
use App\Http\Requests\Workspace\AdminWorkspaceIndexRequest;
use App\Library\Entitlement\EntitlementManager;
use App\Library\PlatformOwner\PlatformOwnerOverviewReader;
use App\Library\PlatformOwner\WorkspaceSupportReader;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Admin-only, intentionally cross-tenant, READ-ONLY Workspace inspection
 * (RFC-003 Milestone 5 — `docs/automation/RFC-003-M5-CONTRACT.md`). No
 * WorkspaceManager dependency: this controller never mutates, so none of
 * WorkspaceManager's customer-facing authority/locking logic applies here.
 * Platform-administrator access is intentionally independent of Workspace
 * owner/membership/business_access_scope — those are customer-side RFC-003
 * §14.1 concerns and are never consulted by either action below.
 */
class WorkspaceController extends AdminBaseController
{
    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly EntitlementManager $entitlementManager,
        private readonly WorkspaceSupportReader $support,
        private readonly PlatformOwnerOverviewReader $overview,
    ) {
    }

    /**
     * Deliberately zero-argument to stay signature-compatible with
     * AdminBaseController::index() — mirrors BusinessController::index()'s
     * identical rationale. The dedicated Form Request is instead resolved
     * through the container, which triggers Laravel's normal authorization/
     * validation lifecycle identically to type-hinting it as a method
     * parameter.
     */
    public function index(): View
    {
        $this->authorize('view workspace');

        $request = app(AdminWorkspaceIndexRequest::class);

        $workspaces = $this->workspaceRepository->paginateForAdmin($request->filters(), $request->perPage());

        return view('admin.workspaces.index', [
            'workspaces' => $workspaces,
            'filters' => $request->filters(),
            // Plan + subscription for the whole page in two queries, never one
            // lookup per row.
            'page' => $this->overview->forWorkspacePage($workspaces->items()),
            'breadcrumbs' => $this->breadcrumbs(),
        ]);
    }

    public function show(Request $request, Workspace $workspace): View
    {
        $this->authorize('view workspace');

        $workspace->loadMissing(['owner']);

        // Platform Owner / Admin V1 — the support cockpit's read model. Its
        // member and Business lists are capped; nothing here loads a
        // Workspace's whole membership.
        $support = $this->support->forWorkspace($workspace);

        $viewData = [
            'workspace' => $workspace,
            'support' => $support,
            'actors' => $this->support->actorLabels($support['recentActions']),
            'breadcrumbs' => $this->breadcrumbs($workspace),
        ];

        // The read card (§14, gated by @can('view workspace plans')) and the
        // mutate card (gated by @can('manage workspace plans')) are
        // independent permissions -- an administrator can legitimately hold
        // either without the other. Both cards render from the same
        // entitlementSummary, so it must be loaded whenever either
        // permission is present; the explanation query is exclusively the
        // read card's own feature and stays gated to that one permission.
        if (Gate::allows('view workspace plans') || Gate::allows('manage workspace plans')) {
            $summary = $this->entitlementManager->getWorkspaceEntitlementSummary($workspace);
            $viewData['entitlementSummary'] = $summary;

            // Customer Experience Slice 1A, correction round 2 (RFC-004
            // §33.2): the additional-Business-slot chooser offers only what
            // the catalog rows actually allow, so the form can never present
            // a value EntitlementManager would refuse. The assign/change
            // forms pick their tier in the same request, so they offer only
            // what EVERY tier allows; the update form knows this Workspace's
            // own tier.
            $optionsByTier = $this->entitlementManager->additionalBusinessSlotOptionsByTier();
            $viewData['additionalBusinessSlotOptionsByTier'] = $optionsByTier;
            $viewData['additionalBusinessSlotOptionsForAnyTier'] = array_values(array_intersect(...array_values($optionsByTier)));
            $viewData['additionalBusinessSlotOptionsForCurrentTier'] = $summary->tier === null
                ? [0]
                : ($optionsByTier[$summary->tier->value] ?? [0]);
        }

        if (Gate::allows('view workspace plans')) {
            $viewData['entitlementExplanation'] = $this->resolveEntitlementExplanation($request, $workspace);
        }

        return view('admin.workspaces.show', $viewData);
    }

    /**
     * §11's optional effective-entitlement explanation block: only computed
     * when both business_uid and feature_key query parameters are present.
     * An unknown feature_key or a business_uid that does not belong to this
     * already-loaded Workspace both fail closed with 404 -- addressability
     * failures, matching Blocker 4's rule applied identically here.
     *
     * @return array{business: \App\Models\Business, feature: PlatformFeature, decision: \App\Library\Entitlement\EntitlementDecision}|null
     */
    private function resolveEntitlementExplanation(Request $request, Workspace $workspace): ?array
    {
        $businessUid = $request->query('business_uid');
        $featureKey = $request->query('feature_key');

        if ($businessUid === null || $featureKey === null) {
            return null;
        }

        $feature = PlatformFeature::tryFrom((string) $featureKey);

        if ($feature === null) {
            abort(404);
        }

        // Indexed lookup scoped to THIS Workspace — a Business uid that
        // belongs anywhere else is simply not found.
        $business = $workspace->businesses()->where('uid', (string) $businessUid)->first();

        if ($business === null) {
            abort(404);
        }

        // decide() re-reads the authoritative Business and can throw if it
        // was deleted/reassigned between the eager-loaded read above and
        // this call -- an addressability/stale-target race, not an
        // authority failure, so it fails closed with 404 rather than
        // leaking an uncaught 500 (Blocker 4's rule, applied identically
        // here).
        try {
            $decision = $this->entitlementManager->decide($workspace, $business, $feature->value, (int) Auth::id());
        } catch (WorkspaceBusinessNotFoundException|BusinessWorkspaceMismatchException) {
            abort(404);
        }

        return [
            'business' => $business,
            'feature' => $feature,
            'decision' => $decision,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function breadcrumbs(?Workspace $workspace = null): array
    {
        $breadcrumbs = [
            ['link' => url(config('app.admin_path') . '/dashboard'), 'name' => __('locale.menu.Dashboard')],
            ['link' => route('admin.workspaces.index'), 'name' => 'Workspaces'],
        ];

        if ($workspace !== null) {
            $breadcrumbs[] = ['name' => $workspace->name];
        }

        return $breadcrumbs;
    }
}

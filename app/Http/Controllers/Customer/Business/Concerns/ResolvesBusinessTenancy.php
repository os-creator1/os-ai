<?php

namespace App\Http\Controllers\Customer\Business\Concerns;

use App\Enums\Business\BusinessStatus;
use App\Exceptions\Workspace\BusinessWorkspaceMismatchException;
use App\Exceptions\Workspace\WorkspaceBusinessNotFoundException;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Navigation\ContextSource;
use App\Library\Navigation\CustomerContext;
use App\Library\Navigation\CustomerMenuBuilder;
use App\Library\Navigation\CustomerShellComposer;
use App\Library\Workspace\BusinessRouteAccess;
use App\Models\Business;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Support\Facades\Auth;

/**
 * The tenancy chain every Business-scoped customer controller runs, in one
 * place instead of one private copy per controller (Automations V2 §18,
 * Phase 2 — "the remaining shared floor comes from repeatedly deriving
 * Account/Business/entitlement facts even though ResolveCustomerContext...
 * has already resolved them").
 *
 * This generalizes, by feature key, the exact seam V2-E's own
 * ResolvesAutomationWorkflows::resolveEntitledBusiness() already proved out
 * for the workflow endpoints — rather than adding a seventh near-identical
 * private method, every other Business-scoped controller adopts the same
 * shape here.
 *
 * WHAT CHANGED AND WHAT DID NOT. The AUTHORIZATION is the same chain every
 * one of these controllers ran before, on every request: an active
 * Workspace, the canonical Business-route decision
 * (BusinessRouteAccess::actorMayUseBusinessRoute() — ordinary
 * WorkspaceManager tenancy, or the exact currently-valid View-As target, and
 * nothing else), an active Business, and — when a feature key is given —
 * EntitlementManager's decision. Only where the answers are FETCHED from
 * changed:
 *
 *   IDENTIFICATION. ResolveCustomerContext has already read, in one
 *   statement, which Workspaces and Businesses this actor can see, and
 *   bound the result to the request. When it selected exactly this route's
 *   pair — for this actor, from this route, with no view-as session
 *   narrowing it — the ids come from there instead of two more repository
 *   reads. That snapshot is a presentation read model and is NEVER trusted
 *   as an authorization answer: the canonical decision still runs below
 *   and re-reads the persisted rows itself. When the context did not select
 *   this exact pair (a route uid the snapshot doesn't list, a view-as
 *   session, a stale snapshot from earlier in the request), the
 *   repositories are used exactly as before, so nobody who could reach this
 *   Business before is refused now. A view-as request therefore always takes
 *   the repository path here, and its authority comes from
 *   BusinessRouteAccess below — never from the snapshot.
 *
 *   ENTITLEMENT. When the requested feature is one the customer shell
 *   already gates the menu on (CustomerMenuBuilder::ENTITLEMENT_GATED_FEATURES)
 *   and the context matched, the decision is read from that same
 *   per-request entitlement snapshot the shell composer builds anyway
 *   (CustomerShellComposer::currentMenuEntitlements()) — the identical
 *   eight-step precedence EntitlementManager::decide() applies, just asked
 *   once per request instead of twice. Any feature outside that gated list,
 *   or any request the context cannot vouch for, calls decide() directly,
 *   exactly as before.
 */
trait ResolvesBusinessTenancy
{
    /**
     * @return array{0: Workspace, 1: Business}
     */
    protected function resolveBusinessTenancy(string $workspaceUid, string $businessUid, bool $requireActive = true): array
    {
        [, $workspace, $business] = $this->resolveBusinessTenancyWithContext($workspaceUid, $businessUid, $requireActive);

        return [$workspace, $business];
    }

    /**
     * @return array{0: Workspace, 1: Business}
     */
    protected function resolveEntitledBusinessTenancy(string $workspaceUid, string $businessUid, string $featureKey): array
    {
        [$context, $workspace, $business] = $this->resolveBusinessTenancyWithContext($workspaceUid, $businessUid, true);

        try {
            $allowed = $context !== null && in_array($featureKey, CustomerMenuBuilder::ENTITLEMENT_GATED_FEATURES, true)
                ? app(CustomerShellComposer::class)->currentMenuEntitlements($context)->allows($featureKey)
                : app(EntitlementManager::class)->decide($workspace, $business, $featureKey, (int) Auth::id())->allowed;
        } catch (WorkspaceBusinessNotFoundException|BusinessWorkspaceMismatchException) {
            abort(404);
        }

        if (! $allowed) {
            abort(404);
        }

        return [$workspace, $business];
    }

    /**
     * @return array{0: ?CustomerContext, 1: Workspace, 2: Business}
     */
    private function resolveBusinessTenancyWithContext(string $workspaceUid, string $businessUid, bool $requireActive): array
    {
        $userId = (int) Auth::id();
        $context = $this->tenancyContextSelecting($workspaceUid, $businessUid, $userId);

        [$workspace, $business] = $context !== null
            ? $this->tenancyIdentifiedFromContext($context)
            : $this->tenancyIdentifiedFromRepositories($workspaceUid, $businessUid);

        if ($workspace === null || ($requireActive && ! $workspace->is_active)) {
            abort(404);
        }

        if ($business === null || ! app(BusinessRouteAccess::class)->actorMayUseBusinessRoute(Auth::user(), $workspace, $business)) {
            abort(404);
        }

        if ($requireActive && $business->status !== BusinessStatus::Active) {
            abort(404);
        }

        return [$context, $workspace, $business];
    }

    /**
     * The request's already-resolved context, but only when it selected
     * EXACTLY this route's Workspace and Business, from this route, for
     * this actor, with no view-as session narrowing it. Anything else
     * returns null and the request takes the repository path.
     */
    private function tenancyContextSelecting(string $workspaceUid, string $businessUid, int $userId): ?CustomerContext
    {
        $context = request()->attributes->get('customerContext');

        if (! $context instanceof CustomerContext
            || $context->userId !== $userId
            || $context->viewAs !== null
            || $context->source !== ContextSource::Route
            || ! $context->isBusinessFrame()) {
            return null;
        }

        $workspace = $context->frameWorkspace();
        $business = $context->selectedBusiness;

        if ($workspace === null || $business === null
            || $workspace->uid !== $workspaceUid
            || $business->uid !== $businessUid) {
            return null;
        }

        return $context;
    }

    /**
     * The ids only come from the context this request already read — every
     * field on the returned models is a fresh, FULLY hydrated read by
     * primary key, never a partial object built from the context's
     * presentation candidate.
     *
     * DELIBERATELY NOT a skeleton/forceFill shortcut. Every one of these
     * six controllers hands its resolved Business/Workspace on to real
     * business logic beyond tenancy identifiers alone (draft/document
     * generation, connection binding, completeness checks, ...), so
     * anything less than the real row risks silently wrong answers there —
     * unlike V2-E's own ResolvesAutomationWorkflows, which never reads
     * either model for anything but ->id and can safely skip the row read
     * entirely. findById() is itself request-memoized (Phase 1), so this
     * costs the same one read per row that userCanAccessBusiness() would
     * have paid for anyway; the id it reads by (from the context, not a
     * uid-keyed collection scan) is the only thing this path changes.
     *
     * @return array{0: ?Workspace, 1: ?Business}
     */
    private function tenancyIdentifiedFromContext(CustomerContext $context): array
    {
        $candidateWorkspace = $context->frameWorkspace();
        $candidateBusiness = $context->selectedBusiness;

        $workspace = app(WorkspaceRepository::class)->findById($candidateWorkspace->id);
        $business = app(BusinessRepository::class)->findById($candidateBusiness->id);

        return [$workspace, $business];
    }

    /**
     * The original lookup, unchanged, for every request the context cannot
     * vouch for.
     *
     * @return array{0: ?Workspace, 1: ?Business}
     */
    private function tenancyIdentifiedFromRepositories(string $workspaceUid, string $businessUid): array
    {
        $workspace = app(WorkspaceRepository::class)->findByUid($workspaceUid);

        if ($workspace === null) {
            return [null, null];
        }

        $business = app(WorkspaceRepository::class)->businessesForWorkspace($workspace)->firstWhere('uid', $businessUid);

        return [$workspace, $business];
    }
}

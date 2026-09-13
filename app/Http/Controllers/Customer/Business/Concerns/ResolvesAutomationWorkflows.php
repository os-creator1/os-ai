<?php

namespace App\Http\Controllers\Customer\Business\Concerns;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\PlatformFeature;
use App\Exceptions\Workspace\BusinessWorkspaceMismatchException;
use App\Exceptions\Workspace\WorkspaceBusinessNotFoundException;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Navigation\ContextSource;
use App\Library\Navigation\CustomerContext;
use App\Library\Navigation\CustomerShellComposer;
use App\Library\Workspace\WorkspaceManager;
use App\Models\AutomationWorkflow;
use App\Models\Business;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceRepository;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Automations V2-E — the request boundary every workflow endpoint shares.
 *
 * TWO JOBS, BOTH OF WHICH MUST HAPPEN IN ONE PLACE.
 *
 * 1. THE TENANCY CHAIN (§14.1, B4 §2.2 verbatim). Account by uid → Business by uid
 *    INSIDE that Account → WorkspaceManager::userCanAccessBusiness() → the
 *    Business must be active → EntitlementManager::decide() for Automations →
 *    the workflow resolved THROUGH that Business. Every failure along the chain
 *    is the same 404, so a foreign identifier is indistinguishable from one that
 *    does not exist. This mirrors AutomationsController::resolveEntitledBusiness(),
 *    which is private to B4; each Business controller in this codebase carries its
 *    own resolver, and this trait keeps the three V2-E controllers from carrying
 *    three copies of a security-critical one.
 *
 * 2. STATUS CODES THE APPLICATION WOULD OTHERWISE LOSE. App\Exceptions\Handler
 *    answers ANY exception on a request that wants JSON with
 *    `response()->json([...])` and no status — HTTP 200. Left to it, `abort(404)`
 *    for a foreign Account, a 422 publish failure and a 409 autosave conflict
 *    would all reach a JSON client as 200 success, which for a tenancy denial is
 *    worse than wrong. That Handler is app-wide and out of this slice's scope, so
 *    instead nothing is allowed to escape to it: respond() converts every
 *    expected failure into a response carrying its real status.
 *
 * ORDER IS PART OF THE CONTRACT. The permission gate runs first (401, the
 * customer portal's existing convention for a Gate denial), then tenancy (404),
 * and only then is the request body validated (422). A caller who cannot see this
 * Business therefore learns nothing about what a valid body would look like —
 * which is also why Form Requests are resolved inside the action rather than
 * type-hinted, where Laravel would validate them before tenancy ran.
 */
trait ResolvesAutomationWorkflows
{
    /**
     * Run one endpoint behind the gate, and answer with the status it deserves.
     *
     * NEGOTIATED. A JSON client — the builder's fetch calls — has every expected
     * failure converted here, because the application Handler would turn it into
     * a 200. A browser navigating to a page (the list, the builder shell, "New
     * workflow") is left to the Handler, which renders the app's own HTML error
     * pages with correct statuses for non-JSON requests; catching those here
     * would hand a person a JSON 404 instead of a page.
     *
     * @param Closure(): (JsonResponse|\Illuminate\Contracts\View\View|\Symfony\Component\HttpFoundation\Response) $action
     */
    protected function respond(Closure $action): mixed
    {
        if (! request()->wantsJson()) {
            $this->authorize('automations');

            return $action();
        }

        try {
            $this->authorize('automations');

            return $action();
        } catch (AuthorizationException) {
            return response()->json(['message' => 'You do not have permission to manage automations.'], 401);
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => $exception->errors(),
            ], 422);
        } catch (ModelNotFoundException) {
            return $this->notFound();
        } catch (HttpExceptionInterface $exception) {
            $status = $exception->getStatusCode();

            return $status === 404
                ? $this->notFound()
                : response()->json(['message' => $exception->getMessage()], $status);
        }
    }

    protected function notFound(): JsonResponse
    {
        return response()->json(['message' => 'Not found.'], 404);
    }

    /**
     * The Account and Business this request is allowed to act in.
     *
     * WHAT CHANGED AND WHAT DID NOT (§18 correction). The AUTHORIZATION is the
     * same chain as before, run on every request: an active Account, the
     * canonical WorkspaceManager::userCanAccessBusiness(), an active Business,
     * and EntitlementManager's own Automations decision. Only where two answers
     * are FETCHED from changed, to stop paying for them twice:
     *
     *   IDENTIFICATION. ResolveCustomerContext has already read, in one
     *   statement, which Accounts and Businesses this actor can see, and bound
     *   the result to the request. When it selected exactly this route's pair,
     *   the ids come from there instead of two more repository reads. That
     *   snapshot is a presentation read model and is never trusted as an
     *   authorization answer — which is why userCanAccessBusiness() still runs
     *   below and re-reads the persisted rows itself. When the context did not
     *   select this pair (for example a Business owner the snapshot does not
     *   list), the repositories are used exactly as before, so nobody who could
     *   reach this Business before is refused now.
     *
     *   ENTITLEMENT. On a page, the customer shell resolves every menu feature
     *   decision once per request through
     *   CustomerShellComposer::currentMenuEntitlements(), and `automations` is
     *   one of them. Taking the decision from that same memoized snapshot —
     *   exactly as BusinessHomePresenter already does — means the page asks
     *   EntitlementManager once instead of twice. It is still EntitlementManager's
     *   bulk decision, "the same eight steps decide() applies" (its own
     *   docblock), and it throws the same not-found and mismatch exceptions, so a
     *   foreign or reassigned Business is still a 404. Anything the memo cannot
     *   vouch for — no matching context, a view-as session — uses decide().
     *
     * @return array{0: Workspace, 1: Business}
     */
    protected function resolveEntitledBusiness(string $workspaceUid, string $businessUid): array
    {
        $userId = (int) Auth::id();
        $context = $this->contextSelecting($workspaceUid, $businessUid, $userId);

        [$workspace, $business] = $context !== null
            ? $this->identifiedFromContext($context)
            : $this->identifiedFromRepositories($workspaceUid, $businessUid);

        if ($workspace === null || ! $workspace->is_active) {
            abort(404);
        }

        if ($business === null || ! app(WorkspaceManager::class)->userCanAccessBusiness($userId, $business)) {
            abort(404);
        }

        if ($business->status !== BusinessStatus::Active) {
            abort(404);
        }

        try {
            $allowed = $context !== null
                ? app(CustomerShellComposer::class)
                    ->currentMenuEntitlements($context)
                    ->allows(PlatformFeature::Automations->value)
                // (int) Auth::id() is the audit/actor argument the decision
                // signature requires — never a tenancy decision.
                : app(EntitlementManager::class)->decide(
                    $workspace,
                    $business,
                    PlatformFeature::Automations->value,
                    $userId,
                )->allowed;
        } catch (WorkspaceBusinessNotFoundException|BusinessWorkspaceMismatchException) {
            abort(404);
        }

        if (! $allowed) {
            abort(404);
        }

        return [$workspace, $business];
    }

    /**
     * The request's already-resolved context, but only when it selected EXACTLY
     * this route's Account and Business, from this route, for this actor, with no
     * view-as session narrowing it. Anything else returns null and the request
     * takes the repository path.
     */
    private function contextSelecting(string $workspaceUid, string $businessUid, int $userId): ?CustomerContext
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
     * Identifiers only, from the context this request already read. Every
     * decision made about them below re-reads the persisted rows.
     *
     * @return array{0: Workspace, 1: Business}
     */
    private function identifiedFromContext(CustomerContext $context): array
    {
        $candidateWorkspace = $context->frameWorkspace();
        $candidateBusiness = $context->selectedBusiness;

        $workspace = (new Workspace())->forceFill([
            'id' => $candidateWorkspace->id,
            'uid' => $candidateWorkspace->uid,
            'name' => $candidateWorkspace->name,
            'is_active' => $candidateWorkspace->isActive,
            'owner_user_id' => $candidateWorkspace->ownerUserId,
        ]);
        $workspace->exists = true;

        $business = (new Business())->forceFill([
            'id' => $candidateBusiness->id,
            'uid' => $candidateBusiness->uid,
            'name' => $candidateBusiness->name,
            'status' => $candidateBusiness->status,
            'customer_id' => $candidateBusiness->customerId,
            'workspace_id' => $candidateWorkspace->id,
        ]);
        $business->exists = true;

        return [$workspace, $business];
    }

    /**
     * The original lookup, unchanged, for every request the context cannot vouch
     * for.
     *
     * @return array{0: ?Workspace, 1: ?Business}
     */
    private function identifiedFromRepositories(string $workspaceUid, string $businessUid): array
    {
        $workspace = app(WorkspaceRepository::class)->findByUid($workspaceUid);

        if ($workspace === null) {
            return [null, null];
        }

        $business = app(WorkspaceRepository::class)->businessesForWorkspace($workspace)->firstWhere('uid', $businessUid);

        return [$workspace, $business];
    }

    /**
     * The workflow, resolved THROUGH the already-resolved Business. A uid that
     * belongs to another Business matches nothing here, so it 404s exactly like
     * one that never existed.
     */
    protected function resolveWorkflow(Business $business, string $workflowUid): AutomationWorkflow
    {
        $workflow = AutomationWorkflow::query()
            ->where('business_id', (int) $business->id)
            ->where('uid', $workflowUid)
            ->first();

        abort_unless($workflow !== null, 404);

        return $workflow;
    }

    /**
     * Resolve a Form Request NOW, after tenancy, so its validation runs in that
     * order and its failure lands in respond() rather than in the app Handler.
     *
     * @template T of \Illuminate\Foundation\Http\FormRequest
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    protected function validated(string $class)
    {
        return app($class);
    }
}

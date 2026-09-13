<?php

namespace App\Http\Controllers\Customer\Business\Concerns;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\PlatformFeature;
use App\Exceptions\Workspace\BusinessWorkspaceMismatchException;
use App\Exceptions\Workspace\WorkspaceBusinessNotFoundException;
use App\Library\Entitlement\EntitlementManager;
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
     * @param Closure(): JsonResponse $action
     */
    protected function respond(Closure $action): JsonResponse
    {
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
     * @return array{0: Workspace, 1: Business}
     */
    protected function resolveEntitledBusiness(string $workspaceUid, string $businessUid): array
    {
        $workspace = app(WorkspaceRepository::class)->findByUid($workspaceUid);

        if ($workspace === null || ! $workspace->is_active) {
            abort(404);
        }

        $business = app(WorkspaceRepository::class)->businessesForWorkspace($workspace)->firstWhere('uid', $businessUid);

        if ($business === null || ! app(WorkspaceManager::class)->userCanAccessBusiness((int) Auth::id(), $business)) {
            abort(404);
        }

        if ($business->status !== BusinessStatus::Active) {
            abort(404);
        }

        try {
            // (int) Auth::id() is the audit/actor argument the decision signature
            // requires — never a tenancy decision.
            $decision = app(EntitlementManager::class)->decide(
                $workspace,
                $business,
                PlatformFeature::Automations->value,
                (int) Auth::id(),
            );
        } catch (WorkspaceBusinessNotFoundException|BusinessWorkspaceMismatchException) {
            abort(404);
        }

        if (! $decision->allowed) {
            abort(404);
        }

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

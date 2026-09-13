<?php

namespace App\Http\Controllers\Customer\Business\Concerns;

use App\Enums\Entitlement\PlatformFeature;
use App\Models\AutomationWorkflow;
use App\Models\Business;
use App\Models\Workspace;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Automations V2-E — what the workflow endpoints need BEYOND the canonical
 * Business tenancy chain, and nothing more.
 *
 * TENANCY IS NOT HERE ANY MORE. It used to be: this trait carried its own
 * context-reusing resolver, and #288 generalized exactly that seam into
 * ResolvesBusinessTenancy for every Business-scoped controller. Keeping a second
 * copy would mean two tenancy algorithms able to drift apart, so V2-E now uses the
 * canonical one. That also retires this trait's old identification shortcut,
 * which built partial Workspace/Business objects from the context's presentation
 * candidates: the canonical trait hydrates the real rows through request-memoized
 * findById(), at no additional query, so no caller can ever read a field that was
 * never loaded.
 *
 * The chain is unchanged in every rule that matters — an active Account, the
 * canonical WorkspaceManager::userCanAccessBusiness(), an active Business, and the
 * Automations entitlement; the context is only reused when it selected exactly
 * this route's Account and Business, for this actor, from this route, with no
 * view-as session; every failure is the same 404.
 *
 * WHAT STAYS, BECAUSE IT IS WORKFLOW-SPECIFIC:
 *
 *   respond()          The application Handler now carries real JSON statuses
 *                      (#282), but its body is `{status, message}` with no
 *                      `errors` map. A 422 from publishing is only useful to
 *                      V2-D's builder WITH that map — index.js renders
 *                      `body.errors[node.key]` — so the conversion stays for the
 *                      workflow endpoints. Browsers still get the app's own HTML
 *                      error pages.
 *   resolveWorkflow()  The workflow, resolved THROUGH the tenancy-checked Business.
 *   validated()        Form Requests resolved AFTER tenancy, so a caller who cannot
 *                      see a Business learns nothing about a valid body.
 *   the §18 seam       WorkflowFeatureQueryScope brackets the feature-owned SQL.
 *
 * ORDER IS PART OF THE CONTRACT: permission (401), then tenancy (404), then body
 * validation (422).
 */
trait ResolvesAutomationWorkflows
{
    use ResolvesBusinessTenancy;

    /**
     * Run one endpoint behind the gate, and answer with the status it deserves.
     *
     * @param Closure(): mixed $action
     */
    protected function respond(Closure $action): mixed
    {
        try {
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
        } finally {
            // Whatever renders after this — the customer layout — is shared.
            WorkflowFeatureQueryScope::end();
        }
    }

    /**
     * The one not-found answer every V2-E endpoint gives, so a foreign workflow,
     * a foreign enrollment and a foreign contact are indistinguishable from ones
     * that never existed.
     */
    protected function notFound(): JsonResponse
    {
        return response()->json(['message' => 'Not found.'], 404);
    }

    /**
     * The Account and Business this request may act in, through the canonical
     * chain, entitled for Automations.
     *
     * @return array{0: Workspace, 1: Business}
     */
    protected function resolveEntitledBusiness(string $workspaceUid, string $businessUid): array
    {
        $resolved = $this->resolveEntitledBusinessTenancy(
            $workspaceUid,
            $businessUid,
            PlatformFeature::Automations->value,
        );

        // §18 — shared tenancy is complete; from here until the action returns,
        // every statement belongs to the workflow feature.
        WorkflowFeatureQueryScope::begin();

        return $resolved;
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
     * order and its failure lands in respond().
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

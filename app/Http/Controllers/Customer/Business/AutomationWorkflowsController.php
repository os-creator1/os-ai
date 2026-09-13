<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesAutomationWorkflows;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Http\Requests\Automations\Workflow\StoreWorkflowRequest;
use App\Library\Automation\Workflow\Contracts\WorkflowLifecycle;
use App\Library\Automation\Workflow\WorkflowDraftService;
use App\Models\AutomationWorkflow;
use App\Models\AutomationWorkflowVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Automations V2-E — the workflow itself: list, create, show, settings, and the
 * three status changes (§20.2).
 *
 * THIN BY CONSTRUCTION. Every decision here belongs to a service that already
 * exists: creating a workflow and its first draft is WorkflowDraftService's, and
 * pausing, resuming and archiving go through the WorkflowLifecycle contract
 * (§20 "Between A and E"). Resume in particular flips status and dispatches
 * RedispatchHeldEnrollments after commit INSIDE that service — this controller
 * never dispatches enrollment work itself.
 *
 * JSON THROUGHOUT. The builder shell and list pages are V2-D's views; this slice
 * exposes the data they render, so it can ship and be tested without inventing
 * pages another lane owns.
 */
class AutomationWorkflowsController extends CustomerBaseController
{
    use ResolvesAutomationWorkflows;

    /** One page of a Business's workflows. */
    private const PAGE_SIZE = 25;

    public function __construct(
        private readonly WorkflowDraftService $drafts,
        private readonly WorkflowLifecycle $lifecycle,
    ) {
    }

    /**
     * Named listing(), not index(): CustomerBaseController::index() takes no
     * parameters, and overriding it with a different signature is fatal — the
     * same reason B4's controller uses this name.
     */
    public function listing(string $workspaceUid, string $businessUid): JsonResponse
    {
        return $this->respond(function () use ($workspaceUid, $businessUid): JsonResponse {
            [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);

            $page = AutomationWorkflow::query()
                ->where('business_id', (int) $business->id)
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->paginate(self::PAGE_SIZE);

            return response()->json([
                'data' => $page->getCollection()->map(fn (AutomationWorkflow $w): array => $this->summary($w))->values(),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'total' => $page->total(),
                ],
            ]);
        });
    }

    public function store(string $workspaceUid, string $businessUid): JsonResponse
    {
        return $this->respond(function () use ($workspaceUid, $businessUid): JsonResponse {
            [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);

            $request = $this->validated(StoreWorkflowRequest::class);

            $workflow = $this->drafts->createWorkflowWithDraft(
                $business,
                (string) $request->validated('name'),
                WorkflowTriggerType::from((string) $request->validated('trigger_type')),
                (int) Auth::id(),
            );

            return response()->json(['data' => $this->summary($workflow)], 201);
        });
    }

    /** The builder shell's data: identity, status and which versions exist. */
    public function show(string $workspaceUid, string $businessUid, string $workflowUid): JsonResponse
    {
        return $this->respond(function () use ($workspaceUid, $businessUid, $workflowUid): JsonResponse {
            [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
            $workflow = $this->resolveWorkflow($business, $workflowUid);

            return response()->json(['data' => $this->summary($workflow)]);
        });
    }

    /**
     * The settings tab: the behavioural rules of the LIVE version, which are the
     * ones journeys actually follow, beside the draft's when one exists. Policies
     * live on the version rather than the workflow (§7.5, C2), so showing only one
     * would hide the difference a publish is about to make.
     */
    public function settings(string $workspaceUid, string $businessUid, string $workflowUid): JsonResponse
    {
        return $this->respond(function () use ($workspaceUid, $businessUid, $workflowUid): JsonResponse {
            [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
            $workflow = $this->resolveWorkflow($business, $workflowUid);

            $published = $workflow->published_version_id === null
                ? null
                : AutomationWorkflowVersion::query()
                    ->where('workflow_id', (int) $workflow->id)
                    ->whereKey((int) $workflow->published_version_id)
                    ->first();

            return response()->json(['data' => [
                'workflow' => $this->summary($workflow),
                'published' => $this->versionRules($published),
                'draft' => $this->versionRules($workflow->draftVersion()),
            ]]);
        });
    }

    public function pause(string $workspaceUid, string $businessUid, string $workflowUid): JsonResponse
    {
        return $this->transition($workspaceUid, $businessUid, $workflowUid, fn (AutomationWorkflow $w) => $this->lifecycle->pause($w));
    }

    public function resume(string $workspaceUid, string $businessUid, string $workflowUid): JsonResponse
    {
        return $this->transition($workspaceUid, $businessUid, $workflowUid, fn (AutomationWorkflow $w) => $this->lifecycle->resume($w));
    }

    public function archive(string $workspaceUid, string $businessUid, string $workflowUid): JsonResponse
    {
        return $this->transition($workspaceUid, $businessUid, $workflowUid, fn (AutomationWorkflow $w) => $this->lifecycle->archive($w));
    }

    /**
     * Run one lifecycle change and report what actually happened.
     *
     * The service deliberately treats an inapplicable change — pausing a draft,
     * resuming something already live — as a no-op rather than an error, so that
     * a double click is harmless. This reports the resulting status and whether
     * it moved, instead of claiming a change that did not occur.
     *
     * @param \Closure(AutomationWorkflow): void $change
     */
    private function transition(string $workspaceUid, string $businessUid, string $workflowUid, \Closure $change): JsonResponse
    {
        return $this->respond(function () use ($workspaceUid, $businessUid, $workflowUid, $change): JsonResponse {
            [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
            $workflow = $this->resolveWorkflow($business, $workflowUid);

            $before = $workflow->status;
            $change($workflow);
            $after = $workflow->fresh();

            return response()->json(['data' => [
                'workflow' => $this->summary($after),
                'changed' => $before !== $after->status,
            ]]);
        });
    }

    /** @return array<string, mixed> */
    private function summary(AutomationWorkflow $workflow): array
    {
        return [
            'uid' => $workflow->uid,
            'name' => $workflow->name,
            'status' => $workflow->status->value,
            'status_label' => $workflow->status->label(),
            'has_published_version' => $workflow->published_version_id !== null,
            'has_draft' => $workflow->draftVersion() !== null,
            'archived_at' => $workflow->archived_at?->toIso8601String(),
            'updated_at' => $workflow->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function versionRules(?AutomationWorkflowVersion $version): ?array
    {
        if ($version === null) {
            return null;
        }

        return [
            'uid' => $version->uid,
            'version_number' => (int) $version->version_number,
            'state' => $version->state->value,
            'trigger_type' => $version->trigger_type?->value,
            'enrollment_policy' => $version->enrollment_policy?->value,
            'enrollment_policy_source' => $version->enrollment_policy_source?->value,
            'failure_policy' => $version->failure_policy?->value,
            'published_at' => $version->published_at?->toIso8601String(),
        ];
    }
}

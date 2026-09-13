<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Automation\Workflow\WorkflowStatus;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesAutomationWorkflows;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Http\Requests\Automations\Workflow\AutosaveDraftRequest;
use App\Http\Requests\Automations\Workflow\SimulateWorkflowRequest;
use App\Library\Automation\Workflow\WorkflowCompiler;
use App\Library\Automation\Workflow\WorkflowDraftService;
use App\Library\Automation\Workflow\WorkflowPublisher;
use App\Library\Automation\Workflow\WorkflowSimulator;
use App\Models\AutomationWorkflow;
use App\Models\AutomationWorkflowVersion;
use App\Models\Contacts;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Automations V2-E — editing, publishing and testing a workflow (§14.4, §20.2).
 *
 * Nothing here decides anything about the document. Saving, and the 409 that
 * protects a second tab, is WorkflowDraftService::autosave(); the errors a draft
 * is reported with are WorkflowCompiler::validate()'s, the same check publish
 * enforces; publishing is WorkflowPublisher's; a test run is WorkflowSimulator's.
 * The controller's whole job is the order those are called in and the status
 * each answer deserves.
 *
 * WHY OPENING THE DRAFT MAY CREATE ONE. `GET /draft` goes through
 * WorkflowDraftService::ensureDraft(), which returns the existing draft or clones
 * the published document into a new one. That is a write behind a GET, and it is
 * a deliberate trade. The alternative — a read-only GET that hands a published
 * workflow's editor no revision to save against — pushes draft creation into the
 * first autosave, where two tabs racing to be first can overwrite each other.
 * ensureDraft() is idempotent under a row lock and a draft has no effect on any
 * running journey, so the GET is repeatable and harmless, and every save after it
 * carries a real revision the 409 can protect.
 */
class AutomationWorkflowDraftController extends CustomerBaseController
{
    use ResolvesAutomationWorkflows;

    public function __construct(
        private readonly WorkflowDraftService $drafts,
        private readonly WorkflowCompiler $compiler,
        private readonly WorkflowPublisher $publisher,
        private readonly WorkflowSimulator $simulator,
    ) {
    }

    public function show(string $workspaceUid, string $businessUid, string $workflowUid): JsonResponse
    {
        return $this->respond(function () use ($workspaceUid, $businessUid, $workflowUid): JsonResponse {
            [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
            $workflow = $this->resolveWorkflow($business, $workflowUid);

            $this->assertEditable($workflow);

            $draft = $this->drafts->ensureDraft($workflow);

            return response()->json(['data' => $this->draftPayload($draft)]);
        });
    }

    /**
     * Autosave. §14.4: an invalid document is STILL saved and returned with its
     * errors — work is never lost — but it cannot publish. A stale revision is a
     * 409, never a silent overwrite of another tab's edits.
     */
    public function autosave(string $workspaceUid, string $businessUid, string $workflowUid): JsonResponse
    {
        return $this->respond(function () use ($workspaceUid, $businessUid, $workflowUid): JsonResponse {
            [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
            $workflow = $this->resolveWorkflow($business, $workflowUid);

            $this->assertEditable($workflow);

            $request = $this->validated(AutosaveDraftRequest::class);

            $saved = $this->drafts->autosave(
                $this->drafts->ensureDraft($workflow),
                (array) $request->validated('definition'),
                (int) $request->validated('definition_revision'),
            );

            return response()->json(['data' => [
                'revision' => (int) $saved->definition_revision,
                'errors' => $this->compiler->validate($saved),
            ]]);
        });
    }

    /** 200 with the new version, or 422 with errors keyed by node key. */
    public function publish(string $workspaceUid, string $businessUid, string $workflowUid): JsonResponse
    {
        return $this->respond(function () use ($workspaceUid, $businessUid, $workflowUid): JsonResponse {
            [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
            $workflow = $this->resolveWorkflow($business, $workflowUid);

            $version = $this->publisher->publish($workflow, (int) Auth::id());

            return response()->json(['data' => [
                'workflow_uid' => $workflow->uid,
                'status' => $workflow->fresh()->status->value,
                'version_uid' => $version->uid,
                'version_number' => (int) $version->version_number,
            ]]);
        });
    }

    public function discard(string $workspaceUid, string $businessUid, string $workflowUid): JsonResponse
    {
        return $this->respond(function () use ($workspaceUid, $businessUid, $workflowUid): JsonResponse {
            [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
            $workflow = $this->resolveWorkflow($business, $workflowUid);

            $this->drafts->discardDraft($workflow);

            return response()->json(['data' => [
                'workflow_uid' => $workflow->uid,
                'has_draft' => $workflow->fresh()->draftVersion() !== null,
            ]]);
        });
    }

    /**
     * "Test workflow" for one contact: the path this workflow WOULD take, with no
     * enrollment, no message and no write (WorkflowSimulator's own contract).
     *
     * It tests the version the customer is looking at — the draft when one exists,
     * otherwise the live version — because the point of the button is to try an
     * edit before publishing it.
     */
    public function simulate(string $workspaceUid, string $businessUid, string $workflowUid): JsonResponse
    {
        return $this->respond(function () use ($workspaceUid, $businessUid, $workflowUid): JsonResponse {
            [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
            $workflow = $this->resolveWorkflow($business, $workflowUid);

            $request = $this->validated(SimulateWorkflowRequest::class);

            // Resolved strictly inside this Business: another Business's contact
            // uid matches nothing and 404s like an unknown one. The simulator
            // re-checks the pairing too; this is the boundary that decides what
            // the caller is allowed to ask about at all.
            $contact = Contacts::query()
                ->where('business_id', (int) $business->id)
                ->where('uid', (string) $request->validated('contact_uid'))
                ->first();

            if ($contact === null) {
                return $this->notFound();
            }

            $version = $workflow->draftVersion() ?? $this->publishedVersion($workflow);

            if ($version === null) {
                return response()->json(['message' => 'This workflow has nothing to test yet.'], 422);
            }

            return response()->json(['data' => $this->simulator->simulate($version, $contact)]);
        });
    }

    /** An archived workflow is finished; editing it would only create a dead draft. */
    private function assertEditable(AutomationWorkflow $workflow): void
    {
        if ($workflow->status === WorkflowStatus::Archived) {
            throw new ConflictHttpException('An archived workflow cannot be edited.');
        }
    }

    private function publishedVersion(AutomationWorkflow $workflow): ?AutomationWorkflowVersion
    {
        if ($workflow->published_version_id === null) {
            return null;
        }

        return AutomationWorkflowVersion::query()
            ->where('workflow_id', (int) $workflow->id)
            ->whereKey((int) $workflow->published_version_id)
            ->first();
    }

    /** @return array<string, mixed> */
    private function draftPayload(AutomationWorkflowVersion $draft): array
    {
        return [
            'uid' => $draft->uid,
            'definition' => $draft->definition,
            'revision' => (int) $draft->definition_revision,
            'errors' => $this->compiler->validate($draft),
        ];
    }
}

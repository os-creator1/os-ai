<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Automation\Workflow\WorkflowStatus;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesAutomationWorkflows;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Http\Requests\Automations\Workflow\ManualEnrollmentRequest;
use App\Jobs\Automation\Workflow\EnrollWorkflowContact;
use App\Library\Automation\Workflow\Contracts\WorkflowLifecycle;
use App\Models\AutomationEnrollment;
use App\Models\AutomationStepRun;
use App\Models\Contacts;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * Automations V2-E — the journeys running through a workflow (§20.2).
 *
 * READS ARE SCOPED TWICE. The enrollment list and the step log are filtered by
 * BOTH the workflow and the Business, never by the workflow alone. The workflow
 * was already resolved through the Business, so the second predicate should be
 * redundant — and it is exactly the predicate that keeps an enrollment row from
 * appearing under the wrong tenant if a workflow id ever pointed somewhere it
 * should not.
 *
 * MANUAL ENROLLMENT DISPATCHES, IT DOES NOT ENROLL. The canonical path already
 * exists: EnrollWorkflowContact::forManualEnrollment() → ManualEnrollmentTriggerSource
 * → EnrollmentService, which re-proves tenancy and claims the enrollment key.
 * That job's own docblock names V2-E as the layer that authorizes the request and
 * dispatches it, and this is that layer. Queuing also keeps a 500-contact request
 * fast: it hands off 500 ids instead of performing 500 enrollments and 500
 * advances inside one HTTP response.
 */
class AutomationWorkflowEnrollmentsController extends CustomerBaseController
{
    use ResolvesAutomationWorkflows;

    private const PAGE_SIZE = 50;

    /** The reason recorded on journeys stopped from this screen. */
    public const STOP_ALL_REASON = 'stopped_by_user';

    public function __construct(private readonly WorkflowLifecycle $lifecycle)
    {
    }

    public function history(string $workspaceUid, string $businessUid, string $workflowUid): JsonResponse
    {
        return $this->respond(function () use ($workspaceUid, $businessUid, $workflowUid): JsonResponse {
            [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
            $workflow = $this->resolveWorkflow($business, $workflowUid);

            $page = AutomationEnrollment::query()
                ->where('workflow_id', (int) $workflow->id)
                ->where('business_id', (int) $business->id)
                // One query for every row's contact, never one per row.
                ->with('contact:id,uid')
                ->orderByDesc('id')
                ->paginate(self::PAGE_SIZE);

            return response()->json([
                'data' => $page->getCollection()->map(fn (AutomationEnrollment $e): array => [
                    'uid' => $e->uid,
                    'contact_uid' => $e->contact?->uid,
                    'status' => $e->status->value,
                    'status_label' => $e->status->label(),
                    'step_count' => (int) $e->step_count,
                    'exit_reason' => $e->exit_reason,
                    'enrolled_at' => $e->enrolled_at?->toIso8601String(),
                    'resume_at' => $e->resume_at?->toIso8601String(),
                    'completed_at' => $e->completed_at?->toIso8601String(),
                ])->values(),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'total' => $page->total(),
                ],
            ]);
        });
    }

    /** What happened, step by step, on one journey. */
    public function logs(string $workspaceUid, string $businessUid, string $workflowUid, string $enrollmentUid): JsonResponse
    {
        return $this->respond(function () use ($workspaceUid, $businessUid, $workflowUid, $enrollmentUid): JsonResponse {
            [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
            $workflow = $this->resolveWorkflow($business, $workflowUid);

            // Resolved inside this workflow AND this Business. An enrollment uid
            // from another workflow — even one of this Business's — is a 404.
            $enrollment = AutomationEnrollment::query()
                ->where('workflow_id', (int) $workflow->id)
                ->where('business_id', (int) $business->id)
                ->where('uid', $enrollmentUid)
                ->first();

            if ($enrollment === null) {
                return $this->notFound();
            }

            $steps = AutomationStepRun::query()
                ->where('enrollment_id', (int) $enrollment->id)
                ->where('business_id', (int) $business->id)
                ->with('node:id,node_key')
                ->orderBy('id')
                ->get();

            return response()->json(['data' => [
                'enrollment_uid' => $enrollment->uid,
                'status' => $enrollment->status->value,
                'steps' => $steps->map(fn (AutomationStepRun $s): array => [
                    'uid' => $s->uid,
                    'node_key' => $s->node?->node_key,
                    'node_type' => $s->node_type->value,
                    'node_label' => $s->node_type->label(),
                    'status' => $s->status->value,
                    'branch_taken' => $s->branch_taken?->value,
                    // Bounded, human-safe summaries by construction (B4 §4.3):
                    // never a provider body, a credential or the full message.
                    'result' => $s->safe_result_summary,
                    'error' => $s->safe_error_summary,
                    'started_at' => $s->started_at?->toIso8601String(),
                    'completed_at' => $s->completed_at?->toIso8601String(),
                ])->values(),
            ]]);
        });
    }

    /**
     * Enroll up to 500 of this Business's contacts by hand.
     *
     * THE LIST IS ALL OR NOTHING. Every uid must resolve to a contact of THIS
     * Business. If any does not — unknown, or another Business's — nothing is
     * queued, and the unmatched uids are named. Enrolling the matches and quietly
     * dropping the rest would leave the customer believing a list was enrolled
     * when part of it was not.
     */
    public function manual(string $workspaceUid, string $businessUid, string $workflowUid): JsonResponse
    {
        return $this->respond(function () use ($workspaceUid, $businessUid, $workflowUid): JsonResponse {
            [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
            $workflow = $this->resolveWorkflow($business, $workflowUid);

            $request = $this->validated(ManualEnrollmentRequest::class);

            if ($workflow->status !== WorkflowStatus::Published) {
                // EnrollmentService refuses a workflow that is not live anyway;
                // saying so now beats queuing jobs that will all quietly no-op.
                return response()->json([
                    'message' => 'Only a live workflow can have contacts enrolled. Publish or resume it first.',
                ], 409);
            }

            /** @var list<string> $uids */
            $uids = array_values((array) $request->validated('contact_uids'));

            $contacts = Contacts::query()
                ->where('business_id', (int) $business->id)
                ->whereIn('uid', $uids)
                ->get(['id', 'uid']);

            $unmatched = array_values(array_diff($uids, $contacts->pluck('uid')->all()));

            if ($unmatched !== []) {
                return response()->json([
                    'message' => 'Some contacts could not be found in this business, so nobody was enrolled.',
                    'errors' => ['contact_uids' => $unmatched],
                ], 422);
            }

            // One server-derived identity for this deliberate request. It becomes
            // each journey's occurrence key, so a redelivered job cannot enroll
            // the same contact twice, while a second click is a second request.
            $requestUid = (string) Str::uuid();

            foreach ($contacts as $contact) {
                // Through the job's named constructor: its constructor is private
                // precisely so a manual enrollment can only ever be built with a
                // workflow, a contact and a server-derived request identity.
                dispatch(EnrollWorkflowContact::forManualEnrollment(
                    (int) $workflow->id,
                    (int) $contact->id,
                    $requestUid,
                ));
            }

            return response()->json(['data' => [
                'request_uid' => $requestUid,
                'queued' => $contacts->count(),
            ]], 202);
        });
    }

    public function stopAll(string $workspaceUid, string $businessUid, string $workflowUid): JsonResponse
    {
        return $this->respond(function () use ($workspaceUid, $businessUid, $workflowUid): JsonResponse {
            [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
            $workflow = $this->resolveWorkflow($business, $workflowUid);

            $cancelled = $this->lifecycle->stopAllActive($workflow, self::STOP_ALL_REASON);

            return response()->json(['data' => [
                'workflow_uid' => $workflow->uid,
                'cancelled' => $cancelled,
            ]]);
        });
    }
}

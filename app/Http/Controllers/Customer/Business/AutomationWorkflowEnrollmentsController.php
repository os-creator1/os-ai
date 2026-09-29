<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Automation\Workflow\WorkflowStatus;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesAutomationWorkflows;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Http\Requests\Automations\Workflow\ManualEnrollmentRequest;
use App\Jobs\Automation\Workflow\EnrollWorkflowContact;
use App\Library\Automation\Workflow\Contracts\WorkflowLifecycle;
use App\Library\Workspace\LocationAccessGuard;
use App\Models\AutomationEnrollment;
use App\Models\AutomationStepRun;
use App\Models\Business;
use App\Models\Contacts;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
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

    public function __construct(
        private readonly WorkflowLifecycle $lifecycle,
        private readonly LocationAccessGuard $locationAccess,
    ) {
    }

    public function history(string $workspaceUid, string $businessUid, string $workflowUid): JsonResponse
    {
        return $this->respond(function () use ($workspaceUid, $businessUid, $workflowUid): JsonResponse {
            [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
            $workflow = $this->resolveWorkflow($business, $workflowUid);

            $page = AutomationEnrollment::query()
                ->where('workflow_id', (int) $workflow->id)
                ->where('business_id', (int) $business->id)
                ->when(
                    // Location ACL (lane contract §12D): an owner/all-scope
                    // actor sees every run; a Selected-scope staff member sees
                    // only runs whose pinned Location they can access. A
                    // legacy NULL-Location row is never shown to a
                    // Selected-scope actor — it cannot be proven accessible,
                    // so it fails closed exactly like an inaccessible one.
                    ! $this->locationAccess->userHasAllLocationReach((int) Auth::id(), $business),
                    fn ($query) => $query->whereIn(
                        'business_location_id',
                        $this->locationAccess->accessibleLocationIdsForBusiness((int) Auth::id(), $business),
                    ),
                )
                // One query for every row's contact and Location, never one
                // per row.
                ->with(['contact:id,uid', 'businessLocation:id,name'])
                ->orderByDesc('id')
                ->paginate(self::PAGE_SIZE);

            return response()->json([
                'enrollments' => $page->getCollection()->map(fn (AutomationEnrollment $e): array => [
                    'uid' => $e->uid,
                    'contact_uid' => $e->contact?->uid,
                    'status' => $e->status->value,
                    'status_label' => $e->status->label(),
                    'step_count' => (int) $e->step_count,
                    'exit_reason' => $e->exit_reason,
                    'location_name' => $e->businessLocation?->name,
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

            // Location ACL (lane contract §12D): a Selected-scope staff
            // member reaching this enrollment's uid directly must be refused
            // exactly like an unknown one — the same indistinguishable 404
            // §14.1 already uses for a foreign workflow/business id, so a
            // direct URL guess can never distinguish "does not exist" from
            // "exists, at a Location you cannot see" (never leaks contact
            // identity, step summaries or error summaries either, since
            // nothing about the row is returned at all).
            if (! $this->locationAccess->userHasAllLocationReach((int) Auth::id(), $business)
                && ! in_array(
                    (int) $enrollment->business_location_id,
                    $this->locationAccess->accessibleLocationIdsForBusiness((int) Auth::id(), $business),
                    true,
                )) {
                return $this->notFound();
            }

            $steps = AutomationStepRun::query()
                ->where('enrollment_id', (int) $enrollment->id)
                ->where('business_id', (int) $business->id)
                ->with('node:id,node_key')
                ->orderBy('id')
                ->get();

            return response()->json([
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
            ]);
        });
    }

    /**
     * Enroll up to 500 of this Business's contacts by hand.
     *
     * THREE DIFFERENT FAILURES, THREE DIFFERENT ANSWERS (T-WF-21):
     *
     *   A MALFORMED body — not a list, over the limit, unconfirmed — is 422.
     *   That is a statement about the request, and says nothing about any
     *   contact.
     *
     *   A uid that names no contact of THIS Business is 404 — and it is the
     *   SAME 404 whether the contact does not exist at all or belongs to another
     *   Business. §14.1: a foreign identifier fails exactly like a nonexistent
     *   one. The response is byte-for-byte the not-found answer every V2-E
     *   endpoint gives, names no uid and offers no hint, so it cannot be used to
     *   discover that some other Business holds a contact with that uid.
     *
     *   Nothing else is ever partly honoured: the list is ALL OR NOTHING. If any
     *   uid fails to resolve, NO contact is enqueued, including the ones that did
     *   resolve — enrolling the matches and dropping the rest would leave the
     *   customer believing a list was enrolled when part of it was not.
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
                ->get(['id', 'uid', 'location_id']);

            if ($contacts->count() !== count($uids)) {
                // Unknown and foreign are deliberately indistinguishable, and
                // nothing has been enqueued yet, so nothing is partly honoured.
                return $this->notFound();
            }

            // Location ACL (lane contract §12C): a forged uid naming a real
            // Contact at a Location this actor cannot access is refused
            // exactly like an unknown one — the same indistinguishable 404,
            // all-or-nothing, before anything is queued.
            if (! $this->locationAccess->userHasAllLocationReach((int) Auth::id(), $business)) {
                $accessible = $this->locationAccess->accessibleLocationIdsForBusiness((int) Auth::id(), $business);
                $inaccessible = $contacts->contains(fn (Contacts $c): bool => ! in_array((int) $c->location_id, $accessible, true));

                if ($inaccessible) {
                    return $this->notFound();
                }
            }

            // A FOURTH failure, distinct from the three T-WF-21 already names
            // (lane contract §9 "MANUAL ENROLLMENT... refuse clearly; tell the
            // customer the Contact must have a Location before it can be
            // enrolled"): a Contact with no Location cannot start a run at
            // all, and this is refused synchronously, by name, before
            // anything is queued — the same all-or-nothing discipline as the
            // uid check above, so a customer is never told "queued" for a
            // request that will silently enroll nobody once the job runs.
            $withoutLocation = $contacts->whereNull('location_id')->pluck('uid')->values();

            if ($withoutLocation->isNotEmpty()) {
                return response()->json([
                    'message' => 'These contacts have no Location yet and cannot be enrolled. Add a Location to each contact first.',
                    'contact_uids' => $withoutLocation,
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

            return response()->json([
                'request_uid' => $requestUid,
                'queued' => $contacts->count(),
            ], 202);
        });
    }

    public function stopAll(string $workspaceUid, string $businessUid, string $workflowUid): JsonResponse
    {
        return $this->respond(function () use ($workspaceUid, $businessUid, $workflowUid): JsonResponse {
            [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
            $workflow = $this->resolveWorkflow($business, $workflowUid);

            $cancelled = $this->lifecycle->stopAllActive($workflow, self::STOP_ALL_REASON);

            return response()->json([
                'workflow_uid' => $workflow->uid,
                'cancelled' => $cancelled,
            ]);
        });
    }
}

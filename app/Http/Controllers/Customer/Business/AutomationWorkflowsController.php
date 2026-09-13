<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Automation\Workflow\WorkflowStatus;
use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesAutomationWorkflows;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Http\Requests\Automations\Workflow\StoreWorkflowRequest;
use App\Library\Automation\Workflow\Contracts\WorkflowLifecycle;
use App\Library\Automation\Workflow\WorkflowCompiler;
use App\Library\Automation\Workflow\WorkflowDraftService;
use App\Models\AutomationWorkflow;
use App\Models\AutomationWorkflowVersion;
use App\Models\Business;
use App\Models\ContactGroupFields;
use App\Models\ContactGroups;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Automations V2-E — the workflow itself: list, "New workflow", create, show,
 * settings, and the three status changes (§20.2).
 *
 * THIN BY CONSTRUCTION. Creating a workflow and its first draft is
 * WorkflowDraftService's; pausing, resuming and archiving go through the
 * WorkflowLifecycle contract (§20 "Between A and E"). Resume's after-commit
 * RedispatchHeldEnrollments happens inside that service — this controller never
 * dispatches enrollment work itself.
 *
 * PAGES AND DATA FROM THE SAME ROUTES. V2-D's builder is a client of these
 * endpoints: a person navigating to the list, the builder or "New workflow" is
 * served V2-D's views with exactly the props those views document, while the
 * builder's own fetch calls (Accept: application/json) get JSON. Response bodies
 * are FLAT — `{revision, errors}`, `{workflow, redirect}` — because that is the
 * shape §20.2 fixes and the shape V2-D's JavaScript reads; an envelope here
 * would silently hide every validation error from the builder.
 */
class AutomationWorkflowsController extends CustomerBaseController
{
    use ResolvesAutomationWorkflows;

    /** One page of a Business's workflows. */
    private const PAGE_SIZE = 25;

    public function __construct(
        private readonly WorkflowDraftService $drafts,
        private readonly WorkflowCompiler $compiler,
        private readonly WorkflowLifecycle $lifecycle,
    ) {
    }

    /**
     * Named listing(), not index(): CustomerBaseController::index() takes no
     * parameters, and overriding it with a different signature is fatal — the
     * same reason B4's controller uses this name.
     */
    public function listing(string $workspaceUid, string $businessUid): mixed
    {
        return $this->respond(function () use ($workspaceUid, $businessUid): mixed {
            [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);

            $page = AutomationWorkflow::query()
                ->where('business_id', (int) $business->id)
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->paginate(self::PAGE_SIZE);

            if (! request()->wantsJson()) {
                return view('customer.Automations.Workflows.index', [
                    'workspaceUid' => $workspaceUid,
                    'businessUid' => $businessUid,
                    'basePath' => $this->basePath($workspaceUid, $businessUid),
                    'workflows' => $page,
                ]);
            }

            // §18 — the list must cost the same whatever the page size, so
            // "has a draft" is ONE query for the whole page rather than a
            // draftVersion() lookup per row.
            $withDraft = array_flip(AutomationWorkflowVersion::query()
                ->whereIn('workflow_id', $page->getCollection()->pluck('id')->all())
                ->where('state', \App\Enums\Automation\Workflow\WorkflowVersionState::Draft->value)
                ->pluck('workflow_id')
                ->map(fn ($id): int => (int) $id)
                ->all());

            return response()->json([
                'workflows' => $page->getCollection()
                    ->map(fn (AutomationWorkflow $w): array => $this->summary($w, isset($withDraft[(int) $w->id])))
                    ->values(),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'total' => $page->total(),
                ],
            ]);
        });
    }

    /**
     * "New workflow" — the chooser V2-D's list links to at `{basePath}/new`.
     *
     * Not in §20.2's table, and added for exactly one reason: the merged builder's
     * primary call to action points here. Without it that button would fall
     * through to `GET /{workflowUid}` with uid "new" and 404. It is registered
     * before the wildcard for the same reason.
     */
    public function create(string $workspaceUid, string $businessUid): mixed
    {
        return $this->respond(function () use ($workspaceUid, $businessUid): mixed {
            $this->resolveEntitledBusiness($workspaceUid, $businessUid);

            return view('customer.Automations.Workflows.chooser', [
                'workspaceUid' => $workspaceUid,
                'businessUid' => $businessUid,
                'basePath' => $this->basePath($workspaceUid, $businessUid),
            ]);
        });
    }

    /** 201 with `{workflow, redirect}` — the two keys V2-D's chooser reads. */
    public function store(string $workspaceUid, string $businessUid): mixed
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

            return response()->json([
                'workflow' => $this->summary($workflow),
                'redirect' => $this->basePath($workspaceUid, $businessUid) . '/' . $workflow->uid,
            ], 201);
        });
    }

    /**
     * The builder shell for a person; the workflow's identity for a client.
     *
     * The page needs a document to edit, so it opens the draft through
     * ensureDraft() — see AutomationWorkflowDraftController for why that write
     * behind a GET is deliberate. An ARCHIVED workflow is the exception: it is
     * shown from its published document and never given a draft, so viewing a
     * finished workflow cannot resurrect an editable copy of it.
     */
    public function show(string $workspaceUid, string $businessUid, string $workflowUid): mixed
    {
        return $this->respond(function () use ($workspaceUid, $businessUid, $workflowUid): mixed {
            [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
            $workflow = $this->resolveWorkflow($business, $workflowUid);

            if (request()->wantsJson()) {
                return response()->json(['workflow' => $this->summary($workflow)]);
            }

            $version = $workflow->status === WorkflowStatus::Archived
                ? $this->publishedVersion($workflow)
                : $this->drafts->ensureDraft($workflow);

            abort_if($version === null, 404);

            return view('customer.Automations.Workflows.builder', [
                'workspaceUid' => $workspaceUid,
                'businessUid' => $businessUid,
                'basePath' => $this->basePath($workspaceUid, $businessUid),
                'workflow' => $workflow,
                'draft' => [
                    'definition' => $version->definition ?? [],
                    'revision' => (int) $version->definition_revision,
                    'errors' => $this->compiler->validate($version),
                ],
                ...$this->catalogs($business),
            ]);
        });
    }

    /**
     * The settings tab: the behavioural rules of the LIVE version, which are the
     * ones journeys actually follow, beside the draft's when one exists. Policies
     * live on the version rather than the workflow (§7.5, C2), so showing only one
     * would hide the difference a publish is about to make.
     */
    public function settings(string $workspaceUid, string $businessUid, string $workflowUid): mixed
    {
        return $this->respond(function () use ($workspaceUid, $businessUid, $workflowUid): JsonResponse {
            [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
            $workflow = $this->resolveWorkflow($business, $workflowUid);

            return response()->json([
                'workflow' => $this->summary($workflow),
                'published' => $this->versionRules($this->publishedVersion($workflow)),
                'draft' => $this->versionRules($workflow->draftVersion()),
            ]);
        });
    }

    public function pause(string $workspaceUid, string $businessUid, string $workflowUid): mixed
    {
        return $this->transition($workspaceUid, $businessUid, $workflowUid, fn (AutomationWorkflow $w) => $this->lifecycle->pause($w));
    }

    public function resume(string $workspaceUid, string $businessUid, string $workflowUid): mixed
    {
        return $this->transition($workspaceUid, $businessUid, $workflowUid, fn (AutomationWorkflow $w) => $this->lifecycle->resume($w));
    }

    public function archive(string $workspaceUid, string $businessUid, string $workflowUid): mixed
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
    private function transition(string $workspaceUid, string $businessUid, string $workflowUid, \Closure $change): mixed
    {
        return $this->respond(function () use ($workspaceUid, $businessUid, $workflowUid, $change): JsonResponse {
            [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
            $workflow = $this->resolveWorkflow($business, $workflowUid);

            $before = $workflow->status;
            $change($workflow);
            $after = $workflow->fresh();

            return response()->json([
                'workflow' => $this->summary($after),
                'changed' => $before !== $after->status,
            ]);
        });
    }

    /**
     * The builder's pickers, each read once and each scoped to this Business —
     * three queries whatever the size of the Business, inside §18's builder-load
     * budget. The phone field is excluded from the writable list here, as the
     * view's own props contract requires: it is a contact's identity, and a
     * picker that offered it would offer a write the executor refuses.
     *
     * @return array{contactGroups: Collection<int, ContactGroups>, dateFields: Collection<int, ContactGroupFields>, writableFields: Collection<int, ContactGroupFields>}
     */
    private function catalogs(Business $business): array
    {
        $businessFields = fn () => ContactGroupFields::query()
            ->select(['contact_group_fields.id', 'contact_group_fields.label', 'contact_group_fields.contact_group_id', 'contact_group_fields.type'])
            ->join('contact_groups', 'contact_groups.id', '=', 'contact_group_fields.contact_group_id')
            ->where('contact_groups.business_id', (int) $business->id)
            ->orderBy('contact_group_fields.id');

        return [
            'contactGroups' => ContactGroups::query()
                ->where('business_id', (int) $business->id)
                ->orderBy('name')
                ->get(['id', 'name']),
            'dateFields' => $businessFields()
                ->whereIn('contact_group_fields.type', [ContactGroupFields::TYPE_DATE, ContactGroupFields::TYPE_DATETIME])
                ->get(),
            'writableFields' => $businessFields()
                ->where('contact_group_fields.is_phone', false)
                ->get(),
        ];
    }

    private function basePath(string $workspaceUid, string $businessUid): string
    {
        return route('customer.workspaces.businesses.automations.workflows.index', [$workspaceUid, $businessUid], false);
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

    /**
     * @param bool|null $hasDraft already known for a whole page, so a list never
     *                            asks per row; null for a single workflow
     *
     * @return array<string, mixed>
     */
    private function summary(AutomationWorkflow $workflow, ?bool $hasDraft = null): array
    {
        return [
            'uid' => $workflow->uid,
            'name' => $workflow->name,
            'status' => $workflow->status->value,
            'status_label' => $workflow->status->label(),
            'has_published_version' => $workflow->published_version_id !== null,
            'has_draft' => $hasDraft ?? $workflow->draftVersion() !== null,
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

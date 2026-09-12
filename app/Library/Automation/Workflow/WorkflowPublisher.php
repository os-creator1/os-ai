<?php

namespace App\Library\Automation\Workflow;

use App\Enums\Automation\Workflow\EnrollmentPolicy;
use App\Enums\Automation\Workflow\EnrollmentPolicySource;
use App\Enums\Automation\Workflow\FailurePolicy;
use App\Enums\Automation\Workflow\WorkflowNodeType;
use App\Enums\Automation\Workflow\WorkflowStatus;
use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Enums\Automation\Workflow\WorkflowVersionState;
use App\Models\AutomationWorkflow;
use App\Models\AutomationWorkflowVersion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Automations V2 §6.1 — publishing, as one all-or-nothing transaction.
 *
 * What publishing means here: the draft is validated, compiled into immutable
 * node and edge rows, and promoted — while the version that was live becomes
 * `superseded` and keeps its own rows untouched. Every enrollment already
 * running stays pinned to the version it started on and keeps executing exactly
 * the graph it started with. That is the whole point of versioning, and it is
 * why nothing in this class ever writes to an already-published version's graph.
 *
 * THE ORDER OF THE TWO STATE CHANGES IS LOAD-BEARING. `published_guard` is a
 * UNIQUE generated column, so two `published` versions of one workflow cannot
 * coexist for even an instant. The previous version must therefore be marked
 * `superseded` BEFORE the new one is marked `published`; doing it the other way
 * round fails on the unique index. Being forced into the correct order by the
 * schema is deliberate.
 *
 * Failures throw ValidationException with messages keyed by node key, which is
 * exactly what the canvas needs to mark the offending step and what V2-E turns
 * into a 422.
 */
class WorkflowPublisher
{
    public function __construct(private readonly WorkflowCompiler $compiler)
    {
    }

    /**
     * @throws ValidationException when the draft cannot be published
     */
    public function publish(AutomationWorkflow $workflow, ?int $publishedByUserId = null): AutomationWorkflowVersion
    {
        return DB::transaction(function () use ($workflow, $publishedByUserId): AutomationWorkflowVersion {
            // Serialize against a concurrent publish, pause or resume of the same
            // workflow. Held only for this short, I/O-free transaction.
            $locked = AutomationWorkflow::query()
                ->whereKey($workflow->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw ValidationException::withMessages([
                    WorkflowDefinitionValidator::DOCUMENT_KEY => ['This workflow no longer exists.'],
                ]);
            }

            if ($locked->status === WorkflowStatus::Archived) {
                throw ValidationException::withMessages([
                    WorkflowDefinitionValidator::DOCUMENT_KEY => ['An archived workflow cannot be published.'],
                ]);
            }

            $draft = AutomationWorkflowVersion::query()
                ->where('workflow_id', $locked->getKey())
                ->where('state', WorkflowVersionState::Draft->value)
                ->lockForUpdate()
                ->first();

            if ($draft === null) {
                throw ValidationException::withMessages([
                    WorkflowDefinitionValidator::DOCUMENT_KEY => ['There are no changes to publish.'],
                ]);
            }

            $errors = $this->compiler->validate($draft);

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $this->assertPublishedWorkflowQuota($locked);

            $nodeCount = $this->compiler->compile($draft);

            $triggerConfig = $this->triggerConfig($draft);

            // (1) Retire the version that was live. MUST precede (2).
            $previousId = $locked->published_version_id;

            if ($previousId !== null) {
                AutomationWorkflowVersion::query()
                    ->whereKey($previousId)
                    ->where('state', WorkflowVersionState::Published->value)
                    ->update([
                        'state' => WorkflowVersionState::Superseded->value,
                        'updated_at' => Carbon::now(),
                    ]);
            }

            // (2) Promote the draft. Its own definition document is retained so
            // the builder can still render and diff it; the runtime reads the
            // compiled rows instead.
            $draft->forceFill([
                'state' => WorkflowVersionState::Published,
                'definition_hash' => hash('sha256', (string) json_encode($draft->definition)),
                'trigger_type' => WorkflowTriggerType::from((string) $triggerConfig['trigger_type']),
                'node_count' => $nodeCount,
                'enrollment_policy' => EnrollmentPolicy::from((string) $triggerConfig['enrollment_policy']),
                'enrollment_policy_source' => EnrollmentPolicySource::from((string) $triggerConfig['enrollment_policy_source']),
                'failure_policy' => FailurePolicy::from((string) $triggerConfig['failure_policy']),
                'published_at' => Carbon::now(),
                'published_by_user_id' => $publishedByUserId,
            ])->save();

            // (3) Point the workflow at it. A paused workflow that publishes
            // becomes live again, which is what the button says it does.
            $locked->forceFill([
                'published_version_id' => $draft->getKey(),
                'status' => WorkflowStatus::Published,
            ])->save();

            return $draft->refresh();
        });
    }

    /**
     * The trigger node's config, which carries the three versioned policies.
     * Validation has already proven it exists and is well-formed.
     */
    private function triggerConfig(AutomationWorkflowVersion $draft): array
    {
        $root = $draft->definition['root'] ?? [];

        if ((string) ($root['type'] ?? '') !== WorkflowNodeType::Trigger->value) {
            throw ValidationException::withMessages([
                WorkflowDefinitionValidator::DOCUMENT_KEY => ['A workflow must start with a trigger.'],
            ]);
        }

        return is_array($root['config'] ?? null) ? $root['config'] : [];
    }

    private function assertPublishedWorkflowQuota(AutomationWorkflow $workflow): void
    {
        // Only a first publish consumes a slot; republishing an already-live
        // workflow must never be blocked by the cap.
        if ($workflow->published_version_id !== null) {
            return;
        }

        $published = AutomationWorkflow::query()
            ->where('business_id', $workflow->business_id)
            ->whereNotNull('published_version_id')
            ->whereKeyNot($workflow->getKey())
            ->count();

        if ($published >= WorkflowLimits::MAX_PUBLISHED_WORKFLOWS_PER_BUSINESS) {
            throw ValidationException::withMessages([
                WorkflowDefinitionValidator::DOCUMENT_KEY => [sprintf(
                    'This business already has %d published workflows, which is the limit. Archive one to publish another.',
                    WorkflowLimits::MAX_PUBLISHED_WORKFLOWS_PER_BUSINESS,
                )],
            ]);
        }
    }
}

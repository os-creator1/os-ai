<?php

namespace App\Library\Automation\Workflow;

use App\Enums\Automation\Workflow\EnrollmentPolicySource;
use App\Enums\Automation\Workflow\FailurePolicy;
use App\Enums\Automation\Workflow\WorkflowNodeType;
use App\Enums\Automation\Workflow\WorkflowStatus;
use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Enums\Automation\Workflow\WorkflowVersionState;
use App\Models\AutomationWorkflow;
use App\Models\AutomationWorkflowVersion;
use App\Models\Business;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Automations V2 §6.1/§7.5/§14.4 — creating workflows and editing drafts.
 *
 * THE RULE THIS CLASS EXISTS TO KEEP: editing never touches what is live. Every
 * write here lands on a `draft` version. The published version — its row, its
 * policies and its compiled graph — is never modified, so a journey already
 * underway cannot change shape beneath the contact.
 *
 * Autosave is guarded by `definition_revision`. The update is conditional on the
 * revision the editor last saw, so two browser tabs cannot silently overwrite
 * each other: the loser is told, rather than the last writer winning.
 */
class WorkflowDraftService
{
    /**
     * Create a workflow with its first draft, applying the trigger's own default
     * enrollment rule (§9.1, D3).
     */
    public function createWorkflowWithDraft(
        Business $business,
        string $name,
        WorkflowTriggerType $triggerType,
        ?int $createdByUserId = null,
    ): AutomationWorkflow {
        return DB::transaction(function () use ($business, $name, $triggerType, $createdByUserId): AutomationWorkflow {
            $workflow = new AutomationWorkflow([
                'business_id' => $business->getKey(),
                'name' => $name,
                'status' => WorkflowStatus::Draft,
                'created_by_user_id' => $createdByUserId,
            ]);
            $workflow->save();

            $this->createDraft($workflow, $this->starterDefinition($triggerType), 1);

            return $workflow->refresh();
        });
    }

    /**
     * The editable version for this workflow, created from the published one if
     * it does not exist yet.
     *
     * Cloning the published document — rather than starting empty — is what makes
     * "edit a live workflow" safe: the customer edits a copy, and the live version
     * keeps running untouched until they publish.
     */
    public function ensureDraft(AutomationWorkflow $workflow): AutomationWorkflowVersion
    {
        return DB::transaction(function () use ($workflow): AutomationWorkflowVersion {
            $locked = AutomationWorkflow::query()
                ->whereKey($workflow->getKey())
                ->lockForUpdate()
                ->first();

            $existing = AutomationWorkflowVersion::query()
                ->where('workflow_id', $locked->getKey())
                ->where('state', WorkflowVersionState::Draft->value)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $published = $locked->published_version_id === null
                ? null
                : AutomationWorkflowVersion::query()->whereKey($locked->published_version_id)->first();

            $definition = $published?->definition
                ?? $this->starterDefinition(WorkflowTriggerType::ContactCreated);

            return $this->createDraft($locked, $definition, $this->nextVersionNumber($locked));
        });
    }

    /**
     * Save the document the editor is holding.
     *
     * @param int $expectedRevision the revision the editor last received
     *
     * @throws ConflictHttpException when another writer moved on first
     */
    public function autosave(
        AutomationWorkflowVersion $draft,
        array $definition,
        int $expectedRevision,
    ): AutomationWorkflowVersion {
        if (! $draft->isDraft()) {
            // A published version is immutable. Reaching here means a caller
            // resolved the wrong version, and silently writing would rewrite a
            // live workflow under its running enrollments.
            throw new ConflictHttpException('Only a draft can be edited.');
        }

        $affected = AutomationWorkflowVersion::query()
            ->whereKey($draft->getKey())
            ->where('definition_revision', $expectedRevision)
            ->where('state', WorkflowVersionState::Draft->value)
            ->update([
                'definition' => json_encode($definition),
                'definition_revision' => $expectedRevision + 1,
                'updated_at' => now(),
            ]);

        if ($affected === 0) {
            throw new ConflictHttpException(
                'This workflow changed somewhere else. Reload to get the latest version before editing.',
            );
        }

        return $draft->refresh();
    }

    /** Throw the draft away and go back to whatever is published. */
    public function discardDraft(AutomationWorkflow $workflow): void
    {
        DB::transaction(function () use ($workflow): void {
            $draft = AutomationWorkflowVersion::query()
                ->where('workflow_id', $workflow->getKey())
                ->where('state', WorkflowVersionState::Draft->value)
                ->lockForUpdate()
                ->first();

            if ($draft === null) {
                return;
            }

            // A draft has no compiled graph — nodes and edges are written only at
            // publish — so there is nothing else to clean up.
            $draft->delete();
        });
    }

    /**
     * Apply a trigger change to a document, honouring the policy's origin (§7.5).
     *
     * An UNTOUCHED default follows the new trigger, so changing a "contact
     * created" workflow into a birthday workflow starts letting contacts enter
     * every year instead of once. A policy the customer chose themselves is left
     * alone here — the builder must ask them first, and the validator refuses to
     * publish a default that no longer matches its trigger.
     */
    public function withTriggerChanged(array $definition, WorkflowTriggerType $newTriggerType): array
    {
        $root = $definition['root'] ?? null;

        if (! is_array($root) || (string) ($root['type'] ?? '') !== WorkflowNodeType::Trigger->value) {
            return $definition;
        }

        $config = is_array($root['config'] ?? null) ? $root['config'] : [];
        $source = EnrollmentPolicySource::tryFrom((string) ($config['enrollment_policy_source'] ?? ''));

        $config['trigger_type'] = $newTriggerType->value;

        if ($source !== EnrollmentPolicySource::User) {
            $config['enrollment_policy'] = $newTriggerType->defaultEnrollmentPolicy()->value;
            $config['enrollment_policy_source'] = EnrollmentPolicySource::Default->value;
        }

        $definition['root']['config'] = $config;

        return $definition;
    }

    /** A new workflow's starting document: a trigger and nothing after it yet. */
    public function starterDefinition(WorkflowTriggerType $triggerType): array
    {
        return [
            'schema_version' => WorkflowDefinitionValidator::SCHEMA_VERSION,
            'root' => [
                'key' => (string) Str::uuid(),
                'type' => WorkflowNodeType::Trigger->value,
                'config' => [
                    'trigger_type' => $triggerType->value,
                    'enrollment_policy' => $triggerType->defaultEnrollmentPolicy()->value,
                    'enrollment_policy_source' => EnrollmentPolicySource::Default->value,
                    'failure_policy' => FailurePolicy::default()->value,
                ],
                'next' => [],
            ],
        ];
    }

    private function createDraft(
        AutomationWorkflow $workflow,
        array $definition,
        int $versionNumber,
    ): AutomationWorkflowVersion {
        $draft = new AutomationWorkflowVersion([
            'workflow_id' => $workflow->getKey(),
            'business_id' => $workflow->business_id,
            'version_number' => $versionNumber,
            'state' => WorkflowVersionState::Draft,
            'definition' => $definition,
            'definition_revision' => 1,
        ]);
        $draft->save();

        return $draft;
    }

    private function nextVersionNumber(AutomationWorkflow $workflow): int
    {
        return 1 + (int) AutomationWorkflowVersion::query()
            ->where('workflow_id', $workflow->getKey())
            ->max('version_number');
    }
}

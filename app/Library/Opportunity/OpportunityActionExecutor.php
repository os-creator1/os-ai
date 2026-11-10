<?php

declare(strict_types=1);

namespace App\Library\Opportunity;

use App\Enums\Opportunity\OpportunityActionExecutionStatus;
use App\Enums\Opportunity\OpportunityStatus;
use App\Library\Business\BusinessManager;
use App\Library\Opportunity\Exceptions\OpportunityActionNotExecutableException;
use App\Library\Opportunity\Exceptions\OpportunityActionVerificationException;
use App\Models\Opportunity;
use App\Models\OpportunityActionExecution;

/**
 * Invokes the real, registry-approved handler for a running execution
 * (RFC-002 §30.1 step 5, §30.2). Supports `add_phone`, `add_email` and `add_description` (each writes exactly one Business column).
 * Owns no transaction and no lock of its own — the caller (future
 * ExecuteOpportunityAction) is expected to already hold the Opportunity and
 * execution row locks inside its own transaction. Never persists any
 * Opportunity/OpportunityActionExecution field itself; it only mutates
 * Business data through BusinessManager and returns a customer-safe result
 * summary for the caller to record.
 */
final class OpportunityActionExecutor
{
    private const HANDLERS = [
        'business.update_phone' => 'executePhone',
        'business.update_email' => 'executeEmail',
        'business.update_description' => 'executeDescription',
    ];

    private const VERIFIERS = [
        'business.phone_matches_parameter' => true,
        'business.email_matches_parameter' => true,
        'business.description_matches_parameter' => true,
    ];

    private const SUCCESS_SUMMARY = 'Business phone updated and verified.';

    /** The one Business column each handler may write, and its fixed customer-safe success summary. Not a generic field updater. */
    private const FIELD_ACTIONS = [
        'add_phone' => ['phone', 'Business phone updated and verified.'],
        'add_email' => ['email', 'Business email updated and verified.'],
        'add_description' => ['description', 'Business description updated and verified.'],
    ];

    private const EXPECTED_RECOMMENDED_ACTION_KEYS = [
        'action_key',
        'approval_required',
        'completion_policy',
        'parameters',
        'schema_version',
    ];

    public function __construct(
        private readonly BusinessManager $businessManager,
        private readonly OpportunityActionRegistry $registry,
        private readonly OpportunityAuthorityGuard $authority,
    ) {
    }

    /**
     * Pure executor-capability predicate (RFC-002 §13.1, §30) — answers only
     * "do I have working handler/verifier code for this action_key and
     * completion_policy," never whether the action should require approval
     * or whether it mutates Business data (those are OpportunityManager's
     * own workflow-eligibility concerns, not executor capability). Performs
     * no database query and never throws; an unrecognized combination
     * simply returns false. Reused by assertExecutable() below so this
     * class has exactly one source of truth for what it can run.
     */
    public function supports(string $actionKey, array $actionDefinition): bool
    {
        $registered = $this->registry::get($actionKey);

        return $registered !== null
            && isset(self::HANDLERS[$registered['handler_identifier'] ?? ''])
            && isset(self::VERIFIERS[$registered['verifier_identifier'] ?? ''])
            && ($actionDefinition['handler_identifier'] ?? null) === ($registered['handler_identifier'] ?? null)
            && ($actionDefinition['verifier_identifier'] ?? null) === ($registered['verifier_identifier'] ?? null)
            && isset($actionDefinition['completion_policy'])
            && $actionDefinition['completion_policy'] === $registered['completion_policy'];
    }

    public function execute(Opportunity $opportunity, OpportunityActionExecution $execution): string
    {
        $value = $this->assertExecutable($opportunity, $execution);

        $this->authority->assertPaidEffectIsCovered($opportunity, $execution->action_key, $execution);

        $definition = $this->registry::get($execution->action_key);
        $handler = self::HANDLERS[$definition['handler_identifier']];

        return $this->{$handler}($opportunity, $value, $execution);
    }

    private function executePhone(Opportunity $opportunity, string $value, OpportunityActionExecution $execution): string
    {
        return $this->executeField($opportunity, 'add_phone', $value, $execution);
    }

    private function executeEmail(Opportunity $opportunity, string $value, OpportunityActionExecution $execution): string
    {
        return $this->executeField($opportunity, 'add_email', $value, $execution);
    }

    private function executeDescription(Opportunity $opportunity, string $value, OpportunityActionExecution $execution): string
    {
        return $this->executeField($opportunity, 'add_description', $value, $execution);
    }

    /** Writes the approved value through BusinessManager, then re-reads persisted state: success is reported only if it matches exactly. */
    private function executeField(Opportunity $opportunity, string $actionKey, string $value, OpportunityActionExecution $execution): string
    {
        [$field, $summary] = self::FIELD_ACTIONS[$actionKey];

        $business = $opportunity->business;

        if ($business === null) {
            throw new OpportunityActionNotExecutableException(
                "Opportunity [{$opportunity->id}] has no owning Business."
            );
        }

        $customer = $business->customer;

        if ($customer === null) {
            throw new OpportunityActionNotExecutableException(
                "Business [{$business->id}] has no owning Customer."
            );
        }

        // Compare-and-swap on what the customer saw at approval: the Business row is locked (a concurrent manual save waits for this
        // transaction), and the authoritative current value must still match the durably recorded expectation. Otherwise nothing is
        // written and the execution fails safely, leaving the newer value untouched.
        if ($execution->expected_state_hash !== null) {
            $business = $business::query()->whereKey($business->id)->lockForUpdate()->first();

            if ($business === null || ! hash_equals($execution->expected_state_hash, hash('sha256', (string) $business->{$field}))) {
                throw new OpportunityActionNotExecutableException(
                    "Opportunity [{$opportunity->id}]'s {$actionKey} target changed after approval."
                );
            }
        }

        $updatedBusiness = $this->businessManager->updateBusiness($customer, $business, [$field => $value]);

        $verifiedBusiness = $updatedBusiness->fresh();

        if ($verifiedBusiness === null || $verifiedBusiness->{$field} !== $value) {
            throw new OpportunityActionVerificationException(
                "Opportunity [{$opportunity->id}]'s {$actionKey} execution could not be verified."
            );
        }

        return $summary;
    }

    private function assertExecutable(Opportunity $opportunity, OpportunityActionExecution $execution): string
    {
        if ($opportunity->status !== OpportunityStatus::InProgress) {
            throw new OpportunityActionNotExecutableException(
                "Opportunity [{$opportunity->id}] is not in_progress."
            );
        }

        if ($execution->status !== OpportunityActionExecutionStatus::Running) {
            throw new OpportunityActionNotExecutableException(
                "Execution [{$execution->id}] is not running."
            );
        }

        if ($execution->opportunity_id !== $opportunity->id) {
            throw new OpportunityActionNotExecutableException(
                "Execution [{$execution->id}] does not belong to Opportunity [{$opportunity->id}]."
            );
        }

        $recommendedAction = $opportunity->recommended_action;

        if (! is_array($recommendedAction)) {
            throw new OpportunityActionNotExecutableException(
                "Opportunity [{$opportunity->id}] has no configured action."
            );
        }

        $actualKeys = array_keys($recommendedAction);
        sort($actualKeys);

        if ($actualKeys !== self::EXPECTED_RECOMMENDED_ACTION_KEYS) {
            throw new OpportunityActionNotExecutableException(
                "Opportunity [{$opportunity->id}]'s recommended_action has an unexpected structure."
            );
        }

        $actionKey = $recommendedAction['action_key'];

        $actionDefinition = $this->registry::get($actionKey);

        if ($actionDefinition === null) {
            throw new OpportunityActionNotExecutableException(
                "Action [{$actionKey}] has no registered action definition."
            );
        }

        if (! $this->supports($actionKey, $actionDefinition)
            || ($actionDefinition['mutates_business_data'] ?? null) !== true
            || ($actionDefinition['approval_required'] ?? null) !== true
        ) {
            throw new OpportunityActionNotExecutableException(
                "Action [{$actionKey}] is not a fully supported executable action."
            );
        }

        if ($recommendedAction['schema_version'] !== $actionDefinition['schema_version']
            || $recommendedAction['approval_required'] !== $actionDefinition['approval_required']
            || $recommendedAction['completion_policy'] !== $actionDefinition['completion_policy']->value
        ) {
            throw new OpportunityActionNotExecutableException(
                "Opportunity [{$opportunity->id}]'s persisted action disagrees with the trusted registry."
            );
        }

        if ($opportunity->action_schema_version !== $actionDefinition['schema_version']) {
            throw new OpportunityActionNotExecutableException(
                "Opportunity [{$opportunity->id}]'s action_schema_version disagrees with the trusted registry."
            );
        }

        $recomputedHash = (new OpportunityActionHash())->compute($recommendedAction);

        if (! is_string($opportunity->recommended_action_hash) || $recomputedHash !== $opportunity->recommended_action_hash) {
            throw new OpportunityActionNotExecutableException(
                "Opportunity [{$opportunity->id}]'s recommended_action_hash does not match its persisted action."
            );
        }

        if ($execution->action_key !== $actionKey
            || $execution->recommended_action_hash !== $opportunity->recommended_action_hash
            || $execution->action_schema_version !== $opportunity->action_schema_version
            || $execution->occurrence_number !== $opportunity->occurrence_number
            || $execution->completion_policy->value !== $recommendedAction['completion_policy']
        ) {
            throw new OpportunityActionNotExecutableException(
                "Execution [{$execution->id}] no longer matches the live Opportunity/action."
            );
        }

        $parameters = $recommendedAction['parameters'];

        if (! is_array($parameters) || array_keys($parameters) !== ['value']) {
            throw new OpportunityActionNotExecutableException(
                "Opportunity [{$opportunity->id}]'s action parameters are not fully configured."
            );
        }

        $value = $parameters['value'];

        if (! $this->registry::valueIsValid($actionKey, $value)) {
            throw new OpportunityActionNotExecutableException(
                "Opportunity [{$opportunity->id}]'s configured {$actionKey} value is invalid."
            );
        }

        return $value;
    }
}

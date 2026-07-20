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
 * (RFC-002 §30.1 step 5, §30.2). Supports exactly `add_phone` in this phase.
 * Owns no transaction and no lock of its own — the caller (future
 * ExecuteOpportunityAction) is expected to already hold the Opportunity and
 * execution row locks inside its own transaction. Never persists any
 * Opportunity/OpportunityActionExecution field itself; it only mutates
 * Business data through BusinessManager and returns a customer-safe result
 * summary for the caller to record.
 */
final class OpportunityActionExecutor
{
    private const SUPPORTED_ACTION_KEY = 'add_phone';

    private const SUPPORTED_HANDLER_IDENTIFIER = 'business.update_phone';

    private const SUPPORTED_VERIFIER_IDENTIFIER = 'business.phone_matches_parameter';

    private const SUPPORTED_COMPLETION_POLICY = 'system_verified';

    private const SUCCESS_SUMMARY = 'Business phone updated and verified.';

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
        return $actionKey === self::SUPPORTED_ACTION_KEY
            && ($actionDefinition['handler_identifier'] ?? null) === self::SUPPORTED_HANDLER_IDENTIFIER
            && ($actionDefinition['verifier_identifier'] ?? null) === self::SUPPORTED_VERIFIER_IDENTIFIER
            && isset($actionDefinition['completion_policy'])
            && $actionDefinition['completion_policy']->value === self::SUPPORTED_COMPLETION_POLICY;
    }

    public function execute(Opportunity $opportunity, OpportunityActionExecution $execution): string
    {
        $value = $this->assertExecutable($opportunity, $execution);

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

        $updatedBusiness = $this->businessManager->updateBusiness($customer, $business, ['phone' => $value]);

        $verifiedBusiness = $updatedBusiness->fresh();

        if ($verifiedBusiness === null || $verifiedBusiness->phone !== $value) {
            throw new OpportunityActionVerificationException(
                "Opportunity [{$opportunity->id}]'s add_phone execution could not be verified."
            );
        }

        return self::SUCCESS_SUMMARY;
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

        if ($actionKey !== self::SUPPORTED_ACTION_KEY) {
            throw new OpportunityActionNotExecutableException(
                "Action [{$actionKey}] is not executable."
            );
        }

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

        if (! is_string($value) || trim($value) === '' || mb_strlen($value) > 50) {
            throw new OpportunityActionNotExecutableException(
                "Opportunity [{$opportunity->id}]'s configured phone value is invalid."
            );
        }

        return $value;
    }
}

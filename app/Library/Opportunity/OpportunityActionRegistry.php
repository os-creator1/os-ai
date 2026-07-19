<?php

namespace App\Library\Opportunity;

use App\Enums\Opportunity\OpportunityCompletionPolicy;

/**
 * Closed, source-controlled action metadata (RFC-002 §13.1).
 *
 * Milestone 1 defined: is this action allowed, does it mutate Business data,
 * does it require approval, what completion policy applies. Milestone 2
 * Phase 2A adds `parameter_rules` — a trusted, purely declarative allowlist
 * of accepted `actionParameters` keys, used only to reject a non-empty
 * parameters payload before an action is executable. None of RFC-002's 11
 * business_advisor actions accept any parameter, so every entry's
 * `parameter_rules` is `[]`. This is deliberately not a `validator`
 * class-name key: handlers and system-verification methods are still added
 * only in Milestone 4, and this registry never claims an action is
 * executable — an action_key existing here does not mean it is executable
 * yet.
 *
 * Every entry mirrors RFC-001 Milestone 5's OnboardingActionExecutor
 * allowlist for the business_advisor actions (RFC-002 §39, locked).
 */
final class OpportunityActionRegistry
{
    /**
     * @var array<string, array{schema_version: int, mutates_business_data: bool, approval_required: bool, completion_policy: OpportunityCompletionPolicy, parameter_rules: array<string, mixed>}>
     */
    private const DEFINITIONS = [
        'add_phone' => [
            'schema_version' => 1,
            'mutates_business_data' => true,
            'approval_required' => true,
            'completion_policy' => OpportunityCompletionPolicy::SystemVerified,
            'parameter_rules' => [
                'value' => [
                    'required' => true,
                    'type' => 'string',
                    'max_length' => 50,
                    'allow_blank' => false,
                ],
            ],
            'handler_identifier' => 'business.update_phone',
            'verifier_identifier' => 'business.phone_matches_parameter',
        ],
        'add_email' => [
            'schema_version' => 1,
            'mutates_business_data' => true,
            'approval_required' => true,
            'completion_policy' => OpportunityCompletionPolicy::SystemVerified,
            'parameter_rules' => [],
        ],
        'add_website' => [
            'schema_version' => 1,
            'mutates_business_data' => true,
            'approval_required' => true,
            'completion_policy' => OpportunityCompletionPolicy::SystemVerified,
            'parameter_rules' => [],
        ],
        'add_description' => [
            'schema_version' => 1,
            'mutates_business_data' => true,
            'approval_required' => true,
            'completion_policy' => OpportunityCompletionPolicy::SystemVerified,
            'parameter_rules' => [],
        ],
        'add_location' => [
            'schema_version' => 1,
            'mutates_business_data' => true,
            'approval_required' => true,
            'completion_policy' => OpportunityCompletionPolicy::SystemVerified,
            'parameter_rules' => [],
        ],
        'complete_location' => [
            'schema_version' => 1,
            'mutates_business_data' => true,
            'approval_required' => true,
            'completion_policy' => OpportunityCompletionPolicy::SystemVerified,
            'parameter_rules' => [],
        ],
        'add_service' => [
            'schema_version' => 1,
            'mutates_business_data' => true,
            'approval_required' => true,
            'completion_policy' => OpportunityCompletionPolicy::SystemVerified,
            'parameter_rules' => [],
        ],
        'confirm_primary_service' => [
            'schema_version' => 1,
            'mutates_business_data' => true,
            'approval_required' => true,
            'completion_policy' => OpportunityCompletionPolicy::SystemVerified,
            'parameter_rules' => [],
        ],
        'add_gbp_url' => [
            'schema_version' => 1,
            'mutates_business_data' => true,
            'approval_required' => true,
            'completion_policy' => OpportunityCompletionPolicy::SystemVerified,
            'parameter_rules' => [],
        ],
        'add_facebook_url' => [
            'schema_version' => 1,
            'mutates_business_data' => true,
            'approval_required' => true,
            'completion_policy' => OpportunityCompletionPolicy::SystemVerified,
            'parameter_rules' => [],
        ],
        'add_instagram_url' => [
            'schema_version' => 1,
            'mutates_business_data' => true,
            'approval_required' => true,
            'completion_policy' => OpportunityCompletionPolicy::SystemVerified,
            'parameter_rules' => [],
        ],
    ];

    public static function has(string $actionKey): bool
    {
        return array_key_exists($actionKey, self::DEFINITIONS);
    }

    /**
     * @return array{schema_version: int, mutates_business_data: bool, approval_required: bool, completion_policy: OpportunityCompletionPolicy, parameter_rules: array<string, mixed>}|null
     */
    public static function get(string $actionKey): ?array
    {
        return self::DEFINITIONS[$actionKey] ?? null;
    }

    /**
     * @return array<string, array{schema_version: int, mutates_business_data: bool, approval_required: bool, completion_policy: OpportunityCompletionPolicy, parameter_rules: array<string, mixed>}>
     */
    public static function all(): array
    {
        return self::DEFINITIONS;
    }
}

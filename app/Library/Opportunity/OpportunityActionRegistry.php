<?php

namespace App\Library\Opportunity;

use App\Enums\Opportunity\OpportunityCompletionPolicy;

/**
 * Closed, source-controlled action metadata (RFC-002 §13.1) — Milestone 1
 * scope only.
 *
 * Only the metadata already needed by Milestone 1/2 (candidate staging and
 * scoring: is this action allowed, does it mutate Business data, does it
 * require approval, what completion policy applies) is defined here.
 * Parameter validators are added in Milestone 2; handlers and
 * system-verification methods are added in Milestone 4. Until then, this
 * class exposes no validator/handler/verification lookup at all — an
 * action_key existing in this registry does not mean it is executable yet.
 *
 * Every entry mirrors RFC-001 Milestone 5's OnboardingActionExecutor
 * allowlist for the business_advisor actions (RFC-002 §39, locked).
 */
final class OpportunityActionRegistry
{
    /**
     * @var array<string, array{schema_version: int, mutates_business_data: bool, approval_required: bool, completion_policy: OpportunityCompletionPolicy}>
     */
    private const DEFINITIONS = [
        'add_phone' => [
            'schema_version' => 1,
            'mutates_business_data' => true,
            'approval_required' => true,
            'completion_policy' => OpportunityCompletionPolicy::SystemVerified,
        ],
        'add_email' => [
            'schema_version' => 1,
            'mutates_business_data' => true,
            'approval_required' => true,
            'completion_policy' => OpportunityCompletionPolicy::SystemVerified,
        ],
        'add_website' => [
            'schema_version' => 1,
            'mutates_business_data' => true,
            'approval_required' => true,
            'completion_policy' => OpportunityCompletionPolicy::SystemVerified,
        ],
        'add_description' => [
            'schema_version' => 1,
            'mutates_business_data' => true,
            'approval_required' => true,
            'completion_policy' => OpportunityCompletionPolicy::SystemVerified,
        ],
        'add_location' => [
            'schema_version' => 1,
            'mutates_business_data' => true,
            'approval_required' => true,
            'completion_policy' => OpportunityCompletionPolicy::SystemVerified,
        ],
        'complete_location' => [
            'schema_version' => 1,
            'mutates_business_data' => true,
            'approval_required' => true,
            'completion_policy' => OpportunityCompletionPolicy::SystemVerified,
        ],
        'add_service' => [
            'schema_version' => 1,
            'mutates_business_data' => true,
            'approval_required' => true,
            'completion_policy' => OpportunityCompletionPolicy::SystemVerified,
        ],
        'confirm_primary_service' => [
            'schema_version' => 1,
            'mutates_business_data' => true,
            'approval_required' => true,
            'completion_policy' => OpportunityCompletionPolicy::SystemVerified,
        ],
        'add_gbp_url' => [
            'schema_version' => 1,
            'mutates_business_data' => true,
            'approval_required' => true,
            'completion_policy' => OpportunityCompletionPolicy::SystemVerified,
        ],
        'add_facebook_url' => [
            'schema_version' => 1,
            'mutates_business_data' => true,
            'approval_required' => true,
            'completion_policy' => OpportunityCompletionPolicy::SystemVerified,
        ],
        'add_instagram_url' => [
            'schema_version' => 1,
            'mutates_business_data' => true,
            'approval_required' => true,
            'completion_policy' => OpportunityCompletionPolicy::SystemVerified,
        ],
    ];

    public static function has(string $actionKey): bool
    {
        return array_key_exists($actionKey, self::DEFINITIONS);
    }

    /**
     * @return array{schema_version: int, mutates_business_data: bool, approval_required: bool, completion_policy: OpportunityCompletionPolicy}|null
     */
    public static function get(string $actionKey): ?array
    {
        return self::DEFINITIONS[$actionKey] ?? null;
    }

    /**
     * @return array<string, array{schema_version: int, mutates_business_data: bool, approval_required: bool, completion_policy: OpportunityCompletionPolicy}>
     */
    public static function all(): array
    {
        return self::DEFINITIONS;
    }
}

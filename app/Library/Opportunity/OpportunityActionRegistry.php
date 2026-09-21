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
 *
 * IMPLEMENTATION CONTRACT 19 §5.4(5) adds two first-class risk flags
 * alongside the existing `approval_required`/`mutates_business_data` pair.
 * Both are SOURCE-CONTROLLED and never a column, never client-supplied and
 * never model-authored (§8: "`paid_effect` on the action registry
 * (source-controlled, not a column)") — the point of a closed registry is
 * that what an action may cost and what it may touch are decided in review,
 * not at runtime:
 *
 *   `paid_effect`    — executing it spends real money (a purchase, a
 *                      metered provider call, a wallet debit). §5.4(4)
 *                      forbids retrying one under its original approval,
 *                      and §5.4(5) forbids executing one with no approved
 *                      cost estimate. Every action today is `false`:
 *                      `add_phone` writes a Business column through
 *                      BusinessManager and buys nothing.
 *   `location_bound` — the effect belongs to one Business Location, so
 *                      §5.4(2)'s Location gate must re-check
 *                      LocationAccessGuard against it. Every action today is
 *                      `false`: all eleven mutate Business-level profile
 *                      fields. The flag exists because the gate needs a
 *                      declared source of truth; inferring "does this touch
 *                      a Location" from parameters at runtime is exactly the
 *                      kind of derived authority this registry exists to
 *                      prevent.
 *
 * Adding an action with either flag `true` is therefore a deliberate,
 * reviewed act — which is the whole design.
 */
final class OpportunityActionRegistry
{
    /**
     * @var array<string, array{schema_version: int, mutates_business_data: bool, paid_effect: bool, location_bound: bool, approval_required: bool, completion_policy: OpportunityCompletionPolicy, parameter_rules: array<string, mixed>}>
     */
    private const DEFINITIONS = [
        'add_phone' => [
            'schema_version' => 1,
            'mutates_business_data' => true,
            'paid_effect' => false,
            'location_bound' => false,
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
            'paid_effect' => false,
            'location_bound' => false,
            'approval_required' => true,
            'completion_policy' => OpportunityCompletionPolicy::SystemVerified,
            'parameter_rules' => [],
        ],
        'add_website' => [
            'schema_version' => 1,
            'mutates_business_data' => true,
            'paid_effect' => false,
            'location_bound' => false,
            'approval_required' => true,
            'completion_policy' => OpportunityCompletionPolicy::SystemVerified,
            'parameter_rules' => [],
        ],
        'add_description' => [
            'schema_version' => 1,
            'mutates_business_data' => true,
            'paid_effect' => false,
            'location_bound' => false,
            'approval_required' => true,
            'completion_policy' => OpportunityCompletionPolicy::SystemVerified,
            'parameter_rules' => [],
        ],
        'add_location' => [
            'schema_version' => 1,
            'mutates_business_data' => true,
            'paid_effect' => false,
            'location_bound' => false,
            'approval_required' => true,
            'completion_policy' => OpportunityCompletionPolicy::SystemVerified,
            'parameter_rules' => [],
        ],
        'complete_location' => [
            'schema_version' => 1,
            'mutates_business_data' => true,
            'paid_effect' => false,
            'location_bound' => false,
            'approval_required' => true,
            'completion_policy' => OpportunityCompletionPolicy::SystemVerified,
            'parameter_rules' => [],
        ],
        'add_service' => [
            'schema_version' => 1,
            'mutates_business_data' => true,
            'paid_effect' => false,
            'location_bound' => false,
            'approval_required' => true,
            'completion_policy' => OpportunityCompletionPolicy::SystemVerified,
            'parameter_rules' => [],
        ],
        'confirm_primary_service' => [
            'schema_version' => 1,
            'mutates_business_data' => true,
            'paid_effect' => false,
            'location_bound' => false,
            'approval_required' => true,
            'completion_policy' => OpportunityCompletionPolicy::SystemVerified,
            'parameter_rules' => [],
        ],
        'add_gbp_url' => [
            'schema_version' => 1,
            'mutates_business_data' => true,
            'paid_effect' => false,
            'location_bound' => false,
            'approval_required' => true,
            'completion_policy' => OpportunityCompletionPolicy::SystemVerified,
            'parameter_rules' => [],
        ],
        'add_facebook_url' => [
            'schema_version' => 1,
            'mutates_business_data' => true,
            'paid_effect' => false,
            'location_bound' => false,
            'approval_required' => true,
            'completion_policy' => OpportunityCompletionPolicy::SystemVerified,
            'parameter_rules' => [],
        ],
        'add_instagram_url' => [
            'schema_version' => 1,
            'mutates_business_data' => true,
            'paid_effect' => false,
            'location_bound' => false,
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
     * Implementation Contract 19 §5.4(4)/(5) — does executing this action
     * spend money?
     *
     * Fails CLOSED for an unknown action key: something the registry has
     * never heard of is treated as paid, so a typo or a half-registered
     * action inherits the strictest rule (re-approval required, cost
     * estimate required) rather than the loosest.
     */
    public static function hasPaidEffect(string $actionKey): bool
    {
        $definition = self::get($actionKey);

        return $definition === null || ($definition['paid_effect'] ?? true) !== false;
    }

    /**
     * Implementation Contract 19 §5.4(2) gate 4 — is this action's effect
     * bound to one Business Location?
     *
     * Fails CLOSED for an unknown action key, for the same reason as
     * hasPaidEffect(): an unrecognised action is assumed to need the
     * stricter Location check, and the caller then refuses because it cannot
     * resolve a Location for it.
     */
    public static function isLocationBound(string $actionKey): bool
    {
        $definition = self::get($actionKey);

        return $definition === null || ($definition['location_bound'] ?? true) !== false;
    }

    /**
     * Implementation Contract 19 §5.4(4) — may a FAILED attempt at this
     * action be retried under the approval that authorised the first one?
     *
     * Only a non-mutating, non-paid action may. Every action in the registry
     * today mutates Business data, so every retry today re-enters approval.
     */
    public static function mayRetryUnderOriginalApproval(string $actionKey): bool
    {
        $definition = self::get($actionKey);

        if ($definition === null) {
            return false;
        }

        return self::metadataAllowsRetry($definition);
    }

    /** Pure policy seam; null/unknown metadata fails closed. */
    public static function metadataAllowsRetry(?array $definition): bool
    {
        return $definition !== null
            && ($definition['mutates_business_data'] ?? null) === false
            && ($definition['paid_effect'] ?? null) === false;
    }

    /**
     * @return array{schema_version: int, mutates_business_data: bool, paid_effect: bool, location_bound: bool, approval_required: bool, completion_policy: OpportunityCompletionPolicy, parameter_rules: array<string, mixed>}|null
     */
    public static function get(string $actionKey): ?array
    {
        return self::DEFINITIONS[$actionKey] ?? null;
    }

    /**
     * @return array<string, array{schema_version: int, mutates_business_data: bool, paid_effect: bool, location_bound: bool, approval_required: bool, completion_policy: OpportunityCompletionPolicy, parameter_rules: array<string, mixed>}>
     */
    public static function all(): array
    {
        return self::DEFINITIONS;
    }
}

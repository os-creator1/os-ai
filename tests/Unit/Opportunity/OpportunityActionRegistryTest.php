<?php

namespace Tests\Unit\Opportunity;

use App\Enums\Opportunity\OpportunityCompletionPolicy;
use App\Library\Opportunity\OpportunityActionRegistry;
use Tests\TestCase;

class OpportunityActionRegistryTest extends TestCase
{
    private const EXPECTED_ACTION_KEYS = [
        'add_phone',
        'add_email',
        'add_website',
        'add_description',
        'add_location',
        'complete_location',
        'add_service',
        'confirm_primary_service',
        'add_gbp_url',
        'add_facebook_url',
        'add_instagram_url',
    ];

    public function test_all_eleven_business_advisor_actions_are_registered(): void
    {
        $this->assertSame(self::EXPECTED_ACTION_KEYS, array_keys(OpportunityActionRegistry::all()));

        foreach (self::EXPECTED_ACTION_KEYS as $actionKey) {
            $this->assertTrue(OpportunityActionRegistry::has($actionKey));
            $this->assertNotNull(OpportunityActionRegistry::get($actionKey));
        }
    }

    public function test_every_action_mutates_business_data(): void
    {
        foreach (OpportunityActionRegistry::all() as $actionKey => $definition) {
            $this->assertTrue($definition['mutates_business_data'], "Action [{$actionKey}] should mutate business data.");
        }
    }

    public function test_every_action_requires_approval(): void
    {
        foreach (OpportunityActionRegistry::all() as $actionKey => $definition) {
            $this->assertTrue($definition['approval_required'], "Action [{$actionKey}] should require approval.");
        }
    }

    public function test_every_action_uses_system_verified_completion_policy(): void
    {
        foreach (OpportunityActionRegistry::all() as $actionKey => $definition) {
            $this->assertSame(
                OpportunityCompletionPolicy::SystemVerified,
                $definition['completion_policy'],
                "Action [{$actionKey}] should use the system_verified completion policy in Milestone 1."
            );
        }
    }

    public function test_every_action_has_schema_version_one(): void
    {
        foreach (OpportunityActionRegistry::all() as $actionKey => $definition) {
            $this->assertSame(1, $definition['schema_version'], "Action [{$actionKey}] should be at schema_version 1.");
        }
    }

    public function test_unknown_action_keys_fail_predictably(): void
    {
        $this->assertFalse(OpportunityActionRegistry::has('delete_everything'));
        $this->assertNull(OpportunityActionRegistry::get('delete_everything'));
    }

    /**
     * Milestone 2 Phase 2A adds only `parameter_rules` — a trusted,
     * declarative array, not a class-name/callable. No validator, handler,
     * or system_verification_method key may exist yet — not even as null —
     * since those are still added only in Milestone 4.
     */
    public function test_registry_exposes_no_execution_metadata_keys(): void
    {
        $expectedKeys = ['schema_version', 'mutates_business_data', 'approval_required', 'completion_policy', 'parameter_rules'];

        foreach (OpportunityActionRegistry::all() as $actionKey => $definition) {
            $this->assertSame($expectedKeys, array_keys($definition), "Action [{$actionKey}] should expose only Milestone 1 + Phase 2A metadata keys.");
            $this->assertArrayNotHasKey('validator', $definition);
            $this->assertArrayNotHasKey('handler', $definition);
            $this->assertArrayNotHasKey('system_verification_method', $definition);
        }
    }

    /**
     * None of RFC-002's 11 business_advisor actions accept any worker-
     * supplied parameter yet — every entry's parameter_rules must be
     * exactly an empty array, so any non-empty actionParameters payload is
     * rejected by the future staging boundary.
     */
    public function test_every_action_has_an_empty_parameter_rules_array(): void
    {
        foreach (OpportunityActionRegistry::all() as $actionKey => $definition) {
            $this->assertArrayHasKey('parameter_rules', $definition, "Action [{$actionKey}] should declare parameter_rules.");
            $this->assertSame([], $definition['parameter_rules'], "Action [{$actionKey}] should accept no parameters yet.");
        }
    }

    /**
     * Every value in this registry must be a closed, source-controlled
     * scalar/enum/array — never a class name, callable, route, or command
     * string that could be resolved dynamically.
     */
    public function test_registry_contains_no_dynamically_resolvable_values(): void
    {
        foreach (OpportunityActionRegistry::all() as $actionKey => $definition) {
            $this->assertIsInt($definition['schema_version']);
            $this->assertIsBool($definition['mutates_business_data']);
            $this->assertIsBool($definition['approval_required']);
            $this->assertInstanceOf(OpportunityCompletionPolicy::class, $definition['completion_policy']);
            $this->assertFalse(is_callable($definition['completion_policy']), "Action [{$actionKey}] completion_policy must not be callable.");
            $this->assertIsArray($definition['parameter_rules']);
            $this->assertFalse(is_callable($definition['parameter_rules']), "Action [{$actionKey}] parameter_rules must not be callable.");
        }
    }
}

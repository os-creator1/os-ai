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
     * `add_phone` was intentionally made executable (RFC-002 Phase 4B.2A):
     * it alone carries the trusted, fixed scalar identifiers
     * `handler_identifier`/`verifier_identifier` that a future
     * OpportunityActionExecutor matches via a closed match() — never a
     * class name, callable, or other dynamically resolvable value. Every
     * other action still exposes only the Milestone 1 + Phase 2A metadata
     * keys. No action may ever expose a legacy callback-style key.
     */
    public function test_registry_exposes_only_the_trusted_execution_metadata_boundary(): void
    {
        $baseKeys = ['schema_version', 'mutates_business_data', 'approval_required', 'completion_policy', 'parameter_rules'];
        $addPhoneKeys = [...$baseKeys, 'handler_identifier', 'verifier_identifier'];
        $forbiddenKeys = ['validator', 'handler', 'verifier', 'callback', 'handler_class', 'verifier_class', 'system_verification_method'];

        foreach (OpportunityActionRegistry::all() as $actionKey => $definition) {
            $expectedKeys = $actionKey === 'add_phone' ? $addPhoneKeys : $baseKeys;

            $this->assertSame($expectedKeys, array_keys($definition), "Action [{$actionKey}] should expose exactly its trusted metadata keys.");

            foreach ($forbiddenKeys as $forbiddenKey) {
                $this->assertArrayNotHasKey($forbiddenKey, $definition, "Action [{$actionKey}] must not expose the legacy key [{$forbiddenKey}].");
            }
        }

        $addPhone = OpportunityActionRegistry::get('add_phone');
        $this->assertSame('business.update_phone', $addPhone['handler_identifier']);
        $this->assertSame('business.phone_matches_parameter', $addPhone['verifier_identifier']);
    }

    /**
     * `add_phone` alone accepts a customer-configured parameter (RFC-002
     * Phase 4B.2A) — its parameter_rules declares the exact trusted `value`
     * rule shape used by OpportunityManager::configureAction(). Every other
     * of RFC-002's 11 business_advisor actions still accepts no worker- or
     * customer-supplied parameter, so their parameter_rules must remain
     * exactly an empty array.
     */
    public function test_every_action_has_its_exact_trusted_parameter_rules(): void
    {
        $addPhoneParameterRules = [
            'value' => [
                'required' => true,
                'type' => 'string',
                'max_length' => 50,
                'allow_blank' => false,
            ],
        ];

        foreach (OpportunityActionRegistry::all() as $actionKey => $definition) {
            $this->assertArrayHasKey('parameter_rules', $definition, "Action [{$actionKey}] should declare parameter_rules.");

            if ($actionKey === 'add_phone') {
                $this->assertSame($addPhoneParameterRules, $definition['parameter_rules'], "Action [{$actionKey}] should declare its exact trusted parameter_rules.");
            } else {
                $this->assertSame([], $definition['parameter_rules'], "Action [{$actionKey}] should accept no parameters.");
            }
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

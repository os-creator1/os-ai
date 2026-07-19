<?php

namespace Tests\Unit\Opportunity;

use App\Enums\Opportunity\OpportunityWorkerKey;
use App\Library\Opportunity\OpportunityActionRegistry;
use App\Library\Opportunity\OpportunityTypeRegistry;
use Tests\TestCase;

class OpportunityTypeRegistryTest extends TestCase
{
    private const EXPECTED_TYPES = [
        'missing_phone',
        'missing_email',
        'missing_website',
        'missing_description',
        'missing_primary_location',
        'incomplete_primary_location',
        'missing_services',
        'missing_primary_service',
        'missing_gbp_url',
        'missing_facebook_url',
        'missing_instagram_url',
    ];

    public function test_all_eleven_business_advisor_types_are_registered(): void
    {
        $definitions = OpportunityTypeRegistry::forWorker(OpportunityWorkerKey::BusinessAdvisor->value);

        $this->assertSame(self::EXPECTED_TYPES, array_keys($definitions));

        foreach (self::EXPECTED_TYPES as $type) {
            $this->assertTrue(OpportunityTypeRegistry::has(OpportunityWorkerKey::BusinessAdvisor->value, $type));
            $this->assertNotNull(OpportunityTypeRegistry::get(OpportunityWorkerKey::BusinessAdvisor->value, $type));
        }
    }

    public function test_types_are_scoped_to_the_business_advisor_worker_only(): void
    {
        foreach (self::EXPECTED_TYPES as $type) {
            $this->assertFalse(OpportunityTypeRegistry::has(OpportunityWorkerKey::Seo->value, $type));
            $this->assertNull(OpportunityTypeRegistry::get(OpportunityWorkerKey::Seo->value, $type));
        }

        $this->assertSame([], OpportunityTypeRegistry::forWorker(OpportunityWorkerKey::Seo->value));
    }

    public function test_evidence_summary_templates_cover_every_required_fact_key(): void
    {
        foreach (OpportunityTypeRegistry::forWorker(OpportunityWorkerKey::BusinessAdvisor->value) as $type => $definition) {
            foreach ($definition['required_evidence_fact_keys'] as $factKey) {
                $this->assertArrayHasKey(
                    $factKey,
                    $definition['evidence_summary_templates'],
                    "Type [{$type}] is missing an evidence_summary_template for required fact key [{$factKey}]."
                );
            }
        }
    }

    public function test_required_evidence_fact_keys_are_a_subset_of_allowed_fact_keys(): void
    {
        foreach (OpportunityTypeRegistry::forWorker(OpportunityWorkerKey::BusinessAdvisor->value) as $type => $definition) {
            foreach ($definition['required_evidence_fact_keys'] as $factKey) {
                $this->assertContains(
                    $factKey,
                    $definition['allowed_evidence_fact_keys'],
                    "Type [{$type}]'s required fact key [{$factKey}] must also be an allowed fact key."
                );
            }
        }
    }

    public function test_every_type_references_a_registered_action_key(): void
    {
        foreach (OpportunityTypeRegistry::forWorker(OpportunityWorkerKey::BusinessAdvisor->value) as $type => $definition) {
            $this->assertTrue(
                OpportunityActionRegistry::has($definition['action_key']),
                "Type [{$type}]'s action_key [{$definition['action_key']}] must exist in the action registry."
            );
        }
    }

    /**
     * Every business_advisor type fires at most once per Business (RFC-002
     * §13.2), so context_validator is always null in this registry.
     */
    public function test_context_validator_is_null_for_every_type(): void
    {
        foreach (OpportunityTypeRegistry::forWorker(OpportunityWorkerKey::BusinessAdvisor->value) as $type => $definition) {
            $this->assertNull($definition['context_validator'], "Type [{$type}] should have a null context_validator.");
        }
    }

    public function test_every_type_only_allows_the_business_profile_evidence_source(): void
    {
        foreach (OpportunityTypeRegistry::forWorker(OpportunityWorkerKey::BusinessAdvisor->value) as $type => $definition) {
            $this->assertSame(['business_profile'], $definition['allowed_evidence_source_types'], "Type [{$type}] should only allow the business_profile evidence source.");
        }
    }

    public function test_unknown_type_fails_predictably(): void
    {
        $this->assertFalse(OpportunityTypeRegistry::has(OpportunityWorkerKey::BusinessAdvisor->value, 'not_a_real_type'));
        $this->assertNull(OpportunityTypeRegistry::get(OpportunityWorkerKey::BusinessAdvisor->value, 'not_a_real_type'));
    }
}

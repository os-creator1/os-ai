<?php

namespace Tests\Unit\Opportunity;

use App\Library\Opportunity\Exceptions\OpportunityEvidenceValidationException;
use App\Library\Opportunity\OpportunityCandidateData;
use App\Library\Opportunity\OpportunityEvidenceFactData;
use ReflectionClass;
use Tests\TestCase;
use Throwable;
use TypeError;

class OpportunityCandidateDataTest extends TestCase
{
    /**
     * @return array<int, array<string, mixed>>
     */
    private function validRawEvidence(): array
    {
        return [[
            'source_type' => 'business_profile',
            'source_identifier' => 'business:1:phone',
            'fact_key' => 'phone_blank',
            'observed_value' => null,
            'retrieved_at' => '2026-01-01T00:00:00+00:00',
        ]];
    }

    /**
     * @return array<string, mixed>
     */
    private function validRawCandidate(array $overrides = []): array
    {
        return array_merge([
            'type' => 'missing_phone',
            'context' => null,
            'impact' => 3,
            'urgency' => 3,
            'effort' => 1,
            'confidence' => 0.9,
            'relevantGoalKeys' => [],
            'evidence' => $this->validRawEvidence(),
            'actionParameters' => null,
        ], $overrides);
    }

    public function test_from_array_hydrates_known_fields(): void
    {
        $data = OpportunityCandidateData::fromArray($this->validRawCandidate());

        $this->assertSame('missing_phone', $data->type);
        $this->assertNull($data->context);
        $this->assertSame(3, $data->impact);
        $this->assertSame(3, $data->urgency);
        $this->assertSame(1, $data->effort);
        $this->assertSame(0.9, $data->confidence);
        $this->assertSame([], $data->relevantGoalKeys);
        $this->assertCount(1, $data->evidence);
        $this->assertInstanceOf(OpportunityEvidenceFactData::class, $data->evidence[0]);
        $this->assertNull($data->actionParameters);
    }

    public function test_from_array_discards_unknown_top_level_keys_including_an_injected_priority_score(): void
    {
        // Construction must succeed despite the extra junk keys — they are
        // simply discarded, never applied, never surfaced anywhere.
        $data = OpportunityCandidateData::fromArray($this->validRawCandidate([
            'priority_score' => 100,
            'title' => 'Attacker title',
            'fingerprint' => 'deadbeef',
            'status' => 'completed',
            'handler' => 'App\\Malicious\\Handler',
        ]));

        $this->assertSame('missing_phone', $data->type);
    }

    public function test_structurally_carries_no_registry_or_manager_owned_properties(): void
    {
        $reflection = new ReflectionClass(OpportunityCandidateData::class);
        $propertyNames = array_map(fn ($p) => $p->getName(), $reflection->getProperties());

        foreach ([
            'title', 'summary', 'actionKey', 'fingerprint', 'fingerprintVersion',
            'priorityScore', 'status', 'freshness', 'occurrenceNumber',
            'actionSchemaVersion', 'handler', 'validator', 'callback', 'class',
        ] as $forbidden) {
            $this->assertNotContains($forbidden, $propertyNames, "OpportunityCandidateData must not have a [{$forbidden}] property.");
        }
    }

    public function test_a_numeric_string_impact_is_not_silently_coerced(): void
    {
        $this->expectException(TypeError::class);

        OpportunityCandidateData::fromArray($this->validRawCandidate(['impact' => '3']));
    }

    public function test_a_numeric_string_confidence_is_not_silently_coerced(): void
    {
        $this->expectException(TypeError::class);

        OpportunityCandidateData::fromArray($this->validRawCandidate(['confidence' => '0.9']));
    }

    public function test_a_missing_required_key_fails_predictably(): void
    {
        $raw = $this->validRawCandidate();
        unset($raw['impact']);

        // Accessing an absent array key yields null before the constructor's
        // type check runs; either way this must fail, not silently proceed
        // with a wrong value.
        $this->expectException(Throwable::class);

        OpportunityCandidateData::fromArray($raw);
    }

    public function test_evidence_fact_data_structurally_carries_no_summary_property(): void
    {
        $reflection = new ReflectionClass(OpportunityEvidenceFactData::class);
        $propertyNames = array_map(fn ($p) => $p->getName(), $reflection->getProperties());

        $this->assertNotContains('summary', $propertyNames);
    }

    public function test_evidence_fact_data_rejects_a_raw_summary_key_explicitly(): void
    {
        $raw = $this->validRawEvidence()[0];
        $raw['summary'] = 'Attacker-supplied summary text.';

        $this->expectException(OpportunityEvidenceValidationException::class);

        OpportunityEvidenceFactData::fromArray($raw);
    }

    public function test_evidence_fact_data_rejects_executable_metadata_keys(): void
    {
        foreach (['class', 'handler', 'validator', 'callback', 'command'] as $forbiddenKey) {
            $raw = $this->validRawEvidence()[0];
            $raw[$forbiddenKey] = 'App\\Some\\Class';

            try {
                OpportunityEvidenceFactData::fromArray($raw);
                $this->fail("Expected rejection for forbidden key [{$forbiddenKey}].");
            } catch (OpportunityEvidenceValidationException $e) {
                $this->assertStringContainsString($forbiddenKey, $e->getMessage());
            }
        }
    }

    public function test_evidence_fact_data_discards_other_unknown_keys(): void
    {
        $raw = $this->validRawEvidence()[0];
        $raw['unexpected_extra_key'] = 'should be discarded';

        $item = OpportunityEvidenceFactData::fromArray($raw);

        $this->assertSame('phone_blank', $item->factKey);
    }
}

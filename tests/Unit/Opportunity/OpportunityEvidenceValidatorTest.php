<?php

namespace Tests\Unit\Opportunity;

use App\Library\Opportunity\Exceptions\OpportunityEvidenceValidationException;
use App\Library\Opportunity\OpportunityEvidenceFactData;
use App\Library\Opportunity\OpportunityEvidenceValidator;
use Carbon\CarbonImmutable;
use stdClass;
use Tests\TestCase;

class OpportunityEvidenceValidatorTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function typeDefinition(array $overrides = []): array
    {
        return array_merge([
            'allowed_evidence_source_types' => ['business_profile'],
            'allowed_evidence_fact_keys' => ['phone_blank'],
            'required_evidence_fact_keys' => ['phone_blank'],
            'evidence_summary_templates' => [
                'phone_blank' => 'The business phone number is not set.',
            ],
        ], $overrides);
    }

    private function validEvidenceItem(array $overrides = []): OpportunityEvidenceFactData
    {
        return OpportunityEvidenceFactData::fromArray(array_merge([
            'source_type' => 'business_profile',
            'source_identifier' => 'business:1:phone',
            'fact_key' => 'phone_blank',
            'observed_value' => null,
            'retrieved_at' => '2026-01-01T00:00:00+00:00',
        ], $overrides));
    }

    private function scoredAt(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-01-02T00:00:00+00:00');
    }

    public function test_valid_evidence_is_rendered_with_the_registry_summary(): void
    {
        $validator = new OpportunityEvidenceValidator();

        $result = $validator->validate([$this->validEvidenceItem()], $this->typeDefinition(), $this->scoredAt());

        $this->assertCount(1, $result);
        $this->assertSame('The business phone number is not set.', $result[0]['summary']);
        $this->assertSame('phone_blank', $result[0]['fact_key']);
        $this->assertSame('business_profile', $result[0]['source_type']);
    }

    public function test_valid_absolute_source_url_is_accepted(): void
    {
        $validator = new OpportunityEvidenceValidator();
        $item = $this->validEvidenceItem(['source_url' => 'https://example.com/path']);

        $result = $validator->validate([$item], $this->typeDefinition(), $this->scoredAt());

        $this->assertSame('https://example.com/path', $result[0]['source_url']);
    }

    public function test_empty_evidence_is_rejected(): void
    {
        $validator = new OpportunityEvidenceValidator();

        $this->expectException(OpportunityEvidenceValidationException::class);

        $validator->validate([], $this->typeDefinition(), $this->scoredAt());
    }

    public function test_more_than_twenty_items_is_rejected(): void
    {
        $validator = new OpportunityEvidenceValidator();
        $items = array_fill(0, 21, $this->validEvidenceItem());

        $this->expectException(OpportunityEvidenceValidationException::class);

        $validator->validate($items, $this->typeDefinition(), $this->scoredAt());
    }

    public function test_non_dto_item_is_rejected(): void
    {
        $validator = new OpportunityEvidenceValidator();

        $this->expectException(OpportunityEvidenceValidationException::class);

        $validator->validate(['not-a-dto'], $this->typeDefinition(), $this->scoredAt());
    }

    public function test_disallowed_source_type_is_rejected(): void
    {
        $validator = new OpportunityEvidenceValidator();
        $item = $this->validEvidenceItem(['source_type' => 'web_crawl']);

        $this->expectException(OpportunityEvidenceValidationException::class);

        $validator->validate([$item], $this->typeDefinition(), $this->scoredAt());
    }

    public function test_disallowed_fact_key_is_rejected(): void
    {
        $validator = new OpportunityEvidenceValidator();
        $item = $this->validEvidenceItem(['fact_key' => 'unknown_fact']);

        $this->expectException(OpportunityEvidenceValidationException::class);

        $validator->validate([$item], $this->typeDefinition(), $this->scoredAt());
    }

    public function test_missing_required_fact_key_is_rejected(): void
    {
        $validator = new OpportunityEvidenceValidator();
        $typeDefinition = $this->typeDefinition([
            'allowed_evidence_fact_keys' => ['phone_blank', 'email_blank'],
            'required_evidence_fact_keys' => ['phone_blank', 'email_blank'],
            'evidence_summary_templates' => [
                'phone_blank' => 'The business phone number is not set.',
                'email_blank' => 'The business email address is not set.',
            ],
        ]);

        $this->expectException(OpportunityEvidenceValidationException::class);

        $validator->validate([$this->validEvidenceItem()], $typeDefinition, $this->scoredAt());
    }

    public function test_fact_key_with_no_registered_summary_template_is_rejected(): void
    {
        $validator = new OpportunityEvidenceValidator();
        $typeDefinition = $this->typeDefinition([
            'allowed_evidence_fact_keys' => ['phone_blank', 'other_fact'],
            'required_evidence_fact_keys' => [],
            'evidence_summary_templates' => ['phone_blank' => 'The business phone number is not set.'],
        ]);
        $item = $this->validEvidenceItem(['fact_key' => 'other_fact']);

        $this->expectException(OpportunityEvidenceValidationException::class);

        $validator->validate([$item], $typeDefinition, $this->scoredAt());
    }

    public function test_future_retrieved_at_is_rejected(): void
    {
        $validator = new OpportunityEvidenceValidator();
        $item = $this->validEvidenceItem(['retrieved_at' => now()->addDay()->toIso8601String()]);

        $this->expectException(OpportunityEvidenceValidationException::class);

        $validator->validate([$item], $this->typeDefinition(), CarbonImmutable::now()->addDays(2));
    }

    public function test_retrieved_at_after_scored_at_is_rejected(): void
    {
        $validator = new OpportunityEvidenceValidator();
        $item = $this->validEvidenceItem(['retrieved_at' => '2026-01-05T00:00:00+00:00']);

        $this->expectException(OpportunityEvidenceValidationException::class);

        $validator->validate([$item], $this->typeDefinition(), CarbonImmutable::parse('2026-01-01T00:00:00+00:00'));
    }

    public function test_observed_at_after_retrieved_at_is_rejected(): void
    {
        $validator = new OpportunityEvidenceValidator();
        $item = $this->validEvidenceItem([
            'retrieved_at' => '2026-01-01T00:00:00+00:00',
            'observed_at' => '2026-01-02T00:00:00+00:00',
        ]);

        $this->expectException(OpportunityEvidenceValidationException::class);

        $validator->validate([$item], $this->typeDefinition(), CarbonImmutable::parse('2026-01-03T00:00:00+00:00'));
    }

    public function test_expires_at_less_than_or_equal_to_retrieved_at_is_rejected(): void
    {
        $validator = new OpportunityEvidenceValidator();
        $item = $this->validEvidenceItem([
            'retrieved_at' => '2026-01-01T00:00:00+00:00',
            'expires_at' => '2026-01-01T00:00:00+00:00',
        ]);

        $this->expectException(OpportunityEvidenceValidationException::class);

        $validator->validate([$item], $this->typeDefinition(), CarbonImmutable::parse('2026-01-01T01:00:00+00:00'));
    }

    public function test_oversized_source_identifier_is_rejected(): void
    {
        $validator = new OpportunityEvidenceValidator();
        $item = $this->validEvidenceItem(['source_identifier' => str_repeat('a', 192)]);

        $this->expectException(OpportunityEvidenceValidationException::class);

        $validator->validate([$item], $this->typeDefinition(), $this->scoredAt());
    }

    public function test_oversized_fact_key_is_rejected(): void
    {
        $validator = new OpportunityEvidenceValidator();
        $longKey = str_repeat('a', 101);
        $item = $this->validEvidenceItem(['fact_key' => $longKey]);
        $typeDefinition = $this->typeDefinition([
            'allowed_evidence_fact_keys' => [$longKey],
            'required_evidence_fact_keys' => [],
            'evidence_summary_templates' => [$longKey => 'x'],
        ]);

        $this->expectException(OpportunityEvidenceValidationException::class);

        $validator->validate([$item], $typeDefinition, $this->scoredAt());
    }

    public function test_oversized_observed_value_is_rejected(): void
    {
        $validator = new OpportunityEvidenceValidator();
        $item = $this->validEvidenceItem(['observed_value' => str_repeat('a', 4200)]);

        $this->expectException(OpportunityEvidenceValidationException::class);

        $validator->validate([$item], $this->typeDefinition(), $this->scoredAt());
    }

    public function test_total_evidence_canonical_json_exceeding_the_bound_is_rejected(): void
    {
        $validator = new OpportunityEvidenceValidator();
        $largeValue = str_repeat('a', 1700);
        $items = array_fill(0, 20, $this->validEvidenceItem(['observed_value' => $largeValue]));
        $typeDefinition = $this->typeDefinition(['required_evidence_fact_keys' => []]);

        $this->expectException(OpportunityEvidenceValidationException::class);

        $validator->validate($items, $typeDefinition, $this->scoredAt());
    }

    public function test_malformed_content_hash_is_rejected(): void
    {
        $validator = new OpportunityEvidenceValidator();
        $item = $this->validEvidenceItem(['content_hash' => 'not-a-valid-hash']);

        $this->expectException(OpportunityEvidenceValidationException::class);

        $validator->validate([$item], $this->typeDefinition(), $this->scoredAt());
    }

    public function test_uppercase_content_hash_is_rejected(): void
    {
        $validator = new OpportunityEvidenceValidator();
        $item = $this->validEvidenceItem(['content_hash' => str_repeat('A', 64)]);

        $this->expectException(OpportunityEvidenceValidationException::class);

        $validator->validate([$item], $this->typeDefinition(), $this->scoredAt());
    }

    public function test_valid_content_hash_is_accepted(): void
    {
        $validator = new OpportunityEvidenceValidator();
        $hash = str_repeat('a', 64);
        $item = $this->validEvidenceItem(['content_hash' => $hash]);

        $result = $validator->validate([$item], $this->typeDefinition(), $this->scoredAt());

        $this->assertSame($hash, $result[0]['content_hash']);
    }

    public function test_non_finite_observed_value_is_rejected(): void
    {
        $validator = new OpportunityEvidenceValidator();
        $item = $this->validEvidenceItem(['observed_value' => NAN]);

        $this->expectException(OpportunityEvidenceValidationException::class);

        $validator->validate([$item], $this->typeDefinition(), $this->scoredAt());
    }

    public function test_object_observed_value_is_rejected(): void
    {
        $validator = new OpportunityEvidenceValidator();
        $item = $this->validEvidenceItem(['observed_value' => new stdClass()]);

        $this->expectException(OpportunityEvidenceValidationException::class);

        $validator->validate([$item], $this->typeDefinition(), $this->scoredAt());
    }

    public function test_nested_unsafe_observed_value_is_rejected(): void
    {
        $validator = new OpportunityEvidenceValidator();
        $item = $this->validEvidenceItem(['observed_value' => ['inner' => new stdClass()]]);

        $this->expectException(OpportunityEvidenceValidationException::class);

        $validator->validate([$item], $this->typeDefinition(), $this->scoredAt());
    }

    public function test_control_character_content_is_rejected(): void
    {
        $validator = new OpportunityEvidenceValidator();
        $item = $this->validEvidenceItem(['source_identifier' => "business:1:phone\x01"]);

        $this->expectException(OpportunityEvidenceValidationException::class);

        $validator->validate([$item], $this->typeDefinition(), $this->scoredAt());
    }

    public function test_html_content_is_rejected(): void
    {
        $validator = new OpportunityEvidenceValidator();
        $item = $this->validEvidenceItem(['source_identifier' => '<script>alert(1)</script>']);

        $this->expectException(OpportunityEvidenceValidationException::class);

        $validator->validate([$item], $this->typeDefinition(), $this->scoredAt());
    }

    public function test_serialized_php_signature_is_rejected(): void
    {
        $validator = new OpportunityEvidenceValidator();
        $item = $this->validEvidenceItem(['source_identifier' => 'O:8:"stdClass":0:{}']);

        $this->expectException(OpportunityEvidenceValidationException::class);

        $validator->validate([$item], $this->typeDefinition(), $this->scoredAt());
    }

    public function test_source_url_must_be_absolute_http_or_https(): void
    {
        $validator = new OpportunityEvidenceValidator();
        $item = $this->validEvidenceItem(['source_url' => 'ftp://example.com/file']);

        $this->expectException(OpportunityEvidenceValidationException::class);

        $validator->validate([$item], $this->typeDefinition(), $this->scoredAt());
    }

    public function test_source_url_rejects_a_relative_path(): void
    {
        $validator = new OpportunityEvidenceValidator();
        $item = $this->validEvidenceItem(['source_url' => '/relative/path']);

        $this->expectException(OpportunityEvidenceValidationException::class);

        $validator->validate([$item], $this->typeDefinition(), $this->scoredAt());
    }

    public function test_source_url_rejects_embedded_credentials(): void
    {
        $validator = new OpportunityEvidenceValidator();
        $item = $this->validEvidenceItem(['source_url' => 'https://user:pass@example.com/']);

        $this->expectException(OpportunityEvidenceValidationException::class);

        $validator->validate([$item], $this->typeDefinition(), $this->scoredAt());
    }

    public function test_source_url_enforces_length_bound(): void
    {
        $validator = new OpportunityEvidenceValidator();
        $item = $this->validEvidenceItem(['source_url' => 'https://example.com/' . str_repeat('a', 2048)]);

        $this->expectException(OpportunityEvidenceValidationException::class);

        $validator->validate([$item], $this->typeDefinition(), $this->scoredAt());
    }
}

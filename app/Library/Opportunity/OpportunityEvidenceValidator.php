<?php

declare(strict_types=1);

namespace App\Library\Opportunity;

use App\Library\Opportunity\Exceptions\OpportunityEvidenceValidationException;
use Carbon\CarbonInterface;

/**
 * Validates a candidate's hydrated evidence items against RFC-002 §27's
 * bounds and the resolved OpportunityTypeRegistry type definition, and
 * renders each item's persisted `summary` exclusively from that type's own
 * `evidence_summary_templates[$factKey]` — a worker value can never
 * override it (the DTO layer already rejects a raw `summary` key outright;
 * this class never even has one to consider). Rejects, never truncates or
 * clamps.
 */
final class OpportunityEvidenceValidator
{
    private const MAX_EVIDENCE_ITEMS = 20;
    private const MAX_SOURCE_TYPE_LENGTH = 50;
    private const MAX_SOURCE_IDENTIFIER_LENGTH = 191;
    private const MAX_FACT_KEY_LENGTH = 100;
    private const MAX_SUMMARY_LENGTH = 500;
    private const MAX_SOURCE_URL_LENGTH = 2048;
    private const MAX_OBSERVED_VALUE_CANONICAL_BYTES = 4096;
    private const MAX_TOTAL_EVIDENCE_CANONICAL_BYTES = 32768;
    private const CONTENT_HASH_PATTERN = '/^[a-f0-9]{64}$/';
    private const SERIALIZED_PHP_SIGNATURE_PATTERN = '/^(O:\d+:|a:\d+:|s:\d+:|<\?php|<script)/i';
    private const ALLOWED_URL_SCHEMES = ['http', 'https'];

    /**
     * @param  array<int, OpportunityEvidenceFactData>  $evidenceFacts
     * @param  array<string, mixed>  $typeDefinition  A resolved OpportunityTypeRegistry entry.
     * @return array<int, array<string, mixed>> Persisted-shape evidence, each item's summary registry-rendered.
     */
    public function validate(array $evidenceFacts, array $typeDefinition, CarbonInterface $scoredAt): array
    {
        if ($evidenceFacts === []) {
            throw new OpportunityEvidenceValidationException('Evidence must contain at least one item.');
        }

        if (count($evidenceFacts) > self::MAX_EVIDENCE_ITEMS) {
            throw new OpportunityEvidenceValidationException(
                'Evidence may not contain more than ' . self::MAX_EVIDENCE_ITEMS . ' items.'
            );
        }

        $allowedSourceTypes = $typeDefinition['allowed_evidence_source_types'] ?? [];
        $allowedFactKeys = $typeDefinition['allowed_evidence_fact_keys'] ?? [];
        $requiredFactKeys = $typeDefinition['required_evidence_fact_keys'] ?? [];
        $summaryTemplates = $typeDefinition['evidence_summary_templates'] ?? [];

        $rendered = [];
        $seenFactKeys = [];

        foreach ($evidenceFacts as $item) {
            if (! $item instanceof OpportunityEvidenceFactData) {
                throw new OpportunityEvidenceValidationException('Each evidence item must be an OpportunityEvidenceFactData instance.');
            }

            $rendered[] = $this->validateItem($item, $allowedSourceTypes, $allowedFactKeys, $summaryTemplates, $scoredAt);
            $seenFactKeys[] = $item->factKey;
        }

        foreach ($requiredFactKeys as $requiredFactKey) {
            if (! in_array($requiredFactKey, $seenFactKeys, true)) {
                throw new OpportunityEvidenceValidationException("Required evidence fact key [{$requiredFactKey}] is missing.");
            }
        }

        $totalBytes = strlen(CanonicalJson::encode($rendered));

        if ($totalBytes > self::MAX_TOTAL_EVIDENCE_CANONICAL_BYTES) {
            throw new OpportunityEvidenceValidationException(
                'Total evidence canonical JSON size exceeds ' . self::MAX_TOTAL_EVIDENCE_CANONICAL_BYTES . ' bytes.'
            );
        }

        return $rendered;
    }

    /**
     * @return array<string, mixed>
     */
    private function validateItem(
        OpportunityEvidenceFactData $item,
        array $allowedSourceTypes,
        array $allowedFactKeys,
        array $summaryTemplates,
        CarbonInterface $scoredAt,
    ): array {
        if (! in_array($item->sourceType, $allowedSourceTypes, true)) {
            throw new OpportunityEvidenceValidationException("Evidence source_type [{$item->sourceType}] is not allowed for this type.");
        }

        if (! in_array($item->factKey, $allowedFactKeys, true)) {
            throw new OpportunityEvidenceValidationException("Evidence fact_key [{$item->factKey}] is not allowed for this type.");
        }

        if (! array_key_exists($item->factKey, $summaryTemplates)) {
            throw new OpportunityEvidenceValidationException("Evidence fact_key [{$item->factKey}] has no registered evidence summary template.");
        }

        $this->assertLength('source_type', $item->sourceType, self::MAX_SOURCE_TYPE_LENGTH);
        $this->assertLength('source_identifier', $item->sourceIdentifier, self::MAX_SOURCE_IDENTIFIER_LENGTH);
        $this->assertLength('fact_key', $item->factKey, self::MAX_FACT_KEY_LENGTH);

        $this->assertNoUnsafeContent('source_type', $item->sourceType);
        $this->assertNoUnsafeContent('source_identifier', $item->sourceIdentifier);
        $this->assertNoUnsafeContent('fact_key', $item->factKey);

        if ($item->contentHash !== null && preg_match(self::CONTENT_HASH_PATTERN, $item->contentHash) !== 1) {
            throw new OpportunityEvidenceValidationException('Evidence content_hash must be exactly 64 lowercase hexadecimal characters.');
        }

        if ($item->sourceUrl !== null) {
            $this->assertValidSourceUrl($item->sourceUrl);
        }

        $this->assertObservedValueIsSafe($item->observedValue);

        $observedValueBytes = strlen(CanonicalJson::encode($item->observedValue));
        if ($observedValueBytes > self::MAX_OBSERVED_VALUE_CANONICAL_BYTES) {
            throw new OpportunityEvidenceValidationException(
                'Evidence observed_value canonical JSON size exceeds ' . self::MAX_OBSERVED_VALUE_CANONICAL_BYTES . ' bytes.'
            );
        }

        $this->assertTimestamps($item, $scoredAt);

        $summary = $summaryTemplates[$item->factKey];
        $this->assertLength('summary', $summary, self::MAX_SUMMARY_LENGTH);

        return [
            'source_type' => $item->sourceType,
            'source_identifier' => $item->sourceIdentifier,
            'fact_key' => $item->factKey,
            'observed_value' => $item->observedValue,
            'summary' => $summary,
            'retrieved_at' => $item->retrievedAt->toIso8601String(),
            'observed_at' => $item->observedAt?->toIso8601String(),
            'expires_at' => $item->expiresAt?->toIso8601String(),
            'source_url' => $item->sourceUrl,
            'content_hash' => $item->contentHash,
        ];
    }

    private function assertLength(string $field, string $value, int $maxLength): void
    {
        if (mb_strlen($value) > $maxLength) {
            throw new OpportunityEvidenceValidationException("Evidence [{$field}] exceeds the maximum length of {$maxLength} characters.");
        }
    }

    private function assertNoUnsafeContent(string $field, string $value): void
    {
        if ($value !== strip_tags($value)) {
            throw new OpportunityEvidenceValidationException("Evidence [{$field}] may not contain HTML or script content.");
        }

        if (preg_match(self::SERIALIZED_PHP_SIGNATURE_PATTERN, $value) === 1) {
            throw new OpportunityEvidenceValidationException("Evidence [{$field}] may not contain a serialized PHP payload.");
        }

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value) === 1) {
            throw new OpportunityEvidenceValidationException("Evidence [{$field}] may not contain binary or control-character content.");
        }
    }

    private function assertValidSourceUrl(string $url): void
    {
        $this->assertLength('source_url', $url, self::MAX_SOURCE_URL_LENGTH);
        $this->assertNoUnsafeContent('source_url', $url);

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new OpportunityEvidenceValidationException('Evidence source_url must be an absolute URL.');
        }

        if (! in_array(strtolower($parts['scheme']), self::ALLOWED_URL_SCHEMES, true)) {
            throw new OpportunityEvidenceValidationException('Evidence source_url must use the http or https scheme.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new OpportunityEvidenceValidationException('Evidence source_url may not contain embedded credentials.');
        }
    }

    private function assertObservedValueIsSafe(mixed $value): void
    {
        match (true) {
            $value === null, is_bool($value), is_int($value) => null,
            is_float($value) => $this->assertFiniteFloat($value),
            is_string($value) => $this->assertNoUnsafeContent('observed_value', $value),
            is_array($value) => $this->assertObservedValueArraySafe($value),
            default => throw new OpportunityEvidenceValidationException(
                'Evidence observed_value contains an unsupported type [' . get_debug_type($value) . '].'
            ),
        };
    }

    private function assertFiniteFloat(float $value): void
    {
        if (! is_finite($value)) {
            throw new OpportunityEvidenceValidationException('Evidence observed_value contains a non-finite decimal.');
        }
    }

    private function assertObservedValueArraySafe(array $value): void
    {
        foreach ($value as $item) {
            $this->assertObservedValueIsSafe($item);
        }
    }

    private function assertTimestamps(OpportunityEvidenceFactData $item, CarbonInterface $scoredAt): void
    {
        if ($item->retrievedAt->isFuture()) {
            throw new OpportunityEvidenceValidationException('Evidence retrieved_at may not be in the future.');
        }

        if ($item->retrievedAt->greaterThan($scoredAt)) {
            throw new OpportunityEvidenceValidationException('Evidence retrieved_at may not be after scored_at.');
        }

        if ($item->observedAt !== null && $item->observedAt->greaterThan($item->retrievedAt)) {
            throw new OpportunityEvidenceValidationException('Evidence observed_at may not be after retrieved_at.');
        }

        if ($item->expiresAt !== null && $item->expiresAt->lessThanOrEqualTo($item->retrievedAt)) {
            throw new OpportunityEvidenceValidationException('Evidence expires_at must be after retrieved_at.');
        }
    }
}

<?php

namespace App\Library\NicheBlueprint\Adapters;

use App\Enums\NicheBlueprint\BlueprintUpdatePolicy;
use InvalidArgumentException;

/**
 * Blueprint V2 — the niche SEO STRATEGY: keyword-intent patterns, FAQ topics,
 * schema strategy and internal-link defaults. LIVE: nothing is copied, the
 * SEO module reads the latest published strategy through
 * `BlueprintConfigReader::seoStrategy()`, so an improved niche strategy reaches
 * every Business of the niche without an install step.
 *
 * It creates no `seo_keywords` rows (those are tracked, billed objects) —
 * patterns like "{service} {city}" are guidance the SEO surface turns into
 * suggestions the owner accepts.
 */
final class SeoStrategyComponentAdapter extends ConfigOnlyBlueprintComponentAdapter
{
    public const TYPE = 'seo_strategy';

    public const INTENTS = ['informational', 'commercial', 'transactional', 'local'];

    public function componentType(): string
    {
        return self::TYPE;
    }

    public function validateDescriptor(array $payload): void
    {
        $this->parse($payload);
    }

    /** @return array<string, mixed> */
    private function parse(array $payload): array
    {
        $patterns = $payload['keyword_patterns'] ?? [];

        if (! is_array($patterns) || ! array_is_list($patterns)) {
            throw new InvalidArgumentException('"keyword_patterns" must be a list.');
        }

        $cleanPatterns = [];

        foreach ($patterns as $p) {
            $intent = is_array($p) ? (string) ($p['intent'] ?? '') : '';

            if (! in_array($intent, self::INTENTS, true)) {
                throw new InvalidArgumentException('Every keyword pattern needs an intent: '.implode(', ', self::INTENTS).'.');
            }

            $cleanPatterns[] = ['pattern' => $this->requireString($p, 'pattern', 120), 'intent' => $intent];
        }

        $links = $payload['internal_links'] ?? [];

        if (! is_array($links) || ! array_is_list($links)) {
            throw new InvalidArgumentException('"internal_links" must be a list.');
        }

        $cleanLinks = [];

        foreach ($links as $l) {
            $cleanLinks[] = [
                'from' => $this->requireString((array) $l, 'from', 60),
                'to' => $this->requireString((array) $l, 'to', 60),
                'anchor' => $this->requireString((array) $l, 'anchor', 120),
            ];
        }

        $out = [
            'keyword_patterns' => $cleanPatterns,
            'faq_topics' => $this->stringList($payload, 'faq_topics', 50, 200, false),
            'schema_types' => $this->stringList($payload, 'schema_types', 20, 60, false),
            'schema_notes' => $this->optionalString($payload, 'schema_notes', 1000),
            'internal_links' => $cleanLinks,
        ];

        if ($cleanPatterns === [] && $out['faq_topics'] === [] && $out['schema_types'] === [] && $cleanLinks === []) {
            throw new InvalidArgumentException('An SEO strategy needs at least one keyword pattern, FAQ topic, schema type or internal link.');
        }

        return $out;
    }

    public function surface(): string
    {
        return 'seo';
    }

    public function updatePolicy(): BlueprintUpdatePolicy
    {
        return BlueprintUpdatePolicy::Live;
    }

    public function featureKey(): string
    {
        return 'seo_module';
    }

    public function typeLabel(): string
    {
        return 'SEO strategy';
    }

    public function summary(array $payload): string
    {
        return count($payload['keyword_patterns'] ?? []).' keyword patterns, '.count($payload['faq_topics'] ?? []).' FAQ topics, '
            .count($payload['schema_types'] ?? []).' schema types';
    }

    public function formFields(): array
    {
        return [
            ['name' => 'keyword_patterns', 'label' => 'Keyword-intent patterns', 'type' => 'lines', 'required' => false,
                'help' => 'One per line: pattern | intent ('.implode(', ', self::INTENTS).'). Example: photo booth rental {city} | transactional'],
            ['name' => 'faq_topics', 'label' => 'FAQ topics', 'type' => 'lines', 'required' => false, 'help' => 'One topic per line.'],
            ['name' => 'schema_types', 'label' => 'Schema types', 'type' => 'lines', 'required' => false, 'help' => 'e.g. LocalBusiness, FAQPage, Service'],
            ['name' => 'schema_notes', 'label' => 'Schema strategy notes', 'type' => 'textarea', 'required' => false],
            ['name' => 'internal_links', 'label' => 'Internal-link defaults', 'type' => 'lines', 'required' => false, 'help' => 'One per line: from page | to page | anchor text'],
        ];
    }

    public function payloadFromInput(array $input): array
    {
        $patterns = [];

        foreach ($this->linesOf($input['keyword_patterns'] ?? '') as $line) {
            [$pattern, $intent] = $this->pipeParts($line, 2);
            $patterns[] = ['pattern' => (string) $pattern, 'intent' => strtolower((string) $intent)];
        }

        $links = [];

        foreach ($this->linesOf($input['internal_links'] ?? '') as $line) {
            [$from, $to, $anchor] = $this->pipeParts($line, 3);
            $links[] = ['from' => (string) $from, 'to' => (string) $to, 'anchor' => (string) $anchor];
        }

        $payload = [
            'keyword_patterns' => $patterns,
            'faq_topics' => $this->linesOf($input['faq_topics'] ?? ''),
            'schema_types' => $this->linesOf($input['schema_types'] ?? ''),
            'internal_links' => $links,
        ];

        if (trim((string) ($input['schema_notes'] ?? '')) !== '') {
            $payload['schema_notes'] = trim((string) $input['schema_notes']);
        }

        $this->parse($payload);

        return $payload;
    }

    public function inputFromPayload(array $payload): array
    {
        return [
            'keyword_patterns' => implode("\n", array_map(fn ($p) => $p['pattern'].' | '.$p['intent'], $payload['keyword_patterns'] ?? [])),
            'faq_topics' => implode("\n", $payload['faq_topics'] ?? []),
            'schema_types' => implode("\n", $payload['schema_types'] ?? []),
            'schema_notes' => $payload['schema_notes'] ?? '',
            'internal_links' => implode("\n", array_map(fn ($l) => $l['from'].' | '.$l['to'].' | '.$l['anchor'], $payload['internal_links'] ?? [])),
        ];
    }
}

<?php

namespace App\Library\NicheBlueprint\Adapters;

use App\Enums\NicheBlueprint\BlueprintUpdatePolicy;
use App\Enums\Seo\ArticleIntent;
use App\Library\Seo\Content\ArticleTopicSignature;
use InvalidArgumentException;

/**
 * Blueprint V2 — the niche SEO STRATEGY: keyword-intent patterns, FAQ topics,
 * article (content) topics, schema strategy and internal-link defaults. LIVE: nothing is copied, the
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

    /** SEO Content Engine — how a content topic expands: once, per top service page, or per served city. */
    public const CONTENT_TOPIC_SCOPES = ['none', 'service', 'city'];

    public const CONTENT_TOPIC_PLACEHOLDERS = ['service', 'city', 'business'];

    public const MAX_CONTENT_TOPICS = 40;

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
            'content_topics' => $this->parseContentTopics($payload['content_topics'] ?? []),
        ];

        if ($cleanPatterns === [] && $out['faq_topics'] === [] && $out['schema_types'] === [] && $cleanLinks === [] && $out['content_topics'] === []) {
            throw new InvalidArgumentException('An SEO strategy needs at least one keyword pattern, FAQ topic, content topic, schema type or internal link.');
        }

        return $out;
    }

    /**
     * SEO Content Engine V1 — the niche's article topics, validated (a publish or Workspace save refuses a
     * malformed one). See `contentTopics()` for the tolerant read the SEO module uses.
     *
     * @return list<array{title: string, intent: string, per: string, supports: ?string, cluster: ?string, why: ?string}>
     */
    private function parseContentTopics(mixed $topics): array
    {
        if (! is_array($topics) || ! array_is_list($topics)) {
            throw new InvalidArgumentException('"content_topics" must be a list.');
        }

        if (count($topics) > self::MAX_CONTENT_TOPICS) {
            throw new InvalidArgumentException('"content_topics" may hold at most '.self::MAX_CONTENT_TOPICS.' entries.');
        }

        return array_map(fn ($topic) => $this->parseContentTopic($topic), $topics);
    }

    /** @return array{title: string, intent: string, per: string, supports: ?string, cluster: ?string, why: ?string} */
    private function parseContentTopic(mixed $topic): array
    {
        if (! is_array($topic)) {
            throw new InvalidArgumentException('Every content topic must be an object with a title and an intent.');
        }

        $title = $this->requireString($topic, 'title', 160);
        $intent = (string) ($topic['intent'] ?? '');

        if (ArticleIntent::tryFrom($intent) === null) {
            throw new InvalidArgumentException('Every content topic needs an intent: '.implode(', ', array_map(fn (ArticleIntent $i) => $i->value, ArticleIntent::cases())).'.');
        }

        $per = strtolower((string) ($topic['per'] ?? 'none')) ?: 'none';

        if (! in_array($per, self::CONTENT_TOPIC_SCOPES, true)) {
            throw new InvalidArgumentException('A content topic\'s "per" must be one of: '.implode(', ', self::CONTENT_TOPIC_SCOPES).'.');
        }

        preg_match_all('/\{([^{}]*)\}/', $title, $found);

        foreach ($found[1] as $placeholder) {
            if (! in_array($placeholder, self::CONTENT_TOPIC_PLACEHOLDERS, true)) {
                throw new InvalidArgumentException('Unknown placeholder {'.$placeholder.'} in a content topic. Use '.implode(', ', array_map(fn ($p) => '{'.$p.'}', self::CONTENT_TOPIC_PLACEHOLDERS)).'.');
            }
        }

        $withoutPlaceholders = preg_replace('/\{[^{}]*\}/', '', $title) ?? $title;

        if (str_contains($withoutPlaceholders, '{') || str_contains($withoutPlaceholders, '}')) {
            throw new InvalidArgumentException('A content topic title has an unbalanced brace.');
        }

        if ($per === 'service' && ! in_array('service', $found[1], true)) {
            throw new InvalidArgumentException('A per-service content topic must use {service} in its title.');
        }

        if ($per === 'city' && ! in_array('city', $found[1], true)) {
            throw new InvalidArgumentException('A per-city content topic must use {city} in its title.');
        }

        // The deterministic guard rail: an article never targets the "hire / book this" search — that belongs
        // to the Website's own pages. A topic must carry an informational word (how much, cost, vs, ideas ...).
        $plain = trim((string) preg_replace('/\{[^{}]*\}/', ' ', $title));

        if (ArticleTopicSignature::of($plain)['modifiers'] === []) {
            throw new InvalidArgumentException('A content topic must ask a question or offer ideas, a cost, a comparison or a how-to ("How much does ... cost", "... vs ...", "... ideas"). A plain service search belongs on a page, not an article.');
        }

        return [
            'title' => $title,
            'intent' => $intent,
            'per' => $per,
            'supports' => ($supports = $this->optionalString($topic, 'supports', 60)) !== null ? strtolower($supports) : null,
            'cluster' => $this->optionalString($topic, 'cluster', 60),
            'why' => $this->optionalString($topic, 'why', 300),
        ];
    }

    /**
     * The tolerant READ of a stored strategy's content topics (SEO Content Engine). The payload was validated
     * at publish, but the reader never trusts a stored shape: an entry that no longer parses is skipped, and
     * the rest still work.
     *
     * @param  array<string, mixed>  $strategy  a payload as returned by BlueprintConfigReader::seoStrategy()
     * @return list<array{title: string, intent: string, per: string, supports: ?string, cluster: ?string, why: ?string}>
     */
    public static function contentTopics(array $strategy): array
    {
        $raw = $strategy['content_topics'] ?? [];

        if (! is_array($raw)) {
            return [];
        }

        $adapter = new self();
        $out = [];

        foreach (array_slice(array_values($raw), 0, self::MAX_CONTENT_TOPICS) as $topic) {
            try {
                $out[] = $adapter->parseContentTopic($topic);
            } catch (InvalidArgumentException) {
                continue;
            }
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
            .count($payload['content_topics'] ?? []).' article topics, '
            .count($payload['schema_types'] ?? []).' schema types';
    }

    public function formFields(): array
    {
        return [
            ['name' => 'keyword_patterns', 'label' => 'Keyword-intent patterns', 'type' => 'lines', 'required' => false,
                'help' => 'One per line: pattern | intent ('.implode(', ', self::INTENTS).'). Example: photo booth rental {city} | transactional'],
            ['name' => 'faq_topics', 'label' => 'FAQ topics', 'type' => 'lines', 'required' => false, 'help' => 'One topic per line.'],
            ['name' => 'content_topics', 'label' => 'Article topics', 'type' => 'lines', 'required' => false,
                'help' => 'Blog topics a Business of this niche can write, one per line: title | intent ('.implode(', ', array_map(fn (ArticleIntent $i) => $i->value, ArticleIntent::cases())).') | per (none, service, city) | supports (page hint) | cluster | why. '
                    .'Titles may use {service}, {city} and {business}, and must be a question, comparison, cost or ideas topic - never a plain service search. Example: How much does a photo booth rental cost in {city}? | cost | city | packages | Pricing | Pricing is the first question most buyers ask.'],
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

        $topics = [];

        foreach ($this->linesOf($input['content_topics'] ?? '') as $line) {
            [$title, $intent, $per, $supports, $cluster, $why] = $this->pipeParts($line, 6);
            $topic = ['title' => (string) $title, 'intent' => strtolower((string) $intent)];

            foreach (['per' => strtolower((string) $per), 'supports' => (string) $supports, 'cluster' => (string) $cluster, 'why' => (string) $why] as $key => $value) {
                if ($value !== '') {
                    $topic[$key] = $value;
                }
            }

            $topics[] = $topic;
        }

        $payload = [
            'keyword_patterns' => $patterns,
            'faq_topics' => $this->linesOf($input['faq_topics'] ?? ''),
            'content_topics' => $topics,
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
            'content_topics' => implode("\n", array_map(
                fn ($t) => implode(' | ', [$t['title'] ?? '', $t['intent'] ?? '', $t['per'] ?? '', $t['supports'] ?? '', $t['cluster'] ?? '', $t['why'] ?? '']),
                $payload['content_topics'] ?? [],
            )),
            'schema_types' => implode("\n", $payload['schema_types'] ?? []),
            'schema_notes' => $payload['schema_notes'] ?? '',
            'internal_links' => implode("\n", array_map(fn ($l) => $l['from'].' | '.$l['to'].' | '.$l['anchor'], $payload['internal_links'] ?? [])),
        ];
    }
}

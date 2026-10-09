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

    /** Content Autopilot - how risky a niche's claims are; decides how much review an article needs. */
    public const CONTENT_RISK_TIERS = ['standard', 'sensitive', 'regulated'];

    /** Content Autopilot - may it publish on its own? Only `allowed` on a `standard` niche ever does; anything else needs the owner. */
    public const CONTENT_AUTO_PUBLISH = ['allowed', 'approval_required', 'never'];

    /** Content Autopilot - where in the customer's journey a topic helps. */
    public const JOURNEY_STAGES = ['awareness', 'consideration', 'decision'];

    private const MAX_POLICY_PHRASES = 40;

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
            'content_policy' => $this->parseContentPolicy($payload['content_policy'] ?? null),
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

        $parsed = [
            'title' => $title,
            'intent' => $intent,
            'per' => $per,
            'supports' => ($supports = $this->optionalString($topic, 'supports', 60)) !== null ? strtolower($supports) : null,
            'cluster' => $this->optionalString($topic, 'cluster', 60),
            'why' => $this->optionalString($topic, 'why', 300),
        ];

        // Content Autopilot - seasonality (the months the topic is useful, 1-12) and the journey stage. Both optional.
        $months = $this->parseMonths($topic['months'] ?? null);

        if ($months !== []) {
            $parsed['months'] = $months;
        }

        $stage = strtolower((string) ($topic['stage'] ?? ''));

        if ($stage !== '') {
            if (! in_array($stage, self::JOURNEY_STAGES, true)) {
                throw new InvalidArgumentException('A content topic\'s "stage" must be one of: '.implode(', ', self::JOURNEY_STAGES).'.');
            }

            $parsed['stage'] = $stage;
        }

        return $parsed;
    }

    /** @return list<int> sorted, unique months 1-12 */
    private function parseMonths(mixed $months): array
    {
        if ($months === null || $months === '' || $months === []) {
            return [];
        }

        if (is_string($months)) {
            $months = preg_split('/\s*,\s*/', trim($months)) ?: [];
        }

        if (! is_array($months) || count($months) > 12) {
            throw new InvalidArgumentException('A content topic\'s "months" must list up to 12 months, as numbers 1-12.');
        }

        $out = [];

        foreach ($months as $month) {
            if (! is_numeric($month) || (int) $month != $month || (int) $month < 1 || (int) $month > 12) {
                throw new InvalidArgumentException('A content topic\'s "months" must be numbers from 1 (January) to 12 (December).');
            }

            $out[(int) $month] = (int) $month;
        }

        ksort($out);

        return array_values($out);
    }

    /**
     * Content Autopilot - the niche's content POLICY, validated: how risky its claims are, whether Autopilot may ever
     * publish on its own, phrases it must never write, and the terms the niche prefers. Optional; a niche that says
     * nothing is treated as "unknown", which never auto-publishes (see `contentPolicy()`).
     *
     * @return array{risk_tier: string, auto_publish: string, prohibited_phrases: list<string>, preferred_terms: list<string>}|null
     */
    private function parseContentPolicy(mixed $policy): ?array
    {
        if ($policy === null || $policy === []) {
            return null;
        }

        if (! is_array($policy) || array_is_list($policy)) {
            throw new InvalidArgumentException('"content_policy" must be an object.');
        }

        $tier = strtolower((string) ($policy['risk_tier'] ?? ''));
        $auto = strtolower((string) ($policy['auto_publish'] ?? 'approval_required'));

        if (! in_array($tier, self::CONTENT_RISK_TIERS, true)) {
            throw new InvalidArgumentException('"content_policy.risk_tier" must be one of: '.implode(', ', self::CONTENT_RISK_TIERS).'.');
        }

        if (! in_array($auto, self::CONTENT_AUTO_PUBLISH, true)) {
            throw new InvalidArgumentException('"content_policy.auto_publish" must be one of: '.implode(', ', self::CONTENT_AUTO_PUBLISH).'.');
        }

        if ($tier !== 'standard' && $auto === 'allowed') {
            throw new InvalidArgumentException('A sensitive or regulated niche cannot allow automatic publishing; use approval_required or never.');
        }

        return [
            'risk_tier' => $tier,
            'auto_publish' => $auto,
            'prohibited_phrases' => $this->stringList($policy, 'prohibited_phrases', self::MAX_POLICY_PHRASES, 160, false),
            'preferred_terms' => $this->stringList($policy, 'preferred_terms', self::MAX_POLICY_PHRASES, 80, false),
        ];
    }

    /**
     * The tolerant READ of a stored strategy's content policy. FAIL CLOSED: a missing, unreadable or invalid policy
     * (or no Blueprint at all - pass null) is `unspecified` + `approval_required`, which never publishes on its own.
     *
     * @param  array<string, mixed>|null  $strategy  a payload as returned by BlueprintConfigReader::seoStrategy()
     * @return array{risk_tier: string, auto_publish: string, prohibited_phrases: list<string>, preferred_terms: list<string>}
     */
    public static function contentPolicy(?array $strategy): array
    {
        $closed = ['risk_tier' => 'unspecified', 'auto_publish' => 'approval_required', 'prohibited_phrases' => [], 'preferred_terms' => []];

        $raw = $strategy['content_policy'] ?? null;

        if (! is_array($raw)) {
            return $closed;
        }

        try {
            return (new self())->parseContentPolicy($raw) ?? $closed;
        } catch (InvalidArgumentException) {
            return $closed;
        }
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
                    .'Titles may use {service}, {city} and {business}, and must be a question, comparison, cost or ideas topic - never a plain service search. Example: How much does a photo booth rental cost in {city}? | cost | city | packages | Pricing | Pricing is the first question most buyers ask. '
                    .'Optional trailing parts: | months (1-12, comma separated, when the topic is most useful) | stage ('.implode(', ', self::JOURNEY_STAGES).'). Example: ... | Pricing | Why | 3,4,5 | consideration'],
            ['name' => 'content_risk_tier', 'label' => 'Content risk', 'type' => 'select', 'required' => false,
                'options' => ['standard' => 'Standard - everyday local services', 'sensitive' => 'Sensitive - owner reviews every article', 'regulated' => 'Regulated (health, legal, financial) - owner reviews every article'],
                'help' => 'How careful Content Autopilot must be for this niche. Leave empty if unsure: articles then always wait for the owner.'],
            ['name' => 'content_auto_publish', 'label' => 'Automatic publishing', 'type' => 'select', 'required' => false,
                'options' => ['approval_required' => 'Owner approves each article', 'allowed' => 'May publish itself once validated (Standard risk only)', 'never' => 'Never publish automatically'],
                'help' => 'Even when allowed, the first articles of every Business wait for approval.'],
            ['name' => 'content_prohibited_phrases', 'label' => 'Never write', 'type' => 'lines', 'required' => false, 'help' => 'Phrases or claims Content Autopilot must never use in this niche, one per line.'],
            ['name' => 'content_preferred_terms', 'label' => 'Preferred terms', 'type' => 'lines', 'required' => false, 'help' => 'Words the niche prefers, one per line (for example "photo booth", not "photobooth").'],
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
            [$title, $intent, $per, $supports, $cluster, $why, $months, $stage] = $this->pipeParts($line, 8);
            $topic = ['title' => (string) $title, 'intent' => strtolower((string) $intent)];

            foreach (['per' => strtolower((string) $per), 'supports' => (string) $supports, 'cluster' => (string) $cluster, 'why' => (string) $why, 'months' => (string) $months, 'stage' => strtolower((string) $stage)] as $key => $value) {
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

        $tier = strtolower(trim((string) ($input['content_risk_tier'] ?? '')));
        $phrases = $this->linesOf($input['content_prohibited_phrases'] ?? '');
        $terms = $this->linesOf($input['content_preferred_terms'] ?? '');

        if ($tier !== '' || $phrases !== [] || $terms !== []) {
            // Naming phrases or terms without a risk tier must not silently become "standard": it is recorded as unspecified-safe.
            $payload['content_policy'] = [
                'risk_tier' => $tier !== '' ? $tier : 'sensitive',
                'auto_publish' => strtolower(trim((string) ($input['content_auto_publish'] ?? ''))) ?: 'approval_required',
                'prohibited_phrases' => $phrases,
                'preferred_terms' => $terms,
            ];
        }

        $this->parse($payload);

        return $payload;
    }

    public function inputFromPayload(array $payload): array
    {
        return [
            'keyword_patterns' => implode("\n", array_map(fn ($p) => $p['pattern'].' | '.$p['intent'], $payload['keyword_patterns'] ?? [])),
            'faq_topics' => implode("\n", $payload['faq_topics'] ?? []),
            'content_topics' => implode("\n", array_map(function ($t) {
                $parts = [$t['title'] ?? '', $t['intent'] ?? '', $t['per'] ?? '', $t['supports'] ?? '', $t['cluster'] ?? '', $t['why'] ?? ''];

                // Months and stage are optional trailing parts; written only when present so older topics round-trip unchanged.
                if (! empty($t['months']) || ! empty($t['stage'])) {
                    $parts[] = implode(',', (array) ($t['months'] ?? []));
                    $parts[] = $t['stage'] ?? '';
                }

                return implode(' | ', $parts);
            }, $payload['content_topics'] ?? [])),
            'content_risk_tier' => $payload['content_policy']['risk_tier'] ?? '',
            'content_auto_publish' => $payload['content_policy']['auto_publish'] ?? '',
            'content_prohibited_phrases' => implode("\n", $payload['content_policy']['prohibited_phrases'] ?? []),
            'content_preferred_terms' => implode("\n", $payload['content_policy']['preferred_terms'] ?? []),
            'schema_types' => implode("\n", $payload['schema_types'] ?? []),
            'schema_notes' => $payload['schema_notes'] ?? '',
            'internal_links' => implode("\n", array_map(fn ($l) => $l['from'].' | '.$l['to'].' | '.$l['anchor'], $payload['internal_links'] ?? [])),
        ];
    }
}

<?php

namespace App\Library\Coo\Insight;

use App\Enums\Coo\SignalDirection;

/**
 * Contract §8.3, §9.1 — the exact, aggregate facts one COO insight is about.
 *
 * WHAT CAN BE IN HERE, and nothing else: canonical counts for one Business
 * performance window and the one before it, their materiality classification
 * (C-3), the Attention types currently raised, the Opportunity types in the
 * work queue with their registry evidence keys and the registry's own static
 * evidence wording, and the website/Google status words. No contact, no name,
 * no phone number, no email address, no message body, no observed evidence
 * value — there is no field that could hold one (§8.3, §15.3).
 *
 * Every fact has a stable reference (`metric.new_contacts`,
 * `attention.google_connection_lost`, …). The model may only cite those; the
 * output validator rejects anything else.
 */
final readonly class CooInsightFacts
{
    public const METRIC_LABELS = [
        'new_contacts' => 'New contacts',
        'conversations_started' => 'New conversations',
        'messages_received' => 'Messages received',
    ];

    /**
     * @param  array<string, array{current: int, previous: int, direction: string}>  $metrics
     * @param  array<int, string>  $attention  AttentionType values
     * @param  array<int, array{type: string, evidence_keys: array<int, string>, evidence: array<int, string>, first_detected_in_period: bool}>  $opportunities
     * @param  array{website: ?string, google: ?string}  $visibility
     */
    public function __construct(
        public int $businessId,
        public string $periodKey,
        public string $periodLabel,
        public string $currentStart,
        public string $currentEnd,
        public string $previousStart,
        public string $previousEnd,
        public array $metrics,
        public array $attention,
        public array $opportunities,
        public array $visibility,
    ) {
    }

    /** Metrics classified `material_increase` or `material_decrease` (§6.3). */
    public function materialMetricKeys(): array
    {
        return array_keys(array_filter(
            $this->metrics,
            fn (array $metric): bool => in_array($metric['direction'], [SignalDirection::MaterialIncrease->value, SignalDirection::MaterialDecrease->value], true),
        ));
    }

    /**
     * §8.2 E-1's exclusion: does a deterministic rule already account for
     * this period?
     *
     * The contract excludes changes that an Attention item or an Opportunity
     * "touching those metrics" explains, and no canonical source relates an
     * Attention or Opportunity type to a metric. Rather than invent that
     * relation, the check is the conservative superset: ANY raised Attention
     * type, or ANY Opportunity first detected inside the period, counts as an
     * explanation. The error this can make is to stay silent when AI might
     * have helped — never to pay for an explanation a rule already gives.
     */
    public function hasDeterministicExplanation(): bool
    {
        if ($this->attention !== []) {
            return true;
        }

        foreach ($this->opportunities as $opportunity) {
            if ($opportunity['first_detected_in_period']) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> every reference a statement may cite */
    public function factRefs(): array
    {
        $refs = [];

        foreach (array_keys($this->metrics) as $key) {
            $refs[] = 'metric.' . $key;
        }

        foreach ($this->attention as $type) {
            $refs[] = 'attention.' . $type;
        }

        foreach ($this->opportunities as $opportunity) {
            $refs[] = 'opportunity.' . $opportunity['type'];
        }

        foreach (array_keys(array_filter($this->visibility, fn (?string $value): bool => $value !== null)) as $key) {
            $refs[] = 'visibility.' . $key;
        }

        return $refs;
    }

    /** @return array{current: int, previous: int, direction: string}|null */
    public function metric(string $key): ?array
    {
        return $this->metrics[$key] ?? null;
    }

    /**
     * The facts exactly as the model receives them and the insight row stores
     * them (`facts_snapshot`). Real counts: a statement may restate them.
     *
     * @return array<string, mixed>
     */
    public function forPrompt(): array
    {
        $facts = [];

        foreach ($this->metrics as $key => $metric) {
            $facts[] = [
                'ref' => 'metric.' . $key,
                'metric' => self::METRIC_LABELS[$key] ?? $key,
                'current_period' => $metric['current'],
                'previous_period' => $metric['previous'],
                'classification' => $metric['direction'],
            ];
        }

        foreach ($this->attention as $type) {
            $facts[] = ['ref' => 'attention.' . $type, 'attention_type' => $type];
        }

        foreach ($this->opportunities as $opportunity) {
            $facts[] = [
                'ref' => 'opportunity.' . $opportunity['type'],
                'opportunity_type' => $opportunity['type'],
                'evidence_keys' => $opportunity['evidence_keys'],
                'evidence' => $opportunity['evidence'],
                'first_detected_in_period' => $opportunity['first_detected_in_period'],
            ];
        }

        foreach ($this->visibility as $key => $value) {
            if ($value !== null) {
                $facts[] = ['ref' => 'visibility.' . $key, 'visibility' => $key, 'status' => $value];
            }
        }

        return [
            'period' => [
                'key' => $this->periodKey,
                'label' => $this->periodLabel,
                'current' => ['start' => $this->currentStart, 'end' => $this->currentEnd],
                'previous' => ['start' => $this->previousStart, 'end' => $this->previousEnd],
            ],
            'facts' => $facts,
        ];
    }

    /**
     * §9.1 — what the fingerprint is computed over: every count reduced to its
     * band, the classifications and types as they are, and no dates beyond the
     * window's name, so a one-contact change or the passing of a day inside
     * the same named window never buys a new insight — while a change of
     * materiality, which changes a classification, always does.
     *
     * @return array<string, mixed>
     */
    public function bucketed(): array
    {
        $metrics = [];

        foreach ($this->metrics as $key => $metric) {
            $metrics[$key] = [
                'current' => CountBucket::label($metric['current']),
                'previous' => CountBucket::label($metric['previous']),
                'direction' => $metric['direction'],
            ];
        }

        $opportunities = array_map(fn (array $opportunity): array => [
            'type' => $opportunity['type'],
            'evidence_keys' => $opportunity['evidence_keys'],
            'first_detected_in_period' => $opportunity['first_detected_in_period'],
        ], $this->opportunities);

        return [
            'period_key' => $this->periodKey,
            'metrics' => $metrics,
            'attention' => $this->attention,
            'opportunities' => $opportunities,
            'visibility' => $this->visibility,
        ];
    }

    public function fingerprint(int $promptVersion, int $policyVersion): string
    {
        return hash('sha256', self::canonicalJson([
            'facts' => $this->bucketed(),
            'prompt_version' => $promptVersion,
            'policy_version' => $policyVersion,
        ]));
    }

    /**
     * Keys sorted at every level of an associative array; lists keep the
     * (already deterministic) order they were built in. The same facts
     * always serialise to the same bytes.
     */
    public static function canonicalJson(array $value): string
    {
        return (string) json_encode(self::sortKeys($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(fn (mixed $item): mixed => self::sortKeys($item), $value);
    }
}

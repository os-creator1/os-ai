<?php

declare(strict_types=1);

namespace App\Library\Growth;

use App\Models\Business;
use App\Models\Opportunity;

/**
 * The ONLY thing the Growth Advisor's AI is ever shown (Growth Center §34-36).
 *
 * A bounded, structured digest of the canonical Growth truth — never a database
 * handle, never a query result the AI can extend, never free text from a
 * customer. It contains:
 *
 *   - the score and its category scores (numbers)
 *   - at most MAX_OPPORTUNITIES open Opportunities, each as the rule's fixed
 *     headline plus its closed evidence FIGURES (count, canonical value) —
 *     no contact names, no deal titles, no phone numbers, no record uids
 *   - the stored "what changed" and "what's working" lines
 *   - which modules are unavailable, so the answer says so instead of guessing
 *
 * PROVIDER-DATA POLICY. The AI only ever receives the NORMALIZED, closed evidence of a stored
 * Opportunity (counts, figures, fixed copy) — never a provider payload, a campaign or keyword name,
 * an account id or a token. Search Console and Google Business Profile domains stay excluded
 * (AI_FORBIDDEN_DOMAINS): any Opportunity whose rule reads one is dropped here, before the AI sees
 * anything. Ads and rank figures are included as the same normalized numbers every other rule
 * contributes; the AI can explain and prioritise them but has no way to act on them.
 *
 * It also carries the set of numbers it exposed, so the AI's reply can be
 * checked: a figure the AI wrote that is not in this set is a hallucination and
 * the reply is rejected.
 */
final class GrowthAdvisorContext
{
    public const MAX_OPPORTUNITIES = 12;

    /** Fact domains whose data must never be sent to an AI. */
    public const AI_FORBIDDEN_DOMAINS = ['search_console', 'gbp'];

    /**
     * @param  array<string, mixed>  $data  the structured digest
     * @param  array<string, true>  $numbers  every number token the digest exposes
     * @param  array<string, array<string, mixed>>  $opportunities  handle => presented card (the card, with its record uids, is never sent to the AI)
     */
    private function __construct(
        public readonly array $data,
        public readonly array $numbers,
        public readonly array $opportunities,
    ) {
    }

    /**
     * @param  array<int, array<string, mixed>>  $cards  presented open Opportunities, priority order
     * @param  array<string, mixed>  $score  {overall, based_on, categories}
     * @param  array<int, string>  $changes
     * @param  array<int, string>  $positives
     * @param  array<int, string>  $unavailable  owner-facing module names
     */
    public static function fromParts(array $cards, array $score, array $changes, array $positives, array $unavailable): self
    {
        $items = [];
        $byUid = [];

        foreach ($cards as $card) {
            $rule = GrowthRuleRegistry::find((string) ($card['rule_key'] ?? ''));

            if ($rule === null || in_array($rule->definition()->domain, self::AI_FORBIDDEN_DOMAINS, true)) {
                continue;
            }

            if (count($items) >= self::MAX_OPPORTUNITIES) {
                break;
            }

            $e = $card['evidence'] ?? [];
            // A short handle (o1, o2 ...), never the real uid: the AI refers to items
            // by handle and the handle is mapped back to the card server-side.
            $id = 'o' . (count($items) + 1);
            $items[] = [
                'id' => $id,
                'category' => $card['category_label'],
                'impact' => $card['impact'],
                'confidence' => $card['confidence'],
                'headline' => $card['headline'],
                'count' => $e['count'] ?? null,
                'value' => $e['value'] ?? null,
                'location' => $card['location_name'] ?? null,
                'age' => $card['age_label'] ?? null,
            ];
            $byUid[$id] = $card;
        }

        $data = [
            'score' => $score,
            'opportunities' => $items,
            'changes' => array_values($changes),
            'working' => array_values($positives),
            'unavailable_modules' => array_values($unavailable),
        ];

        return new self($data, self::numberTokens(json_encode($data, JSON_UNESCAPED_UNICODE) ?: ''), $byUid);
    }

    /** @return array<string, true> normalized digit tokens (commas stripped) found in a string */
    public static function numberTokens(string $text): array
    {
        preg_match_all('/\d[\d,]*(?:\.\d+)?/', $text, $m);
        $tokens = [];

        foreach ($m[0] as $token) {
            $tokens[rtrim(str_replace(',', '', $token), '.')] = true;
        }

        return $tokens;
    }

    /** Every number in $text must have been exposed in the digest (or be a trivial 1-3). */
    public function numbersAreGrounded(string $text): bool
    {
        foreach (array_keys(self::numberTokens($text)) as $token) {
            if (! isset($this->numbers[$token]) && ! in_array($token, ['1', '2', '3'], true)) {
                return false;
            }
        }

        return true;
    }

    public function hasOpportunity(string $handle): bool
    {
        return isset($this->opportunities[$handle]);
    }
}

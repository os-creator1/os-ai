<?php

namespace App\Library\Seo\Content\Autopilot;

use App\Library\Seo\Content\ArticleCannibalizationGuard;
use App\Library\Seo\Content\ArticleOpportunityEngine;
use App\Library\Seo\Content\ArticleSiteInventory;
use App\Library\Seo\Content\ArticleTopicSignature;
use App\Models\Business;
use Carbon\CarbonInterface;

/**
 * Content Autopilot - the deterministic opportunity score. No AI, no provider call, no rank job: it reads the computed
 * opportunities (ArticleOpportunityEngine), the Content Fact Pack and the niche policy, and answers "is this topic worth
 * writing for THIS Business now?" with a number and the reasons behind it.
 *
 * Eight factors (weights sum to 100):
 *   relevance 20 - the topic comes from the niche, or from a keyword the owner tracks, and matches what they emphasise;
 *   usefulness 15 - the kind of question customers actually ask (cost beats ideas), and one they have been asking;
 *   support 15 - it supports a commercial page that exists and can be linked;
 *   gap 15 - nothing on the site already answers it;
 *   facts 15 - how much unique Business information the writer can use;
 *   rank 10 - it answers a search the owner already tracks (cached keywords only);
 *   season 5 - in season (or evergreen);
 *   links 5 - enough real pages and articles to link to.
 *
 * Disqualifiers score 0 and are never written: already covered, a strong duplicate of an article, a topic the owner told
 * us to avoid, a claim the owner or the niche prohibits. A related (not duplicate) article costs 15 points. Flags
 * (out of season, thin facts, no pages to support) keep a topic out of `eligible` however it scores.
 *
 * Bands: eligible (>= eligible_score and no flags) - Autopilot may write it; hold - wait, or ask for ONE missing fact;
 * skip - not worth it; disqualified. Not optimising for volume: nothing here counts how many articles exist.
 */
final class AutopilotScorer
{
    public const BAND_ELIGIBLE = 'eligible';
    public const BAND_HOLD = 'hold';
    public const BAND_SKIP = 'skip';
    public const BAND_DISQUALIFIED = 'disqualified';

    public const FLAG_OUT_OF_SEASON = 'out_of_season';
    public const FLAG_THIN_FACTS = 'thin_facts';
    public const FLAG_NO_SUPPORT_PAGE = 'no_support_page';

    private const INTENT_USEFULNESS = ['cost' => 15, 'how_to' => 13, 'planning' => 13, 'comparison' => 12, 'guide' => 10, 'ideas' => 9];

    /** Fact groups that make an article genuinely Business-specific, and the points each is worth (max 15). */
    private const FACT_POINTS = ['priced_packages' => 4, 'differentiators' => 3, 'faqs' => 2, 'customer_problems' => 2, 'common_questions' => 2, 'proof_points' => 1];

    /** How many DISTINCT groups above must hold something before a topic has enough to be written from. */
    private const FACT_FLOOR = 2;

    public function __construct(
        private readonly ArticleOpportunityEngine $opportunities,
        private readonly ArticleCannibalizationGuard $guard,
        private readonly ArticleSiteInventory $inventory,
        private readonly ContentFactPack $facts,
        private readonly ContentPolicy $policy,
    ) {
    }

    /**
     * Every computed opportunity, scored, best first. Ties break on the key so the order is stable.
     *
     * @return list<array<string, mixed>>
     */
    public function rank(Business $business, ?array $pack = null, ?CarbonInterface $now = null): array
    {
        $pack ??= $this->facts->forBusiness($business);
        $now ??= now();
        $pages = $this->inventory->pages($business);

        $scored = array_map(fn (array $opportunity) => $this->score($business, $opportunity, $pack, $pages, $now), $this->opportunities->forBusiness($business));

        // Equal scores prefer an angle the site does not have yet (fewer existing articles asking the same kind of question),
        // then the key, so the order is stable.
        $variety = fn (array $s) => $this->sameAngleCount($s['title'], $pack);
        usort($scored, fn (array $a, array $b) => [$b['score'], $variety($a), $a['key']] <=> [$a['score'], $variety($b), $b['key']]);

        return $scored;
    }

    /**
     * @param  array<string, mixed>  $opportunity  one row of ArticleOpportunityEngine::forBusiness()
     * @param  array<int, array<string, mixed>>  $pages  ArticleSiteInventory::pages()
     * @return array<string, mixed>
     */
    public function score(Business $business, array $opportunity, array $pack, array $pages, CarbonInterface $now): array
    {
        $title = (string) $opportunity['title'];
        $phrase = $title . ' ' . ($opportunity['primary_topic'] ?? '');
        $tokens = $this->tokens($phrase);
        $everyToken = $this->tokens($phrase, true);
        $intent = (string) ($opportunity['search_intent'] ?? 'guide');

        $result = [
            'key' => (string) $opportunity['key'],
            'title' => $title,
            'intent' => $intent,
            'score' => 0,
            'band' => self::BAND_SKIP,
            'breakdown' => [],
            'flags' => [],
            'disqualified' => null,
            'facts_short_by' => 0,
            'opportunity' => $opportunity,
        ];

        // ---- disqualifiers: never written, whatever they would score ----
        if (($opportunity['status'] ?? 'not_covered') !== 'not_covered') {
            return $this->disqualify($result, 'already_covered');
        }

        foreach ((array) $pack['avoid_topics'] as $avoid) {
            $avoidTokens = $this->tokens((string) $avoid, true);

            if ($avoidTokens !== [] && array_diff($avoidTokens, $everyToken) === []) {
                return $this->disqualify($result, 'owner_avoids_topic');
            }
        }

        foreach ($this->policy->prohibitedPhrases($business) as $phrase) {
            if (mb_stripos($title, $phrase) !== false) {
                return $this->disqualify($result, 'prohibited_claim');
            }
        }

        $conflict = $this->guard->check($business, array_filter([$title, (string) ($opportunity['primary_topic'] ?? '')]), null, $pages);

        if ($conflict->conflictsWithPage()) {
            return $this->disqualify($result, 'competes_with_money_page');
        }

        if ($conflict->strongFindings() !== []) {
            return $this->disqualify($result, 'duplicate_article');
        }

        // ---- the eight factors ----
        $breakdown = [];

        $source = (string) ($opportunity['source'] ?? 'generic');
        $relevance = match ($source) {
            'keyword' => 16,
            'niche_blueprint' => 14,
            default => 8,
        };

        foreach ((array) $pack['emphasis'] as $emphasis) {
            if ($this->overlap($this->tokens((string) $emphasis), $tokens) >= 0.5) {
                $relevance += 6;
                break;
            }
        }

        $breakdown['relevance'] = min(20, $relevance);

        $usefulness = self::INTENT_USEFULNESS[$intent] ?? 8;

        if ($this->askedBefore($tokens, $pack)) {
            $usefulness += 3;
        }

        $breakdown['usefulness'] = min(15, $usefulness);

        $supports = ! empty($opportunity['supports_page_uid']);
        $breakdown['support'] = $supports ? 15 : 0;

        if (! $supports) {
            $result['flags'][] = self::FLAG_NO_SUPPORT_PAGE;
        }

        $breakdown['gap'] = 15;

        if ($conflict->related() !== []) {
            $breakdown['gap'] = 0;
        }

        [$factPoints, $factsOk, $short] = $this->factPoints($pack, $intent, $tokens);
        $breakdown['facts'] = $factPoints;
        $result['facts_short_by'] = $short;

        if (! $factsOk) {
            $result['flags'][] = self::FLAG_THIN_FACTS;
        }

        $breakdown['rank'] = $this->rankPoints($tokens, $source, $pack);

        $months = array_values(array_filter((array) ($opportunity['months'] ?? []), 'is_int'));
        $inSeason = $this->policy->inSeason($months, (int) $now->format('n'));
        $breakdown['season'] = $months === [] ? 3 : ($inSeason ? 5 : 0);

        if (! $inSeason) {
            $result['flags'][] = self::FLAG_OUT_OF_SEASON;
        }

        $links = count((array) ($opportunity['internal_links'] ?? []));
        $breakdown['links'] = $links >= 2 ? 5 : ($links === 1 ? 3 : 0);

        $result['breakdown'] = $breakdown;
        $result['score'] = max(0, min(100, array_sum($breakdown)));

        // A cost article with no priced package would have to invent a price: it can never be written, however it scores.
        if ($intent === 'cost' && ($this->groups($pack)['priced_packages'] ?? 0) === 0) {
            return $this->disqualify($result, 'no_pricing_facts');
        }

        return $this->band($result);
    }

    /**
     * The score the topic would have with a full set of facts - used to decide whether asking the owner for ONE more fact
     * could make it eligible (we never ask for information that cannot change the answer).
     */
    public function couldBeEligibleWithMoreFacts(array $scored): bool
    {
        if ($scored['disqualified'] !== null || ! in_array(self::FLAG_THIN_FACTS, $scored['flags'], true)) {
            return false;
        }

        $others = array_diff($scored['flags'], [self::FLAG_THIN_FACTS]);

        return $others === [] && ($scored['score'] - ($scored['breakdown']['facts'] ?? 0) + 15) >= $this->eligibleScore();
    }

    private function band(array $result): array
    {
        $score = $result['score'];

        if ($score < $this->holdScore()) {
            $result['band'] = self::BAND_SKIP;
        } elseif ($score >= $this->eligibleScore() && $result['flags'] === []) {
            $result['band'] = self::BAND_ELIGIBLE;
        } else {
            $result['band'] = self::BAND_HOLD;
        }

        return $result;
    }

    private function disqualify(array $result, string $reason): array
    {
        $result['score'] = 0;
        $result['band'] = self::BAND_DISQUALIFIED;
        $result['disqualified'] = $reason;

        return $result;
    }

    /** @return array{0: int, 1: bool, 2: int} points (0-15), whether the floor is met, how many groups short */
    private function factPoints(array $pack, string $intent, array $tokens): array
    {
        $groups = $this->groups($pack);
        $points = 0;
        $present = 0;

        foreach (self::FACT_POINTS as $group => $worth) {
            if (($groups[$group] ?? 0) > 0) {
                $points += $worth;
                $present++;
            }
        }

        // A topic that matches a question or FAQ the Business has actually answered is the strongest kind of fact.
        foreach ([...(array) $pack['common_questions'], ...array_column((array) $pack['faqs'], 'question')] as $question) {
            if ($this->overlap($this->tokens((string) $question), $tokens) >= 0.5) {
                $points += 1;
                break;
            }
        }

        $short = max(0, self::FACT_FLOOR - $present);

        return [min(15, $points), $short === 0, $short];
    }

    private function askedBefore(array $tokens, array $pack): bool
    {
        foreach ([...(array) $pack['common_questions'], ...(array) $pack['customer_problems']] as $asked) {
            if ($this->overlap($this->tokens((string) $asked), $tokens) >= 0.5) {
                return true;
            }
        }

        return false;
    }

    private function rankPoints(array $tokens, string $source, array $pack): int
    {
        if ($source === 'keyword') {
            return 10;
        }

        foreach ((array) $pack['tracked_phrases'] as $phrase) {
            if ($this->overlap($this->tokens((string) $phrase), $tokens) >= 0.6) {
                return 6;
            }
        }

        return 0;
    }

    private function groups(array $pack): array
    {
        return $this->facts->groups($pack);
    }

    /**
     * The SUBJECT words of a phrase ("photo booth cost" -> photo, booth); `$withModifiers` adds the question words
     * (cost, how, vs...). Matching a question to a topic uses the subject only, or every "how much" would match every other.
     *
     * @return list<string>
     */
    private function tokens(string $phrase, bool $withModifiers = false): array
    {
        $signature = ArticleTopicSignature::of($phrase);

        return array_values(array_unique($withModifiers ? [...$signature['core'], ...$signature['modifiers']] : $signature['core']));
    }

    /** How many existing articles already ask the same KIND of question (same modifier set) as this topic. */
    private function sameAngleCount(string $title, array $pack): int
    {
        $modifiers = ArticleTopicSignature::of($title)['modifiers'];

        return count(array_filter((array) $pack['existing_articles'], fn (array $a) => ArticleTopicSignature::of((string) $a['title'])['modifiers'] === $modifiers));
    }

    /** Share of the SMALLER token set that the other contains (0.0 when either is empty). */
    private function overlap(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }

        return count(array_intersect($a, $b)) / min(count($a), count($b));
    }

    private function eligibleScore(): int
    {
        return (int) config('seo.content_autopilot.eligible_score', 70);
    }

    private function holdScore(): int
    {
        return (int) config('seo.content_autopilot.hold_score', 45);
    }
}

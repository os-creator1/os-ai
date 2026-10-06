<?php

namespace App\Library\Seo\Content;

use App\Enums\Seo\SeoRankCheckType;
use App\Enums\Seo\SeoRankTrackingState;
use App\Library\Seo\Rank\SeoRankHistoryReader;
use App\Library\Seo\SeoPhraseNormalizer;
use App\Models\Business;
use App\Models\SeoKeyword;
use App\Models\SeoRankTarget;
use App\Models\WebsiteArticle;

/**
 * SEO Content Engine V1 — what the EXISTING rank tracking already knows about a published article.
 *
 * This reads cached observations only (SeoRankObservation, through SeoRankHistoryReader): no provider
 * call, no AI, and publishing an article NEVER creates a rank target — tracked keywords are paid,
 * budgeted objects the owner chooses on Search keywords. An article is linked to a tracked keyword
 * only by the one phrase rule the SEO module already owns: its `primary_topic` normalized by
 * SeoPhraseNormalizer equals `SeoKeyword.phrase_normalized`. No match, no signal — never a guess.
 *
 * Signals (one per article, most useful first):
 *
 *   stale_and_declined  the position got materially worse AND the article is due for review
 *                       (older than a year, or a package it quotes has changed)
 *   declined            the latest check is at least MATERIAL_DECLINE positions worse than the one
 *                       before, or the article dropped out of the tracked results after being in the top 20
 *   near_page_one       latest position is NEAR_PAGE_ONE_FROM..NEAR_PAGE_ONE_TO
 *
 * Wording is deliberately modest: a position is an observation, not a result of anything the owner did,
 * so messages say that updating or supporting the content "may help", never that it will.
 */
final class ArticleRankSignals
{
    public const NEAR_PAGE_ONE_FROM = 8;
    public const NEAR_PAGE_ONE_TO = 20;
    public const MATERIAL_DECLINE = 5;
    /** A position of this or better counts as "doing well". */
    public const PERFORMING_WELL_TO = 5;

    public const KIND_NEAR_PAGE_ONE = 'near_page_one';
    public const KIND_DECLINED = 'declined';
    public const KIND_STALE_AND_DECLINED = 'stale_and_declined';

    private const ARTICLE_CAP = 500;

    public function __construct(private readonly ArticleFreshness $freshness, private readonly SeoRankHistoryReader $history)
    {
    }

    /**
     * @return array<int, array{article_uid: string, title: string, topic: string, kind: string, position: ?int, previous: ?int, message: string}>
     */
    public function forBusiness(Business $business): array
    {
        $observed = $this->observed($business);

        if ($observed === []) {
            return [];
        }

        $declined = array_filter($observed, fn (array $o) => $o['declined']);
        $staleUids = $declined === [] ? [] : $this->staleUids($business);

        $out = [];

        foreach ($observed as $o) {
            $kind = match (true) {
                $o['declined'] && in_array($o['article_uid'], $staleUids, true) => self::KIND_STALE_AND_DECLINED,
                $o['declined'] => self::KIND_DECLINED,
                $o['position'] !== null && $o['position'] >= self::NEAR_PAGE_ONE_FROM && $o['position'] <= self::NEAR_PAGE_ONE_TO => self::KIND_NEAR_PAGE_ONE,
                default => null,
            };

            if ($kind === null) {
                continue;
            }

            $out[] = [
                'article_uid' => $o['article_uid'],
                'title' => $o['title'],
                'topic' => $o['topic'],
                'kind' => $kind,
                'position' => $o['position'],
                'previous' => $o['previous'],
                'message' => $this->message($kind, $o),
            ];
        }

        return $out;
    }

    /**
     * Published articles whose tracked search is currently in the top PERFORMING_WELL_TO. A fact for Growth
     * ("what's working"), not a recommendation.
     *
     * @return array<int, array{article_uid: string, title: string, topic: string, position: int}>
     */
    public function performingWell(Business $business): array
    {
        $out = [];

        foreach ($this->observed($business) as $o) {
            if (! $o['declined'] && $o['position'] !== null && $o['position'] <= self::PERFORMING_WELL_TO) {
                $out[] = ['article_uid' => $o['article_uid'], 'title' => $o['title'], 'topic' => $o['topic'], 'position' => $o['position']];
            }
        }

        return $out;
    }

    /**
     * One row per published article that is linked to a tracked keyword with a stored organic observation.
     *
     * @return array<int, array{article_uid: string, title: string, topic: string, position: ?int, previous: ?int, declined: bool}>
     */
    private function observed(Business $business): array
    {
        $articles = WebsiteArticle::query()
            ->where('business_id', $business->id)
            ->published()
            ->whereNotNull('primary_topic')
            ->orderBy('id')
            ->limit(self::ARTICLE_CAP)
            ->get(['id', 'uid', 'title', 'primary_topic']);

        if ($articles->isEmpty()) {
            return [];
        }

        $byPhrase = [];

        foreach ($articles as $article) {
            $phrase = SeoPhraseNormalizer::normalize((string) $article->primary_topic);

            if ($phrase !== '') {
                $byPhrase[$phrase][] = $article;
            }
        }

        if ($byPhrase === []) {
            return [];
        }

        $keywordIds = SeoKeyword::query()
            ->where('business_id', $business->id)
            ->active()
            ->whereIn('phrase_normalized', array_keys($byPhrase))
            ->pluck('phrase_normalized', 'id')
            ->all();

        if ($keywordIds === []) {
            return [];
        }

        $targets = SeoRankTarget::query()
            ->where('business_id', $business->id)
            ->where('tracking_state', SeoRankTrackingState::Tracking->value)
            ->whereIn('seo_keyword_id', array_keys($keywordIds))
            ->orderBy('id')
            ->get(['id', 'seo_keyword_id']);

        if ($targets->isEmpty()) {
            return [];
        }

        $summaries = $this->history->summaries($targets->pluck('id')->all());

        // For each phrase, the target whose current organic position is best (a keyword can be tracked in
        // several places; the article is judged by where it does best), else the first target with data.
        $bestByPhrase = [];

        foreach ($targets as $target) {
            $summary = $summaries[$target->id][SeoRankCheckType::Organic->value] ?? null;

            if ($summary === null || $summary['current'] === null) {
                continue;
            }

            $phrase = $keywordIds[$target->seo_keyword_id];
            $position = $summary['current']->isFound() ? (int) $summary['current']->position : null;
            $held = $bestByPhrase[$phrase] ?? null;

            if ($held === null || ($position !== null && ($held['position'] === null || $position < $held['position']))) {
                $bestByPhrase[$phrase] = ['position' => $position, 'summary' => $summary];
            }
        }

        $out = [];

        foreach ($bestByPhrase as $phrase => $best) {
            $current = $best['summary']['current'];
            $previous = $best['summary']['previous'];
            $previousPosition = $previous !== null && $previous->isFound() ? (int) $previous->position : null;

            foreach ($byPhrase[$phrase] as $article) {
                $out[] = [
                    'article_uid' => (string) $article->uid,
                    'title' => (string) $article->title,
                    'topic' => (string) $article->primary_topic,
                    'position' => $best['position'],
                    'previous' => $previousPosition,
                    'declined' => $this->declined($best['position'], $previousPosition, $current->isFound()),
                ];
            }
        }

        return $out;
    }

    private function declined(?int $position, ?int $previous, bool $currentFound): bool
    {
        if ($previous === null) {
            return false;
        }

        if ($position !== null) {
            return $position - $previous >= self::MATERIAL_DECLINE;
        }

        // Not found now (and not an error/unknown state): it was in the results before and has left them.
        return ! $currentFound && $previous <= self::NEAR_PAGE_ONE_TO;
    }

    /** @return array<int, string> uids of articles due for review for a reason other than their position */
    private function staleUids(Business $business): array
    {
        return array_values(array_map(
            fn (array $row) => $row['article_uid'],
            array_filter(
                $this->freshness->forBusiness($business),
                fn (array $row) => in_array(ArticleFreshness::REASON_OLD, $row['reasons'], true)
                    || in_array(ArticleFreshness::REASON_CATALOG_CHANGED, $row['reasons'], true),
            ),
        ));
    }

    /** @param  array{topic: string, position: ?int, previous: ?int}  $o */
    private function message(string $kind, array $o): string
    {
        $topic = '"' . $o['topic'] . '"';

        return match ($kind) {
            self::KIND_NEAR_PAGE_ONE => 'Your latest check has this article at position ' . $o['position'] . ' for ' . $topic . '. Updating or supporting this content may help it be found more easily.',
            self::KIND_STALE_AND_DECLINED => 'This article has not been reviewed recently, and its position for ' . $topic . ' moved ' . $this->movement($o) . '. Updating or supporting this content may help.',
            default => 'This article\'s position for ' . $topic . ' moved ' . $this->movement($o) . ' between your last two checks. Reviewing and updating this content may help.',
        };
    }

    /** @param  array{position: ?int, previous: ?int}  $o */
    private function movement(array $o): string
    {
        return $o['position'] === null
            ? 'from ' . $o['previous'] . ' to outside the results checked'
            : 'from ' . $o['previous'] . ' to ' . $o['position'];
    }
}

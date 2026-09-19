<?php

namespace App\Library\Seo;

use App\Enums\Seo\SeoKeywordCoverageStatus;
use App\Models\SeoKeyword;

/**
 * Contract 18 §5.2.3 / §8.4 — Core keyword coverage: does each keyword's
 * phrase appear in the PUBLISHED Website content, and where (page titles, meta
 * descriptions, page text)?
 *
 * PURE. It takes the already-read SeoPublishedContent (immutable published
 * snapshot, via SeoPublishedContentReader) and a keyword list, and returns
 * facts. No query, no write, no network, no clock. It says nothing about
 * position, traffic or indexing — coverage is a content fact only.
 *
 * MATCHING. Both sides are normalized by SeoPhraseNormalizer (the one
 * normalization), then the phrase must appear as WHOLE WORDS: "art" is not
 * found inside "party", but "best bakery" is found in "the best bakery, in
 * town". A Location-attributed keyword is checked against the whole site:
 * Website has no Location pages yet (Contract 18 G-1).
 */
final class SeoKeywordCoverageReader
{
    /**
     * @param  iterable<SeoKeyword>  $keywords
     * @return array<int, SeoKeywordCoverageResult> keyed by keyword id
     */
    public function forKeywords(iterable $keywords, ?SeoPublishedContent $content): array
    {
        $results = [];

        if ($content === null) {
            foreach ($keywords as $keyword) {
                $results[(int) $keyword->id] = new SeoKeywordCoverageResult(SeoKeywordCoverageStatus::NoPublishedWebsite, 0, 0, 0, 0);
            }

            return $results;
        }

        // Normalize every page's text ONCE, however many keywords there are.
        $pages = [];

        foreach ($content->pages as $page) {
            $surfaces = $page->textSurfaces();
            $pages[] = [
                'title' => SeoPhraseNormalizer::normalize($surfaces['title']),
                'description' => SeoPhraseNormalizer::normalize($surfaces['meta_description']),
                'body' => SeoPhraseNormalizer::normalize($surfaces['body']),
            ];
        }

        foreach ($keywords as $keyword) {
            $pattern = $this->wholeWordPattern((string) $keyword->phrase_normalized);
            $title = $description = $body = 0;

            foreach ($pages as $surfaces) {
                $title += $this->found($pattern, $surfaces['title']) ? 1 : 0;
                $description += $this->found($pattern, $surfaces['description']) ? 1 : 0;
                $body += $this->found($pattern, $surfaces['body']) ? 1 : 0;
            }

            $results[(int) $keyword->id] = new SeoKeywordCoverageResult(
                ($title + $description + $body) > 0 ? SeoKeywordCoverageStatus::Covered : SeoKeywordCoverageStatus::NotCovered,
                count($pages),
                $title,
                $description,
                $body,
            );
        }

        return $results;
    }

    private function wholeWordPattern(string $normalizedPhrase): string
    {
        return '/(?<![\p{L}\p{N}])' . preg_quote($normalizedPhrase, '/') . '(?![\p{L}\p{N}])/u';
    }

    private function found(string $pattern, string $text): bool
    {
        return $text !== '' && preg_match($pattern, $text) === 1;
    }
}

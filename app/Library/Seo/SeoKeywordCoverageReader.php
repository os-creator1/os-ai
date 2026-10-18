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
 * town".
 *
 * HIDDEN PAGES DO NOT COVER A KEYWORD. A page the owner marked hidden from
 * search can never be found for the phrase, so a phrase that appears only on
 * such pages is reported as OnlyOnHiddenPages, not Covered, and the per-place
 * counts describe the pages search engines can actually list.
 *
 * A Location-attributed keyword is checked against the whole published site.
 * Website now generates service-area pages, but the published snapshot records
 * no page-to-Location link, and guessing one from a slug or from page text
 * would be inference presented as fact (Contract 18 G-1).
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
                'hidden' => $page->noindex,
                'title' => SeoPhraseNormalizer::normalize($surfaces['title']),
                'description' => SeoPhraseNormalizer::normalize($surfaces['meta_description']),
                'body' => SeoPhraseNormalizer::normalize($surfaces['body']),
            ];
        }

        foreach ($keywords as $keyword) {
            $pattern = $this->wholeWordPattern((string) $keyword->phrase_normalized);
            $title = $description = $body = 0;
            $onHiddenPage = false;

            foreach ($pages as $surfaces) {
                $inTitle = $this->found($pattern, $surfaces['title']);
                $inDescription = $this->found($pattern, $surfaces['description']);
                $inBody = $this->found($pattern, $surfaces['body']);

                if ($surfaces['hidden']) {
                    $onHiddenPage = $onHiddenPage || $inTitle || $inDescription || $inBody;

                    continue;
                }

                $title += $inTitle ? 1 : 0;
                $description += $inDescription ? 1 : 0;
                $body += $inBody ? 1 : 0;
            }

            $status = match (true) {
                ($title + $description + $body) > 0 => SeoKeywordCoverageStatus::Covered,
                $onHiddenPage => SeoKeywordCoverageStatus::OnlyOnHiddenPages,
                default => SeoKeywordCoverageStatus::NotCovered,
            };

            $results[(int) $keyword->id] = new SeoKeywordCoverageResult($status, count($pages), $title, $description, $body);
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

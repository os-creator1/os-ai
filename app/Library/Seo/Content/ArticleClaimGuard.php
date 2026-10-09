<?php

namespace App\Library\Seo\Content;

use App\Models\Business;

/**
 * SEO Content Engine V1 — deterministic protection against unsupported claims in article text.
 *
 * An article may only state what the Business has actually provided (its catalog prices, its services,
 * its areas). This scans text for the kinds of claim an AI most often invents and a Business can be held
 * responsible for, and reports each one. It runs on AI output and on the owner's own edits alike, live —
 * nothing is stored — so fixing the sentence clears the finding.
 *
 * `hard` findings block publishing (a dollar figure that is in no catalog item, years in business,
 * awards, review scores, customer counts, guarantees). `soft` findings are shown as "needs attention"
 * but do not block (percentages, superlatives, quotations).
 *
 * This is a pattern net, not a proof of truth: it cannot know that a sentence is accurate. What it
 * guarantees is that the specific invented-fact shapes above never reach a published page unreviewed.
 */
final class ArticleClaimGuard
{
    public function __construct(private readonly ArticleCatalogFacts $catalog, private readonly ConfirmedBusinessClaims $confirmed)
    {
    }

    /**
     * @return array<int, array{kind: string, excerpt: string, hard: bool, message: string}>
     */
    public function scan(Business $business, ?string $text): array
    {
        $text = ArticleMarkdown::plainText($text);

        if ($text === '') {
            return [];
        }

        $findings = [];
        $add = function (string $kind, string $excerpt, bool $hard, string $message) use (&$findings): void {
            $findings[$kind . '|' . mb_strtolower($excerpt)] = ['kind' => $kind, 'excerpt' => $excerpt, 'hard' => $hard, 'message' => $message];
        };

        $allowed = $this->catalog->allowedAmounts($business);

        if (preg_match_all('/(?:[$€£]\s?|USD\s?)(\d[\d,]*(?:\.\d{1,2})?)/i', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $amount = str_replace(',', '', $hit[1]);

                if (! in_array($amount, $allowed, true) && ! in_array((string) (int) $amount, $allowed, true)) {
                    $add('price', $hit[0], true, 'The price "' . $hit[0] . '" is not in your Packages & Products. Use a price from your catalog, or describe cost without a number.');
                }
            }
        }

        $rules = [
            ['years_in_business', '/\b(?:over\s+|more\s+than\s+)?\d{1,3}\+?\s+years?\s+(?:of\s+)?(?:experience|in\s+business|serving)\b/i', true, 'Years in business is not a fact you have provided.'],
            ['years_in_business', '/\b(?:since|established\s+in|founded\s+in)\s+(?:19|20)\d{2}\b/i', true, 'A founding year is not a fact you have provided.'],
            ['award', '/\baward[- ]winning\b|\bvoted\s+(?:the\s+)?(?:best|#\s?1)\b|\bwon\s+(?:the\s+)?\w+\s+award\b/i', true, 'Awards are not facts you have provided.'],
            ['review_score', '/\b\d(?:\.\d)?\s*(?:\/\s*5|out\s+of\s+5)\b|\b\d(?:\.\d)?[- ]stars?\b|\bfive[- ]stars?\b/i', true, 'Review scores are not facts you have provided.'],
            ['customer_count', '/\b\d[\d,]*\+?\s+(?:happy\s+|satisfied\s+)?(?:customers|clients|events|weddings|parties|guests)\b/i', true, 'Customer or event counts are not facts you have provided.'],
            ['guarantee', '/\bguarantee[sd]?\b|\bmoney[- ]back\b/i', true, 'Guarantees are not claims you have provided.'],
            ['celebrity', '/\bcelebrit(?:y|ies)\b|\bas\s+seen\s+(?:on|in)\b/i', true, 'Celebrity or press claims are not facts you have provided.'],
            ['statistic', '/\b\d{1,3}(?:\.\d+)?\s?%/', false, 'A percentage needs a source you can point to. Remove it or confirm it is yours.'],
            ['superlative', '/\b(?:#\s?1|number\s+one|the\s+best\s+in|leading|premier|unmatched|unrivaled|unrivalled)\b/i', false, 'Superlatives like "best" or "leading" are claims that need proof.'],
        ];

        $confirmedYears = $this->confirmed->years($business);

        foreach ($rules as [$kind, $pattern, $hard, $message]) {
            if (preg_match_all($pattern, $text, $m, PREG_SET_ORDER)) {
                foreach ($m as $hit) {
                    if ($kind === 'years_in_business' && $this->isConfirmedYears($hit[0], $confirmedYears)) {
                        continue;
                    }

                    $add($kind, trim($hit[0]), $hard, $message);
                }
            }
        }

        return array_values($findings);
    }

    /**
     * Quotation blocks in the raw Markdown: a fake quote is the classic invented-testimonial shape.
     */
    /**
     * "15 years of experience" is allowed when 15 is the number of years the owner has confirmed in the Knowledge Profile
     * ("over/more than N" when N is at most that). A founding year ("since 2009") is never allowed this way.
     */
    private function isConfirmedYears(string $phrase, ?int $confirmedYears): bool
    {
        if ($confirmedYears === null || preg_match('/\b(?:since|established|founded)\b/i', $phrase) === 1 || preg_match('/\d{1,3}/', $phrase, $n) !== 1) {
            return false;
        }

        $claimed = (int) $n[0];

        return $claimed === $confirmedYears
            || ($claimed < $confirmedYears && preg_match('/\b(?:over|more\s+than)\b/i', $phrase) === 1);
    }

    public function hasQuotation(?string $markdown): bool
    {
        return preg_match('/^\s*>\s+\S/m', (string) $markdown) === 1;
    }

    /** @return array<int, array{kind: string, excerpt: string, hard: bool, message: string}> */
    public function hardFindings(Business $business, ?string $text): array
    {
        return array_values(array_filter($this->scan($business, $text), fn (array $f) => $f['hard']));
    }
}

<?php

namespace App\Library\Seo\Content\Autopilot;

use App\Library\Seo\Content\ArticleAnalyzer;
use App\Library\Seo\Content\ArticleCannibalizationGuard;
use App\Library\Seo\Content\ArticleClaimGuard;
use App\Library\Seo\Content\ArticleMarkdown;
use App\Models\Business;
use App\Models\WebsiteArticle;

/**
 * Content Autopilot - validate a drafted article against its brief. Entirely deterministic: no model judges its own work.
 *
 * HARD findings stop the article (one repair attempt, then it is rejected and never published):
 *   - everything the Content Engine already refuses to publish (title, slug, too short, an unsupported price / years / award /
 *     review score / customer count / guarantee / celebrity - ArticleAnalyzer::blockers, with the owner's confirmed years allowed);
 *   - shorter than the brief's minimum; a quotation; a phrase the niche or the owner prohibits;
 *   - a strong overlap with the Business's pages or other articles.
 *
 * SOFT findings never block a draft but keep it from publishing itself - the owner decides:
 *   - the claim guard's soft findings (a percentage, a superlative);
 *   - a number that appears in no fact of the brief (small counts and the current year are fine);
 *   - no internal link although the brief offered some; the topic missing from the title.
 *
 * `ready` means no findings at all: the only state in which Autopilot may publish without the owner (and only when the
 * scheduler's own rules - niche policy, trust ramp, cadence - agree).
 */
final class AutopilotArticleValidator
{
    private const SMALL_NUMBER = 12;

    public function __construct(
        private readonly ArticleAnalyzer $analyzer,
        private readonly ArticleClaimGuard $claims,
        private readonly ArticleCannibalizationGuard $guard,
        private readonly ContentPolicy $policy,
    ) {
    }

    /**
     * @param  array<string, mixed>  $brief
     * @return array{hard: list<string>, soft: list<string>, ready: bool}
     */
    public function validate(Business $business, WebsiteArticle $article, array $brief): array
    {
        $hard = [];
        $soft = [];
        $body = (string) $article->body;
        $everything = $body . "\n" . $article->title . "\n" . $article->excerpt . "\n" . $article->meta_description;

        foreach ($this->analyzer->blockers($business, $article) as $blocker) {
            $hard[] = $blocker;
        }

        $minimum = (int) ($brief['length']['min_words'] ?? 0);

        if ($minimum > 0 && ArticleMarkdown::wordCount($body) < $minimum) {
            $hard[] = "The article is shorter than the {$minimum} words the brief asks for.";
        }

        if ($this->claims->hasQuotation($body)) {
            $hard[] = 'The article contains a quotation or testimonial, which is not a provided fact.';
        }

        foreach ($this->policy->prohibitedPhrases($business) as $phrase) {
            if (mb_stripos($everything, $phrase) !== false) {
                $hard[] = 'The article uses a phrase this Business never wants used: "' . $phrase . '".';
            }
        }

        $overlap = $this->guard->check($business, array_filter([(string) $article->title, (string) $article->primary_topic]), $article->exists ? (int) $article->id : null);

        if ($overlap->strong()) {
            $hard[] = $overlap->strongFindings()[0]['reason'];
        }

        foreach ($this->claims->scan($business, $body) as $finding) {
            if (! $finding['hard']) {
                $soft[] = $finding['message'];
            }
        }

        foreach ($this->unsupportedNumbers($body, $brief) as $number) {
            $soft[] = 'The number "' . $number . '" is not one of the facts provided. Confirm it or remove it.';
        }

        if (! empty($brief['links']) && ArticleMarkdown::internalRefs($body) === []) {
            $soft[] = 'The article does not link to any of your pages.';
        }

        $hard = array_values(array_unique($hard));
        $soft = array_values(array_unique($soft));

        return ['hard' => $hard, 'soft' => $soft, 'ready' => $hard === [] && $soft === []];
    }

    /** @return list<string> */
    private function unsupportedNumbers(string $body, array $brief): array
    {
        $allowed = [];
        $haystack = json_encode([$brief['facts'] ?? [], $brief['topic'] ?? '', $brief['questions'] ?? [], $brief['audience'] ?? '']);

        preg_match_all('/\d[\d,]*(?:\.\d+)?/', (string) $haystack, $known);

        foreach ($known[0] as $number) {
            $allowed[$this->normalise($number)] = true;
        }

        $year = (int) now()->format('Y');
        $allowed[(string) $year] = $allowed[(string) ($year + 1)] = true;

        preg_match_all('/(?<![\w.])\d[\d,]*(?:\.\d+)?(?![\w])/', ArticleMarkdown::plainText($body), $found);

        $unsupported = [];

        foreach ($found[0] as $number) {
            $value = $this->normalise($number);

            if ((float) $value <= self::SMALL_NUMBER || isset($allowed[$value])) {
                continue;
            }

            $unsupported[$value] = $number;
        }

        return array_values($unsupported);
    }

    private function normalise(string $number): string
    {
        $number = str_replace(',', '', $number);

        return str_contains($number, '.') ? rtrim(rtrim($number, '0'), '.') : $number;
    }
}

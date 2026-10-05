<?php

namespace App\Library\Website\Seo;

/**
 * Website V1 closure — FAQPage (schema.org) structured data, and only ever the FAQ a visitor can
 * actually read on that page. It is built from the very `faq` sections the page renders (the same
 * redacted array the template receives), so the markup cannot drift from the visible text: every
 * Question/Answer here is one rendered `<summary>` / `<p>` pair, verbatim. A page with no FAQ
 * section — or whose FAQ section has no complete question + answer — gets nothing, and there is no
 * site-wide FAQ block. The caller decides indexability (like every other schema block here).
 */
final class WebsiteFaqStructuredData
{
    /**
     * @param  array<int, array{type?: string, data?: array<string, mixed>}>  $sections  the page's sections as rendered
     * @return ?array<string, mixed>
     */
    public function build(array $sections): ?array
    {
        $entities = [];

        foreach ($sections as $section) {
            if (($section['type'] ?? null) !== 'faq') {
                continue;
            }

            foreach ($section['data']['items'] ?? [] as $item) {
                $question = trim((string) ($item['question'] ?? ''));
                $answer = trim((string) ($item['answer'] ?? ''));

                if ($question === '' || $answer === '') {
                    continue;
                }

                $entities[] = [
                    '@type' => 'Question',
                    'name' => $question,
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => $answer],
                ];
            }
        }

        if ($entities === []) {
            return null;
        }

        return ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $entities];
    }
}

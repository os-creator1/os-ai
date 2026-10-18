<?php

namespace App\Library\Website\Seo;

/**
 * FAQPage (schema.org) structured data, and only ever the FAQ a visitor can actually read on that page.
 * It is built from the very `faq` sections the page renders (the same redacted array the template
 * receives), so the markup cannot drift from the visible text: every Question/Answer here is one
 * rendered `<summary>` / `<p>` pair (whitespace-normalised, in page order, every visible pair exactly once). A page
 * with no FAQ section, or whose FAQ section has no complete question + answer, gets nothing, and
 * there is no site-wide FAQ block. The caller decides indexability (like every other schema block).
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

            foreach ((array) ($section['data']['items'] ?? []) as $item) {
                $question = self::text($item['question'] ?? null);
                $answer = self::text($item['answer'] ?? null);

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

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim((string) preg_replace('/\s+/u', ' ', $value)) : '';
    }
}

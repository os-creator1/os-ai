<?php

namespace App\Library\Website\Seo;

/**
 * SEO V1 final — FAQPage (schema.org) structured data, only ever for a page that
 * actually renders a `faq` section, and built from exactly the questions and
 * answers that section prints (the same escaped text, whitespace-normalised) —
 * never emitted site-wide, never invented, never from AI at render time.
 *
 * A page with several FAQ sections lists every question once, in page order.
 * A question or answer left blank in the section is skipped (it is not shown
 * either); a page with no usable question gets no FAQPage at all.
 */
final class WebsiteFaqStructuredData
{
    /**
     * @param  array<int, array{type?: string, data?: array<string, mixed>}>  $sections  the page's sections as rendered
     * @return ?array<string, mixed>
     */
    public static function build(array $sections): ?array
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

                $entities[$question] ??= [
                    '@type' => 'Question',
                    'name' => $question,
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => $answer],
                ];
            }
        }

        if ($entities === []) {
            return null;
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => array_values($entities),
        ];
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim((string) preg_replace('/\s+/u', ' ', $value)) : '';
    }
}

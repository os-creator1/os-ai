<?php

namespace App\Library\Seo\Content;

use Illuminate\Support\HtmlString;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;

/**
 * SEO Content Engine V1 — the ONE place an article body (safe Markdown) becomes HTML, headings,
 * plain text or a list of links.
 *
 * Safety: raw HTML in the body is stripped, unsafe link schemes (javascript:, data:) are refused, and
 * images are not allowed in the body (the featured image is the article's only picture, and it goes
 * through the Website's responsive-image pipeline). A body `# H1` is demoted to H2 because the article
 * page owns the one H1.
 *
 * Internal links are STABLE REFERENCES, never raw URLs: `[anchor](page:<page uid>)` and
 * `[anchor](article:<article uid>)`. They are resolved to the correct address for the surface being
 * rendered (platform path, custom domain, preview). A reference that does not resolve — an unpublished,
 * archived, deleted or other-Business target — renders as plain text instead of a dead or stale link.
 */
final class ArticleMarkdown
{
    private const REF_PATTERN = '/\[([^\]]+)\]\((page|article):([0-9a-fA-F-]{36})\)/';

    /**
     * @param  null|callable(string $type, string $uid): ?string  $resolveRef  null = every reference is dropped to plain text
     */
    public static function toHtml(?string $markdown, ?callable $resolveRef = null): HtmlString
    {
        $markdown = self::withoutImages((string) $markdown);

        $markdown = (string) preg_replace_callback(self::REF_PATTERN, function (array $m) use ($resolveRef) {
            $url = $resolveRef !== null ? $resolveRef($m[2], strtolower($m[3])) : null;

            return $url === null || $url === '' ? $m[1] : '[' . $m[1] . '](' . $url . ')';
        }, $markdown);

        $environment = new Environment(['html_input' => 'strip', 'allow_unsafe_links' => false]);
        $environment->addExtension(new CommonMarkCoreExtension());

        $html = (new MarkdownConverter($environment))->convert($markdown)->getContent();

        // The article page owns the single H1.
        $html = (string) preg_replace(['/<h1(\s|>)/', '/<\/h1>/'], ['<h2$1', '</h2>'], $html);

        return new HtmlString($html);
    }

    /** @return array<int, array{level: int, text: string}> */
    public static function headings(?string $markdown): array
    {
        $out = [];

        foreach (preg_split('/\R/', (string) $markdown) ?: [] as $line) {
            if (preg_match('/^(#{1,6})\s+(.+?)\s*#*\s*$/', $line, $m) === 1) {
                $out[] = ['level' => strlen($m[1]), 'text' => trim($m[2])];
            }
        }

        return $out;
    }

    public static function plainText(?string $markdown): string
    {
        $text = self::withoutImages((string) $markdown);
        $text = (string) preg_replace(self::REF_PATTERN, '$1', $text);
        $text = (string) preg_replace('/\[([^\]]+)\]\([^)]*\)/', '$1', $text);
        $text = (string) preg_replace('/^[#>\-\*\+\d\.\)\s]+(?=\S)/m', '', $text);
        $text = (string) preg_replace('/[*_`~]+/', '', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    public static function wordCount(?string $markdown): int
    {
        $text = self::plainText($markdown);

        return $text === '' ? 0 : count(preg_split('/\s+/u', $text) ?: []);
    }

    /** @return array<int, array{type: string, uid: string, anchor: string}> */
    public static function internalRefs(?string $markdown): array
    {
        preg_match_all(self::REF_PATTERN, (string) $markdown, $matches, PREG_SET_ORDER);

        return array_map(fn (array $m) => ['type' => $m[2], 'uid' => strtolower($m[3]), 'anchor' => $m[1]], $matches);
    }

    /** @return array<int, string> every http(s) URL the body links to directly */
    public static function externalLinks(?string $markdown): array
    {
        preg_match_all('/\]\((https?:\/\/[^)\s]+)\)/i', (string) $markdown, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    private static function withoutImages(string $markdown): string
    {
        return (string) preg_replace('/!\[[^\]]*\]\([^)]*\)/', '', $markdown);
    }
}

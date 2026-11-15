<?php

namespace App\Library\BusinessEmail;

/**
 * Turns what a person typed in the Email composer into a plain-text body and, only when they used formatting, a safe HTML
 * alternative.
 *
 * The browser never sends HTML. The textarea carries a tiny, visible markup the toolbar writes:
 *     **bold**    _italic_    [label](https://address)
 * Everything else is ESCAPED, so no tag, attribute or script a user (or a pasted email) contains can ever reach the HTML
 * part: the only tags ever generated are <strong>, <em>, <a href rel> and <br>. Links must be http(s) or mailto and are
 * checked against header/control characters. The plain-text part (what the ledger stores and what every provider
 * without HTML support sends) has the markers removed ("label (https://address)" for links), so the text alone always
 * reads naturally.
 */
final class SafeEmailMarkup
{
    private const LINK = '/\[([^\[\]\r\n]{1,200})\]\((https?:\/\/[^\s()<>"\']{1,1000}|mailto:[^\s()<>"\']{3,200})\)/i';
    private const BOLD = '/\*\*(?=\S)(.+?)(?<=\S)\*\*/s';
    private const ITALIC = '/(?<![\p{L}\p{N}_])_(?=\S)([^_\r\n]+?)(?<=\S)_(?![\p{L}\p{N}_])/u';

    /** @return array{text: string, html: ?string} html is null when nothing was formatted */
    public static function convert(string $body): array
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);

        $formatted = preg_match(self::LINK, $body) === 1 || preg_match(self::BOLD, $body) === 1 || preg_match(self::ITALIC, $body) === 1;

        return ['text' => self::plain($body), 'html' => $formatted ? self::html($body) : null];
    }

    public static function plain(string $body): string
    {
        $text = (string) preg_replace_callback(self::LINK, static fn (array $m): string => $m[1] === $m[2] ? $m[2] : $m[1] . ' (' . $m[2] . ')', $body);
        $text = (string) preg_replace(self::BOLD, '$1', $text);
        $text = (string) preg_replace(self::ITALIC, '$1', $text);

        return $text;
    }

    public static function html(string $body): string
    {
        // Links are lifted out first (their label/URL must not be re-interpreted), everything is escaped, then re-inserted.
        $links = [];
        $body = (string) preg_replace_callback(self::LINK, static function (array $m) use (&$links): string {
            $links[] = '<a href="' . e($m[2]) . '" rel="noopener noreferrer">' . e($m[1]) . '</a>';

            return "\u{0001}" . (count($links) - 1) . "\u{0002}";
        }, $body);

        $html = e($body);
        $html = (string) preg_replace(self::BOLD, '<strong>$1</strong>', $html);
        $html = (string) preg_replace(self::ITALIC, '<em>$1</em>', $html);
        $html = (string) preg_replace_callback("/\u{0001}(\\d+)\u{0002}/u", static fn (array $m): string => $links[(int) $m[1]] ?? '', $html);

        return nl2br($html, false);
    }
}

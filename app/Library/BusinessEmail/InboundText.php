<?php

namespace App\Library\BusinessEmail;

/**
 * Text hygiene for UNTRUSTED inbound mail, shared by the non-Google adapters:
 * valid UTF-8 for any input, HTML reduced to inert text, and storage-byte
 * bounds. Output is still HTML-escaped at render time; this only guarantees
 * the database never sees invalid or oversized data.
 */
final class InboundText
{
    /** Valid UTF-8 for any input; declared charset first, else UTF-8 as-is, else Windows-1252. */
    public static function utf8(string $value, ?string $charset = null): string
    {
        $charset = $charset === null ? null : strtoupper(trim($charset));
        $converted = null;

        if ($charset !== null && $charset !== '' && ! in_array($charset, ['UTF-8', 'UTF8', 'US-ASCII', 'ASCII'], true)) {
            try {
                $converted = mb_convert_encoding($value, 'UTF-8', $charset);
            } catch (\Throwable) {
                $converted = null;
            }
        }

        if ($converted === null) {
            $converted = mb_check_encoding($value, 'UTF-8') ? $value : mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
        }

        return str_replace("\0", '', mb_scrub((string) $converted, 'UTF-8'));
    }

    public static function htmlToText(string $html): string
    {
        $html = (string) preg_replace('#<(script|style)\b.*?</\1>#is', '', $html);
        $html = (string) preg_replace('#<br\s*/?>|</p>|</div>#i', "\n", $html);

        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** One header line: no control characters, bounded by BYTES without splitting a character. */
    public static function headerLine(?string $value, int $maxBytes): string
    {
        $value = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', self::utf8((string) $value)));

        return mb_strcut($value, 0, $maxBytes, 'UTF-8');
    }

    public static function body(string $text): string
    {
        $maxChars = max(100, (int) config('business_email.inbound.max_body_length', 20000));
        $maxBytes = min(65000, max(100, (int) config('business_email.inbound.max_body_bytes', 60000)));

        return mb_strcut(mb_substr($text, 0, $maxChars), 0, $maxBytes, 'UTF-8');
    }
}

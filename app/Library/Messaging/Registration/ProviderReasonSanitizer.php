<?php

namespace App\Library\Messaging\Registration;

/**
 * Makes a provider-supplied free-text reason safe to store and show a customer:
 * plain text only, bounded, with anything credential-shaped removed. Never
 * invents text — a blank result is null.
 */
final class ProviderReasonSanitizer
{
    public const MAX_LENGTH = 500;

    public static function clean(?string $reason): ?string
    {
        if ($reason === null) {
            return null;
        }

        $text = strip_tags($reason);
        $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? '';
        // Credential-shaped tokens and bearer headers are never kept.
        $text = preg_replace('/\b(?:Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]{8,}/i', '[removed]', $text) ?? $text;
        $text = preg_replace('/\b(?:KEY|sk|pk|tok|token)[_-]?[A-Za-z0-9]{16,}\b/', '[removed]', $text) ?? $text;
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        if ($text === '') {
            return null;
        }

        return mb_substr($text, 0, self::MAX_LENGTH);
    }

    /** Customer wording: camelCase API field names become words, first letter capitalised. */
    public static function humanize(string $reason): string
    {
        $text = preg_replace_callback('/\b[a-z]+(?:[A-Z][a-z0-9]*)+\b/', function (array $m): string {
            $words = preg_split('/(?=[A-Z])/', $m[0]) ?: [$m[0]];

            return strtolower(implode(' ', $words));
        }, $reason) ?? $reason;

        return mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
    }
}

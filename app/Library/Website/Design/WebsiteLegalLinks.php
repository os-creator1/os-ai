<?php

namespace App\Library\Website\Design;

/**
 * The owner's own legal-notice links for a published site: a privacy notice and (optionally) terms. They are
 * ADDRESSES the owner supplies — nothing here writes, copies or implies any legal text, and the platform's own
 * policies are never substituted. Stored with the other owner tokens in the Website theme (`privacy_notice_url`,
 * `terms_url`), so they survive a rebuild or template change and are frozen into each published revision.
 */
final class WebsiteLegalLinks
{
    public const KEYS = ['privacy_notice_url', 'terms_url'];

    /**
     * Only an absolute https address with a host is ever linked (never javascript:, data:, relative or http).
     */
    public static function clean(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '' || mb_strlen($value) > 500 || preg_match('/\s/', $value) === 1) {
            return null;
        }

        $parts = parse_url($value);

        if ($parts === false || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return $value;
    }

    /**
     * @param  ?array<string, mixed>  $theme
     * @return array{privacy: ?string, terms: ?string}
     */
    public static function fromTheme(?array $theme): array
    {
        return [
            'privacy' => self::clean($theme['privacy_notice_url'] ?? null),
            'terms' => self::clean($theme['terms_url'] ?? null),
        ];
    }
}

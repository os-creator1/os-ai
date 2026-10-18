<?php

namespace App\Library\ExternalSite\Audit;

/**
 * How much a page matters to a local business, judged from its ADDRESS alone
 * (deterministic; no content, no AI):
 *
 *   home      the home page
 *   key       a page where a visitor contacts, books, requests a quote, compares prices,
 *             or browses what you sell/offer (contact, booking, pricing, services, locations ...)
 *   utility   a page that exists for housekeeping (privacy, terms, thank-you, search, tags ...)
 *   standard  everything else
 *
 * Severity depends on it (see ExternalAuditCatalog): a missing title on the home page
 * or a booking page is a bigger problem than on a privacy policy. The words used are
 * generic English stems; a page can be mis-classified (a page called "contact" is
 * assumed to be a contact page), which is why the importance label is shown beside
 * every finding instead of being hidden.
 */
final class ExternalPageImportance
{
    public const HOME = 'home';

    public const KEY = 'key';

    public const STANDARD = 'standard';

    public const UTILITY = 'utility';

    /** Segment stems of pages where people take the next step (contact / book / buy / enquire). */
    private const KEY_STEMS = ['contact', 'book', 'appointment', 'schedule', 'reserv', 'quote', 'estimate', 'request', 'pricing', 'price', 'rates', 'packages', 'service', 'treatment', 'menu', 'order', 'location', 'areas', 'service-area', 'get-started', 'consult', 'enquir', 'inquir', 'hire', 'rental', 'shop', 'products', 'classes', 'booking'];

    /** The subset of key pages that ARE the conversion step itself (contact or booking). */
    private const CONVERSION_STEMS = ['contact', 'book', 'appointment', 'schedule', 'reserv', 'quote', 'estimate', 'request', 'get-started', 'consult', 'enquir', 'inquir', 'order'];

    private const UTILITY_STEMS = ['privacy', 'terms', 'cookie', 'legal', 'disclaimer', 'accessibility', 'sitemap', 'thank', 'confirmation', 'success', '404', 'search', 'tag', 'category', 'categories', 'author', 'feed', 'login', 'signin', 'unsubscribe', 'refund', 'returns-policy', 'shipping-policy'];

    public static function classify(string $url): string
    {
        $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?: '/'));
        $segments = array_values(array_filter(explode('/', $path), fn (string $s): bool => $s !== ''));

        if ($segments === []) {
            return self::HOME;
        }

        $segments = array_slice($segments, 0, 2);

        foreach ($segments as $segment) {
            if (self::startsWithAny($segment, self::UTILITY_STEMS)) {
                return self::UTILITY;
            }
        }

        if (in_array($segments[0], ['blog', 'news', 'articles', 'posts', 'journal'], true)) {
            return self::STANDARD;
        }

        foreach ($segments as $segment) {
            if (self::startsWithAny($segment, self::KEY_STEMS)) {
                return self::KEY;
            }
        }

        return self::STANDARD;
    }

    /** True for a contact / booking style page (the conversion step itself). */
    public static function isConversionPage(string $url): bool
    {
        $segments = array_slice(array_values(array_filter(explode('/', strtolower((string) (parse_url($url, PHP_URL_PATH) ?: '/'))), fn (string $s): bool => $s !== '')), 0, 2);

        foreach ($segments as $segment) {
            if (self::startsWithAny($segment, self::CONVERSION_STEMS)) {
                return true;
            }
        }

        return false;
    }

    /** True for an address that looks like a location / service-area page. */
    public static function looksLikeLocationPage(string $url): bool
    {
        return preg_match('#/(locations?|areas?|service-areas?|serving|near-me)(/|$)#i', (string) parse_url($url, PHP_URL_PATH)) === 1;
    }

    public static function weight(string $importance): int
    {
        return match ($importance) {
            self::KEY => 4,
            self::HOME => 3,
            self::STANDARD => 2,
            default => 1,
        };
    }

    /** Owner-facing description, used to explain why a finding got its priority. */
    public static function describe(string $importance): string
    {
        return match ($importance) {
            self::HOME => 'your home page, usually the first page visitors and search engines reach',
            self::KEY => 'a page where visitors contact, book or choose what to buy from you',
            self::UTILITY => 'a housekeeping page (such as a policy or confirmation page)',
            default => 'an ordinary content page',
        };
    }

    /** @param list<string> $stems */
    private static function startsWithAny(string $segment, array $stems): bool
    {
        foreach ($stems as $stem) {
            if (str_starts_with($segment, $stem)) {
                return true;
            }
        }

        return false;
    }
}

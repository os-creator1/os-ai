<?php

namespace App\Library\Website\Seo;

/**
 * The page-intent map: what each page of a Website IS and what search it is for.
 *
 * Decided ONCE, when the generator plans a page (from the plan's own page type and the owner's confirmed entity), stored on
 * the page (`page_role`, `page_intent`) and frozen into each published revision. Everything that needs to know "which page
 * owns this search" — the publish-time quality check, the blog's cannibalization guard, the article support-page resolver and
 * the blog call to action — reads this one map instead of guessing from slug spellings.
 *
 * Pure value logic, no queries. Pages written before the map existed (or by the owner) have no stored role; the
 * WebsitePageIntentClassifier derives one from their content and the Business's own services and areas.
 */
final class WebsitePageIntent
{
    public const HOME = 'home';
    public const SERVICES_HUB = 'services_hub';
    public const SERVICE = 'service';
    public const LOCATION = 'location';
    public const AREA_HUB = 'area_hub';
    public const PACKAGES = 'packages';
    public const CONTACT = 'contact';
    public const ABOUT = 'about';
    public const FAQ = 'faq';
    public const GALLERY = 'gallery';
    public const BACKDROPS = 'backdrops';
    public const LEGAL = 'legal';
    public const OTHER = 'other';

    public const ROLES = [
        self::HOME, self::SERVICES_HUB, self::SERVICE, self::LOCATION, self::AREA_HUB, self::PACKAGES, self::CONTACT,
        self::ABOUT, self::FAQ, self::GALLERY, self::BACKDROPS, self::LEGAL, self::OTHER,
    ];

    /** Roles that answer a "hire / book this" search of their own, and so must not be competed with by an article. */
    public const COMMERCIAL = [self::HOME, self::SERVICES_HUB, self::SERVICE, self::LOCATION, self::AREA_HUB, self::PACKAGES];

    /** The generator's plan page type -> role. */
    public static function roleForPlanType(string $pageType, bool $isHome = false): string
    {
        if ($isHome) {
            return self::HOME;
        }

        return match ($pageType) {
            'home' => self::HOME,
            'services_overview' => self::SERVICES_HUB,
            'service_detail' => self::SERVICE,
            'location' => self::LOCATION,
            'packages' => self::PACKAGES,
            'contact' => self::CONTACT,
            'about' => self::ABOUT,
            'faq' => self::FAQ,
            'gallery' => self::GALLERY,
            'backdrops' => self::BACKDROPS,
            default => self::OTHER,
        };
    }

    /**
     * The role and intent to store for one planned page.
     *
     * @param  array<string, mixed>  $planPage  a WebsitePageStrategy plan entry (page_type, is_home, entity)
     * @return array{role: string, intent: ?array{place?: string, service_uid?: string}}
     */
    public static function forPlanPage(array $planPage): array
    {
        $entity = is_array($planPage['entity'] ?? null) ? $planPage['entity'] : [];
        $intent = array_filter([
            'place' => isset($entity['area']) ? (string) $entity['area'] : (isset($entity['city']) ? (string) $entity['city'] : null),
            'service_uid' => isset($entity['service_uid']) ? (string) $entity['service_uid'] : null,
        ], fn ($value) => $value !== null && $value !== '');

        return ['role' => self::roleForPlanType((string) ($planPage['page_type'] ?? ''), (bool) ($planPage['is_home'] ?? false)), 'intent' => $intent === [] ? null : $intent];
    }

    public static function validRole(mixed $role): ?string
    {
        return is_string($role) && in_array($role, self::ROLES, true) ? $role : null;
    }

    /** @return ?array{place?: string, service_uid?: string} */
    public static function cleanIntent(mixed $intent): ?array
    {
        if (! is_array($intent)) {
            return null;
        }

        $clean = [];

        foreach (['place', 'service_uid'] as $key) {
            $value = trim((string) ($intent[$key] ?? ''));

            if ($value !== '') {
                $clean[$key] = mb_substr($value, 0, 120);
            }
        }

        return $clean === [] ? null : $clean;
    }

    /**
     * The address conventions the generator itself has always used. Used ONLY for a page that has no stored role, and
     * never trusted over one: a site generated before the map existed still classifies its generated pages correctly.
     */
    public static function legacyRole(bool $isHome, string $slug): ?string
    {
        return match (true) {
            $isHome => self::HOME,
            $slug === 'services' => self::SERVICES_HUB,
            str_starts_with($slug, 'service-') => self::SERVICE,
            str_starts_with($slug, 'serving-') => self::LOCATION,
            $slug === 'packages' => self::PACKAGES,
            $slug === 'photo-booth-contact' => self::CONTACT,
            $slug === 'photo-booth-about' => self::ABOUT,
            $slug === 'photo-booth-faq' => self::FAQ,
            $slug === 'gallery' => self::GALLERY,
            $slug === 'backdrops' => self::BACKDROPS,
            default => null,
        };
    }

    /** A snapshot page's stored role, else the generator's own legacy address conventions, else null. */
    public static function roleOf(array $page): ?string
    {
        return self::validRole($page['role'] ?? null) ?? self::legacyRole(! empty($page['is_home']), (string) ($page['slug'] ?? ''));
    }

    /**
     * The page a "see our packages / services" call to action should lead to, found by what the page IS, never by
     * its address: the packages page, else any page that lists catalog packages, else the services hub.
     *
     * @param  array<int, array<string, mixed>>  $pages  snapshot pages
     * @return array{page: array<string, mixed>, label: string}|null
     */
    public static function offeringsPage(array $pages): ?array
    {
        $live = array_values(array_filter($pages, fn ($page) => is_array($page) && empty($page['is_home']) && empty($page['seo']['noindex'])));

        foreach ($live as $page) {
            if (self::roleOf($page) === self::PACKAGES) {
                return ['page' => $page, 'label' => 'See packages'];
            }
        }

        foreach ($live as $page) {
            foreach ((array) ($page['sections'] ?? []) as $section) {
                if (($section['type'] ?? null) === 'services' && collect($section['data']['items'] ?? [])->contains(fn ($item) => ! empty($item['catalog_item_uid']))) {
                    return ['page' => $page, 'label' => 'See packages'];
                }
            }
        }

        foreach ($live as $page) {
            if (self::roleOf($page) === self::SERVICES_HUB) {
                return ['page' => $page, 'label' => 'See our services'];
            }
        }

        return null;
    }
}

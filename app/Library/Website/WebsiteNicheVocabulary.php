<?php

namespace App\Library\Website;

/**
 * The niche-specific ADDRESSES and WORDS of a generated site's fixed pages (About, FAQ, Contact, call-to-action).
 *
 * The page planner historically named these pages "photo-booth-about / -faq / -contact". A Dog Boarding site must not
 * publish those URLs, so a niche may map each fixed page ROLE to its own slug and title. Everything that recognises a
 * fixed page by its address (navigation, the site call-to-action, SEO page intent) asks this class instead of comparing
 * a literal, so both address families classify identically and every existing Photo Booth site is untouched: with no
 * niche (or a niche that has no vocabulary) the original slugs and wording apply unchanged.
 */
final class WebsiteNicheVocabulary
{
    public const DOG_BOARDING = 'dog_boarding';

    /** The original generator addresses, by role. */
    private const DEFAULT_SLUGS = ['about' => 'photo-booth-about', 'faq' => 'photo-booth-faq', 'contact' => 'photo-booth-contact'];

    private const NICHES = [
        self::DOG_BOARDING => [
            'slugs' => ['about' => 'about-us', 'faq' => 'faqs', 'contact' => 'request-a-stay'],
            'titles' => ['about' => 'About Us', 'faq' => 'FAQs', 'contact' => 'Request a Stay', 'packages' => 'Rates & Packages', 'services_overview' => 'Our Services', 'gallery' => 'Gallery'],
            'cta_label' => 'Request a stay',
        ],
    ];

    /** Every non-default fixed slug, so a generated slug can never land on one. @return list<string> */
    public static function reservedSlugs(): array
    {
        $out = [];

        foreach (self::NICHES as $niche) {
            array_push($out, ...array_values($niche['slugs']));
        }

        return array_values(array_unique($out));
    }

    public static function hasVocabulary(?string $nicheKey): bool
    {
        return $nicheKey !== null && isset(self::NICHES[$nicheKey]);
    }

    /** The address of a fixed page ('about' | 'faq' | 'contact') for a niche, else the original one. */
    public static function slugFor(string $role, ?string $nicheKey): string
    {
        return self::NICHES[(string) $nicheKey]['slugs'][$role] ?? self::DEFAULT_SLUGS[$role];
    }

    /** 'about' | 'faq' | 'contact' for ANY niche's fixed address (the original or a niche's own), else null. */
    public static function roleForSlug(string $slug): ?string
    {
        $role = array_search($slug, self::DEFAULT_SLUGS, true);

        if ($role !== false) {
            return $role;
        }

        foreach (self::NICHES as $niche) {
            $role = array_search($slug, $niche['slugs'], true);

            if ($role !== false) {
                return $role;
            }
        }

        return null;
    }

    /** Owner-facing label for the site-wide call to action that leads to the contact page. */
    public static function contactCtaLabel(string $slug, bool $hasForm): string
    {
        foreach (self::NICHES as $niche) {
            if ($slug === $niche['slugs']['contact']) {
                return $hasForm ? $niche['cta_label'] : 'Contact us';
            }
        }

        return $hasForm ? 'Request a quote' : 'Contact us';
    }

    /**
     * Rewrites the fixed pages of a planned page set to the niche's own addresses and titles. A set for a niche with no
     * vocabulary is returned untouched. Service pages in a niche with a vocabulary drop the "service-" address prefix
     * (the owner's own service name is already a clear, descriptive address) unless that would collide.
     *
     * @param  array<int, array<string, mixed>>  $plan
     * @return array<int, array<string, mixed>>
     */
    public static function apply(array $plan, ?string $nicheKey): array
    {
        if (! self::hasVocabulary($nicheKey)) {
            return $plan;
        }

        $niche = self::NICHES[$nicheKey];
        $taken = array_values(array_filter(array_map(fn (array $page) => $page['slug'] ?? null, $plan)));

        foreach ($plan as $i => $page) {
            $type = (string) ($page['page_type'] ?? '');
            $role = ['about' => 'about', 'faq' => 'faq', 'contact' => 'contact'][$type] ?? null;

            if ($role !== null) {
                $plan[$i]['slug'] = $niche['slugs'][$role];
            }

            if (isset($niche['titles'][$type])) {
                $plan[$i]['title'] = $niche['titles'][$type];
            }

            if ($type === 'service_detail' && is_string($page['slug'] ?? null) && str_starts_with($page['slug'], 'service-')) {
                $bare = substr($page['slug'], strlen('service-'));

                if ($bare !== '' && WebsiteSlugRules::isValid($bare) && ! in_array($bare, $taken, true) && ! in_array($bare, WebsiteSlugRules::GENERATED_FIXED, true)) {
                    $plan[$i]['slug'] = $bare;
                    $taken[] = $bare;
                }
            }
        }

        return $plan;
    }
}

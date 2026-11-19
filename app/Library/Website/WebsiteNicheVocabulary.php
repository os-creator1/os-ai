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

    /** Niche-neutral addresses for any business that is not Photo Booth (and has no vocabulary of its own). */
    public const NEUTRAL = 'neutral';

    /** The niche values of a Photo Booth business: these keep the original generator addresses. */
    private const PHOTO_BOOTH_NICHES = ['photo_booth_service', 'photo_booth'];

    /** The original generator addresses, by role. */
    private const DEFAULT_SLUGS = ['about' => 'photo-booth-about', 'faq' => 'photo-booth-faq', 'contact' => 'photo-booth-contact'];

    private const NICHES = [
        self::NEUTRAL => [
            'slugs' => ['about' => 'about', 'faq' => 'faq', 'contact' => 'contact'],
            'titles' => [],
            'cta_label' => 'Request a quote',
            // An owner-described event page is "<occasion>-photo-booth-rental" for a Photo Booth; another niche keeps just the occasion.
            'strip_event_suffix' => true,
        ],
        self::DOG_BOARDING => [
            'slugs' => ['about' => 'about-us', 'faq' => 'faqs', 'contact' => 'request-a-stay'],
            'titles' => ['about' => 'About Us', 'faq' => 'FAQs', 'contact' => 'Request a Stay', 'packages' => 'Rates & Packages', 'services_overview' => 'Our Services', 'gallery' => 'Gallery'],
            'cta_label' => 'Request a stay',
            'bare_service_slugs' => true,
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
     * The vocabulary a business's NEW pages use: its template's own niche when that has a vocabulary (Dog Boarding), else the
     * business's own niche — Photo Booth (or no niche at all) keeps the original addresses untouched, every other niche gets the
     * neutral ones, so a tutoring studio on the Photo Booth template never publishes "/photo-booth-contact".
     */
    public static function nicheFor(?\App\Models\Business $business, ?string $templateNicheKey): ?string
    {
        if (self::hasVocabulary($templateNicheKey)) {
            return $templateNicheKey;
        }

        $niche = $business === null ? null : app(\App\Library\Website\Setup\QuestionnaireResolver::class)->nicheKeyFor($business);

        if ($niche === self::DOG_BOARDING) {
            return self::DOG_BOARDING;
        }

        if ($niche === null || in_array($niche, self::PHOTO_BOOTH_NICHES, true)) {
            return $templateNicheKey;
        }

        return self::NEUTRAL;
    }

    /**
     * An ESTABLISHED site (published, released to search, or generated before) keeps the address its About/FAQ/Contact page
     * already has: a regeneration rewrites that page in place and never renames a customer's URL. A site that has never been
     * published or generated has nothing to preserve (its starter pages are replaced), so it takes the niche's addresses.
     *
     * @param  array<int, array<string, mixed>>  $plan
     * @return array<int, array<string, mixed>>
     */
    public static function keepEstablishedAddresses(array $plan, \App\Models\Website $website): array
    {
        if (! $website->exists) {
            return $plan;
        }

        $established = $website->published_revision_id !== null
            || $website->indexing_released_at !== null
            || \App\Models\WebsiteGuidedGenerationAttempt::where('website_id', $website->id)->where('status', \App\Models\WebsiteGuidedGenerationAttempt::STATUS_SUCCEEDED)->exists();

        if (! $established) {
            return $plan;
        }

        $current = [];
        foreach ($website->pages()->get(['slug', 'page_role', 'is_home']) as $page) {
            if ($page->is_home || (string) $page->slug === '') {
                continue;
            }

            $role = \App\Library\Website\Seo\WebsitePageIntent::roleOf(['role' => $page->page_role, 'slug' => (string) $page->slug, 'is_home' => false]);

            if (in_array($role, ['about', 'faq', 'contact'], true) && ! isset($current[$role])) {
                $current[$role] = (string) $page->slug;
            }
        }

        $taken = array_values(array_filter(array_map(fn (array $page) => $page['slug'] ?? null, $plan)));

        foreach ($plan as $i => $page) {
            $role = ['about' => 'about', 'faq' => 'faq', 'contact' => 'contact'][(string) ($page['page_type'] ?? '')] ?? null;
            $existing = $role === null ? null : ($current[$role] ?? null);

            if ($existing !== null && $existing !== ($page['slug'] ?? null) && ! in_array($existing, $taken, true)) {
                $plan[$i]['slug'] = $existing;
                $taken[] = $existing;
            }
        }

        return $plan;
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

            if (! empty($niche['strip_event_suffix']) && $type === 'event_detail' && is_string($page['slug'] ?? null) && str_ends_with($page['slug'], '-photo-booth-rental')) {
                $bare = substr($page['slug'], 0, -strlen('-photo-booth-rental'));

                if ($bare !== '' && WebsiteSlugRules::isValid($bare) && ! in_array($bare, $taken, true) && ! in_array($bare, WebsiteSlugRules::GENERATED_FIXED, true)) {
                    $plan[$i]['slug'] = $bare;
                    $taken[] = $bare;
                }
            }

            if (! empty($niche['bare_service_slugs']) && $type === 'service_detail' && is_string($page['slug'] ?? null) && str_starts_with($page['slug'], 'service-')) {
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

<?php

namespace App\Enums\Seo;

/**
 * Contract 18 §5.2.4 — the single, honest indexability line on the SEO
 * Overview.
 *
 * Every public Website is served from the platform path and carries a
 * mandatory `noindex` directive until custom domains exist (Website
 * contract §21). SEO therefore never presents a hosted page as indexed,
 * ranked or driving traffic. This is a STATUS, never a finding: it is a
 * property of the platform today, not something the customer did wrong.
 */
enum SeoIndexabilityState: string
{
    case NoPublishedWebsite = 'no_published_website';
    case PlatformPathNotIndexable = 'platform_path_not_indexable';

    public function label(): string
    {
        return match ($this) {
            self::NoPublishedWebsite => 'No published website yet',
            self::PlatformPathNotIndexable => 'Your website is not indexed by search engines yet',
        };
    }

    public function detail(): string
    {
        return match ($this) {
            self::NoPublishedWebsite => 'Publish your website to have something for search engines to find.',
            self::PlatformPathNotIndexable => 'Your site is served from the platform address, which search engines are asked not to index. It can appear in search results once it is connected to your own domain.',
        };
    }
}

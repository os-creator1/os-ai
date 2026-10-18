<?php

namespace App\Enums\Seo;

/**
 * Contract 18 §5.2.4 (revised, SEO V1 final) — the closed set of "can search
 * engines find my website?" states. The words and the page counts that go
 * with each live in App\Library\Seo\SeoIndexability.
 *
 * A site served from the platform path always carries `noindex`; a site served
 * from its Active primary custom domain is indexable page by page, unless the
 * owner marked every page hidden from search. This is a STATUS, never a
 * finding: it describes how the site is set up today.
 */
enum SeoIndexabilityState: string
{
    /** Nothing is published, so there is nothing for a search engine to find. */
    case NoPublishedWebsite = 'no_published_website';

    /** Published, but only at the platform address (no Active primary domain): always noindex. */
    case PlatformPathNotIndexable = 'platform_path_not_indexable';

    /** Published on an Active primary domain, but every page is marked hidden from search. */
    case HiddenFromSearch = 'hidden_from_search';

    /** Published on an Active primary domain with at least one page visible to search engines. */
    case Indexable = 'indexable';
}

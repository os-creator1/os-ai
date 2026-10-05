<?php

namespace App\Library\Seo;

/**
 * SEO V1 final — one SUGGESTED keyword, shown on the Keywords page with an
 * "Add" button. It is only a proposal: nothing here is saved, and adding it
 * goes through the ordinary keyword create endpoint like a keyword typed by
 * hand.
 *
 * `locationUid` is set only when the phrase was filled with the city of one of
 * the actor's own Locations, so adding it attributes the keyword to that
 * Location; a phrase filled from a service name or with no placeholder at all
 * is Business-wide (null).
 */
final class SeoKeywordSuggestion
{
    public function __construct(
        public readonly string $phrase,
        public readonly string $intent,
        public readonly ?string $locationUid = null,
        public readonly ?string $locationName = null,
    ) {
    }

    /** Plain-word label for the intent a niche strategy attaches to a pattern. */
    public function intentLabel(): string
    {
        return match ($this->intent) {
            'transactional' => 'Ready to book',
            'commercial' => 'Comparing options',
            'informational' => 'Questions people ask',
            'local' => 'Near me searches',
            default => 'Search idea',
        };
    }
}

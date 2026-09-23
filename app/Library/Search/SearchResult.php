<?php

namespace App\Library\Search;

/**
 * One Global Search result the searching actor has ALREADY been proven, by
 * the emitting SearchSource, to be authorized to open — never raw row data
 * shaped for later filtering. Carries only what the top-bar result list
 * renders: nothing here is, or could be, a token, a provider identifier or
 * signature evidence.
 */
final class SearchResult
{
    public function __construct(
        public readonly string $domain,
        public readonly string $title,
        public readonly string $subtitle,
        public readonly string $url,
        public readonly string $icon,
    ) {
    }
}

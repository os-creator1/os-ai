<?php

namespace App\Library\Seo;

use App\Models\BusinessLocation;

/**
 * Contract 18 §8.6 — one ACCESSIBLE Location's slice of the Reviews page. A
 * Location the actor cannot access never becomes one of these, so nothing
 * here (link, ledger rows, count, Contact choices) can leak it.
 *
 * `requestCount` is a plain count of ledger rows — never a target, quota,
 * goal or ranking (§8.6 invariant 3). `contacts` and each row's `contact`
 * are empty/null unless the actor holds the existing `view_contact`
 * capability; a row whose Contact is hidden or deleted still shows its
 * channel, status and dates, but never a name or number.
 *
 * `linkSource` is `manual`, `google` (the unexpired GBP mirror's review URI,
 * read through the GBP read model and never stored) or null.
 */
final class SeoReviewLocationSection
{
    /**
     * @param  array<int, array{uid: string, channel: string, channel_label: string, status: string, status_label: string, requested_at: \Carbon\CarbonInterface|null, resolved_at: \Carbon\CarbonInterface|null, has_contact: bool, contact: array{uid: string, name: ?string, phone: string}|null, can_resolve: bool}>  $requests
     * @param  array<int, array{uid: string, name: ?string, phone: string}>  $contacts
     */
    public function __construct(
        public readonly BusinessLocation $location,
        public readonly bool $writable,
        public readonly ?string $manualLink,
        public readonly ?string $effectiveLink,
        public readonly ?string $linkSource,
        public readonly int $requestCount,
        public readonly array $requests,
        public readonly array $contacts,
    ) {
    }
}

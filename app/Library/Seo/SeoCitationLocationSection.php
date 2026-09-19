<?php

namespace App\Library\Seo;

use App\DTO\GoogleBusinessProfile\GoogleLocationStatus;
use App\Models\BusinessLocation;

/**
 * Contract 18 §8.5 — one ACCESSIBLE Location's slice of the Citations page.
 *
 * A Location the actor cannot access never becomes one of these, so nothing
 * here (rows, counts, the Google row) can leak an inaccessible Location.
 *
 * `google` is the synthetic, read-only Google Business Profile row — the
 * status the GBP read model reports for this Location, or null when the actor
 * may not see GBP at all (no permission / not entitled), in which case no row
 * renders. It is never stored as a citation.
 *
 * `canonical` carries the canonical NAP for display. Its `address` is null
 * whenever `addressPermitted` is false.
 */
final class SeoCitationLocationSection
{
    /**
     * @param  array{name: ?string, phone: ?string, address: ?string}  $canonical
     * @param  array<int, SeoCitationRow>  $rows
     */
    public function __construct(
        public readonly BusinessLocation $location,
        public readonly bool $writable,
        public readonly bool $addressPermitted,
        public readonly array $canonical,
        public readonly array $rows,
        public readonly ?GoogleLocationStatus $google,
    ) {
    }
}

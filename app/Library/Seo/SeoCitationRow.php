<?php

namespace App\Library\Seo;

use App\Enums\Seo\SeoCitationStatus;
use App\Enums\Seo\SeoNapFieldResult;
use App\Models\SeoCitation;
use App\Models\SeoCitationDirectory;

/**
 * Contract 18 §8.5 — one (Location, directory) line of the Citations page.
 *
 * `nap` is the read-time comparison; it is presentation only and is never
 * persisted or fed back into `status`. `safeListingUrl` is the listing URL
 * AFTER the render-time https check: null whenever the stored value is
 * absent or not a safe https URL, so a view that only ever links
 * `safeListingUrl` cannot render an unsafe link.
 *
 * `listedAddress` is null whenever the Location is not permitted to expose an
 * address, whatever the row holds — a privacy floor on the read side that
 * mirrors the write-side refusal.
 */
final class SeoCitationRow
{
    /**
     * @param  array{name: SeoNapFieldResult, phone: SeoNapFieldResult, address: SeoNapFieldResult}  $nap
     */
    public function __construct(
        public readonly SeoCitationDirectory $directory,
        public readonly ?SeoCitation $citation,
        public readonly SeoCitationStatus $status,
        public readonly ?string $safeListingUrl,
        public readonly ?string $listedName,
        public readonly ?string $listedPhone,
        public readonly ?string $listedAddress,
        public readonly array $nap,
        public readonly bool $writable,
    ) {
    }
}

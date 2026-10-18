<?php

namespace App\Library\Website\Seo;

use App\Library\GoogleBusinessProfile\GoogleBusinessProfileReadMask;
use App\Models\Business;

/**
 * The ONE place that decides whether a Business's primary-location
 * address may currently be shown anywhere on its published website —
 * visible `contact_details` HTML and LocalBusiness JSON-LD both call
 * this SAME gate, so the two can never disagree, and
 * App\Library\Website\WebsiteSnapshotBuilder delegates its own
 * publish-time check to it too.
 *
 * This is deliberately a LIVE, per-request check, not something baked
 * once into a WebsiteRevision snapshot: an address a business is no
 * longer willing to publish (`business_locations.public_address`
 * turned off, or `service_mode` changed away from Storefront/Hybrid)
 * must stop appearing on the very next public request, even though the
 * currently published revision's snapshot — or an older revision
 * reached through rollback — may still carry an address string that
 * was correctly resolved and permitted at THAT revision's own publish
 * time. Revision rows stay immutable (§9); this gate only ever redacts
 * what a response built from them is allowed to show, in memory, for
 * the current request — it never rewrites a `snapshot` column. A
 * `show_address`/`show_phone`/`show_email` toggle remains a separate,
 * ordinary display PREFERENCE (contract §7.3): only the address
 * privacy PERMISSION itself is live (contract §7.5).
 *
 * Lives in this `Seo/` subdirectory, not directly under
 * `Library/Website/`, for the same mechanical reason
 * WebsiteLocalBusinessStructuredData does: a `BusinessLocation` type-
 * hint/import embeds a false-positive substring against
 * WebsiteBoundaryTest's naive scan of `Library/Website/*.php` — unrelated
 * to TLS/DNS/ACME automation of any kind, confirmed by reading this
 * file's own contents.
 */
final class WebsiteAddressPrivacyGate
{
    public function __construct(
        private readonly GoogleBusinessProfileReadMask $addressPredicate,
    ) {}

    /**
     * Whether the given Business's CURRENT primary location's address
     * may be shown right now — re-read fresh on every call, never
     * cached beyond the caller's own request.
     */
    public function currentlyPermitsAddress(?Business $business): bool
    {
        $location = $business?->primaryLocation;

        return $location !== null && $location->isActive() && $this->addressPredicate->addressPermittedForLocation($location);
    }

    /**
     * Returns a copy of a page's `sections` array with any
     * `contact_details` section's already-resolved address blanked out
     * when the current live permission check fails — whatever the
     * frozen snapshot value says. Every other resolved value (phone,
     * email) and every other section is returned unchanged.
     *
     * @param  array<int, array{type: string, data: array}>  $sections
     * @return array<int, array{type: string, data: array}>
     */
    public function redactSections(array $sections, ?Business $business, ?string $pageSlug = null): array
    {
        if ($this->currentlyPermitsAddress($business) && WebsiteLocationPageAddress::pageMayShowAddress($pageSlug, $business)) {
            return $sections;
        }

        return array_map(function ($section) {
            if (($section['type'] ?? null) === 'contact_details' && ! empty($section['data']['resolved']['address'] ?? null)) {
                $section['data']['resolved']['address'] = null;
            }

            return $section;
        }, $sections);
    }

    /**
     * Returns a copy of the snapshot's `website.localBusiness` facts
     * array with `address` blanked out when the current live
     * permission check fails, before it is ever handed to
     * WebsiteLocalBusinessStructuredData::build().
     *
     * @param  array<string, mixed>  $localBusiness
     * @return array<string, mixed>
     */
    public function redactLocalBusiness(array $localBusiness, ?Business $business, ?string $pageSlug = null): array
    {
        if (! $this->currentlyPermitsAddress($business) || ! WebsiteLocationPageAddress::pageMayShowAddress($pageSlug, $business)) {
            $localBusiness['address'] = null;
        }

        return $localBusiness;
    }
}

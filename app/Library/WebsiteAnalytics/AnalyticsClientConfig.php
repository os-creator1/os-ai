<?php

namespace App\Library\WebsiteAnalytics;

use App\Library\Website\Tracking\WebsiteTrackingSettings;
use App\Models\AgencySite;
use App\Models\BookingType;
use App\Models\Website;
use App\Models\Workspace;

/**
 * What a PUBLISHED page tells MotionGrove's own analytics script (public/js/website-analytics.js), as one inert JSON block.
 *
 * Returns null — so nothing at all is emitted — for any preview, draft, editor or internal test render, and for a site that
 * is not live. The block holds only the opaque property key, the collection endpoint and the consent rule; never a tenant id.
 * (The calendar's booking type is read by the script from the calendar itself, and checked against the property server-side.)
 *
 * Consent: Growth sites reuse the owner's Website → Tracking `require_consent` (any enabled setting that applies to the page
 * and requires consent turns it on). When that setting is on but no third-party tag is configured, the tracking loader is not
 * on the page, so the analytics script shows the same consent bar itself (`own_banner`). Agency sites have no tracking
 * settings; they collect nothing from a visitor who declined (`mg_tracking_consent = denied`) or sent Global Privacy Control.
 */
final class AnalyticsClientConfig
{
    public function __construct(private readonly AnalyticsProperties $properties) {}

    /** @return ?array<string, mixed> */
    public function forAgencySite(AgencySite $site, bool $preview): ?array
    {
        if ($preview || ! $site->isLive()) {
            return null;
        }

        $agency = Workspace::query()->find($site->agency_workspace_id);

        if ($agency === null || ! $agency->is_active) {
            return null;
        }

        $property = $this->properties->ensureForAgencySite($site);

        return $this->payload($property->public_key, false, false, null, 'agency');
    }

    /** @return ?array<string, mixed> */
    public function forWebsite(?Website $website, string $pageScopeKey, bool $preview, bool $trackingLoaderPresent): ?array
    {
        if ($website === null || $preview) {
            return null;
        }

        $property = $this->properties->ensureForWebsite($website);

        if ($property === null || ! $this->properties->isLive($property)) {
            return null;
        }

        $consent = app(WebsiteTrackingSettings::class)->requiresConsent($website, $pageScopeKey);

        return $this->payload($property->public_key, $consent, $consent && ! $trackingLoaderPresent, null, 'website');
    }

    /** The config for the booking page opened on ANOTHER route/tab from a landing page's handoff (query params), or null. */
    public function forHandoff(?string $propertyKey, BookingType $type): ?array
    {
        $property = $this->properties->findByKey($propertyKey);

        if ($property === null || ! $this->properties->isLive($property) || ! $this->properties->ownsBookingType($property, (string) $type->public_booking_uuid)) {
            return null;
        }

        $consent = false;

        if ($property->owner_type === \App\Models\WebsiteAnalyticsProperty::OWNER_WEBSITE) {
            $website = Website::query()->find($property->website_id);
            $consent = $website !== null && app(WebsiteTrackingSettings::class)->requiresConsent($website, WebsiteTrackingSettings::HOME_SCOPE);
        }

        return $this->payload($property->public_key, $consent, false, (string) $type->public_booking_uuid, 'handoff');
    }

    /** @return array<string, mixed> */
    private function payload(string $key, bool $requireConsent, bool $ownBanner, ?string $bookingTypeUuid, string $mode): array
    {
        return [
            'key' => $key,
            'endpoint' => $this->endpoint(),
            'require_consent' => $requireConsent,
            'own_banner' => $ownBanner,
            'booking_type' => $bookingTypeUuid,
            'mode' => $mode,
        ];
    }

    /** Same-origin on the platform host; absolute for a customer's own domain (which accepts no POST of its own). */
    private function endpoint(): string
    {
        $path = '/analytics/collect';
        $platform = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        $hosts = array_map('strtolower', (array) config('marketing.hosts', []));
        $host = strtolower((string) request()->getHost());

        return $host === $platform || in_array($host, $hosts, true) || $platform === '' ? $path : rtrim((string) config('app.url'), '/') . $path;
    }
}

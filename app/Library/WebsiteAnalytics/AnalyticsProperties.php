<?php

namespace App\Library\WebsiteAnalytics;

use App\Library\AgencySite\AgencySiteBookingService;
use App\Library\AgencySite\OfficialDomainPaths;
use App\Library\Website\WebsitePublicEntitlementGate;
use App\Models\AgencySite;
use App\Models\BookingType;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Website;
use App\Models\WebsiteAnalyticsProperty;
use App\Models\Workspace;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Who owns a measured site, resolved SEPARATELY for the two owners and always on the server.
 *
 *  - an Agency White Label site belongs to the Agency workspace that owns the AgencySite;
 *  - a Growth website belongs to its Business, and through it that Business's workspace.
 *
 * Nothing a browser sends is ever used to pick a tenant: a beacon names an opaque public key, and everything else
 * (workspace, business, whether the site is live, which booking type is "its" calendar) is read from here.
 */
final class AnalyticsProperties
{
    public function __construct(
        private readonly WebsitePublicEntitlementGate $gate,
        private readonly AgencySiteBookingService $agencyBooking,
    ) {}

    /** Measurement starts the first time a PUBLISHED page of the site is actually served. Never called for a preview. */
    public function ensureForAgencySite(AgencySite $site): WebsiteAnalyticsProperty
    {
        return $this->ensure(
            ['agency_site_id' => $site->id],
            ['owner_type' => WebsiteAnalyticsProperty::OWNER_AGENCY_SITE, 'workspace_id' => (int) $site->agency_workspace_id, 'business_id' => $this->agencyBooking->business(Workspace::query()->findOrFail($site->agency_workspace_id))?->id],
        );
    }

    public function ensureForWebsite(Website $website): ?WebsiteAnalyticsProperty
    {
        $business = $website->business;

        if ($business === null) {
            return null;
        }

        return $this->ensure(
            ['website_id' => $website->id],
            ['owner_type' => WebsiteAnalyticsProperty::OWNER_WEBSITE, 'workspace_id' => (int) $business->workspace_id, 'business_id' => (int) $business->id],
        );
    }

    public function findForAgencySite(AgencySite $site): ?WebsiteAnalyticsProperty
    {
        return WebsiteAnalyticsProperty::query()->where('agency_site_id', $site->id)->first();
    }

    public function findForWebsite(Website $website): ?WebsiteAnalyticsProperty
    {
        return WebsiteAnalyticsProperty::query()->where('website_id', $website->id)->first();
    }

    public function findByKey(?string $key): ?WebsiteAnalyticsProperty
    {
        if (! is_string($key) || ! preg_match('/^[a-z0-9]{20,40}$/', $key)) {
            return null;
        }

        return WebsiteAnalyticsProperty::query()->where('public_key', $key)->first();
    }

    /** Is this Business website live (published and entitled) right now? Checked BEFORE a property is created. */
    public function websiteLive(Website $website): bool
    {
        return $this->gate->allows($website);
    }

    /** Is the measured site still live right now? A property of an unpublished/suspended site accepts nothing. */
    public function isLive(WebsiteAnalyticsProperty $property): bool
    {
        if ($property->owner_type === WebsiteAnalyticsProperty::OWNER_AGENCY_SITE) {
            $site = AgencySite::query()->find($property->agency_site_id);
            $agency = $site === null ? null : Workspace::query()->find($site->agency_workspace_id);

            return $site !== null && $site->isLive() && $agency !== null && $agency->is_active;
        }

        $website = Website::query()->find($property->website_id);

        return $website !== null && $this->gate->allows($website);
    }

    /** May this booking type's calendar be reported under this property? (Its business must be the property's own.) */
    public function ownsBookingType(WebsiteAnalyticsProperty $property, ?string $bookingTypeUuid): bool
    {
        if ($property->business_id === null || ! is_string($bookingTypeUuid) || ! Str::isUuid($bookingTypeUuid)) {
            return false;
        }

        return BookingType::query()
            ->where('public_booking_uuid', $bookingTypeUuid)
            ->whereIn('business_location_id', BusinessLocation::query()->where('business_id', $property->business_id)->select('id'))
            ->exists();
    }

    public function business(WebsiteAnalyticsProperty $property): ?Business
    {
        return $property->business_id === null ? null : Business::query()->find($property->business_id);
    }

    /** The single landing address an Agency site is served at: its official path (/photobooth) or its hosted address. */
    public function agencyLanding(AgencySite $site): string
    {
        $agency = Workspace::query()->find($site->agency_workspace_id);
        $path = $agency === null ? null : OfficialDomainPaths::pathFor($agency);

        return $path !== null ? '/' . $path : '/w/' . $site->public_id;
    }

    /**
     * @param  array<string, mixed>  $key
     * @param  array<string, mixed>  $attributes
     */
    private function ensure(array $key, array $attributes): WebsiteAnalyticsProperty
    {
        $existing = WebsiteAnalyticsProperty::query()->where($key)->first();

        if ($existing !== null) {
            // The owner's Business can appear (or change) after the first render: keep the tenant link current.
            if (($attributes['business_id'] ?? null) !== null && (int) $existing->business_id !== (int) $attributes['business_id']) {
                $existing->forceFill(['business_id' => (int) $attributes['business_id']])->save();
            }

            return $existing;
        }

        try {
            return WebsiteAnalyticsProperty::query()->create($key + $attributes + [
                'public_key' => strtolower(Str::random(24)),
                'collection_started_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two first renders raced: the unique key on the owner made one of them lose; read the winner.
            return WebsiteAnalyticsProperty::query()->where($key)->firstOrFail();
        }
    }
}

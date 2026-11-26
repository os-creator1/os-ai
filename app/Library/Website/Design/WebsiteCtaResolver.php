<?php

namespace App\Library\Website\Design;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\PlatformFeature;
use App\Library\Calendar\CalendarLocationResolver;
use App\Library\Entitlement\CustomerAccountAccessGuard;
use App\Library\Entitlement\EntitlementManager;
use App\Models\BookingType;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Website;

/**
 * Website V1 final — the ONE answer to "what does the main button do?",
 * used by the header, the hero, every CTA band and the footer so no button
 * on the site is ever dead.
 *
 * Resolution, first that genuinely works wins:
 *  1. BOOKING  — the Business has a bookable public booking page (the same
 *                guards PublicBookingController applies: active type, active
 *                location, an eligible staff member, Calendar entitled).
 *  2. FORM     — the site has a contact page carrying its lead form.
 *  3. CONTACT  — a phone (tel:) or, failing that, an email (mailto:).
 *  4. nothing  — null: the button is simply not rendered. A button never
 *                points at "#" or an empty address.
 *
 * Booking and contact are read live at render time (a deactivated booking
 * type or a changed phone number must never leave a dead or stale button on
 * an already-published page); the contact PAGE comes from the navigation the
 * renderer already has.
 */
final class WebsiteCtaResolver
{
    public function __construct(
        private readonly CalendarLocationResolver $locations,
        private readonly CustomerAccountAccessGuard $accounts,
        private readonly EntitlementManager $entitlements,
    ) {}

    /**
     * @param  array<int, array{uid?: string, slug?: ?string, url: string, has_form?: bool}>  $navigationPages
     * @return array{kind: string, label: string, url: string}|null
     */
    public function resolve(?Business $business, array $navigationPages, bool $isPreview = false, ?string $templateKey = null): ?array
    {
        if ($business === null) {
            return null;
        }

        // The three reference families have an inquiry-first funnel. Only
        // an actually deployed, active Forms-module lead form can earn the
        // "Check Availability" call to action. Other niches and established
        // templates retain the original booking-first behavior.
        if (in_array($templateKey, [
            'photo_booth_reference_kaleb',
            'photo_booth_reference_booths',
            'photo_booth_reference_greenbelt',
        ], true)) {
            $formPage = collect($navigationPages)->first(fn ($page) =>
                \App\Library\Website\WebsiteNicheVocabulary::roleForSlug((string) ($page['slug'] ?? '')) === 'contact'
                && ! empty($page['url']) && ! empty($page['has_form'])
            );

            if ($formPage !== null && app(\App\Library\Website\Forms\WebsiteLeadFormResolver::class)->resolve($business) !== null) {
                return ['kind' => 'form', 'label' => 'Check Availability', 'url' => (string) $formPage['url']];
            }
        }

        $bookingUrl = $this->bookingUrl($business);

        if ($bookingUrl !== null) {
            return ['kind' => 'booking', 'label' => 'Book now', 'url' => $bookingUrl];
        }

        $contactPage = collect($navigationPages)->first(fn ($page) => \App\Library\Website\WebsiteNicheVocabulary::roleForSlug((string) ($page['slug'] ?? '')) === 'contact');

        if ($contactPage !== null && ! empty($contactPage['url'])) {
            $hasForm = (bool) ($contactPage['has_form'] ?? false);

            return ['kind' => $hasForm ? 'form' : 'contact_page', 'label' => \App\Library\Website\WebsiteNicheVocabulary::contactCtaLabel((string) $contactPage['slug'], $hasForm), 'url' => $contactPage['url']];
        }

        return $this->contactTarget($business);
    }

    /**
     * A section-authored CTA (a hero button written at generation time) is
     * kept only when it genuinely points somewhere; anything empty, "#" or
     * not an http(s)/tel/mailto address falls back to the site CTA.
     *
     * @param  ?array{label?: ?string, url?: ?string}  $cta
     * @param  array{kind: string, label: string, url: string}|null  $siteCta
     * @return array{label: string, url: string}|null
     */
    public function sectionCta(?array $cta, ?array $siteCta, array $pageUrls = []): ?array
    {
        $url = $this->resolveLink(trim((string) ($cta['url'] ?? '')), $pageUrls);

        if ($url !== '' && $url !== '#' && preg_match('#^(https?://|tel:|mailto:|/)#i', $url) === 1) {
            // In the reference templates the live Forms-module inquiry
            // destination owns the primary label. This also fixes an older
            // generated hero/CTA that said "Contact us" before the owner
            // activated the form: activating the form makes every primary
            // link to that exact page read "Check Availability" without a
            // destructive website regeneration.
            if (($siteCta['kind'] ?? null) === 'form'
                && ($siteCta['label'] ?? null) === 'Check Availability'
                && $url === ($siteCta['url'] ?? null)) {
                return ['label' => 'Check Availability', 'url' => $url];
            }

            return ['label' => (string) (($cta['label'] ?? '') !== '' ? $cta['label'] : ($siteCta['label'] ?? 'Get in touch')), 'url' => $url];
        }

        return $siteCta === null ? null : ['label' => $siteCta['label'], 'url' => $siteCta['url']];
    }

    /**
     * A site-relative link written at generation time ("/services", "/serving-naperville")
     * names one of THIS site's pages by slug. It is only correct if it is turned into that
     * page's real address on whichever surface is rendering — Preview, the platform path or a
     * custom domain — so a bare "/services" never points outside the site. A slug that is not a
     * page of this site resolves to nothing (a dead link is never rendered). Any other URL
     * (https, tel, mailto, #) is returned untouched.
     *
     * @param  array<string, string>  $pageUrls  slug ("" = home) => the page's address on this surface
     */
    public function resolveLink(string $url, array $pageUrls): string
    {
        // A protocol-relative URL ("//host/x") points off the site: never a page link, never rendered.
        if (str_starts_with($url, '//')) {
            return '';
        }

        if ($url === '' || ! str_starts_with($url, '/')) {
            return $url;
        }

        return (string) ($pageUrls[trim($url, '/')] ?? '');
    }

    /** @return array{kind: string, label: string, url: string}|null */
    public function contactTarget(Business $business): ?array
    {
        $phone = preg_replace('/[^+0-9]/', '', (string) $business->phone);

        if ($phone !== '' && $phone !== null) {
            return ['kind' => 'phone', 'label' => 'Call us', 'url' => 'tel:' . $phone];
        }

        if ($business->email && filter_var($business->email, FILTER_VALIDATE_EMAIL)) {
            return ['kind' => 'email', 'label' => 'Email us', 'url' => 'mailto:' . $business->email];
        }

        return null;
    }

    /**
     * Mirrors PublicBookingController::resolve() — a booking page the
     * controller would 404 on is never offered as a button.
     */
    public function bookingUrl(Business $business): ?string
    {
        $business->loadMissing('workspace');
        $workspace = $business->workspace;

        if ($workspace === null || $business->status !== BusinessStatus::Active || ! $workspace->is_active) {
            return null;
        }

        if ($this->accounts->decisionForBusiness($business)->isLocked()) {
            return null;
        }

        if (! $this->entitlements->decide($workspace, $business, PlatformFeature::Calendar->value, (int) $business->customer_id)->allowed) {
            return null;
        }

        $types = BookingType::query()
            ->where('is_active', true)
            ->whereIn('business_location_id', BusinessLocation::where('business_id', $business->id)->pluck('id'))
            ->orderBy('id')
            ->get();

        foreach ($types as $type) {
            $location = BusinessLocation::find($type->business_location_id);

            if ($location === null || ! $location->isActive()) {
                continue;
            }

            $eligible = $type->staff()->pluck('users.id')->contains(fn ($id) => $this->locations->isEligible((int) $id, $location));

            if ($eligible) {
                // The booking page exists only on the platform host. route()
                // would build it on the CURRENT host, which on a custom domain
                // is a dead 404 (that host serves only the Website's own pages).
                return rtrim((string) config('app.url'), '/').route('public.booking.show', $type->public_booking_uuid, false);
            }
        }

        return null;
    }
}

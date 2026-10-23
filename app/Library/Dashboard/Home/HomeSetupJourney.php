<?php

namespace App\Library\Dashboard\Home;

/**
 * Home V2 STATE 1 — the Launch journey.
 *
 * Every step is "done" only because a persisted fact says so; nothing is stored
 * on the browser, no step reads an onboarding flag, and no percentage is invented.
 * Progress is "N of M steps", counted from those facts.
 *
 * ── The Launch → Growth readiness rule (SEO-first policy) ────────────────────
 * Home stays in Launch while EITHER of these is false:
 *
 *  1. FOUNDATION — Business identity (name and niche), an active PRIMARY Location
 *     and an active PRIMARY Service all exist. These are what onboarding collects;
 *     once they exist Home never asks for them again.
 *  2. REACH — customers can actually find or contact the business, proven by at
 *     least one of:
 *       - a PUBLISHED MotionGrove website;
 *       - an EXISTING website the Website module has completed a check of
 *         (a saved address alone is not evidence of anything);
 *       - a working enquiry path: a bookable booking type whose own readiness
 *         verdict is "ready", a live form, or an active form on a published site.
 *     REACH is only required while some route to it is open to this actor
 *     (website, forms or calendar available and permitted); an account with none
 *     is never held in Launch for something it cannot do.
 *
 * Completing onboarding proves only the FOUNDATION. Google Business Profile,
 * Search Console, mailbox, texting, Stripe and Ads are never steps here and never
 * block Home. Calendar steps appear only once the customer has created a booking
 * type (they chose to use it) and are never essential on their own. Growth
 * opportunities, rankings and activity come from their own modules' measurements;
 * nothing in this class estimates them.
 *
 * A pure function: callers pass facts and already-gated URLs, so a step whose
 * fix this actor cannot reach simply carries no action (never a dead link).
 */
final class HomeSetupJourney
{
    public const POLICY_SEO_FIRST = 'seo_first_v1';

    /**
     * @param  array<string, mixed>  $facts  `policy` selects the rules: POLICY_SEO_FIRST for a Business that completed the
     *   SEO-first onboarding, anything else (absent, null, 'legacy') for an established Business that predates it,
     *   which keeps exactly the Home-stage eligibility it always had.
     * @param  array<string, mixed>  $urls
     * @return array<string, mixed>
     */
    public function build(array $facts, array $urls): array
    {
        return ($facts['policy'] ?? null) === self::POLICY_SEO_FIRST
            ? $this->buildV1($facts, $urls)
            : $this->buildLegacy($facts, $urls);
    }

    /**
     * SEO-first policy. Every fact that decides READINESS is Business-wide and independent of who is looking:
     * canonical records plus what the plan entitles the Business to (websiteCapable / formsCapable /
     * calendarCapable) and `bookingPathReady`, a Business-wide boolean. Only the step ACTIONS (the $urls) and the
     * optional "bookable" display step are actor-scoped, so two people viewing one Business always get the same
     * classification, and a viewer without permission gets a step with no link, never a waived requirement.
     *
     * @param  array{
     *   hasIdentity: bool,
     *   hasLocation: bool,
     *   hasPrimaryService: bool,
     *   websiteCapable: bool,
     *   formsCapable: bool,
     *   calendarCapable: bool,
     *   bookingPathReady: bool,
     *   websiteStatus: ?string,
     *   websiteMode: ?string,
     *   hasCompletedExternalAudit: bool,
     *   hasLiveForm: bool,
     *   hasActiveWebsiteForm: bool,
     *   websiteActiveDomain?: ?string,
     *   calendar?: bool,
     *   typeCount?: int,
     *   activeTypeCount?: int,
     *   readyCount?: int,
     *   problemReason?: ?string,
     * }  $facts
     * @param  array<string, ?string>  $urls  already authorised for this actor; null = no action for them
     * @return array{steps: array<int, array<string, mixed>>, essentialIncomplete: bool, foundationComplete: bool, reachComplete: bool, done: int, total: int, nextKey: ?string}
     */
    private function buildV1(array $facts, array $urls): array
    {
        $identity = $facts['hasIdentity'];
        $service = $facts['hasPrimaryService'];
        $steps = [];

        $steps[] = $identity
            ? $this->step('business', 'Business details', 'Your business name and niche are saved.', true, true, null, '')
            : $this->step('business', 'Add your business details', 'MotionGrove needs your business name and what kind of business it is.', false, true, $urls['business'] ?? null, $urls['businessLabel'] ?? 'Add details');

        $steps[] = $this->step(
            'location',
            $facts['hasLocation'] ? 'Where you work' : 'Add where you work',
            $facts['hasLocation'] ? 'Your main location and service area are saved.' : 'Local search and your website need your main city and the area you serve.',
            $facts['hasLocation'],
            true,
            $urls['locations'] ?? null,
            'Add location',
        );

        $steps[] = $this->step(
            'service',
            $service ? 'Your primary service' : 'Add your primary service',
            $service ? 'Your services are saved.' : 'Choose the main service you offer so your website and search plan have something to build on.',
            $service,
            true,
            $urls['service'] ?? null,
            $urls['serviceLabel'] ?? 'Add service',
        );

        $foundation = $identity && $facts['hasLocation'] && $service;

        // ── Website ────────────────────────────────────────────────────────
        $status = $facts['websiteStatus'];
        $mode = $facts['websiteMode'];
        $published = $status === 'published';
        $externalChecked = $mode === 'external' && $facts['hasCompletedExternalAudit'];
        // A saved address on an external website source IS the connection: the SEO check is a separate, optional
        // module and is never a precondition for leaving Launch.
        $externalConnected = $mode === 'external' && ($facts['hasExternalWebsiteAddress'] ?? false);
        $websiteUsable = $published || $externalChecked || $externalConnected;

        if ($facts['websiteCapable']) {
            [$title, $outcome, $label] = match (true) {
                $published && ($facts['websiteActiveDomain'] ?? null) !== null => ['Your website is live on ' . $facts['websiteActiveDomain'], 'Your website is published on your own domain.', 'Open website'],
                $published => ['Your website is published on the MotionGrove preview', 'Your MotionGrove website is published on its preview address.', 'Open website'],
                $externalChecked => ['Your existing website', 'We have checked your existing website. Open the website section to see the results.', 'Open website'],
                $externalConnected => ['Your existing website', 'Your website address is saved and connected. It has not been checked for search yet; you can run that check from the website section whenever you like.', 'Open website'],
                $mode === 'external' => ['Review your existing website', 'Your website address is saved but has not been checked yet. Open the website section to check it for search.', 'Review website'],
                $status === 'draft' => ['Finish and publish your website', 'Your website is not published yet, so search engines and customers cannot see it.', 'Open website'],
                $status === 'archived' => ['Republish your website', 'Your website is archived and not visible to customers.', 'Open website'],
                $mode === 'hosted' => ['Create your website', 'Start from the business, location and services you already entered. Nothing is published until you say so.', 'Create website'],
                default => ['Set up your website', 'People searching for you can find and contact you online. Build one with MotionGrove or add your existing site.', 'Set up website'],
            };

            $steps[] = $this->step('website', $title, $outcome, $websiteUsable, false, $urls['website'] ?? null, $label);
        }

        // ── Lead capture: a path that really works ─────────────────────────
        $formReady = ($facts['formsCapable'] && $facts['hasLiveForm'])
            || ($facts['websiteCapable'] && $published && $facts['hasActiveWebsiteForm']);
        $bookingReady = $facts['calendarCapable'] && $facts['bookingPathReady'];
        $leadReady = $bookingReady || $formReady;
        $reachAvailable = $facts['websiteCapable'] || $facts['formsCapable'] || $facts['calendarCapable'];

        if ($reachAvailable) {
            $steps[] = $this->step(
                'lead_capture',
                $leadReady ? 'Customers can contact you' : 'Let customers contact you',
                $leadReady
                    ? 'A working enquiry or booking path is in place.'
                    : 'Add an enquiry form or a bookable service so visitors have a real way to reach you.',
                $leadReady,
                false,
                $urls['forms'] ?? null,
                'Add a form',
            );
        }

        // Calendar is optional and its detail is actor-scoped (the viewer's own Locations): shown only once the
        // customer has chosen to use it, never essential, and never an input to readiness.
        if (($facts['calendar'] ?? false) && ($facts['typeCount'] ?? 0) > 0) {
            $steps[] = $this->step(
                'bookable',
                'Make bookings possible',
                'Someone is assigned and has working hours, so real times can be offered.',
                ($facts['readyCount'] ?? 0) > 0,
                false,
                ($facts['activeTypeCount'] ?? 0) > 0 ? ($urls['fixBooking'] ?? null) : null,
                'Finish booking setup',
                ($facts['activeTypeCount'] ?? 0) > 0 && ($facts['readyCount'] ?? 0) === 0 ? ($facts['problemReason'] ?? null) : null,
            );
        }

        // A Business whose plan offers no route to reach at all is not held in Launch for something it cannot do.
        $reachComplete = $websiteUsable || $leadReady || ! $reachAvailable;

        $next = null;

        foreach ($steps as $step) {
            if (! $step['done']) {
                $next = $step['key'];

                break;
            }
        }

        return [
            'steps' => $steps,
            'essentialIncomplete' => ! $foundation || ! $reachComplete,
            'foundationComplete' => $foundation,
            'reachComplete' => $reachComplete,
            'done' => count(array_filter($steps, fn (array $s): bool => $s['done'])),
            'total' => count($steps),
            'nextKey' => $next,
        ];
    }

    /**
     * The journey every Business had before the SEO-first policy, unchanged: Calendar setup (a Location, a booking
     * type, bookability) is essential when Calendar is in play, and the website is a non-essential growth step.
     * Kept so an established Business is never reclassified into Launch by rules it never agreed to.
     */
    private function buildLegacy(array $facts, array $urls): array
    {
        $steps = [];

        $steps[] = [
            'key' => 'location',
            'title' => 'Confirm your business location',
            'outcome' => 'MotionGrove knows where you operate, so bookings and messages belong to the right place.',
            'done' => $facts['hasLocation'],
            'essential' => true,
            'action' => $urls['locations'] === null ? null : ['label' => 'Confirm location', 'url' => $urls['locations'], 'drawer' => null],
        ];

        if ($facts['calendar']) {
            $steps[] = [
                'key' => 'booking_type',
                'title' => 'Add your first service',
                'outcome' => 'Customers can only book something you offer — a consultation, a visit, a class.',
                'done' => $facts['activeTypeCount'] > 0,
                'essential' => true,
                'action' => $facts['hasLocation'] && $urls['bookingType'] !== null
                    ? ['label' => 'Create a booking type', 'url' => $urls['bookingType'], 'drawer' => 'booking-type']
                    : null,
            ];

            $steps[] = [
                'key' => 'bookable',
                'title' => 'Make bookings possible',
                'outcome' => 'Someone is assigned and has working hours, so real times can be offered.',
                'done' => $facts['readyCount'] > 0,
                'essential' => true,
                'note' => $facts['activeTypeCount'] > 0 && $facts['readyCount'] === 0 ? $facts['problemReason'] : null,
                'action' => $facts['activeTypeCount'] > 0 && $urls['fixBooking'] !== null
                    ? ['label' => 'Finish booking setup', 'url' => $urls['fixBooking'], 'drawer' => null]
                    : null,
            ];
        }

        if ($facts['websiteApplicable']) {
            $steps[] = [
                'key' => 'website',
                'title' => 'Launch your website',
                'outcome' => 'People searching for you can find and contact you online.',
                'done' => $facts['websiteStatus'] === 'published',
                'essential' => false,
                'action' => $urls['website'] === null ? null : ['label' => 'Open website', 'url' => $urls['website'], 'drawer' => null],
            ];
        }

        $essential = array_values(array_filter($steps, fn (array $s): bool => $s['essential']));
        $incomplete = array_values(array_filter($essential, fn (array $s): bool => ! $s['done']));
        $next = null;

        foreach ($steps as $step) {
            if (! $step['done']) {
                $next = $step['key'];

                break;
            }
        }

        return [
            'steps' => $steps,
            'essentialIncomplete' => $incomplete !== [],
            'done' => count(array_filter($steps, fn (array $s): bool => $s['done'])),
            'total' => count($steps),
            'nextKey' => $next,
        ];
    }

    /** @return array<string, mixed> */
    private function step(string $key, string $title, string $outcome, bool $done, bool $essential, ?string $url, string $label, ?string $note = null): array
    {
        $step = [
            'key' => $key,
            'title' => $title,
            'outcome' => $outcome,
            'done' => $done,
            'essential' => $essential,
            'action' => $done || $url === null || $label === '' ? null : ['label' => $label, 'url' => $url, 'drawer' => null],
        ];

        if ($note !== null) {
            $step['note'] = $note;
        }

        return $step;
    }
}

<?php

namespace App\Library\Dashboard\Home;

/**
 * Home V2 STATE 1 — the Launch journey.
 *
 * Every step is "done" only because a persisted fact says so; nothing is stored
 * on the browser, no step reads an onboarding flag, and no percentage is invented.
 * Progress is "N of M steps", counted from those facts.
 *
 * ── The Launch → Growth readiness rule (the only one) ────────────────────────
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
    /**
     * @param  array{
     *   hasIdentity?: bool,
     *   hasLocation: bool,
     *   hasPrimaryService?: bool,
     *   calendar: bool,
     *   typeCount?: int,
     *   activeTypeCount: int,
     *   readyCount: int,
     *   problemReason: ?string,
     *   websiteStatus: ?string,
     *   websiteApplicable: bool,
     *   websiteMode?: ?string,
     *   hasCompletedExternalAudit?: bool,
     *   hasLiveForm?: bool,
     *   hasActiveWebsiteForm?: bool,
     *   formsApplicable?: bool,
     * }  $facts  Keys marked optional default to "satisfied / absent" so a caller that does not know a
     *            fact never invents a gap.
     * @param  array{locations: ?string, bookingType: ?string, fixBooking: ?string, website: ?string, business?: ?string, service?: ?string, forms?: ?string}  $urls
     * @return array{steps: array<int, array<string, mixed>>, essentialIncomplete: bool, foundationComplete: bool, reachComplete: bool, done: int, total: int, nextKey: ?string}
     */
    public function build(array $facts, array $urls): array
    {
        $identity = $facts['hasIdentity'] ?? true;
        $service = $facts['hasPrimaryService'] ?? true;
        $steps = [];

        if (! $identity) {
            $steps[] = $this->step('business', 'Add your business details', 'MotionGrove needs your business name and what kind of business it is.', false, true, $urls['business'] ?? null, 'Add details');
        } else {
            $steps[] = $this->step('business', 'Business details', 'Your business name and niche are saved.', true, true, null, '');
        }

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
            'Add service',
        );

        $foundation = $identity && $facts['hasLocation'] && $service;

        // ── Website ────────────────────────────────────────────────────────
        $status = $facts['websiteStatus'];
        $mode = $facts['websiteMode'] ?? null;
        $published = $status === 'published';
        $externalChecked = $mode === 'external' && ($facts['hasCompletedExternalAudit'] ?? false);
        $websiteUsable = $published || $externalChecked;

        if ($facts['websiteApplicable']) {
            [$title, $outcome, $label] = match (true) {
                $published => ['Your website is live', 'Your MotionGrove website is published.', 'Open website'],
                $externalChecked => ['Your existing website', 'We have checked your existing website. Open the website section to see the results.', 'Open website'],
                $mode === 'external' => ['Review your existing website', 'Your website address is saved but has not been checked yet. Open the website section to check it for search.', 'Review website'],
                $status === 'draft' => ['Finish and publish your website', 'Your website is not published yet, so search engines and customers cannot see it.', 'Open website'],
                $status === 'archived' => ['Republish your website', 'Your website is archived and not visible to customers.', 'Open website'],
                $mode === 'hosted' => ['Create your website', 'Start from the business, location and services you already entered. Nothing is published until you say so.', 'Create website'],
                default => ['Set up your website', 'People searching for you can find and contact you online. Build one with MotionGrove or add your existing site.', 'Set up website'],
            };

            $steps[] = $this->step('website', $title, $outcome, $websiteUsable, false, $urls['website'] ?? null, $label);
        }

        // ── Lead capture: a path that really works ─────────────────────────
        $bookingReady = $facts['calendar'] && $facts['readyCount'] > 0;
        $formReady = ($facts['hasLiveForm'] ?? false) || ($published && ($facts['hasActiveWebsiteForm'] ?? false));
        $leadReady = $bookingReady || $formReady;
        $formsApplicable = $facts['formsApplicable'] ?? false;

        if ($facts['websiteApplicable'] || $formsApplicable || $facts['calendar']) {
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

        // Calendar is optional: it shows only once the customer has chosen to use it.
        if ($facts['calendar'] && ($facts['typeCount'] ?? $facts['activeTypeCount']) > 0) {
            $steps[] = $this->step(
                'bookable',
                'Make bookings possible',
                'Someone is assigned and has working hours, so real times can be offered.',
                $facts['readyCount'] > 0,
                false,
                $facts['activeTypeCount'] > 0 ? ($urls['fixBooking'] ?? null) : null,
                'Finish booking setup',
                $facts['activeTypeCount'] > 0 && $facts['readyCount'] === 0 ? $facts['problemReason'] : null,
            );
        }

        $reachAvailable = $facts['websiteApplicable'] || $formsApplicable || $facts['calendar'];
        $reach = $websiteUsable || $leadReady;
        $reachComplete = $reach || ! $reachAvailable;

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

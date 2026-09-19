<?php

namespace App\Library\Seo;

use App\Enums\Seo\SeoReviewRequestStatus;
use App\Library\Contacts\ContactDirectory;
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileStatusReader;
use App\Models\Business;
use App\Models\Contacts;
use App\Models\SeoReviewRequest;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Gate;

/**
 * Contract 18 §8.6 — the READ side of Reviews: composes the manual links, the
 * ledger and the effective link for the actor's accessible Locations.
 *
 * READ-ONLY, and constant in queries however many Locations, requests or
 * Contacts there are (§11.4).
 *
 *  - LOCATION ACL FIRST. Locations come from SeoLocationScope
 *    (LocationAccessGuard); every later query is restricted to those ids, and
 *    counts are taken over the already-filtered set.
 *  - CONTACT PII NO BROADER THAN CONTACTS. A Contact's name/number come only
 *    from ContactDirectory (the same summaries Opportunities use, scoped to
 *    the Business) and only when the actor holds `view_contact`. SEO reaching
 *    a Location never grants Contact details.
 *  - GOOGLE ONLY THROUGH THE READ MODEL. The fallback link is
 *    GoogleBusinessProfileStatusReader's newReviewUri, which is null once the
 *    mirror has expired; nothing is copied into SEO tables.
 *  - NO PROVIDER CALL, NO WRITE, NO SEND.
 */
final class SeoReviewsPageReader
{
    /** The most ledger rows loaded across all Locations, newest first. */
    public const LEDGER_LIMIT = 200;

    /** The most Contact choices offered across all Locations. */
    public const CONTACT_CHOICE_LIMIT = 200;

    public function __construct(
        private readonly SeoLocationScope $scope,
        private readonly SeoReviewLinkManager $links,
        private readonly SeoReviewRequestManager $requests,
        private readonly GoogleBusinessProfileStatusReader $googleStatus,
        private readonly ContactDirectory $contactDirectory,
    ) {
    }

    /**
     * @return array<int, SeoReviewLocationSection>
     */
    public function read(Workspace $workspace, Business $business, User $actor): array
    {
        $accessible = $this->scope->accessibleLocations((int) $actor->id, $business);

        if ($accessible->isEmpty()) {
            return [];
        }

        $ids = $accessible->pluck('id')->map(fn ($id) => (int) $id)->all();
        $activeIds = $accessible->filter(fn ($location) => $location->isActive())->pluck('id')->map(fn ($id) => (int) $id)->all();

        $manual = $this->links->forAccessibleLocations($business, $ids);
        $ledger = $this->requests->forAccessibleLocations($business, $ids, self::LEDGER_LIMIT)->groupBy('business_location_id');
        $counts = SeoReviewRequest::query()
            ->where('business_id', $business->id)
            ->whereIn('business_location_id', $ids)
            ->selectRaw('business_location_id, COUNT(*) as total')
            ->groupBy('business_location_id')
            ->pluck('total', 'business_location_id');

        $google = $this->googleStatus->forBusiness($workspace, $business, $actor);
        $googleByLocation = $google === null ? collect() : collect($google)->keyBy(fn ($status) => $status->locationId);

        $mayUseContacts = Gate::forUser($actor)->allows('view_contact');
        [$choices, $summaries] = $mayUseContacts ? $this->contactDetails($business, $ledger, $ids, $activeIds) : [[], []];

        $sections = [];

        foreach ($accessible as $location) {
            $locationId = (int) $location->id;
            $manualUrl = SeoLinkSafety::safeHttpsUrl($manual->get($locationId)?->review_url);
            $googleUrl = SeoLinkSafety::safeHttpsUrl($googleByLocation->get($locationId)?->newReviewUri);

            $rows = [];

            foreach ($ledger->get($locationId, collect()) as $request) {
                $contactId = $request->contact_id === null ? null : (int) $request->contact_id;

                $rows[] = [
                    'uid' => (string) $request->uid,
                    'channel' => $request->channel->value,
                    'channel_label' => $request->channel->label(),
                    'status' => $request->status->value,
                    'status_label' => $request->status->label(),
                    'requested_at' => $request->requested_at,
                    'resolved_at' => $request->resolved_at,
                    'has_contact' => $contactId !== null,
                    'contact' => $contactId !== null ? ($summaries[$contactId] ?? null) : null,
                    'can_resolve' => $location->isActive() && $request->status === SeoReviewRequestStatus::Requested,
                ];
            }

            $sections[] = new SeoReviewLocationSection(
                location: $location,
                writable: $location->isActive(),
                manualLink: $manualUrl,
                effectiveLink: $manualUrl ?? $googleUrl,
                linkSource: $manualUrl !== null ? 'manual' : ($googleUrl !== null ? 'google' : null),
                requestCount: (int) ($counts[$locationId] ?? 0),
                requests: $rows,
                contacts: $location->isActive() ? ($choices[$locationId] ?? []) : [],
            );
        }

        return $sections;
    }

    /**
     * Everything Contact-shaped the page needs, in a constant number of
     * queries, and only ever for Contacts that belong to one of the actor's
     * accessible Locations:
     *
     *  - the choices offered per (active) Location, and
     *  - name/phone for the Contacts a visible ledger row names — but only
     *    those that STILL belong to an accessible Location (a Contact moved
     *    elsewhere since the request was recorded is not shown).
     *
     * Name/phone come from ContactDirectory::summaries(), the read model the
     * Contacts product itself uses; nothing else about a Contact is read.
     *
     * @param  \Illuminate\Support\Collection<int|string, \Illuminate\Support\Collection<int, SeoReviewRequest>>  $ledger
     * @param  array<int, int>  $accessibleLocationIds
     * @param  array<int, int>  $activeLocationIds
     * @return array{0: array<int, array<int, array{uid: string, name: ?string, phone: string}>>, 1: array<int, array{uid: string, name: ?string, phone: string}>}
     */
    private function contactDetails(Business $business, $ledger, array $accessibleLocationIds, array $activeLocationIds): array
    {
        $choiceContacts = $activeLocationIds === [] ? collect() : Contacts::query()
            ->where('business_id', $business->id)
            ->whereIn('location_id', $activeLocationIds)
            ->orderByDesc('id')
            ->limit(self::CONTACT_CHOICE_LIMIT)
            ->get(['id', 'uid', 'location_id']);

        $ledgerIds = $ledger->flatten(1)->pluck('contact_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();

        $stillAccessible = $ledgerIds === [] ? [] : Contacts::query()
            ->where('business_id', $business->id)
            ->whereIn('id', $ledgerIds)
            ->whereIn('location_id', $accessibleLocationIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $wanted = array_values(array_unique(array_merge($choiceContacts->pluck('id')->map(fn ($id) => (int) $id)->all(), $stillAccessible)));
        $summaries = $this->contactDirectory->summaries($business, $wanted);

        $choices = [];

        foreach ($choiceContacts as $contact) {
            if (isset($summaries[$contact->id])) {
                $choices[(int) $contact->location_id][] = $summaries[$contact->id];
            }
        }

        // A ledger row may name only a Contact that is still accessible.
        $visible = array_intersect_key($summaries, array_flip($stillAccessible));

        return [$choices, $visible];
    }
}

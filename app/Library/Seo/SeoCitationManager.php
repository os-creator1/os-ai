<?php

namespace App\Library\Seo;

use App\Enums\Seo\SeoCitationStatus;
use App\Exceptions\Seo\SeoCitationNotFoundException;
use App\Exceptions\Seo\SeoCitationRefusedException;
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileReadMask;
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileStatusReader;
use App\Library\Workspace\LocationAccessGuard;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\SeoCitation;
use App\Models\SeoCitationDirectory;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessLocationRepository;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Contract 18 §8.5 / §15.E — the Citations service: Location-scoped reads and
 * the one write path for `seo_citations`.
 *
 * WHAT THIS IS. The Business's own, user-asserted record of where it is
 * listed, per Location, beside a deterministic read-time NAP comparison.
 *
 * WHAT THIS IS NOT (each proven by test):
 *  - It never fetches a listing_url or contacts any directory. There is no
 *    HTTP client, no queue, no cache and no provider call in this class.
 *  - It never changes a citation's status because NAP differs. Status is
 *    written only from an explicit user submission; the comparison is
 *    computed for display and discarded.
 *  - It never writes Website, Business, Location or Google data. Its only
 *    write target is `seo_citations`, and it holds no path to the others.
 *  - It never asks Google anything. The synthetic Google row comes only from
 *    GoogleBusinessProfileStatusReader.
 *
 * LOCATION AUTHORITY. LocationAccessGuard is the only Location authority and
 * this class adds none. Reads filter to the actor's accessible Locations
 * BEFORE reading or counting anything (§6 aggregation rule); a write resolves
 * its Location through the Business, then asks the guard about that one
 * Location. Every failure to find/authorize is the same
 * SeoCitationNotFoundException, so a guessed uid is indistinguishable from a
 * missing one. A Location-bound write additionally requires
 * BusinessLocation::isActive() (§10.5).
 *
 * PRIVATE-ADDRESS INVARIANT (§8.5). `listed_address` must be null unless
 * GoogleBusinessProfileReadMask::addressPermittedForLocation() is true for
 * the Location. A submission carrying a non-empty listed_address for a
 * Location that is not permitted is REFUSED WHOLE — nothing is written — and
 * the refusal never echoes the address. When the predicate denies, an
 * accepted write also leaves the column null, so a value stored before the
 * Location became private is cleared by its next write, and reads suppress
 * it in the meantime.
 */
final class SeoCitationManager
{
    private const MAX_NAME = 191;
    private const MAX_PHONE = 50;
    private const MAX_ADDRESS = 255;
    private const MAX_NOTES = 500;

    public function __construct(
        private readonly SeoLocationScope $scope,
        private readonly LocationAccessGuard $locationGuard,
        private readonly BusinessLocationRepository $locations,
        private readonly GoogleBusinessProfileStatusReader $googleStatus,
        private readonly GoogleBusinessProfileReadMask $addressPredicate,
        private readonly SeoNapComparator $comparator,
    ) {
    }

    /**
     * The Citations page for one Business: one section per Location the actor
     * may access. Constant query count regardless of Location, directory or
     * citation count.
     *
     * @return array<int, SeoCitationLocationSection>
     */
    public function page(Workspace $workspace, Business $business, User $actor): array
    {
        $accessible = $this->scope->accessibleLocations((int) $actor->id, $business);

        if ($accessible->isEmpty()) {
            return [];
        }

        $directories = SeoCitationDirectory::query()->orderBy('sort_order')->orderBy('id')->get();

        $citations = SeoCitation::query()
            ->where('business_id', $business->id)
            ->whereIn('business_location_id', $accessible->pluck('id')->all())
            ->get()
            ->keyBy(fn (SeoCitation $citation) => $citation->business_location_id . ':' . $citation->seo_citation_directory_id);

        // Read model only; null when the actor may not see GBP at all.
        $google = $this->googleStatus->forBusiness($workspace, $business, $actor);
        $googleByLocation = $google === null
            ? null
            : collect($google)->keyBy(fn ($status) => $status->locationId);

        $sections = [];

        foreach ($accessible as $location) {
            $sections[] = $this->section($business, $location, $directories, $citations, $googleByLocation?->get((int) $location->id));
        }

        return $sections;
    }

    /**
     * Records (creates or updates) the user's citation for one directory at
     * one Location.
     *
     * @param  array<string, mixed>  $input  status, listing_url, listed_name,
     *                                       listed_phone, listed_address,
     *                                       last_verified_at, notes
     *
     * @throws SeoCitationNotFoundException  unknown / foreign / inaccessible Location or directory
     * @throws SeoCitationRefusedException   Archived Location, private-address rule, unsafe link, bad value
     */
    public function save(int $actorUserId, Business $business, string $locationUid, string $directoryKey, array $input): SeoCitation
    {
        $location = $this->accessibleLocationByUid($actorUserId, $business, $locationUid);

        $directory = SeoCitationDirectory::query()->where('key', $directoryKey)->where('is_active', true)->first();

        if ($directory === null) {
            throw new SeoCitationNotFoundException('Directory not found.');
        }

        // §10.5 — a Location-bound WRITE needs an operational Location.
        if (! $location->isActive()) {
            throw new SeoCitationRefusedException('location', 'This location is archived, so its citations can no longer be edited.');
        }

        $values = $this->validated($location, $input);

        $values['business_id'] = $business->id;
        $values['verification_source'] = SeoCitation::SOURCE_USER_ASSERTED;
        $values['updated_by_user_id'] = $actorUserId;

        return $this->persist($location, $directory, $values);
    }

    /**
     * Resolves a Location THROUGH the Business, then asks the one authority.
     *
     * @throws SeoCitationNotFoundException
     */
    private function accessibleLocationByUid(int $actorUserId, Business $business, string $locationUid): BusinessLocation
    {
        $location = $this->locations->forBusiness($business)
            ->first(fn (BusinessLocation $candidate) => (string) $candidate->uid === $locationUid);

        if ($location === null || ! $this->locationGuard->userCanAccessLocation($actorUserId, $location)) {
            throw new SeoCitationNotFoundException('Location not found.');
        }

        return $location;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function validated(BusinessLocation $location, array $input): array
    {
        $status = SeoCitationStatus::tryFrom((string) ($input['status'] ?? ''));

        if ($status === null) {
            throw new SeoCitationRefusedException('status', 'Choose a valid status.');
        }

        $listingUrl = $this->nullableString($input['listing_url'] ?? null);

        if ($listingUrl !== null && ! SeoLinkSafety::isSafeHttpsUrl($listingUrl)) {
            throw new SeoCitationRefusedException('listing_url', 'Enter a full link that starts with https://.');
        }

        $listedName = $this->boundedString($input['listed_name'] ?? null, self::MAX_NAME, 'listed_name');
        $listedPhone = $this->boundedString($input['listed_phone'] ?? null, self::MAX_PHONE, 'listed_phone');
        $notes = $this->boundedString($input['notes'] ?? null, self::MAX_NOTES, 'notes');
        $listedAddress = $this->boundedString($input['listed_address'] ?? null, self::MAX_ADDRESS, 'listed_address');

        // The private-address invariant. Refuse the whole write; never echo
        // the address; and when the predicate denies, the column stays null.
        if (! $this->addressPredicate->addressPermittedForLocation($location)) {
            if ($listedAddress !== null) {
                throw new SeoCitationRefusedException('listed_address', 'This location does not publish a street address, so a listed address cannot be recorded for it.');
            }

            $listedAddress = null;
        }

        return [
            'status' => $status->value,
            'listing_url' => $listingUrl,
            'listed_name' => $listedName,
            'listed_phone' => $listedPhone,
            'listed_address' => $listedAddress,
            'last_verified_at' => $this->verifiedDate($input['last_verified_at'] ?? null),
            'notes' => $notes,
        ];
    }

    /**
     * unique(business_location_id, seo_citation_directory_id) is the
     * database's guarantee of "one per (Location, directory)". Two concurrent
     * first saves both find no row and both try to insert; the loser's unique
     * violation is retried as an update rather than surfaced.
     *
     * @param  array<string, mixed>  $values
     */
    private function persist(BusinessLocation $location, SeoCitationDirectory $directory, array $values): SeoCitation
    {
        $keys = ['business_location_id' => $location->id, 'seo_citation_directory_id' => $directory->id];

        $write = function () use ($keys, $values): SeoCitation {
            $citation = SeoCitation::query()->firstOrNew($keys);
            $citation->fill($values);
            $citation->save();

            return $citation;
        };

        try {
            return $write();
        } catch (UniqueConstraintViolationException) {
            return $write();
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, SeoCitationDirectory>  $directories
     * @param  \Illuminate\Support\Collection<string, SeoCitation>  $citations
     */
    private function section(Business $business, BusinessLocation $location, $directories, $citations, $google): SeoCitationLocationSection
    {
        $addressPermitted = $this->addressPredicate->addressPermittedForLocation($location);
        $canonical = $this->comparator->canonicalFor($business, $location);
        $writable = $location->isActive();

        $rows = [];

        foreach ($directories as $directory) {
            $citation = $citations->get($location->id . ':' . $directory->id);

            // An inactive directory stays visible only where the Business
            // already holds a citation for it (history), and is read-only.
            if (! $directory->is_active && $citation === null) {
                continue;
            }

            $listedAddress = $addressPermitted ? $citation?->listed_address : null;

            $rows[] = new SeoCitationRow(
                directory: $directory,
                citation: $citation,
                status: $citation?->status ?? SeoCitationStatus::NotStarted,
                safeListingUrl: SeoLinkSafety::safeHttpsUrl($citation?->listing_url),
                listedName: $citation?->listed_name,
                listedPhone: $citation?->listed_phone,
                listedAddress: $listedAddress,
                nap: $this->comparator->compare($canonical, [
                    'name' => $citation?->listed_name,
                    'phone' => $citation?->listed_phone,
                    'address' => $listedAddress,
                ]),
                writable: $writable && $directory->is_active,
            );
        }

        return new SeoCitationLocationSection($location, $writable, $addressPermitted, $canonical, $rows, $google);
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || ! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function boundedString(mixed $value, int $max, string $field): ?string
    {
        $string = $this->nullableString($value);

        if ($string !== null && mb_strlen($string) > $max) {
            throw new SeoCitationRefusedException($field, "This value must be {$max} characters or fewer.");
        }

        return $string;
    }

    /**
     * `last_verified_at` is a DATE the user asserts. A calendar date in the
     * far future is refused; one day of slack absorbs time-zone differences
     * between the user and the server.
     */
    private function verifiedDate(mixed $value): ?string
    {
        $string = $this->nullableString($value);

        if ($string === null) {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('!Y-m-d', $string);
        } catch (\Throwable) {
            $date = null;
        }

        if (! $date instanceof Carbon || $date->format('Y-m-d') !== $string || $string > now()->addDay()->toDateString()) {
            throw new SeoCitationRefusedException('last_verified_at', 'Enter a valid date that is not in the future.');
        }

        return $string;
    }
}

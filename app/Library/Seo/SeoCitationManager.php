<?php

namespace App\Library\Seo;

use App\Enums\Seo\SeoCitationStatus;
use App\Enums\Seo\SeoDirectoryImportance;
use App\Enums\Seo\SeoDirectoryTrackingMode;
use App\Enums\Seo\SeoNapFieldResult;
use App\Exceptions\Seo\SeoCitationNotFoundException;
use App\Exceptions\Seo\SeoCitationRefusedException;
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileReadMask;
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileStatusReader;
use App\Library\Workspace\LocationAccessGuard;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\SeoCitation;
use App\Models\SeoCitationDirectory;
use App\Models\SeoNicheCitationRecommendation;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessLocationRepository;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Contract 18 §8.5 / §15.E — the Citations service: Location-scoped reads and
 * the one write path for `seo_citations` (and for a Business's own custom
 * directories).
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
 *    write targets are `seo_citations` and the Business's OWN custom rows in
 *    `seo_citation_directories`; it holds no path to anything else, and none to
 *    the platform's catalog or the niche recommendations.
 *  - It never asks Google anything. The synthetic Google row comes only from
 *    GoogleBusinessProfileStatusReader, and what it reads is compared at read
 *    time and never stored.
 *
 * WHICH DIRECTORIES A LOCATION SHOWS (Citations V1). One unified list, built
 * live — no per-Business rows are pre-created and nothing is copied:
 *   A. platform CORE directories (is_platform_core, active);
 *   B. the Business niche's enabled recommendations (businesses.industry);
 *   C. the Business's own CUSTOM directories (all Locations, or the one
 *      Location it was scoped to);
 *   plus any directory the Business already holds a citation for, so history
 *   survives a platform change (a disabled or un-recommended directory stays
 *   readable, read-only). A recommended directory with no citation row simply
 *   renders "Needs setup".
 *
 * LOCATION AUTHORITY. LocationAccessGuard is the only Location authority and
 * this class adds none. Reads filter to the actor's accessible Locations
 * BEFORE reading or counting anything (§6 aggregation rule); a write resolves
 * its Location through the Business, then asks the guard about that one
 * Location. Every failure to find/authorize is the same
 * SeoCitationNotFoundException, so a guessed uid is indistinguishable from a
 * missing one. A Location-bound write additionally requires
 * BusinessLocation::isActive() (§10.5). Another Business's custom directory is
 * never loaded, so it is as absent as a guessed key.
 *
 * WHAT A WRITE MAY TOUCH. A platform directory is writable only where the page
 * OFFERS it (SeoCitationApplicability::isOffered), so a forged request cannot
 * write what the UI shows read-only. A custom directory that applies to ALL
 * Locations is added, renamed and archived only by an actor who can access every
 * Location; the custom-directory ceiling counts only what the actor can see; and
 * adding one is a single transaction under the Business row lock.
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
    private const MAX_DIRECTORY_NAME = 120;
    private const MAX_PHONE = 50;
    private const MAX_ADDRESS = 255;
    private const MAX_WEBSITE = 2048;
    private const MAX_NOTES = 500;

    public function __construct(
        private readonly SeoLocationScope $scope,
        private readonly LocationAccessGuard $locationGuard,
        private readonly BusinessLocationRepository $locations,
        private readonly GoogleBusinessProfileStatusReader $googleStatus,
        private readonly GoogleBusinessProfileReadMask $addressPredicate,
        private readonly SeoNapComparator $comparator,
        private readonly SeoConfig $config,
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
        ['accessible' => $accessible, 'all' => $allLocations] = $this->scope->reach((int) $actor->id, $business);

        if ($accessible->isEmpty()) {
            return [];
        }

        // Platform rows and THIS Business's own custom rows — never another
        // Business's.
        $directories = SeoCitationDirectory::query()
            ->where(function ($query) use ($business): void {
                $query->whereNull('business_id')->orWhere('business_id', $business->id);
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $nicheKey = $business->industry?->value;
        $recommendations = $nicheKey === null
            ? collect()
            : SeoNicheCitationRecommendation::query()
                ->where('niche_key', $nicheKey)
                ->where('is_enabled', true)
                ->get()
                ->keyBy('seo_citation_directory_id');

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

        // The phone is a Business-wide fact and a Business-wide custom
        // directory applies to every Location, so both depend on how many
        // Locations the Business has and whether this actor reaches them all.
        $accessibleIds = $accessible->pluck('id')->map(fn ($id) => (int) $id)->all();
        $reachesEveryLocation = $allLocations->every(fn ($location) => in_array((int) $location->id, $accessibleIds, true));

        $sections = [];

        foreach ($accessible as $location) {
            $sections[] = $this->section(
                $business,
                $location,
                $directories,
                $recommendations,
                $citations,
                $googleByLocation?->get((int) $location->id),
                SeoNapComparator::phoneAppliesAt($location, $allLocations->count()),
                $reachesEveryLocation,
            );
        }

        return $sections;
    }

    /**
     * Records (creates or updates) the user's citation for one directory at
     * one Location.
     *
     * @param  array<string, mixed>  $input  status, listing_url, listed_name,
     *                                       listed_phone, listed_address,
     *                                       listed_website, last_verified_at,
     *                                       notes
     *
     * @throws SeoCitationNotFoundException  unknown / foreign / inaccessible Location or directory
     * @throws SeoCitationRefusedException   Archived Location, private-address rule, unsafe link, bad value
     */
    public function save(int $actorUserId, Business $business, string $locationUid, string $directoryKey, array $input): SeoCitation
    {
        $location = $this->accessibleLocationByUid($actorUserId, $business, $locationUid);
        $directory = $this->writableDirectory($business, $location, $directoryKey);

        // §10.5 — a Location-bound WRITE needs an operational Location.
        $this->assertOperational($location);

        $values = $this->validated($location, $input);

        $values['business_id'] = $business->id;
        $values['verification_source'] = SeoCitation::SOURCE_USER_ASSERTED;
        $values['updated_by_user_id'] = $actorUserId;

        return $this->persist($location, $directory, $values);
    }

    /**
     * Marks a directory Not applicable, or restores it, WITHOUT touching
     * anything the owner recorded. Only those two moves exist: every other
     * status change goes through save(), and nothing here is automatic.
     *
     * @throws SeoCitationNotFoundException
     * @throws SeoCitationRefusedException
     */
    public function setApplicability(int $actorUserId, Business $business, string $locationUid, string $directoryKey, bool $applicable): SeoCitation
    {
        $location = $this->accessibleLocationByUid($actorUserId, $business, $locationUid);
        $directory = $this->writableDirectory($business, $location, $directoryKey);
        $this->assertOperational($location);

        $keys = ['business_location_id' => $location->id, 'seo_citation_directory_id' => $directory->id];

        $write = function () use ($keys, $business, $actorUserId, $applicable): SeoCitation {
            $citation = SeoCitation::query()->firstOrNew($keys);
            $citation->business_id = $business->id;
            $citation->verification_source = SeoCitation::SOURCE_USER_ASSERTED;
            $citation->updated_by_user_id = $actorUserId;

            if (! $applicable) {
                $citation->status = SeoCitationStatus::NotApplicable;
            } elseif ($citation->status === SeoCitationStatus::NotApplicable || ! $citation->exists) {
                // Restore: back to where an untouched directory starts.
                $citation->status = SeoCitationStatus::NotStarted;
            }

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
     * Adds a Business-owned custom directory and records its first citation
     * for the selected Location. The directory is always MANUAL, always
     * `custom`, and can never carry platform fields (importance, core,
     * tracking mode) from the customer.
     *
     * @param  array<string, mixed>  $input  name, claim_url, location_scope
     *                                       (all|this) plus the citation fields
     *                                       of save()
     *
     * @throws SeoCitationNotFoundException
     * @throws SeoCitationRefusedException
     */
    public function createCustomDirectory(int $actorUserId, Business $business, string $locationUid, array $input): SeoCitation
    {
        $location = $this->accessibleLocationByUid($actorUserId, $business, $locationUid);
        $this->assertOperational($location);

        $name = $this->requiredBounded($input['name'] ?? null, self::MAX_DIRECTORY_NAME, 'name', 'Enter the directory name.');
        $claimUrl = $this->safeUrlOrNull($input['claim_url'] ?? null, 'claim_url');
        $values = $this->validated($location, $input);
        $sharedAcrossLocations = ($input['location_scope'] ?? 'all') !== 'this';

        // A directory that applies to every Location is visible to Locations an
        // actor restricted to some Locations cannot reach; such an actor adds
        // a directory for their own Location only.
        if ($sharedAcrossLocations && ! $this->scope->accessesEveryLocation($actorUserId, $business)) {
            throw new SeoCitationRefusedException('location_scope', 'Only someone with access to every location can add a directory for all locations. Choose this location only.');
        }

        $values['business_id'] = $business->id;
        $values['verification_source'] = SeoCitation::SOURCE_USER_ASSERTED;
        $values['updated_by_user_id'] = $actorUserId;

        // One transaction: the directory and its first citation are saved
        // together or not at all, and the Business row is locked so two adds
        // cannot both pass the ceiling check.
        return $business->getConnection()->transaction(function () use ($actorUserId, $business, $location, $name, $claimUrl, $values, $sharedAcrossLocations): SeoCitation {
            $business->newQuery()->whereKey($business->getKey())->lockForUpdate()->first();

            if ($this->visibleCustomDirectoryCount($actorUserId, $business) >= $this->config->citationsMaxCustomDirectoriesPerBusiness()) {
                throw new SeoCitationRefusedException('name', 'You have reached the limit of custom directories. Archive one to add another.');
            }

            $directory = new SeoCitationDirectory();
            $directory->forceFill([
                'key' => 'custom_' . Str::lower(Str::random(16)),
                'name' => $name,
                'claim_url' => $claimUrl,
                'website_url' => null,
                'category' => 'custom',
                'icon' => 'link',
                'importance' => SeoDirectoryImportance::Optional->value,
                'tracking_mode' => SeoDirectoryTrackingMode::Manual->value,
                'setup_guidance' => null,
                'is_platform_core' => false,
                'is_active' => true,
                'sort_order' => 1000,
                'business_id' => $business->id,
                'business_location_id' => $sharedAcrossLocations ? null : $location->id,
            ])->save();

            return $this->persist($location, $directory, $values);
        });
    }

    /**
     * How many active custom directories this ACTOR can see — the Business-wide
     * ones plus those scoped to a Location they can reach. The ceiling is
     * measured against what the actor can see, so reaching it can never reveal
     * a directory scoped to a Location they cannot access.
     */
    private function visibleCustomDirectoryCount(int $actorUserId, Business $business): int
    {
        $reachableIds = $this->scope->accessibleLocations($actorUserId, $business)->pluck('id')->map(fn ($id) => (int) $id)->all();

        return SeoCitationDirectory::query()
            ->where('business_id', $business->id)
            ->where('is_active', true)
            ->where(function ($query) use ($reachableIds): void {
                $query->whereNull('business_location_id')->orWhereIn('business_location_id', $reachableIds);
            })
            ->count();
    }

    /**
     * Renames a custom directory or changes its claim/manage link. Platform
     * directories are never reachable here: the lookup requires business_id to
     * be this Business, so a platform key is as absent as a guessed one.
     *
     * @param  array<string, mixed>  $input  name, claim_url
     *
     * @throws SeoCitationNotFoundException
     * @throws SeoCitationRefusedException
     */
    public function updateCustomDirectory(int $actorUserId, Business $business, string $locationUid, string $directoryKey, array $input): SeoCitationDirectory
    {
        $location = $this->accessibleLocationByUid($actorUserId, $business, $locationUid);
        $this->assertOperational($location);
        $directory = $this->ownCustomDirectory($actorUserId, $business, $location, $directoryKey);

        $directory->forceFill([
            'name' => $this->requiredBounded($input['name'] ?? null, self::MAX_DIRECTORY_NAME, 'name', 'Enter the directory name.'),
            'claim_url' => $this->safeUrlOrNull($input['claim_url'] ?? null, 'claim_url'),
        ])->save();

        return $directory;
    }

    /**
     * Archives a custom directory. Its citation history is kept and stays
     * readable; it just stops being offered.
     *
     * @throws SeoCitationNotFoundException
     * @throws SeoCitationRefusedException
     */
    public function archiveCustomDirectory(int $actorUserId, Business $business, string $locationUid, string $directoryKey): void
    {
        $location = $this->accessibleLocationByUid($actorUserId, $business, $locationUid);
        $this->assertOperational($location);

        $this->ownCustomDirectory($actorUserId, $business, $location, $directoryKey)->forceFill(['is_active' => false])->save();
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
     * An ACTIVE directory this Business may write to at this Location: a
     * platform directory that is OFFERED here (core or niche-recommended, and
     * applicable to the Location's country — the very rule that decides which
     * rows the page lets the owner edit), or its own custom one (scoped to this
     * Location if it was). Anything else — another Business's custom directory,
     * a custom one scoped to a different Location, a platform directory the
     * page shows read-only or not at all — is "not found", so a forged POST
     * cannot write what the UI would not offer.
     *
     * @throws SeoCitationNotFoundException
     */
    private function writableDirectory(Business $business, BusinessLocation $location, string $directoryKey): SeoCitationDirectory
    {
        $directory = SeoCitationDirectory::query()
            ->where('key', $directoryKey)
            ->where('is_active', true)
            ->where(function ($query) use ($business): void {
                $query->whereNull('business_id')->orWhere('business_id', $business->id);
            })
            ->first();

        if ($directory === null
            || ($directory->business_location_id !== null && (int) $directory->business_location_id !== (int) $location->id)
            || ! $this->appliesToCountry($directory, $location)
            || ! SeoCitationApplicability::isOffered($directory, $this->enabledRecommendation($business, $directory), $location)) {
            throw new SeoCitationNotFoundException('Directory not found.');
        }

        return $directory;
    }

    /**
     * The Business niche's ENABLED recommendation for one platform directory,
     * or null (also for a custom directory, which no niche recommends).
     */
    private function enabledRecommendation(Business $business, SeoCitationDirectory $directory): ?SeoNicheCitationRecommendation
    {
        $nicheKey = $business->industry?->value;

        if ($nicheKey === null || $directory->isCustom()) {
            return null;
        }

        return SeoNicheCitationRecommendation::query()
            ->where('niche_key', $nicheKey)
            ->where('is_enabled', true)
            ->where('seo_citation_directory_id', $directory->id)
            ->first();
    }

    /**
     * Country applicability, decided per LOCATION (a Business may have Locations in different
     * countries): a platform directory with a NULL country_scope applies everywhere; one with an
     * ISO-2 scope applies only where it equals the Location's country_code (case-insensitive). A
     * Location with no country code cannot prove a match, so a scoped directory does not apply
     * (fail closed). Business custom directories are never filtered by country.
     */
    private function appliesToCountry(SeoCitationDirectory $directory, BusinessLocation $location): bool
    {
        return SeoCitationApplicability::appliesToCountry($directory, $location);
    }

    /**
     * This Business's OWN custom directory, for a change (rename, link,
     * archive). A directory scoped to one Location may be changed by anyone who
     * can reach that Location. A directory that applies to ALL Locations is
     * shared by Locations the actor may not be able to reach, so it may be
     * changed only by an actor who can reach every one of them — otherwise a
     * user restricted to Location A could rename or archive what Location B
     * shows.
     *
     * @throws SeoCitationNotFoundException
     * @throws SeoCitationRefusedException
     */
    private function ownCustomDirectory(int $actorUserId, Business $business, BusinessLocation $location, string $directoryKey): SeoCitationDirectory
    {
        $directory = SeoCitationDirectory::query()
            ->where('key', $directoryKey)
            ->where('business_id', $business->id)
            ->first();

        if ($directory === null
            || ($directory->business_location_id !== null && (int) $directory->business_location_id !== (int) $location->id)) {
            throw new SeoCitationNotFoundException('Directory not found.');
        }

        if ($directory->business_location_id === null && ! $this->scope->accessesEveryLocation($actorUserId, $business)) {
            throw new SeoCitationRefusedException('directory', 'This directory is shared by all your locations, so only someone with access to every location can change it.');
        }

        return $directory;
    }

    /**
     * @throws SeoCitationRefusedException
     */
    private function assertOperational(BusinessLocation $location): void
    {
        if (! $location->isActive()) {
            throw new SeoCitationRefusedException('location', 'This location is archived, so its citations can no longer be edited.');
        }
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
        $listedWebsite = $this->boundedString($input['listed_website'] ?? null, self::MAX_WEBSITE, 'listed_website');
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
            'listed_website' => $listedWebsite,
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
     * @param  \Illuminate\Support\Collection<int, SeoNicheCitationRecommendation>  $recommendations  keyed by directory id
     * @param  \Illuminate\Support\Collection<string, SeoCitation>  $citations
     */
    private function section(Business $business, BusinessLocation $location, $directories, $recommendations, $citations, $google, bool $phoneComparable, bool $reachesEveryLocation): SeoCitationLocationSection
    {
        $addressPermitted = $this->addressPredicate->addressPermittedForLocation($location);
        $canonical = $this->comparator->canonicalFor($business, $location);
        $canonicalWebsite = $business->website_url;
        $writable = $location->isActive();
        $reviewAfter = Carbon::today()->subDays($this->config->citationsReviewAfterDays());
        $nicheLabel = $business->industry?->label();

        $rows = [];

        foreach ($directories as $directory) {
            $citation = $citations->get($location->id . ':' . $directory->id);
            $recommendation = $recommendations->get($directory->id);

            // A custom directory scoped to another Location is not part of
            // this Location's page at all.
            if ($directory->business_location_id !== null && (int) $directory->business_location_id !== (int) $location->id) {
                continue;
            }

            // OFFERED = new, actionable. A platform directory must be active, core or recommended by the
            // niche, AND apply to THIS Location's country (a recommendation never overrides that). A custom
            // directory is governed only by its own Business/Location scope.
            $offered = SeoCitationApplicability::isOffered($directory, $recommendation, $location);

            // Anything else is shown only where the Business already holds a
            // record for it (history), and is then read-only.
            if (! $offered && $citation === null) {
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
                ], $business->country_code, $location->country_code, $phoneComparable),
                writable: $writable && $offered,
                importance: $recommendation?->importance,
                nicheLabel: $recommendation !== null ? $nicheLabel : null,
                nicheGuidance: $recommendation?->guidance,
                listedWebsite: $citation?->listed_website,
                websiteResult: $this->comparator->compareWebsite($canonicalWebsite, $citation?->listed_website),
                offered: $offered,
                phoneCompared: $phoneComparable,
                reviewDue: $citation !== null
                    && $citation->status === SeoCitationStatus::Listed
                    && $citation->last_verified_at !== null
                    && $citation->last_verified_at->lt($reviewAfter),
            );
        }

        // The page order — the same one the Growth Center reads (SeoCitationApplicability::orderKey).
        usort($rows, fn (SeoCitationRow $a, SeoCitationRow $b): int => SeoCitationApplicability::orderKey($a->directory, $recommendations->get($a->directory->id))
            <=> SeoCitationApplicability::orderKey($b->directory, $recommendations->get($b->directory->id)));

        return new SeoCitationLocationSection(
            $location,
            $writable,
            $addressPermitted,
            $canonical,
            $rows,
            $google,
            $canonicalWebsite,
            $nicheLabel,
            $this->googleNap($canonical, $canonicalWebsite, $google, $business, $location, $phoneComparable),
            $phoneComparable,
            $reachesEveryLocation,
        );
    }

    /**
     * The Google row's per-field comparison, from the GBP read model only and
     * only while the connection is ACTIVE and the mirror is fresh (the reader
     * nulls the mirror fields otherwise). Computed here for display and
     * DISCARDED: nothing Google-derived is ever stored. The street address is
     * never part of it.
     *
     * @param  array{name: ?string, phone: ?string, address: ?string}  $canonical
     * @return array{name: SeoNapFieldResult, phone: SeoNapFieldResult, website: SeoNapFieldResult}|null null when not connected or no fresh mirror
     */
    private function googleNap(array $canonical, ?string $canonicalWebsite, $google, Business $business, BusinessLocation $location, bool $phoneComparable): ?array
    {
        if ($google === null || ! $google->bound || $google->connectionState !== 'active' || ! $google->mirrorIsFresh) {
            return null;
        }

        $result = $this->comparator->compare(
            ['name' => $canonical['name'], 'phone' => $canonical['phone'], 'address' => null],
            ['name' => $google->mirrorName, 'phone' => $google->mirrorPhone, 'address' => null],
            $business->country_code,
            $location->country_code,
            $phoneComparable,
        );

        return [
            'name' => $result['name'],
            'phone' => $result['phone'],
            'website' => $this->comparator->compareWebsite($canonicalWebsite, $google->mirrorWebsite),
        ];
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

    private function requiredBounded(mixed $value, int $max, string $field, string $message): string
    {
        $string = $this->boundedString($value, $max, $field);

        if ($string === null) {
            throw new SeoCitationRefusedException($field, $message);
        }

        return $string;
    }

    private function safeUrlOrNull(mixed $value, string $field): ?string
    {
        $url = $this->nullableString($value);

        if ($url !== null && ! SeoLinkSafety::isSafeHttpsUrl($url)) {
            throw new SeoCitationRefusedException($field, 'Enter a full link that starts with https://.');
        }

        return $url;
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

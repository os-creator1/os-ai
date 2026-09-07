<?php

namespace App\DTO\GoogleBusinessProfile;

use App\Library\GoogleBusinessProfile\GoogleProviderValueNormalizer as N;

/**
 * GBP Slice A contract §20.3 — one Google location offered to the user in
 * the chooser.
 *
 * CANDIDATES ARE NEVER PERSISTED (contract §8.3, test T-BIND-2). This DTO
 * exists for the lifetime of one request and is then discarded; only the
 * location the user explicitly confirms becomes a
 * business_google_locations row.
 *
 * It deliberately carries `localityHint` and NOT an address (contract
 * §23.3): a street address must never enter a DTO, so the invariant is
 * structural — there is no field here that could hold one.
 *
 * `matchScore` may ORDER the list and may drive a non-binding "likely
 * match" hint. It may never pre-select anything (contract §8.5,
 * test T-BIND-3).
 */
final readonly class GoogleLocationCandidate
{
    public function __construct(
        public string $resourceName,
        public string $accountResourceName,
        public ?string $title,
        public ?string $storeCode,
        public ?string $localityHint,
        public ?string $regionCode,
        public int $matchScore = 0,
    ) {
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromProviderArray(array $raw, string $accountResourceName, bool $addressPermitted): ?self
    {
        $resourceName = N::locationResourceName($raw['name'] ?? null);

        if ($resourceName === null) {
            return null;
        }

        // Contract §23.3 — enforcement point 2. Even if Google returned a
        // storefrontAddress despite the read mask omitting it, only the
        // locality may survive, and only when §23.2 permitted the field at
        // all. addressLines/sublocality/postalCode/sortingCode/
        // organization/recipients are dropped unconditionally, in every
        // case, for every location: they are simply never read here.
        $locality = $addressPermitted
            ? N::string(N::dig($raw, ['storefrontAddress', 'locality']), 120)
            : null;

        $regionCode = $addressPermitted
            ? N::regionCode(N::dig($raw, ['storefrontAddress', 'regionCode']))
            : null;

        return new self(
            resourceName: $resourceName,
            accountResourceName: $accountResourceName,
            title: N::string($raw['title'] ?? null, 191),
            storeCode: N::string($raw['storeCode'] ?? null, 64),
            localityHint: $locality,
            regionCode: $regionCode,
        );
    }

    public function withMatchScore(int $score): self
    {
        return new self(
            resourceName: $this->resourceName,
            accountResourceName: $this->accountResourceName,
            title: $this->title,
            storeCode: $this->storeCode,
            localityHint: $this->localityHint,
            regionCode: $this->regionCode,
            matchScore: $score,
        );
    }

    public function displayTitle(): string
    {
        return $this->title ?? $this->resourceName;
    }
}

<?php

namespace App\Library\GoogleBusinessProfile;

use App\DTO\GoogleBusinessProfile\GoogleAccountSummary;
use App\DTO\GoogleBusinessProfile\GoogleLocationCandidate;
use App\Enums\GoogleBusinessProfile\GoogleOperationType;
use App\Exceptions\GoogleBusinessProfile\GoogleBusinessProfileProviderException;
use App\Library\GoogleBusinessProfile\Contracts\GoogleBusinessProfileReadClient;
use App\Models\Business;
use App\Models\BusinessGoogleConnection;
use App\Models\BusinessLocation;

/**
 * GBP Slice A contract §8.3 / §8.5 — REQUEST-SCOPED account and location
 * enumeration.
 *
 * NO CANDIDATE IS EVER PERSISTED (test T-BIND-2). Candidates are fetched,
 * ranked, returned for rendering, and discarded when the request ends.
 * Only the location the user explicitly confirms becomes a
 * business_google_locations row.
 *
 * Ranking may ORDER the list and drive a non-binding "likely match" hint.
 * It may never pre-select anything and never submits a binding
 * (test T-BIND-3).
 */
final class GoogleBusinessProfileEnumerator
{
    public function __construct(
        private readonly GoogleBusinessProfileReadClient $client,
        private readonly GoogleBusinessProfileConnectionManager $connections,
        private readonly GoogleBusinessProfileReadMask $readMask,
        private readonly GoogleBusinessProfileOperationLedger $ledger,
    ) {
    }

    /**
     * @return array{accounts: array<int, GoogleAccountSummary>, candidates: array<int, GoogleLocationCandidate>}
     *
     * @throws GoogleBusinessProfileProviderException
     */
    public function enumerate(Business $business, BusinessGoogleConnection $connection, ?int $actorUserId): array
    {
        // Contract §24.9 — the token exchange and every provider call
        // happen outside any transaction. Nothing here opens one at all.
        $accessToken = $this->connections->accessTokenFor($connection);

        $accountsOperation = $this->ledger->open(
            businessId: (int) $business->id,
            type: GoogleOperationType::AccountsEnumerated,
            actorUserId: $actorUserId,
            summary: 'Listing accessible Google accounts',
            fingerprintParts: ['listAccounts'],
        );

        try {
            $accounts = $this->client->listAccounts($accessToken);
        } catch (GoogleBusinessProfileProviderException $exception) {
            $this->ledger->fail($accountsOperation, $exception, 'Listing accessible Google accounts');

            throw $exception;
        }

        $this->ledger->succeed($accountsOperation, count($accounts) . ' account(s) returned');

        $mask = $this->readMask->forEnumeration($business);
        $addressPermitted = $this->readMask->addressPermittedForEnumeration($business);

        $locationsOperation = $this->ledger->open(
            businessId: (int) $business->id,
            type: GoogleOperationType::LocationsEnumerated,
            actorUserId: $actorUserId,
            summary: 'Listing Google locations',
            // Contract §11.3 — method + resource scope + read-mask FIELD
            // NAMES only. Never anything containing Content.
            fingerprintParts: array_merge(['listLocations'], $mask),
        );

        $candidates = [];

        try {
            foreach ($accounts as $account) {
                foreach ($this->client->listLocations($accessToken, $account->resourceName, $mask, $addressPermitted) as $candidate) {
                    $candidates[] = $candidate;
                }
            }
        } catch (GoogleBusinessProfileProviderException $exception) {
            $this->ledger->fail($locationsOperation, $exception, 'Listing Google locations');

            throw $exception;
        }

        $candidates = $this->rank($business, $candidates);

        $this->ledger->succeed($locationsOperation, count($candidates) . ' candidate location(s) returned');

        return ['accounts' => $accounts, 'candidates' => $candidates];
    }

    /**
     * Contract §8.5 — deterministic ordering signals only. These may order
     * the list and drive a "likely match" hint; they never pre-select.
     *
     * @param  array<int, GoogleLocationCandidate>  $candidates
     * @return array<int, GoogleLocationCandidate>
     */
    private function rank(Business $business, array $candidates): array
    {
        $primary = BusinessLocation::query()
            ->where('business_id', $business->id)
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->first();

        $businessName = $this->normalize($business->name);
        $primaryCity = $primary !== null ? $this->normalize($primary->city) : null;
        $primaryCountry = $primary?->country_code;

        $scored = array_map(function (GoogleLocationCandidate $candidate) use ($businessName, $primaryCity, $primaryCountry) {
            $score = 0;

            if ($businessName !== null && $this->normalize($candidate->title) === $businessName) {
                $score += 50;
            }

            if ($primaryCity !== null && $candidate->localityHint !== null && $this->normalize($candidate->localityHint) === $primaryCity) {
                $score += 25;
            }

            if ($primaryCountry !== null && $candidate->regionCode === $primaryCountry) {
                $score += 10;
            }

            return $candidate->withMatchScore($score);
        }, $candidates);

        usort($scored, function (GoogleLocationCandidate $a, GoogleLocationCandidate $b) {
            if ($a->matchScore !== $b->matchScore) {
                return $b->matchScore <=> $a->matchScore;
            }

            return strcmp((string) $a->title, (string) $b->title);
        });

        return $scored;
    }

    /**
     * Contract §22.3's text rule, reused so the chooser's non-binding
     * "likely match" hint and the comparison table agree on what "the
     * same name" means.
     */
    private function normalize(?string $value): ?string
    {
        return GoogleBusinessProfileComparisonRules::text($value);
    }
}

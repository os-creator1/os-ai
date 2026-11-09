<?php

namespace App\Library\GoogleBusinessProfile;

use App\DTO\GoogleBusinessProfile\GoogleLocationProfile;
use App\Enums\GoogleBusinessProfile\GoogleConnectionProduct;
use App\Enums\GoogleBusinessProfile\GoogleOperationType;
use App\Exceptions\GoogleBusinessProfile\GoogleBusinessProfileProviderException;
use App\Library\GoogleBusinessProfile\Contracts\GoogleBusinessProfileReadClient;
use App\Models\Business;
use App\Models\BusinessGoogleConnection;
use LogicException;

/**
 * Reads ONE already-selected Google location for the onboarding preview.
 *
 * It is the onboarding twin of GoogleBusinessProfileMirrorService::refresh()
 * with three deliberate differences:
 *
 *  - there is no binding and no BusinessLocation yet, so the address
 *    permission is the customer's recorded onboarding consent, not a
 *    location's public_address;
 *  - NOTHING is persisted: the profile is returned to the caller for
 *    ephemeral rendering and nothing about it is written to the mirror,
 *    the database or the cache;
 *  - it still goes through the same budget, ledger and read-only provider
 *    client, so every outbound request is accounted to the Business and the
 *    GBP read-only boundary (no mutation method exists) is unchanged.
 */
final class GoogleBusinessProfileOnboardingReader
{
    public function __construct(
        private readonly GoogleBusinessProfileReadClient $client,
        private readonly GoogleBusinessProfileConnectionManager $connections,
        private readonly GoogleBusinessProfileReadMask $readMask,
        private readonly GoogleBusinessProfileOperationLedger $ledger,
        private readonly GoogleBusinessProfileCallBudget $budget,
    ) {
    }

    /**
     * @throws GoogleBusinessProfileProviderException
     */
    public function read(
        Business $business,
        BusinessGoogleConnection $connection,
        int $actorUserId,
        string $locationResourceName,
        bool $addressConsented,
    ): GoogleLocationProfile {
        if ($connection->product !== GoogleConnectionProduct::BusinessProfile) {
            throw new LogicException('Google Business Profile code may only operate on a business_profile connection.');
        }

        $mask = $this->readMask->forOnboarding($addressConsented);

        $operation = $this->ledger->open(
            businessId: (int) $business->id,
            type: GoogleOperationType::MirrorRefreshed,
            actorUserId: $actorUserId,
            summary: 'Reading selected profile for onboarding',
            fingerprintParts: array_merge(['getLocation', $locationResourceName], $mask),
        );

        try {
            $profile = $this->budget->withinOperation($connection, $operation, function () use ($connection, $locationResourceName, $mask, $addressConsented): GoogleLocationProfile {
                $accessToken = $this->connections->accessTokenFor($connection);

                return $this->client->getLocation($accessToken, $locationResourceName, $mask, $addressConsented);
            });
        } catch (GoogleBusinessProfileProviderException $exception) {
            $this->ledger->fail($operation, $exception, 'Reading selected profile for onboarding');

            throw $exception;
        }

        // The summary names the operation only — never a Google Content value.
        $this->ledger->succeed($operation, 'Onboarding profile read');

        return $profile;
    }
}

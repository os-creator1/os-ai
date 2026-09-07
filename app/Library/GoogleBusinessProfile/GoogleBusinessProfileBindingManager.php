<?php

namespace App\Library\GoogleBusinessProfile;

use App\Enums\GoogleBusinessProfile\GoogleOperationType;
use App\Events\GoogleBusinessProfile\GoogleBusinessProfileLocationBound;
use App\Events\GoogleBusinessProfile\GoogleBusinessProfileLocationUnbound;
use App\Exceptions\GoogleBusinessProfile\GoogleBusinessProfileProviderException;
use App\Exceptions\GoogleBusinessProfile\GoogleLocationAlreadyClaimedException;
use App\Library\GoogleBusinessProfile\Contracts\GoogleBusinessProfileReadClient;
use App\Models\Business;
use App\Models\BusinessGoogleConnection;
use App\Models\BusinessGoogleLocation;
use App\Models\BusinessGoogleOperation;
use App\Models\BusinessLocation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * GBP Slice A contract §19.2 — the exact bind sequence, and the only place
 * business_google_locations rows are created or deleted.
 *
 * Enforces C-2..C-5 in the service layer as well as in the database, so a
 * violation is a readable product message rather than a 500.
 *
 * NOTHING IS EVER AUTO-SELECTED (contract §8.5). This class is only ever
 * reached from an explicit POST carrying the user's chosen
 * provider_location_resource_name.
 */
final class GoogleBusinessProfileBindingManager
{
    public function __construct(
        private readonly GoogleBusinessProfileReadClient $client,
        private readonly GoogleBusinessProfileConnectionManager $connections,
        private readonly GoogleBusinessProfileReadMask $readMask,
        private readonly GoogleBusinessProfileMirrorService $mirror,
        private readonly GoogleBusinessProfileOperationLedger $ledger,
    ) {
    }

    public function findForBusiness(Business $business): ?BusinessGoogleLocation
    {
        return BusinessGoogleLocation::query()->where('business_id', $business->id)->first();
    }

    /**
     * Contract §19.2 steps 5-9.
     *
     * @throws GoogleBusinessProfileProviderException
     * @throws GoogleLocationAlreadyClaimedException
     */
    public function bind(
        Business $business,
        BusinessGoogleConnection $connection,
        BusinessLocation $location,
        string $accountResourceName,
        string $locationResourceName,
        int $actorUserId,
    ): BusinessGoogleLocation {
        $mask = $this->readMask->forLocation($location);
        $addressPermitted = $this->readMask->addressPermittedForLocation($location);

        // Step 6 — the ledger row, and therefore the unique
        // local_operation_key, exists BEFORE the provider call in step 5.
        $operation = $this->ledger->open(
            businessId: (int) $business->id,
            type: GoogleOperationType::LocationBound,
            actorUserId: $actorUserId,
            summary: 'Binding ' . $locationResourceName,
            fingerprintParts: array_merge(['getLocation', $locationResourceName], $mask),
        );

        try {
            // Step 5 — OUTSIDE any transaction (contract §24.9). Proves
            // the grant can actually read the chosen location; a
            // syntactically valid resource name the grant cannot reach
            // fails here and is recorded as a failed operation, never as a
            // binding.
            $accessToken = $this->connections->accessTokenFor($connection);
            $profile = $this->client->getLocation($accessToken, $locationResourceName, $mask, $addressPermitted);
            $state = $this->client->getVoiceOfMerchantState($accessToken, $locationResourceName);
        } catch (GoogleBusinessProfileProviderException $exception) {
            $this->ledger->fail($operation, $exception, 'Binding ' . $locationResourceName);

            throw $exception;
        }

        try {
            // Step 7 — one short transaction, no network inside it.
            $binding = DB::transaction(function () use ($business, $connection, $location, $accountResourceName, $locationResourceName, $profile, $actorUserId) {
                return BusinessGoogleLocation::create([
                    'business_google_connection_id' => $connection->id,
                    'business_id' => $business->id,
                    'business_location_id' => $location->id,
                    'provider_account_resource_name' => $accountResourceName,
                    'provider_location_resource_name' => $locationResourceName,
                    // Contract §11.2.1 — three narrow fields, never an
                    // address. Purged with the mirror by §13.2.
                    'bound_title_snapshot' => $profile->title,
                    'bound_locality_snapshot' => $profile->locality,
                    'bound_region_code_snapshot' => $profile->regionCode,
                    'bound_by_user_id' => $actorUserId,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // Contract §19.2 step 7 — C-2 or C-3 collided. Rendered as a
            // product message, never a 500, and never revealing which
            // Business holds the other claim.
            $this->ledger->failLocally(
                $operation,
                BusinessGoogleOperation::FAILURE_UNEXPECTED_RESPONSE,
                'Binding refused: provider location already claimed',
            );

            throw new GoogleLocationAlreadyClaimedException();
        }

        $this->mirror->store($binding, $profile, $state);

        $this->ledger->succeed($operation, 'Bound ' . $locationResourceName);
        $operation->forceFill(['business_google_location_id' => $binding->id])->save();

        GoogleBusinessProfileLocationBound::dispatch(
            (int) $business->id,
            (int) $location->id,
            (int) $binding->id,
            $actorUserId,
        );

        return $binding->refresh();
    }

    public function unbind(BusinessGoogleLocation $binding, ?int $actorUserId): void
    {
        $businessId = (int) $binding->business_id;
        $businessLocationId = (int) $binding->business_location_id;

        $operation = $this->ledger->open(
            businessId: $businessId,
            type: GoogleOperationType::LocationUnbound,
            actorUserId: $actorUserId,
            summary: 'Unbinding ' . $binding->provider_location_resource_name,
        );

        DB::transaction(function () use ($binding) {
            // Deleting the binding removes the mirror with it: there is no
            // separate copy of Google Content anywhere (contract §13.7).
            $binding->delete();
        });

        $this->ledger->succeed($operation, 'Unbound');

        GoogleBusinessProfileLocationUnbound::dispatch($businessId, $businessLocationId, $actorUserId);
    }
}

<?php

namespace App\Library\Messaging;

use App\Enums\Messaging\BusinessMessagingIdentityStatus;
use App\Enums\Messaging\BusinessMessagingNumberStatus;
use App\Enums\Messaging\MessagingProvider;
use App\Library\Messaging\Contracts\MessagingProvisioningAdapter;
use App\Library\Messaging\DTO\AvailableNumberCandidate;
use App\Library\Messaging\DTO\NumberSearchCriteria;
use App\Library\Messaging\Exceptions\MessagingIdentityConflictException;
use App\Library\Messaging\Exceptions\MessagingProviderNotConfiguredException;
use App\Models\Business;
use App\Models\BusinessMessagingNumber;
use Illuminate\Support\Facades\DB;

/**
 * Text messaging setup/number/compliance hub — STATE 1's "Get a phone
 * number" orchestration: a provider-neutral search, then a real order
 * that only writes BusinessMessagingIdentity/BusinessMessagingNumber
 * after a genuine (or explicitly-faked-in-a-test) provider round trip.
 *
 * Mirrors ManagedMessageDispatcher::dispatch()'s own discipline: the
 * adapter is resolved lazily, inside each method, never injected through
 * this service's own constructor — injecting it would make merely
 * constructing this service (e.g. via the container resolving a
 * controller's dependencies) throw MessagingProviderNotConfiguredException
 * whenever managed messaging is off, which is wrong for a service whose
 * whole job includes rendering a truthful INERT state rather than
 * exploding.
 */
class BusinessMessagingProvisioningService
{
    public function __construct(private readonly BusinessMessagingIdentityResolver $identities)
    {
    }

    public function isAvailable(): bool
    {
        return ProvisioningAvailability::isConfigured();
    }

    /**
     * @return list<AvailableNumberCandidate>
     */
    public function searchNumbers(NumberSearchCriteria $criteria): array
    {
        try {
            $adapter = app(MessagingProvisioningAdapter::class);
        } catch (MessagingProviderNotConfiguredException) {
            // Preview/inert state — never a live search, never a guessed
            // result list.
            return [];
        }

        return $adapter->searchNumbers($criteria);
    }

    /**
     * Places the real order and persists the resulting identity/number
     * together, inside one transaction. Only ever called with a candidate
     * the caller obtained from searchNumbers() in this same request —
     * there is no path that reaches a persisted BusinessMessagingNumber
     * without a genuine adapter round trip producing a
     * ProvisionedNumberResult first (see MessagingProvisioningAdapter's
     * own docblock).
     *
     * @throws MessagingProviderNotConfiguredException when not configured — the
     *                                                  caller must have already
     *                                                  checked isAvailable()
     *                                                  before offering this
     *                                                  action at all
     * @throws MessagingIdentityConflictException       when the Business already
     *                                                  has an active-or-pending
     *                                                  identity or number
     */
    public function provisionNumber(Business $business, AvailableNumberCandidate $candidate): BusinessMessagingNumber
    {
        // Deliberately NOT caught here — provisionNumber() must never be
        // reachable at all unless the caller already confirmed
        // isAvailable(); a thrown exception at this point is a caller bug,
        // not a state this method silently absorbs into an inert render.
        $adapter = app(MessagingProvisioningAdapter::class);

        $result = $adapter->provisionNumber($business, $candidate);

        return DB::transaction(function () use ($business, $candidate, $result): BusinessMessagingNumber {
            $identity = $this->identities->resolveForBusiness($business);

            if ($identity === null) {
                $identity = $this->identities->create(
                    $business,
                    $result->messagingProfileId,
                    null,
                    MessagingProvider::Telnyx,
                    BusinessMessagingIdentityStatus::Active,
                );
            }

            return $this->identities->attachNumber(
                $identity,
                $result->phoneNumber,
                true,
                BusinessMessagingNumberStatus::Active,
                $result->providerPhoneNumberId,
                $candidate->numberType,
            );
        });
    }
}

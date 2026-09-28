<?php

namespace App\Library\Messaging;

use App\Enums\Messaging\MessagingRegistrationStatus;
use App\Enums\Messaging\PhoneNumberType;
use App\Library\Dashboard\DashboardMoney;
use App\Library\Messaging\Contracts\MessagingProvisioningAdapter;
use App\Library\Messaging\DTO\MessagingRegistrationSubmission;
use App\Library\Messaging\DTO\RegistrationStatusQuery;
use App\Library\Messaging\Exceptions\MessagingIdentityConflictException;
use App\Library\Messaging\Exceptions\MessagingProviderNotConfiguredException;
use App\Library\Messaging\Exceptions\MessagingRegistrationImmutableException;
use App\Models\Business;
use App\Models\BusinessMessagingRegistration;
use App\Repositories\Contracts\UsageMeterRepository;
use Carbon\CarbonImmutable;

/**
 * Text messaging setup/number/compliance hub — STATE 2's capture/submit/
 * refresh lifecycle for one Business's carrier registration.
 *
 * "Capture Business data once and reuse it where possible" is this
 * class's own responsibility: captureDetails() is idempotent per Business
 * (create-or-update the one row a Business ever has, per the table's own
 * unique constraint) so re-visiting the form after a rejection edits the
 * SAME record rather than creating a second one.
 */
class BusinessMessagingRegistrationService
{
    public function __construct(
        private readonly BusinessMessagingIdentityResolver $identities,
        private readonly UsageMeterRepository $meters,
    ) {
    }
    /**
     * @param  array<string, mixed>  $data  already-validated form input,
     *                                      keyed exactly like the model's
     *                                      own fillable fields
     *
     * @throws MessagingRegistrationImmutableException when the existing
     *                                                  record is already
     *                                                  Approved (PR #295
     *                                                  Correction Round 1,
     *                                                  item 8) — once a
     *                                                  carrier has approved
     *                                                  the exact data this
     *                                                  platform submitted,
     *                                                  the normal customer
     *                                                  edit route must
     *                                                  never silently drift
     *                                                  it out of sync
     */
    public function captureDetails(Business $business, array $data): BusinessMessagingRegistration
    {
        $registration = BusinessMessagingRegistration::query()
            ->where('business_id', $business->id)
            ->first();

        if ($registration !== null && $registration->status === MessagingRegistrationStatus::Approved) {
            throw new MessagingRegistrationImmutableException(
                'This registration has already been approved and its legal/compliance details can no longer be edited through this form.',
            );
        }

        $attributes = array_merge($data, ['business_id' => $business->id]);

        if ($registration === null) {
            // The schema's own DEFAULT 'not_started' only takes effect once
            // MySQL has actually written the row; the in-memory model
            // create() returns would otherwise carry a null status until
            // the next fresh() read. Set it explicitly so the object this
            // method hands back is correct immediately.
            $attributes['status'] = $attributes['status'] ?? MessagingRegistrationStatus::NotStarted->value;

            return BusinessMessagingRegistration::create($attributes);
        }

        // Editing a rejected or not-yet-submitted registration always
        // clears any previous rejection — the customer is trying again
        // with (presumably) corrected information, and a stale rejection
        // reason must never linger beside fresh data.
        $attributes['status'] = MessagingRegistrationStatus::NotStarted->value;
        $attributes['rejection_reason'] = null;

        $registration->update($attributes);

        return $registration->fresh();
    }

    /**
     * @throws MessagingProviderNotConfiguredException when not configured — the
     *                                                  caller must have already
     *                                                  checked
     *                                                  ProvisioningAvailability::isConfigured()
     */
    public function submit(BusinessMessagingRegistration $registration): BusinessMessagingRegistration
    {
        $adapter = app(MessagingProvisioningAdapter::class);

        // Review correction — a toll-free submission's own request body
        // must name the already-owned number being verified
        // (MessagingRegistrationSubmission::$phoneNumber); a local (10DLC)
        // submission has no such requirement and may genuinely be
        // submitted before any number exists, so $phoneNumber stays null
        // there — never resolved, never required.
        $phoneNumber = $this->primaryPhoneNumberFor($registration->business);

        $result = $adapter->submitRegistration(MessagingRegistrationSubmission::fromModel($registration, $phoneNumber));

        $registration->update([
            'provider_brand_id' => $result->providerBrandId,
            'provider_campaign_id' => $result->providerCampaignId,
            'provider_registration_id' => $result->providerRegistrationId,
            'status' => $result->status->value,
            'submitted_at' => CarbonImmutable::now(),
            'rejection_reason' => null,
        ]);

        return $registration->fresh();
    }

    private function primaryPhoneNumberFor(Business $business): ?string
    {
        $identity = $this->identities->resolveForBusiness($business);

        if ($identity === null) {
            return null;
        }

        try {
            return $this->identities->resolvePrimaryNumber($identity)->phone_number;
        } catch (MessagingIdentityConflictException) {
            return null;
        }
    }

    /**
     * Polls the provider and applies whatever it says — never advances the
     * status without an explicit provider answer.
     *
     * PR #295 Correction Round 1, item 7 — routes through the type-aware
     * RegistrationStatusQuery instead of assuming every registration has a
     * brand+campaign pair.
     */
    public function refreshStatus(BusinessMessagingRegistration $registration): BusinessMessagingRegistration
    {
        $query = RegistrationStatusQuery::fromModel($registration);

        if ($query->providerBrandId === null && $query->providerCampaignId === null && $query->providerRegistrationId === null) {
            return $registration;
        }

        try {
            $adapter = app(MessagingProvisioningAdapter::class);
        } catch (MessagingProviderNotConfiguredException) {
            return $registration;
        }

        $result = $adapter->refreshRegistrationStatus($query);

        if ($result->status === $registration->status) {
            return $registration;
        }

        $attributes = ['status' => $result->status->value];

        if ($result->status === MessagingRegistrationStatus::Approved) {
            $attributes['approved_at'] = CarbonImmutable::now();
        } elseif ($result->status === MessagingRegistrationStatus::Rejected) {
            $attributes['rejected_at'] = CarbonImmutable::now();
            // Review correction — the carrier-supplied reason, when the
            // adapter's own response actually carried one; null when it
            // did not, so the customer sees an explicit "no detailed
            // reason was supplied" message rather than nothing at all.
            $attributes['rejection_reason'] = $result->rejectionReason;
        }

        $registration->update($attributes);

        return $registration->fresh();
    }

    /**
     * PR #295 Correction Round 1, item 6 — the one canonical mechanism
     * that actually advances a submitted registration: a scheduled job
     * (RefreshPendingMessagingRegistrations) calls this, never a page
     * render. One provider-configuration failure never aborts the rest of
     * the batch.
     */
    public function refreshAllPending(): void
    {
        BusinessMessagingRegistration::query()
            ->where('status', MessagingRegistrationStatus::Pending->value)
            ->orderBy('id')
            ->chunkById(50, function ($registrations): void {
                foreach ($registrations as $registration) {
                    try {
                        $this->refreshStatus($registration);
                    } catch (\Throwable) {
                        // One Business's provider failure must never stop
                        // the sweep from reaching every other pending
                        // registration in this batch.
                        continue;
                    }
                }
            });
    }

    /**
     * Review correction — TelnyxProvisioningAdapter reserves real wallet
     * funds for both FEATURE_TEN_DLC_REGISTRATION and
     * FEATURE_TOLL_FREE_VERIFICATION before ever submitting a
     * registration; business verification was never actually free, and
     * this platform must never claim otherwise. Reads the SAME meter/rate
     * UsageWalletManager::reserve() would itself apply — never a second,
     * invented pricing source — and states plainly when none is
     * configured, which is the true state of every environment today (no
     * owner has approved a commercial rate for either feature key yet).
     */
    public function chargeDisclosureFor(PhoneNumberType $numberType): string
    {
        $meterKey = $numberType === PhoneNumberType::TollFree
            ? TelnyxProvisioningAdapter::FEATURE_TOLL_FREE_VERIFICATION
            : TelnyxProvisioningAdapter::FEATURE_TEN_DLC_REGISTRATION;

        $meter = $this->meters->findByMeterKey($meterKey);

        if ($meter === null || ! $meter->is_metered || $meter->activeRate === null) {
            return 'No fee is currently configured for business verification in this environment.';
        }

        $rate = $meter->activeRate;
        $amount = DashboardMoney::format((string) $rate->retail_rate_micro, $rate->currency?->code);
        $unit = $rate->unit_label !== null && $rate->unit_label !== '' ? ' per ' . $rate->unit_label : '';

        return sprintf('Business verification currently costs %s%s.', $amount, $unit);
    }
}

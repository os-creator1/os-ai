<?php

namespace App\Library\Messaging;

use App\Enums\Messaging\MessagingRegistrationStatus;
use App\Library\Messaging\Contracts\MessagingProvisioningAdapter;
use App\Library\Messaging\DTO\MessagingRegistrationSubmission;
use App\Library\Messaging\Exceptions\MessagingProviderNotConfiguredException;
use App\Models\Business;
use App\Models\BusinessMessagingRegistration;
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
    /**
     * @param  array<string, mixed>  $data  already-validated form input,
     *                                      keyed exactly like the model's
     *                                      own fillable fields
     */
    public function captureDetails(Business $business, array $data): BusinessMessagingRegistration
    {
        $registration = BusinessMessagingRegistration::query()
            ->where('business_id', $business->id)
            ->first();

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
        if ($registration->status !== MessagingRegistrationStatus::Approved) {
            $attributes['status'] = MessagingRegistrationStatus::NotStarted->value;
            $attributes['rejection_reason'] = null;
        }

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

        $result = $adapter->submitRegistration(MessagingRegistrationSubmission::fromModel($registration));

        $registration->update([
            'provider_brand_id' => $result->providerBrandId,
            'provider_campaign_id' => $result->providerCampaignId,
            'status' => $result->status->value,
            'submitted_at' => CarbonImmutable::now(),
            'rejection_reason' => null,
        ]);

        return $registration->fresh();
    }

    /**
     * Polls the provider and applies whatever it says — never advances the
     * status without an explicit provider answer.
     */
    public function refreshStatus(BusinessMessagingRegistration $registration): BusinessMessagingRegistration
    {
        if ($registration->provider_brand_id === null || $registration->provider_campaign_id === null) {
            return $registration;
        }

        try {
            $adapter = app(MessagingProvisioningAdapter::class);
        } catch (MessagingProviderNotConfiguredException) {
            return $registration;
        }

        $status = $adapter->refreshRegistrationStatus($registration->provider_brand_id, $registration->provider_campaign_id);

        if ($status === $registration->status) {
            return $registration;
        }

        $attributes = ['status' => $status->value];

        if ($status === MessagingRegistrationStatus::Approved) {
            $attributes['approved_at'] = CarbonImmutable::now();
        } elseif ($status === MessagingRegistrationStatus::Rejected) {
            $attributes['rejected_at'] = CarbonImmutable::now();
        }

        $registration->update($attributes);

        return $registration->fresh();
    }
}

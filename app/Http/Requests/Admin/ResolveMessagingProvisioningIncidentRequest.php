<?php

namespace App\Http\Requests\Admin;

use App\Library\Messaging\TelnyxProvisioningAdapter;
use App\Models\BusinessMessagingProvisioningIncident;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorization for this action is the route's own group middleware
 * ('can:access backend' + EnsureUserIsAdministrator), not this request —
 * the same division of responsibility as DispositionPaymentProviderEventRequest.
 *
 * 'reconciliation_confirmed' is a human attestation, not a claim the app
 * verified anything: nothing here calls Telnyx or checks any local record on
 * the operator's behalf. It exists so the operator affirmatively states they
 * personally checked the provider resource and this platform's own records
 * before the incident can be closed, on top of (not instead of) the required
 * free-text 'resolution_note' describing what was checked and how.
 */
class ResolveMessagingProvisioningIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        $rules = [
            'reconciliation_confirmed' => ['required', 'accepted'],
            'resolution_note' => ['required', 'string', 'max:5000'],
        ];

        // An unknown 10DLC outcome means a brand/campaign may already exist
        // at the provider. A note alone must never re-open submission: the
        // operator records what the provider actually holds, and that state
        // is written back to the registration so a retry reuses it.
        $incident = BusinessMessagingProvisioningIncident::query()->find((int) $this->route('incident'));

        if ($incident !== null && in_array($incident->stage, TelnyxProvisioningAdapter::UNCONFIRMED_OUTCOME_STAGES, true)) {
            $allowed = $incident->stage === TelnyxProvisioningAdapter::INCIDENT_FUNDING_AMBIGUOUS
                ? 'brand_and_campaign_created'
                : 'nothing_created,brand_created,brand_and_campaign_created';

            $rules['provider_state'] = ['required', 'in:' . $allowed];
            $rules['provider_brand_id'] = ['required_if:provider_state,brand_created,brand_and_campaign_created', 'nullable', 'string', 'max:191'];
            $rules['provider_campaign_id'] = ['required_if:provider_state,brand_and_campaign_created', 'nullable', 'string', 'max:191'];
        }

        return $rules;
    }
}

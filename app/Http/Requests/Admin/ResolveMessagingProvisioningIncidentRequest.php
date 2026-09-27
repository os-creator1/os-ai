<?php

namespace App\Http\Requests\Admin;

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
        return [
            'reconciliation_confirmed' => ['required', 'accepted'],
            'resolution_note' => ['required', 'string', 'max:5000'],
        ];
    }
}

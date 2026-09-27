<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorization for this action is the route's own group middleware
 * ('can:access backend' + EnsureUserIsAdministrator), not this request —
 * the same division of responsibility as
 * ResolveMessagingProvisioningIncidentRequest.
 *
 * 'release_confirmed' is a human attestation, mirroring the incident
 * reconciliation surface's own 'reconciliation_confirmed': the app has
 * verified the mechanical preconditions (Suspended, grace expired, notice
 * confirmed delivered for the required minimum notice period, no active
 * port-out request — NumberLifecycleManager::recordReleaseDecision()
 * itself), but the operator is affirmatively confirming this Business has
 * been given every reasonable chance to pay or port out, on top of the
 * required free-text note. This attestation is for the release DECISION
 * only — it never claims the carrier has actually released the number.
 */
class ReleaseMessagingNumberRequest extends FormRequest
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
            'release_confirmed' => ['required', 'accepted'],
            'note' => ['required', 'string', 'max:5000'],
        ];
    }
}

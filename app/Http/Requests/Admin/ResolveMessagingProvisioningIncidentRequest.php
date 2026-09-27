<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorization for this action is the route's own group middleware
 * ('can:access backend' + EnsureUserIsAdministrator), not this request —
 * the same division of responsibility as DispositionPaymentProviderEventRequest.
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
            'resolution_note' => ['required', 'string', 'max:5000'],
        ];
    }
}

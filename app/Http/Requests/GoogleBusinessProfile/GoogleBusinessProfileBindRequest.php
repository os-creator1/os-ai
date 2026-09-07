<?php

namespace App\Http\Requests\GoogleBusinessProfile;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GBP Slice A contract §18.2 — SHAPE validation only.
 *
 * authorize() returns true deliberately: a FormRequest cannot see the
 * resolved Business, so AUTHORIZATION IS THE CONTROLLER'S §15 CHAIN. The
 * business_location_uid is resolved INSIDE the already-resolved Business
 * by the controller, and a foreign or unknown uid is a 404.
 *
 * VALIDATION NEVER PROVES OWNERSHIP: a syntactically valid
 * locations/{id} that the grant cannot actually read passes here and then
 * fails at the provider call, recorded as a failed operation rather than
 * a binding (contract §19.2 step 5).
 */
class GoogleBusinessProfileBindRequest extends FormRequest
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
            'provider_account_resource_name' => ['required', 'string', 'max:191', 'regex:/^accounts\/[A-Za-z0-9_-]+$/'],
            'provider_location_resource_name' => ['required', 'string', 'max:191', 'regex:/^locations\/[A-Za-z0-9_-]+$/'],
            'business_location_uid' => ['required', 'string', 'max:64'],
        ];
    }
}

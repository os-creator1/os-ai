<?php

namespace App\Http\Requests\GoogleBusinessProfile;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GBP Slice A contract §18.2 — shape only; the binding uid is resolved
 * THROUGH the already-resolved Business by the controller (§15.3).
 */
class GoogleBusinessProfileUnbindRequest extends FormRequest
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
            'binding_uid' => ['required', 'string', 'max:64'],
        ];
    }
}

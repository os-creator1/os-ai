<?php

namespace App\Http\Requests\Customer\Business;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBusinessPayerRequest extends FormRequest
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
            // agency_rebill is never customer-selectable at M2 (M2
            // contract §9) — deliberately excluded from this list.
            'payer_type' => ['required', 'string', 'in:business,workspace'],
        ];
    }
}

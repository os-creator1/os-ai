<?php

namespace App\Http\Requests\Customer\Business;

use Illuminate\Foundation\Http\FormRequest;

class CreateSetupIntentRequest extends FormRequest
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
        return [];
    }
}

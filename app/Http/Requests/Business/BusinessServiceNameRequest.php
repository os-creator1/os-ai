<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Settings → Business → Services — the one field the editor takes: a service name. Authority and tenancy are proven by
 * the controller and BusinessServiceManager (under the Business lock), not here.
 */
class BusinessServiceNameRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
        ];
    }
}

<?php

namespace App\Http\Requests\Customer\Business;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBusinessBillingContactRequest extends FormRequest
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
            'contact_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'contact_name' => ['nullable', 'required_without:contact_user_id', 'string', 'max:191'],
            'contact_email' => ['nullable', 'required_without:contact_user_id', 'string', 'email', 'max:191'],
            'notification_opt_in' => ['nullable', 'boolean'],
        ];
    }
}

<?php

namespace App\Http\Requests\Customer\Workspace;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWorkspaceMemberAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'business_access_scope' => ['required', Rule::in(['all', 'selected'])],
            'business_uids' => ['array', 'prohibited_if:business_access_scope,all'],
            'business_uids.*' => ['string', 'max:255', 'distinct'],
        ];
    }
}

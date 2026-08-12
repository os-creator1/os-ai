<?php

namespace App\Http\Requests\Customer\Workspace;

use Illuminate\Foundation\Http\FormRequest;

class TransferWorkspaceOwnershipRequest extends FormRequest
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
            'new_owner_user_uid' => ['required', 'string', 'max:255'],
            'previous_owner_disposition' => ['required', 'in:deactivate,convert_to_admin'],
            'business_access_scope' => [
                'prohibited_if:previous_owner_disposition,deactivate',
                'required_if:previous_owner_disposition,convert_to_admin',
                'in:all,selected',
            ],
            'business_uids' => [
                'array',
                'prohibited_if:previous_owner_disposition,deactivate',
                'prohibited_if:business_access_scope,all',
            ],
            'business_uids.*' => ['string', 'max:255', 'distinct'],
        ];
    }
}

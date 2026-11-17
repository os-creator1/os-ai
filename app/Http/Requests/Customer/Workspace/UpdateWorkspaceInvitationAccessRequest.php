<?php

namespace App\Http\Requests\Customer\Workspace;

use App\Enums\Workspace\WorkspaceMemberModule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Settings → Team → Pending invitations → Edit access. Same fields as the invite form, minus the email. */
class UpdateWorkspaceInvitationAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::in(['admin', 'staff'])],
            'business_access_scope' => ['required', Rule::in(['all', 'selected'])],
            'business_uids' => ['array', 'prohibited_if:business_access_scope,all'],
            'business_uids.*' => ['string', 'max:255', 'distinct'],
            'visible_modules' => ['nullable', 'array'],
            'visible_modules.*' => ['string', Rule::in(WorkspaceMemberModule::values()), 'distinct'],
        ];
    }
}

<?php

namespace App\Http\Requests\Customer\Workspace;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Add an existing Business OS user to a Workspace.
 *
 * The person is identified by their EMAIL ADDRESS — the one identifier a
 * customer actually knows. The internal User uid is never asked for and never
 * accepted from this form; WorkspaceController::storeMember() resolves the
 * address server-side and hands only the numeric id to WorkspaceManager.
 */
class StoreWorkspaceMemberRequest extends FormRequest
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
            'member_email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', Rule::in(['admin', 'staff'])],
            'business_access_scope' => ['required', Rule::in(['all', 'selected'])],
            'business_uids' => ['array', 'prohibited_if:business_access_scope,all'],
            'business_uids.*' => ['string', 'max:255', 'distinct'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'member_email.required' => 'Enter the email address of the person you want to add.',
            'member_email.email' => 'Enter a valid email address, like name@example.com.',
            'member_email.max' => 'That email address is too long.',
        ];
    }
}

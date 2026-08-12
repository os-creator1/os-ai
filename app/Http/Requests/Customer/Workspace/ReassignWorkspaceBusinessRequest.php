<?php

namespace App\Http\Requests\Customer\Workspace;

use Illuminate\Foundation\Http\FormRequest;

class ReassignWorkspaceBusinessRequest extends FormRequest
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
            'target_workspace_uid' => ['required', 'string', 'max:255'],
        ];
    }
}

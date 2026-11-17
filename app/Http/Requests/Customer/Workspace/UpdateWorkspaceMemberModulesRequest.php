<?php

namespace App\Http\Requests\Customer\Workspace;

use App\Enums\Workspace\WorkspaceMemberModule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWorkspaceMemberModulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `visible_modules` is the set of switches left ON. A browser sends nothing
     * for a switch that is off, so an absent key legitimately means "none".
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'visible_modules' => ['nullable', 'array'],
            'visible_modules.*' => ['string', Rule::in(WorkspaceMemberModule::values()), 'distinct'],
        ];
    }
}

<?php

namespace App\Http\Requests\Admin\Workspace;

use App\Enums\Entitlement\WorkspacePlanTier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class ChangeWorkspacePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'tier' => ['required', new Enum(WorkspacePlanTier::class)],
            'reason' => ['nullable', 'string'],
            'additional_business_slots' => ['nullable', 'integer', 'in:0,1,2'],
        ];
    }
}

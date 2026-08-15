<?php

namespace App\Http\Requests\Admin\Workspace;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWorkspaceAdditionalSlotsRequest extends FormRequest
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
            'additional_business_slots' => ['required', 'integer', 'in:0,1,2'],
            'reason' => ['nullable', 'string'],
        ];
    }
}

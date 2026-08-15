<?php

namespace App\Http\Requests\Admin\Workspace;

use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class ChangeWorkspacePlanStatusRequest extends FormRequest
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
            'status' => ['required', new Enum(WorkspacePlanAssignmentStatus::class)],
            'reason' => ['required', 'string'],
        ];
    }
}

<?php

namespace App\Http\Requests\Admin\Workspace;

use Illuminate\Foundation\Http\FormRequest;

class GrantWorkspaceComplimentaryStatusRequest extends FormRequest
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
            'reason' => ['required', 'string'],
        ];
    }
}

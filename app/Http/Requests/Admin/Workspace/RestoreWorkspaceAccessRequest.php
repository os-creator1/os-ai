<?php

namespace App\Http\Requests\Admin\Workspace;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Platform Owner / Admin V1 — restore-access input: a required reason (kept
 * in the audit trail) and an explicit confirmation. Authority is NOT decided
 * here; the route group, the controller's permission check and
 * PlatformOwnerAccountActions all decide it independently.
 */
class RestoreWorkspaceAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000', 'regex:/\S/'],
            'confirm' => ['accepted'],
        ];
    }
}

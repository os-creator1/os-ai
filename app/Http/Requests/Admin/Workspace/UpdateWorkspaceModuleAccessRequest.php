<?php

namespace App\Http\Requests\Admin\Workspace;

use App\Library\Entitlement\WorkspaceModuleCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWorkspaceModuleAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * `mixed` is the read-only marker for a module whose stored overrides were
     * set through the advanced form; posting it leaves that module unchanged.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'modules' => ['required', 'array'],
            'modules.*' => ['required', 'string', Rule::in([
                WorkspaceModuleCatalog::STATE_DEFAULT,
                WorkspaceModuleCatalog::STATE_ENABLED,
                WorkspaceModuleCatalog::STATE_DISABLED,
                WorkspaceModuleCatalog::STATE_MIXED,
            ])],
            'reason' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, string> module id => default|enabled|disabled, known modules only
     */
    public function requestedModuleStates(): array
    {
        $states = [];

        foreach ((array) $this->validated('modules') as $moduleId => $state) {
            if (WorkspaceModuleCatalog::has((string) $moduleId) && $state !== WorkspaceModuleCatalog::STATE_MIXED) {
                $states[(string) $moduleId] = (string) $state;
            }
        }

        return $states;
    }
}

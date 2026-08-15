<?php

namespace App\Http\Requests\Admin\Workspace;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspaceEntitlementOverrideState;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreWorkspaceEntitlementOverrideRequest extends FormRequest
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
            'feature_key' => ['required', new Enum(PlatformFeature::class)],
            'state' => ['required', new Enum(WorkspaceEntitlementOverrideState::class)],
            'reason' => ['required', 'string'],
        ];
    }
}

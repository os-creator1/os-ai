<?php

namespace App\Http\Requests\Business;

use App\Enums\Business\BusinessServiceMode;
use Illuminate\Validation\Rule;

/**
 * Customer Experience Slice 1A — a NEW physical location. Reuses the
 * onboarding location rules (UpsertBusinessLocationRequest, RFC-001 §17)
 * and adds two things a second location needs: a name that tells the
 * locations apart, and a physical service mode (a storefront, a service
 * area, or both — never "online only", which is not a place).
 */
class StoreBusinessLocationRequest extends UpsertBusinessLocationRequest
{
    public const PHYSICAL_MODES = [
        BusinessServiceMode::Storefront->value,
        BusinessServiceMode::ServiceArea->value,
        BusinessServiceMode::Hybrid->value,
    ];

    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'name' => ['required', 'string', 'max:255'],
            'service_mode' => ['required', Rule::in(self::PHYSICAL_MODES)],
        ]);
    }
}

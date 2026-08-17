<?php

namespace App\Http\Requests\Customer\Business;

use Illuminate\Foundation\Http\FormRequest;

class ConfigureAutoRechargeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * M3 contract §18 — enabling requires all three fields together; no
     * default value is invented for any of them (M3 contract §5 item 4).
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'auto_recharge_enabled' => ['required', 'boolean'],
            'auto_recharge_threshold_micro' => ['required_if:auto_recharge_enabled,1', 'nullable', 'integer', 'min:1'],
            'auto_recharge_amount_micro' => ['required_if:auto_recharge_enabled,1', 'nullable', 'integer', 'min:1'],
            'monthly_recharge_cap_micro' => ['nullable', 'integer', 'min:1'],
        ];
    }
}

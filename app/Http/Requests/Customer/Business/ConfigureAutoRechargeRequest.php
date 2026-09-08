<?php

namespace App\Http\Requests\Customer\Business;

use App\Library\Usage\UsageWalletManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Customer Experience Slice 5 (contract §12.2 E-17, §28.9; T-WALLET-2/4/5)
 * — automatic top-up is configured only with one of the four fixed preset
 * amounts. There is no custom amount field: a crafted value outside the
 * presets fails validation here and, independently, at
 * UsageWalletManager::configureAutoRecharge(). Decimal entries for the
 * trigger level and the monthly limit are converted with exact bcmath
 * arithmetic, never floats.
 */
class ConfigureAutoRechargeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        foreach (['auto_recharge_threshold' => 'auto_recharge_threshold_micro', 'monthly_recharge_cap' => 'monthly_recharge_cap_micro'] as $decimalField => $microField) {
            $value = $this->input($decimalField);

            if (is_string($value) && preg_match('/^\s*\d{1,9}(\.\d{1,2})?\s*$/', $value) === 1) {
                $merge[$microField] = bcmul(trim($value), '1000000', 0);
            } elseif (is_string($value) && trim($value) === '') {
                $merge[$microField] = null;
            }
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'auto_recharge_enabled' => ['required', 'boolean'],
            'auto_recharge_threshold_micro' => ['required_if:auto_recharge_enabled,1', 'nullable', 'integer', 'min:1'],
            'auto_recharge_amount_micro' => ['required_if:auto_recharge_enabled,1', 'nullable', 'integer', Rule::in(UsageWalletManager::AUTO_RECHARGE_PRESETS_MICRO)],
            'monthly_recharge_cap_micro' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'auto_recharge_threshold_micro.required_if' => __('locale.usage_billing.validation.auto_recharge_threshold_required'),
            'auto_recharge_amount_micro.required_if' => __('locale.usage_billing.validation.auto_recharge_preset_required'),
            'auto_recharge_amount_micro.in' => __('locale.usage_billing.validation.auto_recharge_preset_only'),
            'auto_recharge_amount_micro.integer' => __('locale.usage_billing.validation.auto_recharge_preset_only'),
        ];
    }
}

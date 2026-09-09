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
            // Correction Round 1 §6.1 — required while enabling, at least the
            // chosen preset, never above the approved hard maximum. The manager
            // re-validates the same policy (UsageWalletManager::
            // autoRechargeConfigurationProblem()) so a crafted POST that skips
            // this layer is refused there too.
            'monthly_recharge_cap_micro' => [
                'required_if:auto_recharge_enabled,1',
                'nullable',
                'integer',
                'min:1',
                'max:' . UsageWalletManager::BUSINESS_MONTHLY_AUTO_RECHARGE_MAXIMUM_MICRO,
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $amount = $this->input('auto_recharge_amount_micro');

                    if ($this->boolean('auto_recharge_enabled')
                        && is_scalar($amount)
                        && preg_match('/^\d+$/', (string) $amount) === 1
                        && preg_match('/^\d+$/', (string) $value) === 1
                        && bccomp((string) $value, (string) $amount) < 0) {
                        $fail(__('locale.usage_billing.validation.monthly_cap_below_preset'));
                    }
                },
            ],
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
            'monthly_recharge_cap_micro.required_if' => __('locale.usage_billing.validation.monthly_cap_required'),
            'monthly_recharge_cap_micro.min' => __('locale.usage_billing.validation.monthly_cap_required'),
            'monthly_recharge_cap_micro.integer' => __('locale.usage_billing.validation.monthly_cap_invalid'),
            'monthly_recharge_cap_micro.max' => __('locale.usage_billing.validation.monthly_cap_above_maximum'),
        ];
    }
}

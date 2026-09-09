<?php

namespace App\Http\Requests\Customer\Business;

use App\Library\Usage\UsageWalletManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Customer Experience Slice 5 (contract §12.2 E-16/E-19/E-20) — the
 * spending-controls form. One route carries three controls, selected by
 * `control`:
 *
 *  - business_spend_cap: the Business's monthly spending limit
 *    (`monthly_spend_cap` decimal, or `monthly_spend_cap_micro`);
 *  - business_pause: "Pause paid activity" / resume for this Business;
 *  - workspace_controls: the Agency-wide monthly limit and Agency-wide
 *    pause (authorized server-side for the Workspace owner / Agency-wide
 *    Admin only — see UsageBillingController::updateSpendCap()).
 *
 * Decimal amounts are converted with exact bcmath arithmetic, never a
 * float. Omitting `control` keeps the pre-Slice-5 meaning
 * (business_spend_cap) for existing callers.
 */
class UpdateBusinessSpendCapRequest extends FormRequest
{
    public const CONTROL_BUSINESS_SPEND_CAP = 'business_spend_cap';

    public const CONTROL_BUSINESS_PAUSE = 'business_pause';

    public const CONTROL_WORKSPACE = 'workspace_controls';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = ['control' => $this->input('control', self::CONTROL_BUSINESS_SPEND_CAP)];

        foreach ([
            'monthly_spend_cap' => 'monthly_spend_cap_micro',
            'workspace_monthly_spend_cap' => 'workspace_monthly_spend_cap_micro',
            'workspace_monthly_recharge_cap' => 'workspace_monthly_recharge_cap_micro',
        ] as $decimalField => $microField) {
            $value = $this->input($decimalField);

            if (is_string($value) && preg_match('/^\s*\d{1,9}(\.\d{1,2})?\s*$/', $value) === 1) {
                $merge[$microField] = bcmul(trim($value), '1000000', 0);
            } elseif (is_string($value) && trim($value) === '') {
                $merge[$microField] = null;
            }
        }

        $this->merge($merge);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'control' => ['required', Rule::in([self::CONTROL_BUSINESS_SPEND_CAP, self::CONTROL_BUSINESS_PAUSE, self::CONTROL_WORKSPACE])],
            'monthly_spend_cap_micro' => ['nullable', 'integer', 'min:0'],
            'paused' => ['required_if:control,' . self::CONTROL_BUSINESS_PAUSE, 'nullable', 'boolean'],
            'confirm_pause' => ['nullable', 'boolean'],
            'workspace_monthly_spend_cap_micro' => ['nullable', 'integer', 'min:0'],
            // Correction Round 1 §6.2 — the Agency-wide automatic top-up
            // ceiling is bounded by the approved hard maximum at the request
            // boundary; UsageWalletManager::setWorkspaceAggregateRechargeCap()
            // refuses the same crafted value at the manager boundary.
            'workspace_monthly_recharge_cap_micro' => ['nullable', 'integer', 'min:0', 'max:' . UsageWalletManager::WORKSPACE_MONTHLY_AUTO_RECHARGE_MAXIMUM_MICRO],
            'workspace_paused' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'workspace_monthly_recharge_cap_micro.max' => __('locale.usage_billing.validation.workspace_recharge_cap_above_maximum'),
            'workspace_monthly_recharge_cap_micro.integer' => __('locale.usage_billing.validation.monthly_cap_invalid'),
        ];
    }

    public function control(): string
    {
        return (string) $this->validated('control');
    }
}

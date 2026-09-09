<?php

namespace App\Http\Requests\Customer\Business;

use App\Library\Usage\UsageWalletManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Customer Experience Slice 5 (contract §12.2 E-18; T-WALLET-1) — the
 * request-validation half of the $5.00 manual top-up floor.
 *
 * Accepts either `amount` (the customer's decimal entry in the wallet's
 * currency, e.g. "5.00") or `amount_micro` (an integer in the
 * repository's micro-unit convention). The decimal entry is converted
 * with exact bcmath arithmetic — never a float — and rejected when it
 * carries more than two decimals or any non-numeric character. $4.99
 * (4 990 000 micro) fails; $5.00 (5 000 000) passes.
 */
class InitiateTopUpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $amount = $this->input('amount');

        if (is_string($amount) && preg_match('/^\s*\d{1,9}(\.\d{1,2})?\s*$/', $amount) === 1) {
            $this->merge(['amount_micro' => bcmul(trim($amount), '1000000', 0)]);
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'amount_micro' => ['required', 'integer', 'min:' . UsageWalletManager::MINIMUM_MANUAL_TOP_UP_MICRO],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount_micro.required' => __('locale.usage_billing.validation.top_up_amount_required'),
            'amount_micro.integer' => __('locale.usage_billing.validation.top_up_amount_invalid'),
            'amount_micro.min' => __('locale.usage_billing.validation.top_up_minimum'),
        ];
    }
}

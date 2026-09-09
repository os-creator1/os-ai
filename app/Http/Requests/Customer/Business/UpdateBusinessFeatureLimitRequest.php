<?php

namespace App\Http\Requests\Customer\Business;

use Illuminate\Foundation\Http\FormRequest;

/**
 * feature_key is a route parameter (validated in the controller against
 * the curated customer capability catalogue — Customer Experience Slice 5,
 * E-14 — never typed by the customer), not form input; this request
 * validates only the limit value itself. The decimal entry is converted
 * with exact bcmath arithmetic, never a float.
 */
class UpdateBusinessFeatureLimitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $value = $this->input('monthly_limit');

        if (is_string($value) && preg_match('/^\s*\d{1,9}(\.\d{1,2})?\s*$/', $value) === 1) {
            $this->merge(['monthly_limit_micro' => bcmul(trim($value), '1000000', 0)]);
        } elseif (is_string($value) && trim($value) === '') {
            $this->merge(['monthly_limit_micro' => null]);
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'monthly_limit_micro' => ['nullable', 'integer', 'min:0'],
        ];
    }
}

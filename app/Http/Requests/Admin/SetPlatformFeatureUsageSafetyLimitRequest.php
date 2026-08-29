<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SetPlatformFeatureUsageSafetyLimitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'feature_key' => ['required', 'string', 'max:64'],
            'max_monthly_limit_micro' => ['required', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'max:5000'],
        ];
    }
}

<?php

namespace App\Http\Requests\Customer\Business;

use Illuminate\Foundation\Http\FormRequest;

/**
 * feature_key is a route parameter (validated in the controller against
 * PlatformFeatureRegistry, matching disableBusinessFeature()/
 * enableBusinessFeature()'s own established route-parameter pattern), not
 * form input — this request validates only the limit value itself.
 */
class UpdateBusinessFeatureLimitRequest extends FormRequest
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
            'monthly_limit_micro' => ['nullable', 'integer', 'min:0'],
        ];
    }
}

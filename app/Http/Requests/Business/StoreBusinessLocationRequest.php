<?php

namespace App\Http\Requests\Business;

use App\Enums\Business\BusinessServiceMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Customer Experience Slice 1A — creating an ADDITIONAL physical location
 * (a branch, storefront, office or service area) inside one Business.
 *
 * Deliberately mirrors UpsertBusinessLocationRequest's field set rather
 * than inventing a second shape: it is the same physical-location data,
 * just not necessarily the primary one. `is_primary` and `lifecycle_state`
 * are absent by design — the canonical BusinessLocationManager decides
 * both.
 *
 * Authorization is NOT done here: the controller runs the full
 * Workspace/Business tenancy chain and the manage permission first.
 */
class StoreBusinessLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'service_mode' => ['required', new Enum(BusinessServiceMode::class)],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'region' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:32'],
            'country_code' => ['required', 'string', 'size:2'],
            'public_address' => ['required', 'boolean'],
            'service_radius_km' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'service_area_cities' => ['nullable', 'array', 'max:50'],
            'service_area_cities.*' => ['string', 'max:120', 'distinct'],
        ];
    }
}

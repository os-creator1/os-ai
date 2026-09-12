<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Customer Experience Slice 1A (contract §7.3a rule 9) — archiving the
 * primary location names the active location that becomes primary in the
 * same transaction. Ownership of that uid is proven by
 * BusinessLocationManager under the Business lock, not here.
 */
class ArchiveBusinessLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'new_primary_location_uid' => ['nullable', 'string', 'max:64'],
        ];
    }
}

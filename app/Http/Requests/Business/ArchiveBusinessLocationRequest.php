<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Customer Experience Slice 1A — archiving one physical location
 * (contract §7.3a).
 *
 * `new_primary_uid` is required only when the location being archived is
 * currently the primary one: primary status must move to another ACTIVE
 * location in the same transaction (§7.3a rule 9). The canonical
 * BusinessLocationManager enforces that; this request only carries it.
 *
 * Both uids are resolved THROUGH the already-resolved Business, so a
 * foreign uid is indistinguishable from an unknown one.
 */
class ArchiveBusinessLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'location_uid' => ['required', 'string', 'max:64'],
            'new_primary_uid' => ['nullable', 'string', 'max:64'],
        ];
    }
}

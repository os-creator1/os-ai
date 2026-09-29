<?php

namespace App\Http\Requests\Admin\NicheBlueprint;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Contract 20 §5.1 / §12.F — Blueprint identity/metadata edit. `key` is
 * deliberately absent: it is the Blueprint's stable identity and is not
 * editable through NicheBlueprintPublisher::updateBlueprintIdentity()
 * (§4's docblock explains why — installation records and future resolution
 * logic both address a Blueprint by key).
 */
class UpdateNicheBlueprintRequest extends FormRequest
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
            'display_name' => ['required', 'string', 'max:80'],
            'vertical_key' => ['nullable', 'string', 'max:40'],
            'broad_industry' => ['nullable', 'string', 'max:40'],
        ];
    }
}

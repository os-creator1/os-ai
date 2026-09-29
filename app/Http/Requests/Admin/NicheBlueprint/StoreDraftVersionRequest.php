<?php

namespace App\Http\Requests\Admin\NicheBlueprint;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Contract 20 §5.2 / §12.F — open a new draft version. `notes` is the
 * operator's own release note (§5.2); everything else about a draft
 * (`version_number`, `state`) is NicheBlueprintPublisher's to compute.
 */
class StoreDraftVersionRequest extends FormRequest
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
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}

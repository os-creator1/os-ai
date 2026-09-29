<?php

namespace App\Http\Requests\Admin\NicheBlueprint;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Contract 20 §5.2 / §12.F — edit a draft's own release note. Any version
 * that has left `draft` refuses through NicheBlueprintPublisher itself
 * (NotADraftVersionException) regardless of what this request validates.
 */
class UpdateDraftVersionRequest extends FormRequest
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

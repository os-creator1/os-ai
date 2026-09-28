<?php

namespace App\Http\Requests\Admin\NicheBlueprint;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Contract 20 §5.3 / §12.F — edit a draft component. All fields are
 * `sometimes` because NicheBlueprintPublisher::updateDraftComponent() only
 * changes attributes actually present in its `$attributes` array (§6.2's
 * gates still apply at publish, never here).
 */
class UpdateDraftComponentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'component_key' => ['sometimes', 'required', 'string', 'max:64'],
            'component_type' => ['sometimes', 'required', 'string', 'max:40'],
            'required_feature_key' => ['sometimes', 'required', 'string', 'max:64'],
            'payload_json' => ['sometimes', 'required', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                $decoded = json_decode($value, true);

                if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
                    $fail('The payload must be a valid JSON object.');
                }
            }],
            'position' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * Only the keys the operator actually submitted, translating
     * `payload_json` into the publisher's own `payload` array key.
     *
     * @return array<string, mixed>
     */
    public function attributesForPublisher(): array
    {
        $attributes = $this->validated();

        if (array_key_exists('payload_json', $attributes)) {
            $attributes['payload'] = (array) json_decode((string) $attributes['payload_json'], true);
            unset($attributes['payload_json']);
        }

        return $attributes;
    }
}

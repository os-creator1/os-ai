<?php

namespace App\Http\Requests\Admin\NicheBlueprint;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Contract 20 §5.3 / §12.F — add a component to a draft version.
 *
 * Shape validation only. Whether `component_type` names a registered
 * adapter, whether `required_feature_key` is a known Business-scoped
 * PlatformFeature, and whether the payload satisfies that adapter's own
 * `validateDescriptor()` are all §6.2 publish-time gates enforced by
 * NicheBlueprintPublisher — never duplicated here (§6.2 deliberately defers
 * all of that to publish, not to component add/edit).
 *
 * `payload_json` is a JSON object typed by the operator; the controller
 * decodes it before calling the publisher. It is validated as syntactically
 * valid JSON decoding to an array here so the operator gets a field-level
 * error instead of a confusing downstream failure.
 */
class StoreDraftComponentRequest extends FormRequest
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
            'component_key' => ['required', 'string', 'max:64'],
            'component_type' => ['required', 'string', 'max:40'],
            'required_feature_key' => ['required', 'string', 'max:64'],
            'payload_json' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                $decoded = json_decode($value, true);

                if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
                    $fail('The payload must be a valid JSON object.');
                }
            }],
            'position' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function decodedPayload(): array
    {
        return (array) json_decode((string) $this->validated('payload_json'), true);
    }
}

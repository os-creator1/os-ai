<?php

namespace App\Http\Requests\Automations\Workflow;

use App\Library\Automation\Workflow\WorkflowLimits;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Automations V2-E — autosave a draft document (§14.4).
 *
 * Deliberately permissive about the DOCUMENT and strict about the ENVELOPE.
 * §14.4 says an invalid document is still saved with its errors, so work is never
 * lost — the definition is therefore not validated here; the compiler reports on
 * it after the save. What this refuses is anything that is not a document at
 * all: a non-object definition, a missing revision, or a document larger than
 * §8.4's storage bound, which is the one limit the contract assigns to save.
 */
class AutosaveDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('automations') ?? false;
    }

    public function rules(): array
    {
        return [
            'definition' => [
                'required',
                'array',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    $bytes = strlen((string) json_encode($value));

                    if ($bytes > WorkflowLimits::MAX_DEFINITION_BYTES) {
                        $fail(sprintf(
                            'This workflow is too large to save. Keep it under %d KB.',
                            intdiv(WorkflowLimits::MAX_DEFINITION_BYTES, 1024),
                        ));
                    }
                },
            ],
            // The revision the editor last received; the save is conditional on
            // it, which is what turns a second tab's stale save into a 409.
            'definition_revision' => ['required', 'integer', 'min:1'],
        ];
    }
}

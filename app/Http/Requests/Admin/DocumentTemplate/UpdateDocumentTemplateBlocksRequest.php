<?php

namespace App\Http\Requests\Admin\DocumentTemplate;

use App\Library\Documents\Templates\DocumentTemplateService;
use App\Library\PlatformOwner\PlatformOwnerAuthority;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Implementation Contract 17B §6b - the editor's autosave target for a PLATFORM
 * template. Same fields and answers as the Business template endpoint
 * (`blocks`, `name`, `template_type`|`type`, `description`, and the required
 * `expected_lock_version`); a validation failure is the editor's own
 * `422 {status:'invalid', message, errors}` shape.
 */
class UpdateDocumentTemplateBlocksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(PlatformOwnerAuthority::class)->allowsRequest($this);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'blocks' => ['sometimes', 'array'],
            'name' => ['sometimes', 'required', 'string', 'max:'.DocumentTemplateService::NAME_MAX],
            'template_type' => ['sometimes', 'required', 'in:proposal,contract'],
            'type' => ['sometimes', 'required', 'in:proposal,contract'],
            'description' => ['sometimes', 'nullable', 'string', 'max:'.DocumentTemplateService::DESCRIPTION_MAX],
            'expected_lock_version' => ['required', 'integer', 'min:1'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'status' => 'invalid',
            'message' => $validator->errors()->first(),
            'errors' => $validator->errors()->toArray(),
        ], 422));
    }
}

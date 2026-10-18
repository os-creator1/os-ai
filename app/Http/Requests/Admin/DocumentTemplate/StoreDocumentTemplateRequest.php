<?php

namespace App\Http\Requests\Admin\DocumentTemplate;

use App\Library\Documents\Templates\DocumentTemplateService;
use App\Library\PlatformOwner\PlatformOwnerAuthority;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Implementation Contract 17B §6b - create a blank DRAFT platform template.
 * Platform Owner only (the route group already enforces it; re-asked here).
 */
class StoreDocumentTemplateRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:'.DocumentTemplateService::NAME_MAX],
            'template_type' => ['required', 'in:proposal,contract'],
            'description' => ['nullable', 'string', 'max:'.DocumentTemplateService::DESCRIPTION_MAX],
        ];
    }
}

<?php

namespace App\Http\Requests\Admin\DocumentTemplate;

use App\Library\PlatformOwner\PlatformOwnerAuthority;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Implementation Contract 17B §6b - the explicit "Publish blueprint version"
 * action on the assignment screen: names ONE niche blueprint whose draft is to
 * be published. Requires the owner's explicit confirmation flag.
 */
class PublishBlueprintRequest extends FormRequest
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
            'blueprint' => ['required', 'string', 'uuid'],
            'confirm' => ['accepted'],
        ];
    }
}

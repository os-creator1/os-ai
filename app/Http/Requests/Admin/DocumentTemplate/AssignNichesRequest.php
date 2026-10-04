<?php

namespace App\Http\Requests\Admin\DocumentTemplate;

use App\Library\PlatformOwner\PlatformOwnerAuthority;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Implementation Contract 17B §6b - the COMPLETE set of niche blueprints (by
 * uid) a platform template should be assigned to. An empty / absent list means
 * "no niches". A uid that is not a niche blueprint is refused (404) by the
 * service before anything changes.
 */
class AssignNichesRequest extends FormRequest
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
            'blueprints' => ['nullable', 'array', 'max:200'],
            'blueprints.*' => ['string', 'uuid'],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function blueprintUids(): array
    {
        return array_values(array_map('strval', (array) $this->validated('blueprints', [])));
    }
}

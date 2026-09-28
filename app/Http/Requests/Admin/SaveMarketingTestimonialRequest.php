<?php

namespace App\Http\Requests\Admin;

use App\Rules\ValidMarketingImageRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared by both create and update. `poster_image` is optional on update
 * (an existing image is kept when no new file is submitted) and required
 * on create — enforced in the controller, since a FormRequest cannot see
 * "is this create or update" cleanly without a route parameter check that
 * would only duplicate what the controller already knows.
 */
class SaveMarketingTestimonialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('general settings');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:191'],
            'business_context_label' => ['required', 'string', 'max:255'],
            'video_url' => ['nullable', 'url', 'max:2048'],
            'transcript_text' => ['nullable', 'string', 'max:10000'],
            'poster_image' => ['nullable', 'file', 'image', new ValidMarketingImageRule()],
            'position' => ['nullable', 'integer', 'min:0'],
            'is_visible' => ['nullable', 'boolean'],
        ];
    }
}

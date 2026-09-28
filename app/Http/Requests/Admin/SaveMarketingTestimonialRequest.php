<?php

namespace App\Http\Requests\Admin;

use App\Rules\ValidMarketingImageRule;
use App\Rules\ValidYoutubeUrlRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared by both create and update. `poster_image` is always optional at
 * this layer — whether one is required depends on whether a YouTube
 * `video_url` is also supplied (a YouTube link needs no uploaded poster;
 * its own thumbnail is used), which the controller decides, since a
 * FormRequest cannot see "is this create or update" cleanly without a
 * route parameter check that would only duplicate what the controller
 * already knows.
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
            'video_url' => ['nullable', 'url', 'max:2048', new ValidYoutubeUrlRule()],
            'transcript_text' => ['nullable', 'string', 'max:10000'],
            'poster_image' => ['nullable', 'file', 'image', new ValidMarketingImageRule()],
            'position' => ['nullable', 'integer', 'min:0'],
            'is_visible' => ['nullable', 'boolean'],
        ];
    }
}

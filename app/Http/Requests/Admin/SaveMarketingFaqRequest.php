<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared by both create and update — the FAQ shape never differs between
 * the two, so one request class covers both admin actions.
 */
class SaveMarketingFaqRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('general settings');
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'question' => ['required', 'string', 'max:255'],
            'answer' => ['required', 'string', 'max:5000'],
            'position' => ['nullable', 'integer', 'min:0'],
            'is_visible' => ['nullable', 'boolean'],
        ];
    }
}

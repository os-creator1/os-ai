<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMarketingHeroCopyRequest extends FormRequest
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
            'hero_headline' => ['nullable', 'string', 'max:191'],
            'hero_subheadline' => ['nullable', 'string', 'max:2000'],
        ];
    }
}

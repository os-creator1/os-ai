<?php

    namespace App\Http\Requests\Settings;

    use Illuminate\Foundation\Http\FormRequest;

    class OpenAISettingsRequest extends FormRequest
    {
        /**
         * Determine if the user is authorized to make this request.
         */
        public function authorize(): bool
        {
            return $this->user()->can('manage ai_settings');
        }

        /**
         * Get the validation rules that apply to the request.
         *
         * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
         */
        public function rules(): array
        {
            return [
                // B3 Simplified Platform Settings: the stored API key is
                // never rendered back into the form, so it can never be
                // required to resubmit it. Blank means "keep the existing
                // key" (EloquentSettingsRepository::aiSettings()).
                'api_key'      => 'nullable|string',
                'model'        => 'required|string',
                'role'         => 'nullable|string',
                'organization' => 'nullable|string',
                'project'      => 'nullable|string',
            ];
        }

    }

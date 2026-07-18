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
                'api_key'      => 'required|string',
                'model'        => 'required|string',
                'role'         => 'nullable|string',
                'organization' => 'nullable|string',
                'project'      => 'nullable|string',
            ];
        }

    }

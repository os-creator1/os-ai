<?php

    namespace App\Http\Requests\Plugins;

    use Illuminate\Contracts\Validation\ValidationRule;
    use Illuminate\Foundation\Http\FormRequest;

    class InstallPluginRequest extends FormRequest
    {
        /**
         * Determine if the user is authorized to make this request.
         */
        public function authorize(): bool
        {
            return $this->user()->can('install plugins');
        }

        /**
         * Get the validation rules that apply to the request.
         *
         * @return array<string, ValidationRule|array|string>
         */
        public function rules(): array
        {
            return [
                'purchase_code' => 'required|string|min:15',
                'file'          => 'required|mimetypes:application/zip,application/octet-stream,application/x-zip-compressed,multipart/x-zip',
            ];
        }

        /**
         * Custom messages for validation errors.
         */
        public function messages()
        {
            return [
                'file.mimetypes' => __('locale.settings.invalid_zip'),
            ];
        }

    }

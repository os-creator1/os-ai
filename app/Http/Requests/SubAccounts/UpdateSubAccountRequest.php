<?php

    namespace App\Http\Requests\SubAccounts;

    use App\Rules\Phone;
    use Illuminate\Foundation\Http\FormRequest;
    use Illuminate\Validation\Rule;

    class UpdateSubAccountRequest extends FormRequest
    {
        /**
         * Determine if the user is authorized to make this request.
         */
        public function authorize(): bool
        {
            return true;
        }

        /**
         * Get the validation rules that apply to the request.
         */
        public function rules(): array
        {
            return [
                'first_name'                 => ['required', 'string', 'regex:/^[\pL\s\-\'\.]+$/u', 'max:255'],
                'last_name'                  => ['nullable', 'string', 'regex:/^[\pL\s\-\'\.]+$/u', 'max:255'],
                'email'                      => [
                    'required',
                    'string',
                    'email',
                    'max:255',
                    Rule::unique('users')->ignore($this->route('sub_account')),
                ],
                'image'                      => ['nullable', 'image'],
                "permissions"                => "required|array",
                'permissions.access_backend' => 'required',
            ];
        }

        /**
         * Custom error messages for validation.
         */
        public function messages(): array
        {
            return [
                'first_name.regex'                    => 'The first name may only contain letters, spaces, hyphens, apostrophes, and periods.',
                'last_name.regex'                     => 'The last name may only contain letters, spaces, hyphens, apostrophes, and periods.',
                'permissions.access_backend.required' => __('locale.permission.access_backend_permission_required'),
            ];
        }

    }

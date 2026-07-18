<?php

    namespace App\Http\Requests\SubAccounts;

    use Illuminate\Foundation\Http\FormRequest;
    use Illuminate\Validation\Rules\Password;

    class StoreSubAccountRequest extends FormRequest
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
            $rules = [
                'first_name'                 => ['required', 'string', 'regex:/^[\pL\s\-\'\.]+$/u', 'max:255'],
                'last_name'                  => ['nullable', 'string', 'regex:/^[\pL\s\-\'\.]+$/u', 'max:255'],
                'email'                      => ['required', 'string', 'email', 'max:255', 'unique:users'],
                "permissions"                => "required|array",
                'permissions.access_backend' => 'required',
            ];

            if ($this->has('add_password')) {
                $rules['password'] = ['required', 'string', 'confirmed', Password::default()];
            }

            return $rules;
        }


        /**
         * Custom error messages for validation.
         */
        public function messages(): array
        {
            return [
                'first_name.regex'                    => 'The first name may only contain letters, spaces, hyphens, apostrophes, and periods.',
                'last_name.regex'                     => 'The last name may only contain letters, spaces, hyphens, apostrophes, and periods.',
                'password.required_if'                => 'The password field is required when the Add Password option is selected.',
                'permissions.access_backend.required' => __('locale.permission.access_backend_permission_required'),
            ];
        }

    }

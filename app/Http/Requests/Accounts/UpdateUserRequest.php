<?php

    namespace App\Http\Requests\Accounts;

    use Illuminate\Support\Facades\Auth;
    use Illuminate\Foundation\Http\FormRequest;

    class UpdateUserRequest extends FormRequest
    {
        /**
         * Determine if the user is authorized to make this request.
         *
         * @return bool
         */
        public function authorize(): bool
        {
            return true;
        }

        /**
         * Get the validation rules that apply to the request.
         *
         * @return array
         */
        public function rules(): array
        {
            return [
                'first_name' => ['required', 'string', 'regex:/^[\pL\s\-\'\.]+$/u', 'max:255'],
                'last_name'  => ['nullable', 'string', 'regex:/^[\pL\s\-\'\.]+$/u', 'max:255'],
                'email'      => ['required', 'string', 'email', 'max:255', 'unique:users,email,' . Auth::user()->id],
                'timezone'   => ['required', 'timezone'],
                'locale'     => ['required', 'string', 'min:2', 'max:2'],
            ];
        }


        /**
         * Custom error messages for validation.
         */
        public function messages(): array
        {
            return [
                'first_name.regex' => 'The first name may only contain letters, spaces, hyphens, apostrophes, and periods.',
                'last_name.regex'  => 'The last name may only contain letters, spaces, hyphens, apostrophes, and periods.',
            ];
        }

    }

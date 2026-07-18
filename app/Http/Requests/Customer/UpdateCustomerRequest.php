<?php

    namespace App\Http\Requests\Customer;

    use Illuminate\Foundation\Http\FormRequest;
    use Illuminate\Validation\Rules\Password;

    class UpdateCustomerRequest extends FormRequest
    {
        /**
         * Determine if the user is authorized to make this request.
         */
        public function authorize(): bool
        {
            return $this->user()->can('edit customer');
        }

        /**
         * Get the validation rules that apply to the request.
         */
        public function rules(): array
        {
            $customer = $this->route('customer');

            return [
                'first_name' => ['required', 'string', 'regex:/^[\pL\s\-\'\.]+$/u', 'max:255'],
                'last_name'  => ['nullable', 'string', 'regex:/^[\pL\s\-\'\.]+$/u', 'max:255'],
                'email'      => ['required', 'string', 'email', 'max:255', 'unique:users,email,' . $customer->id],
                'password'   => ['nullable', 'string', 'confirmed', Password::default()],
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

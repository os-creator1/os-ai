<?php

    namespace App\Http\Requests\Customer;

    use App\Rules\Phone;
    use Illuminate\Foundation\Http\FormRequest;
    use Illuminate\Validation\Rules\Password;

    class StoreCustomerRequest extends FormRequest
    {
        /**
         * Determine if the user is authorized to make this request.
         */
        public function authorize(): bool
        {
            return $this->user()->can('create customer');
        }

        /**
         * Get the validation rules that apply to the request.
         */
        public function rules(): array
        {
            return [
                'first_name' => ['required', 'string', 'regex:/^[\pL\s\-\'\.]+$/u', 'max:255'],
                'last_name'  => ['nullable', 'string', 'regex:/^[\pL\s\-\'\.]+$/u', 'max:255'],
                'phone'      => ['required', new Phone($this->phone)],
                'email'      => ['required', 'string', 'email', 'max:255', 'unique:users'],
                'password'   => ['required', 'string', 'confirmed', Password::default()],
                'timezone'   => ['required', 'timezone'],
                'locale'     => ['required', 'string', 'min:2', 'max:2'],
                'status'     => ['required', 'boolean'],
                'image'      => ['sometimes', 'required', 'image'],
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

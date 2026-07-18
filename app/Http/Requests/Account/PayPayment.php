<?php

    namespace App\Http\Requests\Account;

    use Illuminate\Foundation\Http\FormRequest;

    class PayPayment extends FormRequest
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
                'first_name'      => ['required', 'string', 'max:255'],
                'phone'           => ['required'],
                'email'           => ['required', 'string', 'email', 'max:255'],
                'address'         => ['required', 'string'],
                'city'            => ['required'],
                'country'         => ['required'],
                'payment_methods' => ['required'],
                'sms_unit'        => ['required', 'numeric', 'min:0'],
                'balance'         => ['required', 'numeric', 'min:0'],
            ];
        }

    }

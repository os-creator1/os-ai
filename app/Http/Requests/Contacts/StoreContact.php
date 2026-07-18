<?php

    namespace App\Http\Requests\Contacts;

    use App\Rules\Phone;
    use Illuminate\Foundation\Http\FormRequest;

    class StoreContact extends FormRequest
    {
        /**
         * Determine if the user is authorized to make this request.
         *
         * @return bool
         */
        public function authorize(): bool
        {
            return $this->user()->can('create_contact');
        }

        /**
         * Get the validation rules that apply to the request.
         *
         * @return array
         */
        public function rules(): array
        {
            return [
                'PHONE' => ['required', new Phone($this->PHONE)],
            ];
        }

    }

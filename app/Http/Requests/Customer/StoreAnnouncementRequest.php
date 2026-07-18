<?php

    namespace App\Http\Requests\Customer;

    use Illuminate\Foundation\Http\FormRequest;

    class StoreAnnouncementRequest extends FormRequest
    {
        /**
         * Determine if the user is authorized to make this request.
         */
        public function authorize(): bool
        {
            return $this->user()->can('create announcement');
        }

        /**
         * Get the validation rules that apply to the request.
         *
         * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
         */
        public function rules(): array
        {
            return [
                'customer'       => 'required',
                'title'          => 'required|string',
                'description'    => 'required|string',
                'send_by'        => 'required|string',
                'user_ids'       => 'nullable|array',
                'sending_server' => 'nullable|integer|exists:sending_servers,id',
            ];
        }

    }

<?php

    namespace App\Http\Requests\SenderID;

    use Illuminate\Foundation\Http\FormRequest;

    class StoreBlockSenderIDRequest extends FormRequest
    {
        /**
         * Determine if the user is authorized to make this request.
         */
        public function authorize(): bool
        {
            return $this->user()->can('create block_senderid');
        }

        /**
         * Get the validation rules that apply to the request.
         *
         * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
         */
        public function rules(): array
        {
            return [
                'sender_id' => 'required|string|unique:block_sender_id,sender_id',
                'reason'    => 'nullable|string',
            ];
        }

    }

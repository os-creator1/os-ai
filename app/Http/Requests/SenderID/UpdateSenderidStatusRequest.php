<?php

    namespace App\Http\Requests\SenderID;

    use Illuminate\Foundation\Http\FormRequest;

    /**
     * @property mixed user_id
     * @property mixed sender_id
     */
    class UpdateSenderidStatusRequest extends FormRequest
    {
        /**
         * Determine if the user is authorized to make this request.
         *
         * @return bool
         */
        public function authorize(): bool
        {
            return $this->user()->can('edit sender_id');
        }

        /**
         * Get the validation rules that apply to the request.
         *
         * @return array
         */
        public function rules(): array
        {
            return [
                'status'           => 'required|string',
            ];
        }
    }

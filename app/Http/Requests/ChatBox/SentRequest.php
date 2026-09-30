<?php

    namespace App\Http\Requests\ChatBox;

    use Illuminate\Foundation\Http\FormRequest;

    class SentRequest extends FormRequest
    {
        /**
         * Determine if the user is authorized to make this request.
         *
         * @return bool
         */
        public function authorize(): bool
        {
            return $this->user()->can('chat_box');
        }

        /**
         * Get the validation rules that apply to the request.
         *
         * Sender requirements depend on the selected Business's transport.
         * That decision deliberately happens in ChatBoxController only after
         * the Workspace/Business pair and the actor's access have been
         * resolved. Looking up a route Business here would run before that
         * tenancy boundary and let a foreign Business's managed state change
         * the validation response.
         *
         * @return array
         */
        public function rules(): array
        {
            return [
                'sender_id' => ['nullable'],
                'recipient' => 'required',
                'message'   => 'required',
                'idempotency_token' => 'required|uuid',
            ];
        }

    }

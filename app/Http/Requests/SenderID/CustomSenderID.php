<?php

    namespace App\Http\Requests\SenderID;

    use Illuminate\Foundation\Http\FormRequest;
    use Illuminate\Validation\Rule;

    class CustomSenderID extends FormRequest
    {
        /**
         * Determine if the user is authorized to make this request.
         *
         * @return bool
         */
        public function authorize(): bool
        {
            return $this->user()->can('create_sender_id');
        }

        /**
         * Get the validation rules that apply to the request.
         *
         * @return array
         */
        public function rules(): array
        {
            $user_id   = $this->user_id;
            $sender_id = $this->sender_id;

            return [
                'sender_id' => ['required', 'max:11',
                    Rule::unique('senderid')->where(function ($query) use ($user_id, $sender_id) {
                        return $query->where('user_id', $user_id)->where('sender_id', $sender_id);
                    })],
                'plan'      => 'required|exists:senderid_plans,id',
                // Always validated, whether or not DLT is enabled (the upload is stored privately).
                'document'  => ['nullable', 'file', 'max:' . \App\Library\Uploads\SenderIdDocumentStore::MAX_KB,
                    function ($attribute, $value, $fail) {
                        if ($value instanceof \Illuminate\Http\UploadedFile && \App\Library\Uploads\SenderIdDocumentStore::safeExtension($value) === null) {
                            $fail('The document must be a PDF, image, or Word file.');
                        }
                    }],
            ];
        }

        /**
         * custom message
         *
         * @return string[]
         */
        public function messages(): array
        {
            return [
                'sender_id.unique' => __('locale.sender_id.sender_id_available', ['sender_id' => $this->sender_id]),
            ];
        }

    }

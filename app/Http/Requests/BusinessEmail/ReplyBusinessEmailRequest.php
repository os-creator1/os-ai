<?php

namespace App\Http\Requests\BusinessEmail;

use Illuminate\Foundation\Http\FormRequest;

/** Reply to a recorded message — shape only; the contact and thread come from the message. */
class ReplyBusinessEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:' . (int) config('business_email.send.max_subject_length', 200)],
            'body' => ['required', 'string', 'max:' . (int) config('business_email.send.max_body_length', 20000)],
            // Only needed to file an unresolved-Location thread under a Location.
            'location_uid' => ['nullable', 'string', 'max:64'],
            'return_to' => ['nullable', 'in:conversations'],
            'send_token' => ['required', 'uuid'],
        ];
    }
}

<?php

namespace App\Http\Requests\BusinessEmail;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Manual Settings → Email send — shape only. Permission, tenancy and the
 * Contact (resolved THROUGH the already-resolved Business) are decided by the
 * controller and BusinessEmailSender; content bounds are enforced again by the
 * sender itself.
 */
class SendBusinessEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'contact_uid' => ['required', 'string', 'max:64'],
            'subject' => ['required', 'string', 'max:' . (int) config('business_email.send.max_subject_length', 200)],
            'body' => ['required', 'string', 'max:' . (int) config('business_email.send.max_body_length', 20000)],
            // A per-render token: the same form submitted twice is one send.
            'send_token' => ['required', 'uuid'],
        ];
    }
}

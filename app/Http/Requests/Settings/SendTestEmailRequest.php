<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * B3 Simplified Platform Settings §5 — the minimal "Send test email"
 * action. Same ability as the SMTP form itself: sending a probe email is
 * part of managing system email, not a separate concern.
 */
class SendTestEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('system_email settings');
    }

    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'email' => 'required|email',
        ];
    }
}

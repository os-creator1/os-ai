<?php

namespace App\Http\Requests\BusinessEmail;

use Illuminate\Foundation\Http\FormRequest;

/** "Other email" connection form — shape only; ImapSettings validates the values. */
class ConnectOtherEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'max:191'],
            'username' => ['nullable', 'string', 'max:191'],
            'password' => ['required', 'string', 'max:512'],
            'imap_host' => ['nullable', 'string', 'max:253'],
            'imap_port' => ['nullable', 'integer'],
            'smtp_host' => ['nullable', 'string', 'max:253'],
            'smtp_port' => ['nullable', 'integer'],
        ];
    }
}

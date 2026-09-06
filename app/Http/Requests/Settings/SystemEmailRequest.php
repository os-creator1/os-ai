<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class SystemEmailRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return $this->user()->can('system_email settings');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
                'driver'     => 'required',
                'from_email' => 'required|email',
                'from_name'  => 'required',
                'host'       => 'required_if:driver,smtp|nullable',
                'port'       => 'required_if:driver,smtp|nullable|numeric',
                'encryption' => 'required_if:driver,smtp|nullable',
                'username'   => 'required_if:driver,smtp|nullable',
                // B3 Simplified Platform Settings: the stored SMTP password is
                // never rendered back into the form, so it can never be
                // required to resubmit it. A blank submission means "keep the
                // existing password" (EloquentSettingsRepository::systemEmail()),
                // not "clear it".
                'password'   => 'nullable|string',
        ];
    }
}

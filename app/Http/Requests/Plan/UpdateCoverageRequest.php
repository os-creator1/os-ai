<?php

namespace App\Http\Requests\Plan;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCoverageRequest extends FormRequest
{

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {

        return [
                'country'              => 'required',
                'plain_sms'            => 'nullable|numeric|min:0',
                'receive_plain_sms'    => 'nullable|numeric|min:0',
                'voice_sms'            => 'nullable|numeric|min:0',
                'receive_voice_sms'    => 'nullable|numeric|min:0',
                'mms_sms'              => 'nullable|numeric|min:0',
                'receive_mms_sms'      => 'nullable|numeric|min:0',
                'whatsapp_sms'         => 'nullable|numeric|min:0',
                'receive_whatsapp_sms' => 'nullable|numeric|min:0',
                'viber_sms'            => 'nullable|numeric|min:0',
                'receive_viber_sms'    => 'nullable|numeric|min:0',
                'otp_sms'              => 'nullable|numeric|min:0',
                'receive_otp_sms'      => 'nullable|numeric|min:0',
        ];
    }
}

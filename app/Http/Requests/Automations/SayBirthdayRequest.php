<?php

namespace App\Http\Requests\Automations;

use Illuminate\Foundation\Http\FormRequest;

class SayBirthdayRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return $this->user()->can('automations');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules()
    {
        return [
                'name'           => 'required',
                'contact_groups' => 'required|exists:contact_groups,id',
                'before'         => 'required',
                'at'             => 'required|date_format:H:i',
                'timezone'       => 'required|timezone',
                'sms_type'       => 'required',
                'message'        => 'required_if:sms_type,plain|nullable',
                'language'       => 'required_if:sms_type,voice|nullable',
                'gender'         => 'required_if:sms_type,voice|nullable',
                'mms_file'       => 'required_if:sms_type,mms|nullable',
        ];
    }
}

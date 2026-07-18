<?php

namespace App\Http\Requests\Campaigns;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class OTPCampaignBuilderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('otp_campaign_builder');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
                'name'             => 'required',
                'contact_groups'   => 'required',
                'message'          => 'required',
                'schedule_date'    => 'required_if:schedule,true|date|nullable',
                'schedule_time'    => 'required_if:schedule,true|date_format:H:i',
                'timezone'         => 'required_if:schedule,true|timezone',
                'frequency_cycle'  => 'required_if:schedule,true',
                'frequency_amount' => 'required_if:frequency_cycle,custom|nullable|numeric',
                'frequency_unit'   => 'required_if:frequency_cycle,custom|nullable|string',
                'recurring_date'   => 'sometimes|date|nullable',
                'recurring_time'   => 'sometimes|date_format:H:i',
        ];
    }
}

<?php

namespace App\Http\Requests\Plan;

use Illuminate\Foundation\Http\FormRequest;

class SenderIDRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return $this->user()->can('manage plans');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules()
    {
        return [
                'sender_id_price'            => 'nullable|min:0|max:12',
                'sender_id_billing_cycle'    => 'nullable|string',
                'sender_id_frequency_amount' => 'required_if:sender_id_billing_cycle,=,custom|nullable|numeric',
                'sender_id_frequency_unit'   => 'required_if:sender_id_billing_cycle,=,custom|nullable|string',
        ];
    }
}

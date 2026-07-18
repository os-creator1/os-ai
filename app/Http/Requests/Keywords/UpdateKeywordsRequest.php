<?php

namespace App\Http\Requests\Keywords;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateKeywordsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return $this->user()->can('edit keywords');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {

        $id           = $this->route('keyword')->id;
        $keyword_name = $this->keyword_name;
        $user_id      = $this->user_id;

        return [
                'title'            => 'required',
                'keyword_name'     => ['required', 'max:50',
                        Rule::unique('keywords')->where(function ($query) use ($user_id, $keyword_name) {
                            return $query->where('user_id', $user_id)->where('keyword_name', $keyword_name);
                        })->ignore($id)],
                'user_id'          => 'required',
                'price'            => 'required|min:0|max:12',
                'billing_cycle'    => 'required|string',
                'status'           => 'required|string',
                'frequency_amount' => 'required_if:billing_cycle,custom|nullable|numeric',
                'frequency_unit'   => 'required_if:billing_cycle,custom|nullable|string',
                'reply_text'       => 'required_without_all:reply_voice,reply_mms|nullable|string',
                'reply_mms'        => 'sometimes|required|image',
                'currency_id'      => 'required',
        ];
    }
}

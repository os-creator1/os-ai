<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class DefaultCustomerPermission extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return $this->user()->can('authentication settings');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return string[]
     */
    public function rules()
    {
        return [
                "permissions" => "required|array",
                'permissions.access_backend' => 'required',
        ];
    }

    /**
     * custom message
     *
     * @return array
     */
    public function messages(): array
    {
        return [
                'permissions.access_backend.required' => __('locale.permission.access_backend_permission_required'),
        ];
    }

}

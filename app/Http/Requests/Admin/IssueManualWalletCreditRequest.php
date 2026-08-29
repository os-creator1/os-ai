<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class IssueManualWalletCreditRequest extends FormRequest
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
            'operation_id' => ['required', 'uuid'],
            'entry_type' => ['required', 'in:manual_credit,promotional_credit'],
            'amount_micro' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:5000'],
        ];
    }
}

<?php

namespace App\Http\Requests\Customer\Workspace;

use Illuminate\Foundation\Http\FormRequest;

/**
 * M4 contract §11 — change_operation_id is generated exactly once,
 * client-side, by the increase view/form itself; this request only
 * validates it is present and UUID-shaped — it never generates one.
 */
class RequestSlotAgreementIncreaseRequest extends FormRequest
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
            'target_allocation_count' => ['required', 'integer', 'min:1'],
            'change_operation_id' => ['required', 'uuid'],
        ];
    }
}

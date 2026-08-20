<?php

namespace App\Http\Requests\Customer\Workspace;

use Illuminate\Foundation\Http\FormRequest;

/**
 * M4 contract §12/§14 — owner cancellation requires no reason (mandatory
 * only for the administrator branch).
 */
class CancelSlotAgreementRequest extends FormRequest
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
        return [];
    }
}

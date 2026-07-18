<?php

namespace App\Http\Requests\Business;

use App\Enums\Business\BusinessStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Admin-only (RFC-001 §17). Deliberately accepts nothing but 'status' — in
 * particular, never 'activated_at', which BusinessRepository::updateStatus()
 * derives itself on transition to Active.
 */
class UpdateBusinessStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', new Enum(BusinessStatus::class)],
        ];
    }
}

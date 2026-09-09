<?php

namespace App\Http\Requests\Customer\Business;

use App\Enums\Usage\PayerType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Customer Experience Slice 5, Correction Round 1 §8.1 — the customer form
 * speaks in billing-responsibility terms (`billing_responsibility` =
 * `agency` | `client`) and is mapped to the internal payer enum here,
 * server-side; the raw enum never appears in customer HTML. The original
 * `payer_type` field is still accepted for existing authorized callers
 * (backward compatibility only — no customer form renders it). Exactly
 * one of the two must be present.
 *
 * `return_to` tells the controller which authorized page to return to:
 * the Agency account frame (Client accounts) or the Business's own
 * Usage & Billing page. It never influences authorization.
 */
class UpdateBusinessPayerRequest extends FormRequest
{
    public const RESPONSIBILITY_AGENCY = 'agency';
    public const RESPONSIBILITY_CLIENT = 'client';
    public const RETURN_TO_ACCOUNT = 'account';
    public const RETURN_TO_USAGE_BILLING = 'usage-billing';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'billing_responsibility' => ['required_without:payer_type', 'nullable', 'string', Rule::in([self::RESPONSIBILITY_AGENCY, self::RESPONSIBILITY_CLIENT])],
            'payer_type' => ['required_without:billing_responsibility', 'nullable', 'string', 'in:business,workspace'],
            'return_to' => ['nullable', 'string', Rule::in([self::RETURN_TO_ACCOUNT, self::RETURN_TO_USAGE_BILLING])],
        ];
    }

    /**
     * The internal payer the submission asks for. The customer vocabulary
     * wins when both fields are present.
     */
    public function payerType(): PayerType
    {
        $responsibility = $this->validated('billing_responsibility');

        if ($responsibility !== null) {
            return $responsibility === self::RESPONSIBILITY_AGENCY ? PayerType::Workspace : PayerType::Business;
        }

        return PayerType::from((string) $this->validated('payer_type'));
    }

    public function returnsToAccountFrame(): bool
    {
        return $this->validated('return_to') === self::RETURN_TO_ACCOUNT;
    }
}

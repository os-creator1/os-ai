<?php

namespace App\Http\Requests\Opportunity;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates and normalizes the admin Opportunity-run index's query-string
 * input (RFC-002 §44 admin diagnostic inspection — Milestone 5). Only
 * 'business_id', 'per_page', and 'page' are ever read; nothing here is
 * passed to OpportunityRunRepository::paginateForBusiness() unvalidated.
 * Unlike the Opportunity index, business_id is required — there is no
 * "all businesses" run listing, only one Business's runs at a time.
 */
class AdminOpportunityRunIndexRequest extends FormRequest
{
    private const MAX_PER_PAGE = 100;

    private const DEFAULT_PER_PAGE = 25;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'business_id' => ['required', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:' . self::MAX_PER_PAGE],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function businessId(): int
    {
        return (int) $this->validated('business_id');
    }

    public function perPage(): int
    {
        return (int) ($this->validated()['per_page'] ?? self::DEFAULT_PER_PAGE);
    }
}

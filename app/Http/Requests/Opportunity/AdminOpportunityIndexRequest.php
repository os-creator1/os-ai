<?php

namespace App\Http\Requests\Opportunity;

use App\Enums\Opportunity\OpportunityFreshness;
use App\Enums\Opportunity\OpportunityStatus;
use App\Enums\Opportunity\OpportunityWorkerKey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Validates and normalizes the admin Opportunity index's query-string
 * filters (RFC-002 §44 admin index — Milestone 5). Only 'status',
 * 'freshness', 'worker_key', 'business_id', 'per_page', and 'page' are ever
 * read; nothing here is passed to OpportunityRepository::paginateForAdmin()
 * unvalidated. paginateForAdmin() has no per-page override of its own — it
 * always paginates at its own fixed size — so per_page is validated for
 * input safety only and currently has no effect on the result.
 */
class AdminOpportunityIndexRequest extends FormRequest
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
            'status' => ['nullable', new Enum(OpportunityStatus::class)],
            'freshness' => ['nullable', new Enum(OpportunityFreshness::class)],
            'worker_key' => ['nullable', new Enum(OpportunityWorkerKey::class)],
            'business_id' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:' . self::MAX_PER_PAGE],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['status', 'freshness', 'worker_key', 'business_id'] as $field) {
            if ($this->has($field) && trim((string) $this->input($field)) === '') {
                $this->merge([$field => null]);
            }
        }
    }

    /**
     * @return array{status: ?string, freshness: ?string, worker_key: ?string, business_id: ?int}
     */
    public function filters(): array
    {
        $validated = $this->validated();

        return [
            'status' => $validated['status'] ?? null,
            'freshness' => $validated['freshness'] ?? null,
            'worker_key' => $validated['worker_key'] ?? null,
            'business_id' => isset($validated['business_id']) ? (int) $validated['business_id'] : null,
        ];
    }

    public function perPage(): int
    {
        return (int) ($this->validated()['per_page'] ?? self::DEFAULT_PER_PAGE);
    }
}

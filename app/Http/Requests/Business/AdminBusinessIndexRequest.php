<?php

namespace App\Http\Requests\Business;

use App\Enums\Business\BusinessIndustry;
use App\Enums\Business\BusinessStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Validates and normalizes the admin Business index's query-string filters
 * (RFC-001 §19 admin index — Milestone 6). Only 'search', 'status',
 * 'industry', 'per_page', and 'page' are ever read; nothing here is passed
 * to BusinessRepository::paginateForAdmin() unvalidated.
 */
class AdminBusinessIndexRequest extends FormRequest
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
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', new Enum(BusinessStatus::class)],
            'industry' => ['nullable', new Enum(BusinessIndustry::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:' . self::MAX_PER_PAGE],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('search') && trim((string) $this->input('search')) === '') {
            $this->merge(['search' => null]);
        }
    }

    /**
     * @return array{search: ?string, status: ?string, industry: ?string}
     */
    public function filters(): array
    {
        $validated = $this->validated();

        return [
            'search' => $validated['search'] ?? null,
            'status' => $validated['status'] ?? null,
            'industry' => $validated['industry'] ?? null,
        ];
    }

    public function perPage(): int
    {
        return (int) ($this->validated()['per_page'] ?? self::DEFAULT_PER_PAGE);
    }
}

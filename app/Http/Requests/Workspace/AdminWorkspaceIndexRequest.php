<?php

namespace App\Http\Requests\Workspace;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates and normalizes the admin Workspace index's query-string filters
 * (RFC-003 Milestone 5 — `docs/automation/RFC-003-M5-CONTRACT.md`). Only
 * 'search', 'is_active', 'per_page', and 'page' are ever read; nothing here
 * is passed to WorkspaceRepository::paginateForAdmin() unvalidated.
 */
class AdminWorkspaceIndexRequest extends FormRequest
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
            'is_active' => ['nullable', 'boolean'],
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
     * @return array{search: ?string, is_active: ?bool}
     */
    public function filters(): array
    {
        $validated = $this->validated();

        return [
            'search' => $validated['search'] ?? null,
            'is_active' => array_key_exists('is_active', $validated) && $validated['is_active'] !== null
                ? $this->boolean('is_active')
                : null,
        ];
    }

    public function perPage(): int
    {
        return (int) ($this->validated()['per_page'] ?? self::DEFAULT_PER_PAGE);
    }
}

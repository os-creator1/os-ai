<?php

namespace App\Http\Requests\Admin\NicheBlueprint;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Contract 20 §5.1 / §12.F — Niche Blueprint identity creation.
 *
 * Authorization for this action is the route's own group middleware
 * ('can:access backend' + EnsureUserIsAdministrator) plus
 * NicheBlueprintPublisher::assertPlatformAdministrator() re-derived inside
 * the service itself — the same division of responsibility as
 * ResolveMessagingProvisioningIncidentRequest. This request validates shape
 * only; the publisher is still the one place a Blueprint key, vertical_key
 * or broad_industry value is authoritatively checked (§5.1).
 */
class StoreNicheBlueprintRequest extends FormRequest
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
            'key' => ['required', 'string', 'max:40'],
            'display_name' => ['required', 'string', 'max:80'],
            'vertical_key' => ['nullable', 'string', 'max:40'],
            'broad_industry' => ['nullable', 'string', 'max:40'],
        ];
    }
}

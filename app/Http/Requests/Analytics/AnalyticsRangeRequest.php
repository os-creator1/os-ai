<?php

namespace App\Http\Requests\Analytics;

use App\Library\Analytics\AnalyticsDateRange;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * B5 Business Analytics — the SHAPE rules for the range selector
 * (contract §4). The controller resolves the Workspace/Business tenancy
 * chain FIRST (so a foreign identifier is always a 404, never a
 * validation answer) and only then validates the query string against
 * ruleSet(); the semantic rules — 92-day cap, reversed range, strict
 * date parsing — live in AnalyticsDateRange::fromInput().
 */
class AnalyticsRangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return self::ruleSet();
    }

    /** @return array<string, array<int, mixed>> */
    public static function ruleSet(): array
    {
        return [
            'range' => ['nullable', 'string', Rule::in([
                AnalyticsDateRange::PRESET_LAST_7_DAYS,
                AnalyticsDateRange::PRESET_LAST_30_DAYS,
                AnalyticsDateRange::PRESET_LAST_90_DAYS,
                AnalyticsDateRange::PRESET_CUSTOM,
            ])],
            'start' => ['nullable', 'string', 'max:10', 'required_if:range,' . AnalyticsDateRange::PRESET_CUSTOM],
            'end' => ['nullable', 'string', 'max:10', 'required_if:range,' . AnalyticsDateRange::PRESET_CUSTOM],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ];
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Library\Ai\AiUsageReadModel;
use App\Library\Ai\Enums\AiUsageCategory;
use App\Library\Ai\Enums\AiUsageEntryStatus;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Unified Business Home & COO contract §10.3 C-9 (slice AI-2) — the admin-only
 * AI usage ledger summary.
 *
 * Provider cost and token counts are platform facts, never customer facts, so
 * they live here and only here: the route sits inside the admin
 * EnsureUserIsAdministrator group, and nothing on this page writes, re-prices
 * or retries anything.
 *
 * Every read is a bounded page through AiUsageReadModel. There is no export
 * and no "show all": a period can hold every AI call the platform made, and
 * support never needs them at once. Prompt and response text do not exist to
 * show — AI-1 never persists them.
 */
class AiUsageController extends AdminBaseController
{
    private const PERIODS_PER_PAGE = 25;

    private const LEDGER_PER_PAGE = 50;

    public function __construct(
        private readonly AiUsageReadModel $readModel,
    ) {
    }

    public function summary(Request $request): View
    {
        $filters = $this->filters($request);

        return view('admin.ai-usage.index', [
            'filters' => $filters,
            'periods' => $this->readModel->adminPeriods($filters, self::PERIODS_PER_PAGE)->withQueryString(),
            'ledger' => $this->readModel->adminLedger($filters, self::LEDGER_PER_PAGE)->withQueryString(),
            'statuses' => array_map(fn (AiUsageEntryStatus $status): string => $status->value, AiUsageEntryStatus::cases()),
            'categories' => array_map(fn (AiUsageCategory $category): string => $category->value, AiUsageCategory::cases()),
            'usd' => static fn (?int $micro): string => $micro === null ? '—' : '$' . bcdiv((string) $micro, '1000000', 6),
            'breadcrumbs' => [
                ['link' => url(config('app.admin_path') . '/dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['name' => 'AI Usage'],
            ],
        ]);
    }

    /**
     * Anything unrecognised is ignored rather than passed to a query: an
     * unknown status or category simply is not a filter.
     *
     * @return array{period_key: string, workspace_id: int|null, status: string|null, category: string|null}
     */
    private function filters(Request $request): array
    {
        $period = (string) $request->query('period', '');
        $workspaceId = $request->query('workspace_id');
        $status = (string) $request->query('status', '');
        $category = (string) $request->query('category', '');

        return [
            'period_key' => preg_match('/^\d{4}-\d{2}$|^trial:\d+$/', $period) === 1 ? $period : Carbon::now('UTC')->format('Y-m'),
            'workspace_id' => is_scalar($workspaceId) && ctype_digit((string) $workspaceId) ? (int) $workspaceId : null,
            'status' => AiUsageEntryStatus::tryFrom($status)?->value,
            'category' => AiUsageCategory::tryFrom($category)?->value,
        ];
    }
}

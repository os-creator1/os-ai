<?php

namespace App\Http\Controllers\Admin;

use App\Library\Seo\SeoConfig;
use App\Models\SeoRankProviderLedger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SEO Keyword Rank Tracking V1 §24 — admin-only answer to "how much did rank
 * tracking cost this month?". Reads the internal platform provider-cost ledger
 * (never customer-billable usage). Read-only: nothing here spends, releases or
 * edits a ledger row. Inside the admin EnsureUserIsAdministrator group, so no
 * customer can reach it; provider task ids and credentials are never shown.
 */
class SeoRankCostController extends AdminBaseController
{
    private const BUSINESSES_PER_PAGE = 25;

    public function __construct(private readonly SeoConfig $config)
    {
    }

    public function summary(Request $request): View
    {
        $month = (string) $request->query('month', '');
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1 ? $month : Carbon::now('UTC')->format('Y-m');

        $effective = DB::raw('COALESCE(actual_micros, reserved_micros)');
        $month_rows = SeoRankProviderLedger::query()->where('usage_month', $month)->where('status', '!=', SeoRankProviderLedger::RELEASED);

        $totals = (clone $month_rows)->selectRaw('COUNT(*) as runs, COALESCE(SUM(COALESCE(actual_micros, reserved_micros)),0) as micros')->first();

        $byOperation = (clone $month_rows)->groupBy('operation')
            ->selectRaw('operation, COUNT(*) as runs, SUM(COALESCE(actual_micros, reserved_micros)) as micros')
            ->orderBy('operation')->get();

        $byWorkspace = (clone $month_rows)->whereNotNull('workspace_id')->groupBy('workspace_id')
            ->selectRaw('workspace_id, COUNT(*) as runs, SUM(COALESCE(actual_micros, reserved_micros)) as micros')
            ->orderByDesc('micros')->limit(25)->get();

        $byBusiness = (clone $month_rows)->groupBy('business_id')
            ->selectRaw('business_id, COUNT(*) as runs, SUM(COALESCE(actual_micros, reserved_micros)) as micros')
            ->orderByDesc('micros')->paginate(self::BUSINESSES_PER_PAGE)->withQueryString();

        $byStatus = SeoRankProviderLedger::query()->where('usage_month', $month)->groupBy('status')
            ->selectRaw('status, COUNT(*) as runs, SUM(COALESCE(actual_micros, reserved_micros)) as micros')->get();

        $today = Carbon::now('UTC')->toDateString();
        $todayMicros = (int) SeoRankProviderLedger::query()->where('usage_day', $today)->where('status', '!=', SeoRankProviderLedger::RELEASED)->sum($effective);

        return view('admin.seo-rank-cost.index', [
            'month' => $month,
            'enabled' => $this->config->rankTrackingEnabled(),
            'totals' => $totals,
            'byOperation' => $byOperation,
            'byWorkspace' => $byWorkspace,
            'byBusiness' => $byBusiness,
            'byStatus' => $byStatus,
            'todayMicros' => $todayMicros,
            'caps' => [
                'global_daily' => $this->config->rankGlobalDailyCapMicros(),
                'global_monthly' => $this->config->rankGlobalMonthlyCapMicros(),
                'workspace_monthly' => $this->config->rankWorkspaceMonthlyCapMicros(),
            ],
            'usd' => static fn (?int $micro): string => $micro === null ? '—' : '$' . bcdiv((string) $micro, '1000000', 4),
            'breadcrumbs' => [
                ['link' => url(config('app.admin_path') . '/dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['name' => 'Rank tracking cost'],
            ],
        ]);
    }
}

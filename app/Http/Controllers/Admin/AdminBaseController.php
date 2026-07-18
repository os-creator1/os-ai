<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoices;
use App\Models\Plan;
use App\Models\Reports;
use App\Models\Senderid;
use App\Models\SendingServer;
use App\Models\Subscription;
use App\Models\User;
use ArielMejiaDev\LarapexCharts\LarapexChart;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class AdminBaseController extends Controller
{
    public function index()
    {
        $breadcrumbs = [
            ['link' => '/dashboard', 'name' => __('locale.menu.Dashboard')],
            ['name' => Auth::user()->displayName()],
        ];

        // Use tags to clear cache sections independently
        $revenue = Cache::remember('revenue', 3600, function () {
            return Invoices::CurrentMonth()
                ->selectRaw('DAY(created_at) as day, SUM(amount) as revenue')
                ->groupBy('day')
                ->orderBy('day')
                ->pluck('revenue', 'day');
        });

        $revenue_chart = (new LarapexChart())->lineChart()
            ->addData(__('locale.labels.revenue'), $revenue->values()->all())
            ->setXAxis($revenue->keys()->all());

        $customers = Cache::remember('customers', 3600, function () {
            return Customer::thisYear()
                ->selectRaw('DATE_FORMAT(created_at, "%m-%Y") as month, COUNT(uid) as customer')
                ->groupBy('month')
                ->orderBy('month')
                ->pluck('customer', 'month');
        });

        $customer_growth = (new LarapexChart())->barChart()
            ->addData(__('locale.labels.customers_growth'), $customers->values()->all())
            ->setXAxis($customers->keys()->all());

        [$deliveredCount, $undeliveredCount] = Cache::remember('sms_history', 1800, function () {
            // Use conditional count to avoid scanning twice
            return [
                Reports::where('status', 'like', '%Delivered%')->count(),
                Reports::where('status', 'not like', '%Delivered%')->count(),
            ];
        });

        $sms_history = (new LarapexChart())->pieChart()
            ->addData([$deliveredCount, $undeliveredCount]);

        $sender_ids = Cache::remember(
            'sender_ids',
            900,
            fn () => Senderid::where('status', 'pending')->latest()->limit(10)->get()
        );

        // ⚡ Big optimization: use pre-aggregated query
        $mergedData = Cache::remember('current_month_reports', 3600, function () {
            $query = Reports::selectRaw('
                DAY(created_at) as day,
                direction,
                sms_type,
                COUNT(*) as count
            ')
                ->whereIn('sms_type', ['plain', 'unicode', 'mms', 'voice', 'viber', 'whatsapp', 'otp'])
                ->currentMonth()
                ->groupBy('day', 'direction', 'sms_type')
                ->get();

            $daysRange = range(Carbon::now()->startOfMonth()->day, Carbon::now()->endOfMonth()->day);

            $structured = [];
            foreach ($daysRange as $day) {
                $structured[$day] = [
                    'day'      => $day,
                    'outgoing' => [],
                    'incoming' => [],
                    'api'      => [],
                ];
            }

            foreach ($query as $row) {
                $structured[$row->day][$row->direction][$row->sms_type] = $row->count;
            }

            // Fill missing sms_type counts with zeros
            foreach ($structured as &$dayData) {
                foreach (['plain', 'unicode', 'voice', 'mms', 'whatsapp', 'viber', 'otp'] as $type) {
                    foreach (['outgoing', 'incoming', 'api'] as $dir) {
                        $dayData[$dir][$type] = $dayData[$dir][$type] ?? 0;
                    }
                }
            }

            return collect($structured)->values();
        });

        $days     = $mergedData->pluck('day')->all();
        $smsTypes = ['plain', 'unicode', 'voice', 'mms', 'whatsapp', 'viber', 'otp'];
        $charts   = [];

        foreach ($smsTypes as $type) {
            $outgoing = $mergedData->pluck("outgoing.$type")->all();
            $incoming = $mergedData->pluck("incoming.$type")->all();
            $api      = $mergedData->pluck("api.$type")->all();

            $charts[$type] = $this->createLineChart(
                "SMS Statistics for {$type} SMS",
                'Outgoing, Incoming, and API SMS',
                compact('outgoing', 'incoming', 'api', 'days')
            );
        }

        $serverCounts = Cache::remember(
            'serverCounts',
            1800,
            fn () => SendingServer::selectRaw('COUNT(*) as total, SUM(status = 1) as active')->first()
        );

        $planCounts = Cache::remember(
            'planCounts',
            1800,
            fn () => Plan::selectRaw('COUNT(*) as total, SUM(status = 1) as active')->first()
        );

        $customerCounts = Cache::remember(
            'customerCounts',
            1800,
            fn () => User::where('is_customer', 1)->selectRaw('COUNT(*) as total, SUM(status = 1) as active')->first()
        );

        $subscriptionCounts = Cache::remember(
            'subscriptionCounts',
            1800,
            fn () => Subscription::selectRaw('COUNT(*) as total, SUM(status = "active") as active')->first()
        );

        return view('admin.dashboard', compact(
            'breadcrumbs',
            'revenue_chart',
            'customer_growth',
            'sender_ids',
            'charts',
            'smsTypes',
            'serverCounts',
            'planCounts',
            'customerCounts',
            'sms_history',
            'subscriptionCounts'
        ));
    }

    protected function redirectResponse(Request $request, $message, string $type = 'success'): JsonResponse|RedirectResponse
    {
        if ($request->wantsJson()) {
            return response()->json([
                'status'  => $type,
                'message' => $message,
            ]);
        }

        return redirect()->back()->with("flash_{$type}", $message);
    }


    public function createLineChart($title, $subtitle, $data)
    {
        return (new LarapexChart())->lineChart()
            ->setTitle($title)
            ->setSubtitle($subtitle)
            ->addLine(__('locale.labels.outgoing'), $data['outgoing'])
            ->addLine(__('locale.labels.incoming'), $data['incoming'])
            ->addLine(__('locale.labels.api'), $data['api'])
            ->setXAxis($data['days']);
    }


    /**
     * Precompute and cache all dashboard metrics.
     * This can be called manually, via scheduler, or via CLI command.
     */
    public function warmCache(): void
    {
        // Revenue (1 hour)
        Cache::remember('revenue', 3600, function () {
            return Invoices::CurrentMonth()
                ->selectRaw('DAY(created_at) as day, SUM(amount) as revenue')
                ->groupBy('day')
                ->orderBy('day')
                ->pluck('revenue', 'day');
        });

        // Customer growth (1 hour)
        Cache::remember('customers', 3600, function () {
            return Customer::thisYear()
                ->selectRaw('DATE_FORMAT(created_at, "%m-%Y") as month, COUNT(uid) as customer')
                ->groupBy('month')
                ->orderBy('month')
                ->pluck('customer', 'month');
        });

        // SMS delivery stats (30 min)
        Cache::remember('sms_history', 1800, function () {
            return [
                Reports::where('status', 'like', '%Delivered%')->count(),
                Reports::where('status', 'not like', '%Delivered%')->count(),
            ];
        });

        // Pending sender IDs (15 min)
        Cache::remember(
            'sender_ids',
            900,
            fn () => Senderid::where('status', 'pending')->latest()->limit(10)->get()
        );

        // Current month detailed SMS stats (1 hour)
        Cache::remember('current_month_reports', 3600, function () {
            $query = Reports::selectRaw('
                DAY(created_at) as day,
                direction,
                sms_type,
                COUNT(*) as count
            ')
                ->whereIn('sms_type', ['plain', 'unicode', 'mms', 'voice', 'viber', 'whatsapp', 'otp'])
                ->currentMonth()
                ->groupBy('day', 'direction', 'sms_type')
                ->get();

            $daysRange = range(Carbon::now()->startOfMonth()->day, Carbon::now()->endOfMonth()->day);

            $structured = [];
            foreach ($daysRange as $day) {
                $structured[$day] = [
                    'day'      => $day,
                    'outgoing' => [],
                    'incoming' => [],
                    'api'      => [],
                ];
            }

            foreach ($query as $row) {
                $structured[$row->day][$row->direction][$row->sms_type] = $row->count;
            }

            foreach ($structured as &$dayData) {
                foreach (['plain', 'unicode', 'voice', 'mms', 'whatsapp', 'viber', 'otp'] as $type) {
                    foreach (['outgoing', 'incoming', 'api'] as $dir) {
                        $dayData[$dir][$type] = $dayData[$dir][$type] ?? 0;
                    }
                }
            }

            return collect($structured)->values();
        });

        // Other counts (30 min)
        Cache::remember(
            'serverCounts',
            1800,
            fn () => SendingServer::selectRaw('COUNT(*) as total, SUM(status = 1) as active')->first()
        );

        Cache::remember(
            'planCounts',
            1800,
            fn () => Plan::selectRaw('COUNT(*) as total, SUM(status = 1) as active')->first()
        );

        Cache::remember(
            'customerCounts',
            1800,
            fn () => User::where('is_customer', 1)->selectRaw('COUNT(*) as total, SUM(status = 1) as active')->first()
        );

        Cache::remember(
            'subscriptionCounts',
            1800,
            fn () => Subscription::selectRaw('COUNT(*) as total, SUM(status = "active") as active')->first()
        );
    }


}

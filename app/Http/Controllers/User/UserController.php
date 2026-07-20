<?php

    namespace App\Http\Controllers\User;

    use App\Http\Controllers\Controller;
    use App\Models\Reports;
    use App\Repositories\Contracts\BusinessRepository;
    use App\Repositories\Contracts\OpportunityRepository;
    use App\Repositories\Contracts\UserRepository;
    use ArielMejiaDev\LarapexCharts\LarapexChart;
    use Carbon\Carbon;
    use Illuminate\Support\Collection;
    use Illuminate\Support\Facades\Auth;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Cache;

    class UserController extends Controller
    {
        /**
         * Fixed, source-controlled top-N count for the dashboard Opportunity
         * panel (RFC-002 §43) — matches the existing userAnnouncements
         * ->take(5) convention already used in this same method, never
         * request input.
         */
        private const OPPORTUNITY_PANEL_LIMIT = 5;

        protected UserRepository $users;

        protected BusinessRepository $businessRepository;

        protected OpportunityRepository $opportunityRepository;

        /**
         * UserController constructor.
         */
        public function __construct(
            UserRepository $users,
            BusinessRepository $businessRepository,
            OpportunityRepository $opportunityRepository
        ) {
            $this->users = $users;
            $this->businessRepository = $businessRepository;
            $this->opportunityRepository = $opportunityRepository;
        }


        public function index()
        {
            $userId = Auth::id();
            $opportunities = $this->opportunityPanel();

            $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['name' => Auth::user()->displayName()],
            ];

            // Cache SMS counts (10 minutes)
            $smsCounts = Cache::remember("customer_{$userId}_sms_counts", now()->addMinutes(10), function () use ($userId) {
                $reports = Reports::where('user_id', $userId);
                $total   = $reports->count();
                $del     = (clone $reports)->where('status', 'like', '%Delivered%')->count();

                return [
                    'total'       => $total,
                    'delivered'   => $del,
                    'undelivered' => $total - $del,
                ];
            });

            $total_sms_sent   = $smsCounts['total'];
            $deliveredCount   = $smsCounts['delivered'];
            $undeliveredCount = $smsCounts['undelivered'];

            $sms_history = (new LarapexChart)->pieChart()
                ->addData([$deliveredCount, $undeliveredCount]);

            // Cache current month reports (15 minutes)
            $mergedData = Cache::remember("customer_{$userId}_monthly_reports", now()->addMinutes(15), function () use ($userId) {
                $reports = Reports::where('user_id', $userId)
                    ->whereIn('sms_type', ['plain', 'unicode', 'mms', 'voice', 'viber', 'whatsapp', 'otp'])
                    ->currentMonth()
                    ->select(
                        DB::raw('DAY(created_at) as day'),
                        DB::raw('SUM(CASE WHEN direction = "outgoing" AND sms_type = "plain"  THEN 1 ELSE 0 END) as outgoing_plain_sms'),
                        DB::raw('SUM(CASE WHEN direction = "incoming" AND sms_type = "plain"  THEN 1 ELSE 0 END) as incoming_plain_sms'),
                        DB::raw('SUM(CASE WHEN direction = "api" AND sms_type = "plain"  THEN 1 ELSE 0 END) as api_plain_sms'),
                        DB::raw('SUM(CASE WHEN direction = "outgoing" AND sms_type = "unicode"  THEN 1 ELSE 0 END) as outgoing_unicode_sms'),
                        DB::raw('SUM(CASE WHEN direction = "incoming" AND sms_type = "unicode"  THEN 1 ELSE 0 END) as incoming_unicode_sms'),
                        DB::raw('SUM(CASE WHEN direction = "api" AND sms_type = "unicode"  THEN 1 ELSE 0 END) as api_unicode_sms'),
                        DB::raw('SUM(CASE WHEN direction = "outgoing" AND sms_type = "voice" THEN 1 ELSE 0 END) as outgoing_voice_sms'),
                        DB::raw('SUM(CASE WHEN direction = "incoming" AND sms_type = "voice" THEN 1 ELSE 0 END) as incoming_voice_sms'),
                        DB::raw('SUM(CASE WHEN direction = "api" AND sms_type = "voice" THEN 1 ELSE 0 END) as api_voice_sms'),
                        DB::raw('SUM(CASE WHEN direction = "outgoing" AND sms_type = "mms"  THEN 1 ELSE 0 END) as outgoing_mms_sms'),
                        DB::raw('SUM(CASE WHEN direction = "incoming" AND sms_type = "mms"  THEN 1 ELSE 0 END) as incoming_mms_sms'),
                        DB::raw('SUM(CASE WHEN direction = "api" AND sms_type = "mms"  THEN 1 ELSE 0 END) as api_mms_sms'),
                        DB::raw('SUM(CASE WHEN direction = "outgoing" AND sms_type = "whatsapp" THEN 1 ELSE 0 END) as outgoing_whatsapp_sms'),
                        DB::raw('SUM(CASE WHEN direction = "incoming" AND sms_type = "whatsapp" THEN 1 ELSE 0 END) as incoming_whatsapp_sms'),
                        DB::raw('SUM(CASE WHEN direction = "api" AND sms_type = "whatsapp" THEN 1 ELSE 0 END) as api_whatsapp_sms'),
                        DB::raw('SUM(CASE WHEN direction = "outgoing" AND sms_type = "viber" THEN 1 ELSE 0 END) as outgoing_viber_sms'),
                        DB::raw('SUM(CASE WHEN direction = "incoming" AND sms_type = "viber" THEN 1 ELSE 0 END) as incoming_viber_sms'),
                        DB::raw('SUM(CASE WHEN direction = "api" AND sms_type = "viber" THEN 1 ELSE 0 END) as api_viber_sms'),
                        DB::raw('SUM(CASE WHEN direction = "outgoing" AND sms_type = "otp" THEN 1 ELSE 0 END) as outgoing_otp_sms'),
                        DB::raw('SUM(CASE WHEN direction = "incoming" AND sms_type = "otp" THEN 1 ELSE 0 END) as incoming_otp_sms'),
                        DB::raw('SUM(CASE WHEN direction = "api" AND sms_type = "otp" THEN 1 ELSE 0 END) as api_otp_sms')
                    )
                    ->groupBy(DB::raw('DAY(created_at)'))
                    ->get()
                    ->keyBy('day');

                $daysRange = range(Carbon::now()->startOfMonth()->day, Carbon::now()->endOfMonth()->day);

                return collect($daysRange)->map(function ($day) use ($reports) {
                    return [
                        'day'                   => $day,
                        'outgoing_plain_sms'    => $reports->get($day)->outgoing_plain_sms ?? 0,
                        'incoming_plain_sms'    => $reports->get($day)->incoming_plain_sms ?? 0,
                        'api_plain_sms'         => $reports->get($day)->api_plain_sms ?? 0,
                        'outgoing_unicode_sms'  => $reports->get($day)->outgoing_unicode_sms ?? 0,
                        'incoming_unicode_sms'  => $reports->get($day)->incoming_unicode_sms ?? 0,
                        'api_unicode_sms'       => $reports->get($day)->api_unicode_sms ?? 0,
                        'outgoing_voice_sms'    => $reports->get($day)->outgoing_voice_sms ?? 0,
                        'incoming_voice_sms'    => $reports->get($day)->incoming_voice_sms ?? 0,
                        'api_voice_sms'         => $reports->get($day)->api_voice_sms ?? 0,
                        'outgoing_mms_sms'      => $reports->get($day)->outgoing_mms_sms ?? 0,
                        'incoming_mms_sms'      => $reports->get($day)->incoming_mms_sms ?? 0,
                        'api_mms_sms'           => $reports->get($day)->api_mms_sms ?? 0,
                        'outgoing_whatsapp_sms' => $reports->get($day)->outgoing_whatsapp_sms ?? 0,
                        'incoming_whatsapp_sms' => $reports->get($day)->incoming_whatsapp_sms ?? 0,
                        'api_whatsapp_sms'      => $reports->get($day)->api_whatsapp_sms ?? 0,
                        'outgoing_viber_sms'    => $reports->get($day)->outgoing_viber_sms ?? 0,
                        'incoming_viber_sms'    => $reports->get($day)->incoming_viber_sms ?? 0,
                        'api_viber_sms'         => $reports->get($day)->api_viber_sms ?? 0,
                        'outgoing_otp_sms'      => $reports->get($day)->outgoing_otp_sms ?? 0,
                        'incoming_otp_sms'      => $reports->get($day)->incoming_otp_sms ?? 0,
                        'api_otp_sms'           => $reports->get($day)->api_otp_sms ?? 0,
                    ];
                })->values();
            });

            // Build charts from cached data
            $days     = $mergedData->pluck('day')->toArray();
            $smsTypes = ['plain', 'unicode', 'voice', 'mms', 'whatsapp', 'viber', 'otp'];
            $charts   = [];

            foreach ($smsTypes as $type) {
                $outgoingData = $mergedData->pluck("outgoing_{$type}_sms")->toArray();
                $incomingData = $mergedData->pluck("incoming_{$type}_sms")->toArray();
                $apiData      = $mergedData->pluck("api_{$type}_sms")->toArray();

                $chartData = [
                    'outgoing' => $outgoingData,
                    'incoming' => $incomingData,
                    'api'      => $apiData,
                    'days'     => $days,
                ];

                $charts[$type] = $this->createLineChart("SMS Statistics for {$type} SMS", 'Outgoing, Incoming, and API SMS', $chartData);
            }

            // Cache announcements (5 minutes)
            $userAnnouncements = Cache::remember("customer_{$userId}_announcements", now()->addMinutes(5), function () {
                return Auth::user()->announcements()
                    ->wherePivot('read_at', '=', null)
                    ->latest()
                    ->take(5)
                    ->get();
            });

            return view('customer.dashboard', compact(
                'breadcrumbs',
                'sms_history',
                'userAnnouncements',
                'total_sms_sent',
                'deliveredCount',
                'undeliveredCount',
                'charts',
                'opportunities'
            ));
        }

        /**
         * The bounded top-N actionable Opportunity list for the dashboard
         * panel (RFC-002 §43) — null whenever the panel must not render at
         * all: the feature is disabled, or the authenticated Customer has no
         * primary Business yet. Never queries Opportunities (or Business,
         * for this panel) when disabled — the config check is this method's
         * first statement.
         */
        private function opportunityPanel(): ?Collection
        {
            if (! config('opportunity.enabled', false)) {
                return null;
            }

            $customer = Auth::user()->customer;

            $business = $this->businessRepository->findPrimaryByCustomer($customer->user_id);

            if ($business === null) {
                return null;
            }

            return $this->opportunityRepository->topForCustomer($business, self::OPPORTUNITY_PANEL_LIMIT);
        }

        public function createLineChart($title, $subtitle, $data)
        {
            return (new LarapexChart)->lineChart()
                ->setTitle($title)
                ->setSubtitle($subtitle)
                ->addLine(__('locale.labels.outgoing'), $data['outgoing'])
                ->addLine(__('locale.labels.incoming'), $data['incoming'])
                ->addLine(__('locale.labels.api'), $data['api'])
                ->setXAxis($data['days']);
        }

    }

<?php

    namespace App\Http\Controllers\Admin;

    use App\Http\Controllers\Controller;
    use App\Http\Controllers\Customer\DLRController;
    use App\Http\Requests\Reports\DashboardRequest;
    use App\Library\Tool;
    use App\Models\Campaigns;
    use App\Models\Reports;
    use App\Models\SendingServer;
    use App\Models\User;
    use ArielMejiaDev\LarapexCharts\LarapexChart;
    use Exception;
    use Generator;
    use Illuminate\Auth\Access\AuthorizationException;
    use Illuminate\Contracts\Foundation\Application;
    use Illuminate\Contracts\View\Factory;
    use Illuminate\Contracts\View\View;
    use Illuminate\Http\JsonResponse;
    use Illuminate\Http\RedirectResponse;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Cache;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Str;
    use JetBrains\PhpStorm\NoReturn;
    use OpenSpout\Common\Exception\InvalidArgumentException;
    use OpenSpout\Common\Exception\IOException;
    use OpenSpout\Common\Exception\UnsupportedTypeException;
    use OpenSpout\Writer\Exception\WriterNotOpenedException;
    use Rap2hpoutre\FastExcel\FastExcel;
    use Symfony\Component\HttpFoundation\BinaryFileResponse;

    class ReportsController extends Controller
    {


        public function reports(): Factory|View|Application
        {
            $this->authorize('view sms_history');

            $breadcrumbs = [
                ['link' => url(config('app.admin_path') . '/dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url(config('app.admin_path') . '/dashboard'), 'name' => __('locale.menu.Reports')],
                ['name' => __('locale.menu.SMS History')],
            ];

            // Cache customers & sending servers for 10 minutes
            $customers = Cache::remember('all_customers', 600, function () {
                return User::select('id', 'first_name', 'last_name', 'uid', 'email')->get();
            });

            $sendingServers = Cache::remember('active_sending_servers', 600, function () {
                return SendingServer::where('status', true)->select('id', 'name', 'uid', 'settings')->get();
            });

            return view('admin.Reports.all_messages', compact('breadcrumbs', 'customers', 'sendingServers'));
        }

        public function searchAllMessages(Request $request): JsonResponse
        {
            $this->authorize('view sms_history');

            // build a cache key from relevant request inputs
            $keyParts = [
                'reports_search',
                auth()->id() ?: 'guest',
                $request->input('length', 10),
                $request->input('start', 0),
                $request->input('order.0.column'),
                $request->input('order.0.dir'),
                $request->only(['user_id', 'sending_server_id', 'direction', 'type', 'status', 'from', 'to', 'message_id', 'dateRange']),
            ];
            $cacheKey = 'reports_' . md5(json_encode($keyParts));

            // short TTL — reports change frequently; 10-30s is typical
            $cached = Cache::get($cacheKey);
            if ($cached) {
                return response()->json($cached);
            }

            $columns = [
                1  => 'uid',
                2  => 'created_at',
                3  => 'direction',
                4  => 'sms_type',
                5  => 'from',
                6  => 'to',
                7  => 'sms_count',
                8  => 'cost',
                9  => 'status',
                10 => 'sending_server_id',
                11 => 'user_id',
            ];

            $limit            = $request->input('length', 10);
            $start            = $request->input('start', 0);
            $orderColumnIndex = $request->input('order.0.column', 2);
            $orderColumnName  = $columns[$orderColumnIndex] ?? 'created_at';
            $orderDirection   = $request->input('order.0.dir', 'desc');

            $baseQuery = Reports::select([
                'uid', 'user_id', 'sending_server_id', 'direction', 'sms_type',
                'from', 'to', 'sms_count', 'cost', 'status', 'created_at',
            ])
                ->with([
                    'user:id,uid,first_name,last_name,email',
                    'sendingServer:id,uid,name,settings',
                ])
                ->filterByUser($request->input('user_id'))
                ->filterBySendingServer($request->input('sending_server_id'))
                ->filterByDirection($request->input('direction'))
                ->filterByType($request->input('type'))
                ->filterByAdminStatus($request->input('status'))
                ->filterByFrom($request->input('from'))
                ->filterByTo($request->input('to'))
                ->filterByMessageId($request->input('message_id'))
                ->filterByInputDateRange($request->input('dateRange'));

            $totalFiltered = $baseQuery->count();
            $reports       = $baseQuery->orderBy($orderColumnName, $orderDirection)
                ->skip($start)
                ->take($limit)
                ->get();

            $data = $reports->map(fn($report) => [
                'responsive_id'     => '',
                'uid'               => $report->uid,
                'avatar'            => route('admin.customers.avatar', $report->user->uid ?? ''),
                'email'             => $report->user->email ?? '',
                'created_at'        => __('locale.labels.sent') . ': ' . Tool::customerDateTime($report->created_at),
                'user_id'           => "<a href='" . route('admin.customers.show', $report->user->uid ?? '#') . "' class='text-primary mr-1'>" . ($report->user->displayName() ?? '') . "</a>",
                'direction'         => $report->getDirection(),
                'sms_type'          => $report->getSMSType(),
                'from'              => $report->from,
                'to'                => $report->to,
                'cost'              => $report->cost,
                'sms_count'         => $report->sms_count,
                'status'            => Str::limit($report->status, 20),
                'sending_server_id' => isset($report->sendingServer)
                    ? "<a href='" . route('admin.sending-servers.show', $report->sendingServer->uid) . "' class='text-primary mr-1'>" . $report->sendingServer->name . "</a>"
                    : "<a href='#' class='text-primary mr-1'>" . __('locale.sending_servers.sending_server_not_found') . "</a>",
                'dlr'               => isset($report->sendingServer) && $report->sendingServer->settings == SendingServer::TYPE_ADVANCEMSGSYS,
            ]);

            $json = [
                'draw'            => intval($request->input('draw', 1)),
                'recordsTotal'    => Reports::count(),
                'recordsFiltered' => $totalFiltered,
                'data'            => $data,
            ];

            // store short-lived cache
            Cache::put($cacheKey, $json, now()->addMinute());

            return response()->json($json);
        }

        /**
         * view single reports
         */
        public function viewReports(Reports $uid): JsonResponse
        {
            $cacheKey = 'report_view_' . $uid->id;

            $report = Cache::remember($cacheKey, 10, function () use ($uid) {
                return $uid;
            });

            return response()->json([
                'status' => 'success',
                'data'   => $report,
            ]);
        }

        /**
         * @throws Exception|Exception
         */
        public function destroy(Reports $uid): JsonResponse
        {

            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            if ( ! $uid->delete()) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.exceptions.something_went_wrong'),
                ]);
            }

            return response()->json([
                'status'  => 'success',
                'message' => __('locale.campaigns.sms_was_successfully_deleted'),
            ]);

        }

        /**
         * bulk sms delete
         */
        public function batchAction(Request $request): JsonResponse
        {
            if (config('app.stage') === 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $ids    = $request->get('ids', []);
            $action = $request->get('action');

            if ( ! is_array($ids) || empty($ids)) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'No valid IDs provided.',
                ]);
            }

            switch ($action) {
                case 'delete':
                    Reports::whereIn('uid', $ids)->delete();

                    return response()->json([
                        'status'  => 'success',
                        'message' => __('locale.campaigns.sms_was_successfully_deleted'),
                    ]);

                case 'dlr':
                    $reports = Reports::with('sendingServer') // eager load to reduce queries
                    ->whereIn('uid', $ids)
                        ->whereHas('sendingServer', fn($q) => $q->where('settings', SendingServer::TYPE_ADVANCEMSGSYS)
                        )
                        ->get();

                    foreach ($reports as $report) {
                        $statusParts = explode('|', $report->status);
                        $sms_id      = $statusParts[1] ?? null;

                        if ( ! $sms_id || ! isset($report->sendingServer)) continue;

                        $params = [
                            'user'   => $report->sendingServer->username,
                            'pass'   => $report->sendingServer->password,
                            'respid' => $sms_id,
                        ];

                        $url = str_replace('websms', 'websmsstatus', $report->sendingServer->api_link)
                            . '?' . http_build_query($params);

                        try {
                            $ch = curl_init($url);
                            curl_setopt_array($ch, [
                                CURLOPT_RETURNTRANSFER => true,
                                CURLOPT_SSL_VERIFYPEER => false,
                                CURLOPT_SSL_VERIFYHOST => false,
                                CURLOPT_HTTPHEADER     => [
                                    'Authorization: Basic ' . base64_encode("{$params['user']}:{$params['pass']}"),
                                ],
                            ]);

                            $response = curl_exec($ch);
                            if (curl_errno($ch)) {
                                $status = curl_error($ch);
                            } else {
                                preg_match('/Status\s*:\s*(\w+)/', $response, $matches);
                                $status = $matches[1] ?? null;
                                if ($status === 'DELIVRD') {
                                    $status = 'Delivered';
                                }
                            }
                            curl_close($ch);
                        } catch (Exception $e) {
                            $status = $e->getMessage();
                        }

                        if ( ! empty($sms_id)) {
                            DLRController::updateDLR($sms_id, $status);
                        }
                    }

                    return response()->json([
                        'status'  => 'success',
                        'message' => 'DLR was successfully updated',
                    ]);

                default:
                    return response()->json([
                        'status'  => 'error',
                        'message' => __('locale.exceptions.something_went_wrong'),
                    ]);
            }
        }


        public function exportData($request): Generator
        {

            $reports = Reports::with(['user', 'sendingServer'])
                ->filterByUser($request->input('user_id'))
                ->filterBySendingServer($request->input('sending_server'))
                ->filterByDirection($request->input('direction'))
                ->filterByType($request->input('type'))
                ->filterByStatus($request->input('status'))
                ->filterByFrom($request->input('from'))
                ->filterByTo($request->input('to'))
                ->filterByDateRange($request->input('start_date'), $request->input('start_time'), $request->input('end_date'), $request->input('end_time'))
                ->get();


            yield from $reports->map(function ($report) {

                if ($report->direction == Reports::DIRECTION_API) {
                    $direction = __('locale.labels.api');
                } else if ($report->direction == Reports::DIRECTION_INCOMING) {
                    $direction = __('locale.labels.incoming');
                } else {
                    $direction = __('locale.labels.outgoing');
                }


                return [
                    'created_at'     => Tool::customerDateTime($report->created_at),
                    'from'           => $report->from,
                    'to'             => $report->to,
                    'message'        => $report->message,
                    'cost'           => $report->cost,
                    'sms_count'      => $report->sms_count,
                    'username'       => $report->user->displayName(),
                    'company'        => $report->user->customer->company,
                    'email'          => $report->user->email,
                    'sending_server' => isset($report->sendingServer->name) ?? '',
                    'media_url'      => $report->media_url,
                    'sms_type'       => $report->sms_type,
                    'status'         => $report->status,
                    'direction'      => $direction,
                ];
            });

        }

        /**
         * @throws AuthorizationException
         */
        public function export(Request $request): BinaryFileResponse|RedirectResponse
        {

            if (config('app.stage') == 'demo') {
                return redirect()->route('admin.reports.all')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $this->authorize('view sms_history');

            Tool::resetMaxExecutionTime();

            try {
                $file_name = (new FastExcel($this->exportData($request)))->export(storage_path('Reports_' . time() . '.xlsx'));

                return response()->download($file_name);

            } catch (IOException|InvalidArgumentException|UnsupportedTypeException|WriterNotOpenedException $e) {
                return redirect()->route('admin.reports.all')->with([
                    'status'  => 'error',
                    'message' => $e->getMessage(),
                ]);
            }

        }

        /*
        |--------------------------------------------------------------------------
        | Version 3.7
        |--------------------------------------------------------------------------
        |
        | Reports Dashboard, Campaigns, Make more readable reports module
        |
        */

        /**
         * Reports Dashboard
         *
         * @return Factory|View
         *
         * @throws AuthorizationException
         */
        public function dashboard()
        {
            $this->authorize('view sms_history');

            $breadcrumbs = [
                ['link' => url(config('app.admin_path') . '/dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url(config('app.admin_path') . '/dashboard'), 'name' => __('locale.menu.Reports')],
                ['name' => __('locale.menu.Dashboard')],
            ];

            $customers = User::select('id', 'first_name', 'last_name')->get();

            $reportQuery = Reports::select(
                'sms_type',
                DB::raw('SUM(CASE WHEN status LIKE "%Delivered%" THEN cost ELSE 0 END) as total_cost'),
                DB::raw('SUM(sms_count) as total_sms'),
                DB::raw('SUM(CASE WHEN status LIKE "%Delivered%" THEN 1 ELSE 0 END) as delivered_sms'),
                DB::raw('SUM(CASE WHEN status NOT LIKE "%Delivered%" THEN 1 ELSE 0 END) as not_delivered_sms')
            )->groupBy('sms_type')->whereDate('created_at', today());

            $reports = $reportQuery->get();

            $smsTypes = $reportQuery->pluck('sms_type')->unique();

            $chart = (new LarapexChart)->areaChart();

            foreach ($smsTypes as $smsType) {
                $data = $reports->where('sms_type', $smsType)->pluck('total_sms');
                $chart->addData(ucfirst($smsType), $data->toArray());
            }

            $chart->setXAxis([today()->format(config('app.date_format'))]);

            return view('admin.Reports.dashboard', compact('breadcrumbs', 'customers', 'reports', 'chart'));
        }

        public function parseDates(string $dateRange): array
        {
            $dates     = array_map('trim', explode(' to ', $dateRange));
            $startDate = date('Y-m-d', strtotime($dates[0]));
            $endDate   = isset($dates[1]) ? date('Y-m-d', strtotime($dates[1])) : $startDate;

            return [$startDate, $endDate];
        }

        public function getReportsData(string $type, array $dates, int $user_id)
        {
            [$startDate, $endDate] = $dates;

            return Reports::select(DB::raw('DATE(created_at) as date'), DB::raw('SUM(sms_count) as total_sms'))
                ->where('sms_type', $type)
                ->whereBetween('created_at', [$startDate, $endDate])
                ->when($user_id, function ($query, $user_id) {
                    $query->where('user_id', $user_id);
                })
                ->groupBy('date')
                ->get();
        }

        public function postDashboard(DashboardRequest $request)
        {
            $breadcrumbs = [
                ['link' => url(config('app.admin_path') . '/dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url(config('app.admin_path') . '/dashboard'), 'name' => __('locale.menu.Reports')],
                ['name' => __('locale.menu.Dashboard')],
            ];

            $dateRange = $request->input('dateRange');
            if ( ! $dateRange) {
                return back()->withInput('dateRange')->with([
                    'status'  => 'error',
                    'message' => 'Please select a valid date',
                ]);
            }

            $customers = User::select('id', 'first_name', 'last_name')->get();

            $dates   = $this->parseDates($dateRange);
            $user_id = $request->input('user_id');
            $reports = Reports::select('sms_type',
                DB::raw('SUM(CASE WHEN status LIKE "%Delivered%" THEN cost ELSE 0 END) as total_cost'),
                DB::raw('COUNT(sms_count) as total_sms'),
                DB::raw('SUM(CASE WHEN status LIKE "%Delivered%" THEN 1 ELSE 0 END) as delivered_sms'),
                DB::raw('SUM(CASE WHEN status NOT LIKE "%Delivered%" THEN 1 ELSE 0 END) as not_delivered_sms'))
                ->whereBetween('created_at', $dates)
                ->when($user_id, function ($query, $user_id) {
                    $query->where('user_id', $user_id);
                })
                ->groupBy('sms_type')
                ->get();

            $getData  = [];
            $smsTypes = ['plain', 'voice', 'mms', 'whatsapp', 'unicode'];
            array_map(function ($type) use ($dates, $user_id, &$getData) {
                $getData[$type] = $this->getReportsData($type, $dates, $user_id);

                return $getData;
            }, $smsTypes);

            $chart = (new LarapexChart)->areaChart();
            $chart->setTitle('SMS Cost by Type');
            $chart->setXAxis($getData['plain']->pluck('date')->toArray());
            $chart->setLabels($smsTypes);

            foreach ($smsTypes as $type) {
                $chart->addData(ucfirst($type), $getData[$type]->pluck('total_sms')->toArray());
            }

            return view('admin.Reports.dashboard', compact('breadcrumbs', 'reports', 'request', 'chart', 'customers'));
        }

        /**
         * Campaigns
         *
         * @return Factory|View
         *
         * @throws AuthorizationException
         */
        public function campaigns()
        {

            $this->authorize('view sms_history');

            $breadcrumbs = [
                ['link' => url(config('app.admin_path') . '/dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url(config('app.admin_path') . '/dashboard'), 'name' => __('locale.menu.Reports')],
                ['name' => __('locale.menu.Campaigns')],
            ];

            return view('admin.Reports.campaigns', compact('breadcrumbs'));

        }

        /**
         * search campaign data
         *
         *
         * @throws AuthorizationException
         */
        #[NoReturn]
        public function searchCampaigns(Request $request)
        {

            $columns = [
                0 => 'responsive_id',
                1 => 'uid',
                2 => 'user_id',
                3 => 'campaign_name',
                4 => 'contacts',
                5 => 'sms_type',
                6 => 'schedule_type',
                7 => 'status',
            ];

            $totalData = Campaigns::count();

            $totalFiltered = $totalData;

            $limit = $request->input('length');
            $start = $request->input('start');
            $order = $columns[$request->input('order.0.column')];
            $dir   = $request->input('order.0.dir');

            if (empty($request->input('search.value'))) {
                $campaigns = Campaigns::offset($start)
                    ->limit($limit)
                    ->orderBy('created_at', $dir)
                    ->get();
            } else {
                $search = $request->input('search.value');

                $campaigns = Campaigns::whereLike(['uid', 'campaign_name', 'sms_type', 'schedule_type', 'created_at', 'status', 'user.first_name', 'user.last_name', 'user.email'], $search)
                    ->offset($start)
                    ->limit($limit)
                    ->orderBy($order, $dir)
                    ->get();

                $totalFiltered = Campaigns::whereLike(['uid', 'campaign_name', 'sms_type', 'schedule_type', 'created_at', 'status', 'user.first_name', 'user.last_name', 'user.email'], $search)->count();
            }

            $data = [];
            if ( ! empty($campaigns)) {
                foreach ($campaigns as $campaign) {

                    $customer_profile = route('admin.customers.show', $campaign->user->uid);
                    $customer_name    = $campaign->user->displayName();
                    $user_id          = "<a href='$customer_profile' class='text-primary mr-1'>$customer_name</a>";

                    $nestedData['responsive_id'] = '';
                    $nestedData['uid']           = $campaign->uid;
                    $nestedData['campaign_name'] = "<div class='d-flex flex-column'>
                                                        <span class='emp_name text-truncate fw-bold'> $campaign->campaign_name </span>
                                                        <small class='emp_post text-truncate text-muted'>" . __('locale.labels.created_at') . ': ' . Tool::formatHumanTime($campaign->created_at) . '</small>
                                                   </div>';

                    $nestedData['avatar']        = route('admin.customers.avatar', $campaign->user->uid);
                    $nestedData['email']         = $campaign->user->email;
                    $nestedData['user_id']       = $user_id;
                    $nestedData['contacts']      = Tool::number_with_delimiter($campaign->contactCount($campaign->cache));
                    $nestedData['sms_type']      = $campaign->getSMSType();
                    $nestedData['schedule_type'] = $campaign->getCampaignType();
                    $nestedData['status']        = $campaign->getStatus();
                    $nestedData['camp_status']   = str_limit($campaign->status, 30);
                    $nestedData['camp_name']     = $campaign->campaign_name;

                    $nestedData['overview']       = route('admin.reports.campaign.overview', $campaign->uid);
                    $nestedData['overview_label'] = __('locale.menu.Overview');
                    $nestedData['mark_delivered'] = __('locale.labels.mark_as_delivered');

                    $nestedData['delete'] = __('locale.buttons.delete');
                    $data[]               = $nestedData;

                }
            }

            $json_data = [
                'draw'            => intval($request->input('draw')),
                'recordsTotal'    => $totalData,
                'recordsFiltered' => $totalFiltered,
                'data'            => $data,
            ];

            echo json_encode($json_data);
            exit();

        }

        public function campaignOverview(Campaigns $campaign): Factory|\Illuminate\Foundation\Application|View|Application|RedirectResponse
        {
            $this->authorize('view sms_history');

            $breadcrumbs = [
                ['link' => url(config('app.admin_path') . '/dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url(config('app.admin_path') . '/reports/campaigns'), 'name' => __('locale.menu.Reports')],
                ['name' => __('locale.menu.Campaigns')],
            ];

            $campaign = Campaigns::where('uid', $campaign->uid)->first();

            if ( ! $campaign) {
                return redirect()->route('admin.reports.campaigns')->with([
                    'status'  => 'error',
                    'message' => __('locale.exceptions.invalid_action'),
                ]);
            }


            if ( ! $campaign) {
                return redirect()->route('customer.reports.campaigns')->with([
                    'status'  => 'error',
                    'message' => __('locale.exceptions.invalid_action'),
                ]);
            }

            $reportStatusCounts = Reports::where('campaign_id', $campaign->id)->selectRaw('
        COUNT(CASE WHEN customer_status = "Enroute" THEN 1 END) as enroute_count,
        COUNT(CASE WHEN customer_status = "Delivered" THEN 1 END) as delivered_count,
        COUNT(CASE WHEN customer_status = "Expired" THEN 1 END) as expired_count,
        COUNT(CASE WHEN customer_status = "Undelivered" THEN 1 END) as undelivered_count,
        COUNT(CASE WHEN customer_status = "Rejected" THEN 1 END) as rejected_count,
        COUNT(CASE WHEN customer_status = "Accepted" THEN 1 END) as accepted_count,
        COUNT(CASE WHEN customer_status = "Skipped" THEN 1 END) as skipped_count,
        COUNT(CASE WHEN customer_status NOT IN ("Enroute", "Delivered", "Expired", "Undelivered", "Rejected", "Accepted", "Skipped") THEN 1 END) as failed_count
    ')
                ->first();


            $totalCount = $campaign->contactCount($campaign->cache);

// Calculate the percentages for each status
            $reportStatusPercentages = [
                'enroute_percentage'     => $totalCount > 0 ? ($reportStatusCounts->enroute_count / $totalCount) * 100 : 0,
                'delivered_percentage'   => $totalCount > 0 ? ($reportStatusCounts->delivered_count / $totalCount) * 100 : 0,
                'expired_percentage'     => $totalCount > 0 ? ($reportStatusCounts->expired_count / $totalCount) * 100 : 0,
                'undelivered_percentage' => $totalCount > 0 ? ($reportStatusCounts->undelivered_count / $totalCount) * 100 : 0,
                'rejected_percentage'    => $totalCount > 0 ? ($reportStatusCounts->rejected_count / $totalCount) * 100 : 0,
                'accepted_percentage'    => $totalCount > 0 ? ($reportStatusCounts->accepted_count / $totalCount) * 100 : 0,
                'skipped_percentage'     => $totalCount > 0 ? ($reportStatusCounts->skipped_count / $totalCount) * 100 : 0,
                'failed_percentage'      => $totalCount > 0 ? ($reportStatusCounts->failed_count / $totalCount) * 100 : 0,
            ];

            return view('admin.Reports.overview', compact('campaign', 'breadcrumbs', 'reportStatusCounts', 'reportStatusPercentages'));
        }

        /**
         * view campaign reports
         *
         *
         * @throws AuthorizationException
         */
        #[NoReturn]
        public function campaignReports(Campaigns $campaign, Request $request)
        {

            $columns = [
                0  => 'responsive_id',
                1  => 'uid',
                2  => 'uid',
                3  => 'created_at',
                6  => 'from',
                7  => 'to',
                8  => 'cost',
                9  => 'sms_count',
                10 => 'status',
            ];

            $totalData = Reports::where('campaign_id', $campaign->id)->count();

            $totalFiltered = $totalData;

            $limit = $request->input('length');
            $start = $request->input('start');
            $order = $columns[$request->input('order.0.column')];
            $dir   = $request->input('order.0.dir');

            if (empty($request->input('search.value'))) {
                $sms_reports = Reports::where('campaign_id', $campaign->id)->offset($start)
                    ->limit($limit)
                    ->orderBy('created_at', $dir)
                    ->get();
            } else {
                $search = $request->input('search.value');

                $sms_reports = Reports::where('campaign_id', $campaign->id)->whereLike(['uid', 'from', 'to', 'cost', 'sms_count', 'status', 'created_at'], $search)
                    ->offset($start)
                    ->limit($limit)
                    ->orderBy($order, $dir)
                    ->get();

                $totalFiltered = Reports::where('campaign_id', $campaign->id)->whereLike(['uid', 'from', 'to', 'cost', 'sms_count', 'status', 'created_at'], $search)->count();
            }

            $data = [];
            if ( ! empty($sms_reports)) {

                foreach ($sms_reports as $report) {
                    if ($report->created_at == null) {
                        $created_at = null;
                    } else {
                        $created_at = Tool::customerDateTime($report->created_at);
                    }

                    $dlr = isset($report->sendingServer) && $report->sendingServer->settings == SendingServer::TYPE_ADVANCEMSGSYS;

                    $nestedData['responsive_id'] = '';
                    $nestedData['uid']           = $report->uid;
                    $nestedData['created_at']    = $created_at;
                    $nestedData['from']          = $report->from;
                    $nestedData['to']            = $report->to;
                    $nestedData['cost']          = $report->cost;
                    $nestedData['sms_count']     = $report->sms_count;
                    $nestedData['status']        = str_limit($report->status, 20);
                    $nestedData['dlr']           = $dlr;
                    $data[]                      = $nestedData;

                }
            }

            $json_data = [
                'draw'            => intval($request->input('draw')),
                'recordsTotal'    => $totalData,
                'recordsFiltered' => $totalFiltered,
                'data'            => $data,
            ];

            echo json_encode($json_data);
            exit();
        }

        /**
         * delete campaign
         *
         *
         * @throws Exception
         */
        public function campaignDelete(Campaigns $campaign): JsonResponse
        {
            if (config('app.stage') == 'demo') {

                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            if ( ! $campaign->delete()) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.exceptions.something_went_wrong'),
                ]);
            }

            return response()->json([
                'status'  => 'success',
                'message' => __('locale.campaigns.campaign_was_successfully_deleted'),
            ]);

        }

        /**
         * bulk campaign delete
         */
        public function campaignBatchAction(Request $request): JsonResponse
        {

            if (config('app.stage') == 'demo') {

                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $ids = $request->get('ids');

            if (Campaigns::whereIn('uid', $ids)->delete()) {
                return response()->json([
                    'status'  => 'success',
                    'message' => __('locale.campaigns.campaign_was_successfully_deleted'),
                ]);
            }

            return response()->json([
                'status'  => 'error',
                'message' => __('locale.exceptions.something_went_wrong'),
            ]);
        }

        public function campaignReportsGenerator($campaign_id): Generator
        {
            $reports = Reports::where('campaign_id', $campaign_id)->get();

            yield from $reports->map(function ($report) {

                return [
                    'created_at' => Tool::customerDateTime($report->created_at),
                    'from'       => $report->from,
                    'to'         => $report->to,
                    'message'    => $report->message,
                    'cost'       => $report->cost,
                    'media_url'  => $report->media_url,
                    'sms_type'   => $report->sms_type,
                    'status'     => $report->status,
                    'direction'  => $report->direction,
                ];
            });
        }

        /**
         * @throws AuthorizationException
         */
        public function exportCampaign(Campaigns $campaign): BinaryFileResponse|RedirectResponse
        {
            if (config('app.stage') == 'demo') {
                return redirect()->route('admin.reports.all')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $this->authorize('view sms_history');

            try {
                $file_name = (new FastExcel($this->campaignReportsGenerator($campaign->id)))->export(storage_path('Reports_' . time() . '.xlsx'));

                return response()->download($file_name);
            } catch (IOException|InvalidArgumentException|UnsupportedTypeException|WriterNotOpenedException $e) {
                return redirect()->route('admin.reports.all')->with([
                    'status'  => 'error',
                    'message' => $e->getMessage(),
                ]);
            }

        }

        public function campaignGenerator(): Generator
        {
            foreach (Campaigns::all() as $report) {
                yield $report;
            }
        }

        /**
         * @throws AuthorizationException
         */
        public function campaignExport(): BinaryFileResponse|RedirectResponse
        {
            if (config('app.stage') == 'demo') {
                return redirect()->route('admin.reports.all')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $this->authorize('view sms_history');

            try {
                $file_name = (new FastExcel($this->campaignGenerator()))->export(storage_path('Campaign_' . time() . '.xlsx'));

                return response()->download($file_name);
            } catch (IOException|InvalidArgumentException|UnsupportedTypeException|WriterNotOpenedException $e) {
                return redirect()->route('admin.reports.all')->with([
                    'status'  => 'error',
                    'message' => $e->getMessage(),
                ]);
            }

        }


        public function campaignMarkDelivered(Campaigns $campaign): JsonResponse
        {

            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $this->authorize('view sms_history');

            $campaign->cancelAndDeleteJobs();

            $campaign->setDone();

            return response()->json([
                'status'  => 'success',
                'message' => __('locale.campaigns.campaign_mark_as_delivered'),
            ]);

        }

        public function dlrReports(Reports $uid)
        {

            $sending_server = SendingServer::where('id', $uid->sending_server_id)->where('settings', SendingServer::TYPE_ADVANCEMSGSYS)->where('status', true)->first();
            if ($sending_server) {
                $status     = explode('|', $uid->status);
                $sms_id     = $status[1];
                $parameters = [
                    'user'   => $sending_server->username,
                    'pass'   => $sending_server->password,
                    'respid' => $sms_id,
                ];

                $gateway_url = str_replace('websms', 'websmsstatus', $sending_server->api_link) . '?' . http_build_query($parameters);


                try {
                    $ch = curl_init();

                    curl_setopt($ch, CURLOPT_URL, $gateway_url);
                    curl_setopt($ch, CURLOPT_HTTPGET, 1);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                    $headers   = [];
                    $headers[] = 'Authorization: Basic ' . base64_encode("$sending_server->username:$sending_server->password");
                    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                    $response = curl_exec($ch);

                    if (curl_errno($ch)) {
                        $get_sms_status = curl_error($ch);
                    } else {
                        preg_match('/Message ID\s*:\s*(\d+)\s*Status\s*:\s*(\w+)/', $response, $matches);
                        $get_sms_status = $matches[2] ?? null;
                        if ($get_sms_status == 'DELIVRD') {
                            $get_sms_status = 'Delivered';
                        }
                    }
                    curl_close($ch);
                } catch (Exception $e) {
                    $get_sms_status = $e->getMessage();
                }

                DLRController::updateDLR($sms_id, $get_sms_status);

                return response()->json([
                    'status'  => 'success',
                    'message' => 'Successfully updated',
                ]);

            }

            return response()->json([
                'status'  => 'error',
                'message' => 'Invalid Sending Server',
            ]);

        }

    }

<?php

    namespace App\Http\Controllers\Customer;


    use App\Helpers\Helper;
    use App\Http\Requests\Automations\SayBirthdayRequest;
    use App\Jobs\AutomationJob;
    use App\Library\Tool;
    use App\Models\Automation;
    use App\Models\ContactGroupFields;
    use App\Models\ContactGroups;
    use App\Models\Contacts;
    use App\Models\CustomerBasedSendingServer;
    use App\Models\PhoneNumbers;
    use App\Models\Plan;
    use App\Models\Senderid;
    use App\Models\Templates;
    use App\Models\TemplateTags;
    use App\Models\User;
    use App\Repositories\Contracts\AutomationsRepository;
    use Carbon\Carbon;
    use DateTime;
    use DateTimeZone;
    use Illuminate\Auth\Access\AuthorizationException;
    use Illuminate\Contracts\Foundation\Application;
    use Illuminate\Http\JsonResponse;
    use Illuminate\Http\RedirectResponse;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Auth;
    use Illuminate\Support\Facades\DB;
    use Illuminate\View\View;
    use Illuminate\Contracts\View\Factory;
    use JetBrains\PhpStorm\NoReturn;

    class AutomationsController extends CustomerBaseController
    {
        /**
         *
         * @var AutomationsRepository
         */
        protected AutomationsRepository $automations;

        /**
         * AutomationsController Constructor
         *
         * @param AutomationsRepository $automations
         */
        public function __construct(AutomationsRepository $automations)
        {
            $this->automations = $automations;
        }


        /**
         * @return Factory|View|Application
         * @throws AuthorizationException
         */
        public function index(): Factory|View|Application
        {

            $this->authorize('automations');

            $breadcrumbs = [
                ['link' => url("dashboard"), 'name' => __('locale.menu.Dashboard')],
                ['name' => __('locale.menu.Automations')],
            ];

            return view('customer.Automations.index', compact('breadcrumbs'));
        }


        /**
         * @param Request $request
         *
         * @return void
         * @throws AuthorizationException
         */
        #[NoReturn] public function search(Request $request): void
        {
            $this->authorize('automations');

            $columns = [
                0 => 'responsive_id',
                1 => 'uid',
                2 => 'uid',
                3 => 'name',
                4 => 'contacts',
                5 => 'sms_type',
                6 => 'updated_at',
                7 => 'status',
                8 => 'uid',
            ];

            $totalData = Automation::where('user_id', auth()->user()->id)->count();

            $totalFiltered = $totalData;

            $limit = $request->input('length');
            $start = $request->input('start');
            $order = $columns[$request->input('order.0.column')];
            $dir   = $request->input('order.0.dir');

            if (empty($request->input('search.value'))) {
                $automations = Automation::where('user_id', auth()->user()->id)->offset($start)
                    ->limit($limit)
                    ->orderBy($order, $dir)
                    ->get();
            } else {
                $search = $request->input('search.value');

                $automations = Automation::where('user_id', auth()->user()->id)->whereLike(['uid', 'name', 'sms_type', 'updated_at', 'created_at', 'status'], $search)
                    ->offset($start)
                    ->limit($limit)
                    ->orderBy($order, $dir)
                    ->get();

                $totalFiltered = Automation::where('user_id', auth()->user()->id)->whereLike(['uid', 'name', 'sms_type', 'updated_at', 'created_at', 'status'], $search)->count();
            }

            $nestedData['can_delete'] = Auth::user()->customer->getOption('delete_sms_history') == 'yes';

            $data = [];
            if ( ! empty($automations)) {
                foreach ($automations as $automation) {

                    $nestedData['responsive_id']     = '';
                    $nestedData['uid']               = $automation->uid;
                    $nestedData['name']              = "<div>
                                                        <p class='text-bold-600'> $automation->name </p>
                                                        <p class='text-muted'>" . __('locale.labels.created_at') . ': ' . Tool::formatHumanTime($automation->created_at) . "</p>
                                                   </div>";
                    $nestedData['contacts']          = Tool::number_with_delimiter($automation->contactCount($automation->cache));
                    $nestedData['sms_type']          = $automation->getSMSType();
                    $nestedData['updated_at']        = Tool::formatHumanTime($automation->updated_at);
                    $nestedData['status']            = $automation->getStatus();
                    $nestedData['automation_status'] = $automation->status;

                    $nestedData['overview']       = route('customer.automations.show', $automation->uid);
                    $nestedData['overview_label'] = __('locale.menu.Overview');


                    $nestedData['enable']  = __('locale.labels.enable');
                    $nestedData['disable'] = __('locale.labels.disable');
                    $nestedData['delete']  = __('locale.buttons.delete');
                    $data[]                = $nestedData;

                }
            }

            $json_data = [
                "draw"            => intval($request->input('draw')),
                "recordsTotal"    => $totalData,
                "recordsFiltered" => $totalFiltered,
                "data"            => $data,
            ];

            echo json_encode($json_data);
            exit();

        }

        /**
         * @return Application|Factory|View
         * @throws AuthorizationException
         */

        public function create(): Factory|View|Application
        {
            $this->authorize('automations');

            $breadcrumbs = [
                ['link' => url("dashboard"), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('automations'), 'name' => __('locale.menu.Automations')],
                ['name' => __('locale.buttons.create')],
            ];

            return view('customer.Automations.create', compact('breadcrumbs'));
        }

        /**
         * @return Application|Factory|\Illuminate\Contracts\View\View|RedirectResponse
         * @throws AuthorizationException
         */
        public function sayHappyBirthday()
        {

            $this->authorize('automations');

            $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('dashboard'), 'name' => __('locale.menu.Automations')],
                ['name' => __('locale.automations.say_happy_birthday')],
            ];

            if ( ! Auth::user()->customer->activeSubscription()) {
                return redirect()->route('customer.automations.create')->with([
                    'status'  => 'error',
                    'message' => __('locale.customer.no_active_subscription'),
                ]);
            }

            if (Auth::user()->customer->getOption('sender_id_verification') == 'yes') {
                $sender_ids = Senderid::where('user_id', auth()->user()->id)->where('status', 'active')->get();
            } else {
                $sender_ids = null;
            }

            $phone_numbers  = PhoneNumbers::where('user_id', auth()->user()->id)->where('status', 'assigned')->get();
            $template_tags  = TemplateTags::cursor();
            $contact_groups = ContactGroups::where('status', 1)->where('customer_id', auth()->user()->id)->get();
            $templates      = Templates::where('user_id', auth()->user()->id)->where('status', 1)->get();
            $sendingServers = CustomerBasedSendingServer::where('user_id', auth()->user()->id)->where('status', 1)->get();

            $plan_id = Auth::user()->customer->activeSubscription()->plan_id;

            return view('customer.Automations.sayHappyBirthday', compact('breadcrumbs', 'sender_ids', 'phone_numbers', 'template_tags', 'contact_groups', 'templates', 'plan_id', 'sendingServers'));
        }


        /**
         * send birthday message
         *
         * @param Automation         $automation
         * @param SayBirthdayRequest $request
         *
         * @return RedirectResponse
         * @throws AuthorizationException
         * @throws \Exception
         */
        public function postSayHappyBirthday(Automation $automation, SayBirthdayRequest $request)
        {
            $this->authorize('automations');

            if (config('app.stage') == 'demo') {
                return redirect()->route('customer.automations.say.happy.birthday')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            if (config('app.trai_dlt') && Auth::user()->customer->activeSubscription()->plan->is_dlt && $request->input('dlt_template_id') == null) {
                return redirect()->route('customer.automations.say.happy.birthday')->with([
                    'status'  => 'error',
                    'message' => 'DLT Template id is required',
                ]);
            }

            $customer           = Auth::user()->customer;
            $activeSubscription = $customer->activeSubscription();

            if ( ! $activeSubscription) {
                return redirect()->route('customer.automations.say.happy.birthday')->with([
                    'status'  => 'error',
                    'message' => __('locale.customer.no_active_subscription'),
                ]);
            }

            $plan = Plan::where('status', true)->find($activeSubscription->plan_id);

            if ( ! $plan) {
                return redirect()->route('customer.automations.say.happy.birthday')->with([
                    'status'  => 'error',
                    'message' => 'Purchased plan is not active. Please contact support team.',
                ]);
            }


            $currentTime = new DateTime('now', new DateTimeZone(Auth::user()->timezone));
            $triggerTime = new DateTime($request->input('at'), new DateTimeZone(Auth::user()->timezone));


            if ($currentTime < $triggerTime) {
                return redirect()->route('customer.automations.say.happy.birthday')->with([
                    'status'  => 'error',
                    'message' => sprintf('Not the right time: CURRENT %s < %s', $currentTime->format('Y-m-d H:i:s'), $triggerTime->format('Y-m-d H:i:s')),
                ]);
            }

            $dobFieldId = ContactGroupFields::findByUid($request->input('birthday_field'))->id;
            $today      = Carbon::now(Auth::user()->timezone)->modify($request->input('before'));


            if ( ! $dobFieldId) {
                return redirect()->route('customer.automations.say.happy.birthday')->with([
                    'status'  => 'error',
                    'message' => __('locale.automations.birthday_field_not_found'),
                ]);
            }


            $subscribers = Contacts::where('group_id', $request->input('contact_groups'))
                ->join('contacts_custom_field', 'contacts.id', 'contacts_custom_field.contact_id')
                ->where('contacts_custom_field.field_id', $dobFieldId)
                ->whereIn(
                    DB::raw("DATE_FORMAT(STR_TO_DATE(" . Helper::table('contacts_custom_field.value') . ", '" . config('custom.date_format_sql') . "'), '%m-%d')"),
                    [$today->format('m-d')]
                )->get();


            if ($subscribers->isEmpty()) {
                return redirect()->route('customer.automations.say.happy.birthday')->with([
                    'status'  => 'error',
                    'message' => __('locale.automations.birthday_empty_warning'),
                ]);
            }

            $data = $this->automations->automationBuilder($automation, $request->except('_token'));

            if (isset($data->getData()->status)) {

                if ($data->getData()->status == 'success') {
                    return redirect()->route('customer.automations.index')->with([
                        'status'  => 'success',
                        'message' => $data->getData()->message,
                    ]);
                }

                return redirect()->route('customer.automations.say.happy.birthday')->with([
                    'status'  => 'error',
                    'message' => $data->getData()->message,
                ]);
            }

            return redirect()->route('customer.automations.say.happy.birthday')->with([
                'status'  => 'error',
                'message' => __('locale.exceptions.something_went_wrong'),
            ]);

        }


        /**
         * Enable Automation
         *
         * @param Automation $automation
         *
         * @return JsonResponse
         * @throws AuthorizationException
         */
        public function enable(Automation $automation)
        {
            $this->authorize('automations');

            $data = $this->automations->enable($automation);

            if (isset($data->getData()->status)) {
                return response()->json([
                    'status'  => $data->getData()->status,
                    'message' => $data->getData()->message,
                ]);
            }


            return response()->json([
                'status'  => 'error',
                'message' => __('locale.exceptions.something_went_wrong'),
            ]);

        }

        /**
         * Disable Automation
         *
         * @param Automation $automation
         *
         * @return JsonResponse
         * @throws AuthorizationException
         */
        public function disable(Automation $automation)
        {
            $this->authorize('automations');

            $data = $this->automations->disable($automation);

            if (isset($data->getData()->status)) {
                return response()->json([
                    'status'  => $data->getData()->status,
                    'message' => $data->getData()->message,
                ]);
            }

            return response()->json([
                'status'  => 'error',
                'message' => __('locale.exceptions.something_went_wrong'),
            ]);

        }

        /**
         * Delete Automation
         *
         * @param Automation $automation
         *
         * @return JsonResponse
         * @throws AuthorizationException
         */
        public function delete(Automation $automation)
        {

            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $this->authorize('automations');

            $data = $this->automations->delete($automation);

            if (isset($data->getData()->status)) {
                return response()->json([
                    'status'  => $data->getData()->status,
                    'message' => $data->getData()->message,
                ]);
            }

            return response()->json([
                'status'  => 'error',
                'message' => __('locale.exceptions.something_went_wrong'),
            ]);

        }


        /**
         * @param Automation $automation
         *
         * @return Application|Factory|\Illuminate\Contracts\View\View|\Illuminate\Foundation\Application
         * @throws AuthorizationException
         */
        public function show(Automation $automation)
        {
            $this->authorize('automations');

            return view('customer.Automations.overview', compact('automation'));
        }


        /**
         * get reports
         *
         * @param Automation $automation
         * @param Request    $request
         *
         * @return void
         */
        #[NoReturn] public function reports(Automation $automation, Request $request)
        {

            $columns = [
                0 => 'responsive_id',
                1 => 'uid',
                2 => 'triggered_at',
                3 => 'from',
                4 => 'to',
                5 => 'cost',
                6 => 'status',
            ];

            $subscribers = $automation->getSubscribersWithTriggerInfo()
                ->addSelect('contacts.created_at')
                ->addSelect('tracking_logs.updated_at');
            $totalData   = $subscribers->count();


            $totalFiltered = $totalData;

            $limit = $request->input('length');
            $start = $request->input('start');
            $order = $columns[$request->input('order.0.column')];
            $dir   = $request->input('order.0.dir');

            if (empty($request->input('search.value'))) {
                $sms_reports = $subscribers->offset($start)
                    ->limit($limit)
                    ->orderBy($order, $dir)
                    ->get();
            } else {
                $search = $request->input('search.value');

                $sms_reports = $subscribers->whereLike(['phone'], $search)
                    ->offset($start)
                    ->limit($limit)
                    ->orderBy($order, $dir)
                    ->get();

                $totalFiltered = $subscribers->whereLike(['phone'], $search)->count();
            }

            $data = [];
            if ( ! empty($sms_reports)) {

                foreach ($sms_reports as $report) {
                    if ($report->triggered_at == null) {
                        $triggered_at = null;
                    } else {
                        $triggered_at = $report->triggered_at;
                    }

                    $nestedData['responsive_id'] = '';
                    $nestedData['uid']           = $report->uid;
                    $nestedData['send_now']      = 'Send Now';
                    $nestedData['pending']       = __('locale.labels.pending');
                    $nestedData['triggered_at']  = $triggered_at;
                    $nestedData['to']            = $report->phone;
                    $nestedData['status']        = str_limit($report->status, 20);
                    $data[]                      = $nestedData;

                }
            }

            $json_data = [
                "draw"            => intval($request->input('draw')),
                "recordsTotal"    => $totalData,
                "recordsFiltered" => $totalFiltered,
                "data"            => $data,
            ];

            echo json_encode($json_data);
            exit();
        }

        /**
         * Batch Enable, disable, Delete
         *
         * @param Request $request
         *
         * @return JsonResponse
         * @throws AuthorizationException
         */
        public function batchAction(Request $request)
        {

            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $this->authorize('automations');

            $action = $request->get('action');
            $ids    = $request->get('ids');

            switch ($action) {
                case 'destroy':
                    $data = $this->automations->batchDelete($ids);

                    if (isset($data->getData()->status)) {
                        return response()->json([
                            'status'  => $data->getData()->status,
                            'message' => $data->getData()->message,
                        ]);
                    }

                    return response()->json([
                        'status'  => 'error',
                        'message' => __('locale.exceptions.something_went_wrong'),
                    ]);

                case 'enable':
                    $data = $this->automations->batchEnable($ids);

                    if (isset($data->getData()->status)) {
                        return response()->json([
                            'status'  => $data->getData()->status,
                            'message' => $data->getData()->message,
                        ]);
                    }

                    return response()->json([
                        'status'  => 'error',
                        'message' => __('locale.exceptions.something_went_wrong'),
                    ]);


                case 'disable':
                    $data = $this->automations->batchDisable($ids);

                    if (isset($data->getData()->status)) {
                        return response()->json([
                            'status'  => $data->getData()->status,
                            'message' => $data->getData()->message,
                        ]);
                    }

                    return response()->json([
                        'status'  => 'error',
                        'message' => __('locale.exceptions.something_went_wrong'),
                    ]);

            }

            return response()->json([
                'status'  => 'error',
                'message' => __('locale.exceptions.invalid_action'),
            ]);

        }

        /**
         * Manually trigger automation message
         *
         * @param Automation $automation
         * @param Contacts   $subscriber
         *
         * @return JsonResponse
         * @throws AuthorizationException
         */
        public function sendNow(Automation $automation, Contacts $subscriber)
        {

            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $this->authorize('automations');

            $contacts[] = $subscriber;

            if ($automation->status == 'inactive') {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.automations.automation_not_active'),
                ]);
            }

            $user = User::where('status', true)->find($automation->user_id);


            if ( ! $user) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.auth.user_not_exist'),
                ]);
            }

            // Schedule Job initialize
            $scheduler = (new AutomationJob($automation, collect($contacts)));

            // Dispatch using the method provided by TrackJobs
            // to also generate job-monitor record
            $automation->dispatchWithMonitor($scheduler);

            return response()->json([
                'status'  => 'success',
                'message' => sprintf('Manually trigger contact %s in background', $subscriber->phone),
            ]);

        }

        /*Version 3.9*/
        public function getTags($contact_id)
        {

            $group = ContactGroups::where('status', 1)->where('customer_id', auth()->user()->id)->find($contact_id);

            if ( ! $group) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.contacts.contact_group_not_found'),
                ]);
            }

            $tags = $group->getFields;

            if ( ! $tags) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Contacts fields are not available",
                ]);
            }

            return response()->json([
                'status'        => 'success',
                'contactFields' => $tags,
            ]);
        }


    }

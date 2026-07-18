<?php

    namespace App\Http\Controllers\API;

    use App\Http\Controllers\Controller;
    use App\Http\Requests\Campaigns\SendAPICampaign;
    use App\Library\SMSCounter;
    use App\Library\Tool;
    use App\Models\Campaigns;
    use App\Models\CustomerBasedSendingServer;
    use App\Models\Plan;
    use App\Models\Reports;
    use App\Models\Traits\ApiResponser;
    use App\Models\User;
    use App\Repositories\Contracts\CampaignRepository;
    use Carbon\Carbon;
    use DateTimeZone;
    use Exception;
    use Illuminate\Http\JsonResponse;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Validator;
    use libphonenumber\NumberParseException;
    use libphonenumber\PhoneNumberUtil;

    class CampaignHTTPController extends Controller
    {
        use ApiResponser;

        protected CampaignRepository $campaigns;

        /**
         * CampaignController constructor.
         */
        public function __construct(CampaignRepository $campaigns)
        {
            $this->campaigns = $campaigns;
        }

        /**
         * sms sending
         */
        public function smsSend(Campaigns $campaign, Request $request, Carbon $carbon): JsonResponse
        {

            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $validator = Validator::make($request->all(), [
                'recipient' => 'required',
                'message'   => 'required',
                'api_token' => 'required',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status'  => 'error',
                    'message' => $validator->errors()->first(),
                ]);
            }

            $user = User::where('api_token', $request->input('api_token'))->first();

            if ( ! $user) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.auth.failed'),
                ]);
            }

            if ( ! $user->can('developers')) {
                return $this->error('You do not have permission to access API', 403);
            }


            if ( ! isset($user->customer)) {
                return $this->error('Customer not found');
            }


            $activeSubscription = $user->customer->activeSubscription();
            if ($activeSubscription) {
                $plan = Plan::where('status', true)->find($activeSubscription->plan_id);
                if ( ! $plan) {
                    return $this->error('Purchased plan is not active. Please contact support team.');
                }
            }

            if ( ! isset($activeSubscription->plan)) {
                return $this->error('Purchased plan is not active. Please contact support team.');
            }

            if (config('app.trai_dlt') && $activeSubscription->plan->is_dlt) {
                if ($request->missing('dlt_template_id')) {
                    return $this->error('The DLT Template ID is mandatory. Kindly reach out to the system administrator for further assistance');
                }
                if ($user->dlt_entity_id === null) {
                    return $this->error('The DLT Entity ID is mandatory. Kindly reach out to the system administrator for further assistance');
                }
            }

            $sms_type     = $request->get('type', 'plain');
            $sms_counter  = new SMSCounter();
            $message_data = $sms_counter->count($request->input('message'), $sms_type === 'whatsapp' ? 'WHATSAPP' : null);

            if ($message_data->encoding === 'UTF16') {
                $sms_type = 'unicode';
            }

            if ( ! in_array($sms_type, ['plain', 'unicode', 'voice', 'mms', 'whatsapp', 'viber', 'otp'], true)) {
                return $this->error(__('locale.exceptions.invalid_sms_type'));
            }

            if ($sms_type === 'voice' && ($request->missing('gender') || $request->missing('language'))) {
                return $this->error('Language and gender parameters are required');
            }

            if ($sms_type === 'mms') {
                if ($request->missing('media_url')) {
                    return $this->error('media_url parameter is required');
                }
                if ( ! filter_var($request->input('media_url'), FILTER_VALIDATE_URL)) {
                    return $this->error('Valid media url is required.');
                }
            }

            $validateData = $this->campaigns->checkQuickSendValidation([
                'sender_id' => $request->sender_id,
                'message'   => $request->message,
                'user_id'   => $user->id,
                'sms_type'  => $sms_type,
            ]);

            if ($validateData->getData()->status === 'error') {
                return $this->error($validateData->getData()->message);
            }

            try {
                $input = $this->prepareInput($request, $user, $sms_type, $carbon);

                $coverage = CustomerBasedSendingServer::where('user_id', $user->id)->where('status', 1)->count();

                if ($coverage > 0) {
                    // Map sms_type → correct user attribute
                    $serverMap = [
                        'plain'    => 'api_sending_server',
                        'unicode'  => 'api_sending_server', // unicode falls under plain
                        'voice'    => 'api_voice_sending_server',
                        'mms'      => 'api_mms_sending_server',
                        'whatsapp' => 'api_whatsapp_sending_server',
                        'viber'    => 'api_viber_sending_server',
                        'otp'      => 'api_otp_sending_server',
                    ];

                    $serverField = $serverMap[$sms_type] ?? null;

                    if ($serverField && ! empty($user->{$serverField})) {
                        $input['sending_server'] = $user->{$serverField};
                    }
                }

                $isBulkSms = str_contains($input['recipient'], ',');

                if ($request->filled('dlt_template_id')) {
                    $input['dlt_template_id'] = $request->input('dlt_template_id');
                }

                $data = $isBulkSms
                    ? $this->campaigns->sendApi($campaign, $input)
                    : $this->processSingleRecipient($campaign, $input);

                $status = optional($data->getData())->status;

                return $status === 'success'
                    ? $this->success($data->getData()->data ?? null, $data->getData()->message)
                    : $this->error($data->getData()->message ?? __('locale.exceptions.something_went_wrong'), 403);

            } catch (NumberParseException $exception) {
                return $this->error($exception->getMessage(), 403);
            }
        }

        /**
         * @return array
         */
        private function prepareInput($request, $user, $sms_type, $carbon)
        {
            $input = [
                'sender_id' => $request->input('sender_id'),
                'sms_type'  => $sms_type,
                'api_key'   => $user->api_token,
                'user'      => $user,
                'recipient' => $request->input('recipient'),
                'delimiter' => ',',
                'message'   => $request->input('message'),
            ];

            switch ($sms_type) {
                case 'voice':
                    $input['language'] = $request->input('language');
                    $input['gender']   = $request->input('gender');
                    break;
                case 'mms':
                case 'whatsapp':
                case 'viber':
                    if ($request->filled('media_url')) {
                        $input['media_url'] = $request->input('media_url');
                    }
                    break;
            }

            if ($request->filled('schedule_time')) {
                $input['schedule']        = true;
                $input['schedule_date']   = $carbon->parse($request->input('schedule_time'))->toDateString();
                $input['schedule_time']   = $carbon->parse($request->input('schedule_time'))->setSeconds(0)->format('H:i');
                $input['timezone']        = $user->timezone;
                $input['frequency_cycle'] = 'onetime';
            }

            return $input;
        }

        /**
         * @return JsonResponse|mixed
         *
         * @throws NumberParseException
         */
        private function processSingleRecipient($campaign, &$input)
        {
            $phone             = str_replace(['+', '(', ')', '-', ' '], '', $input['recipient']);
            $phoneUtil         = PhoneNumberUtil::getInstance();
            $phoneNumberObject = $phoneUtil->parse('+' . $phone);
            $countryCode       = $phoneNumberObject->getCountryCode();
            $isoCode           = $phoneUtil->getRegionCodeForNumber($phoneNumberObject);

            if ( ! $phoneUtil->isPossibleNumber($phoneNumberObject) || empty($countryCode) || empty($isoCode)) {
                return $this->error(__('locale.customer.invalid_phone_number', ['phone' => $phone]));
            }

            if ($phoneNumberObject->isItalianLeadingZero()) {
                $input['recipient'] = '0' . $phoneNumberObject->getNationalNumber();
            } else {
                $input['recipient'] = $phoneNumberObject->getNationalNumber();
            }

            $input['country_code'] = $countryCode;
            $input['region_code']  = $isoCode;

            return $this->campaigns->quickSend($campaign, $input);
        }

        /**
         * view single sms reports
         */
        public function viewSMS(Reports $uid, Request $request): JsonResponse
        {

            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $user = User::where('api_token', $request->input('api_token'))->first();

            if ( ! $user) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.auth.failed'),
                ]);
            }


            if ( ! $user->can('developers')) {
                return $this->error('You do not have permission to access API', 403);
            }

            $reports = Reports::where('user_id', $user->id)
                ->select('uid', 'to', 'from', 'message', 'sms_type', 'direction', 'customer_status as status', 'sms_count', 'cost')
                ->find($uid->id);

            if ($reports) {
                return $this->success($reports);
            }

            return $this->error('SMS Info not found');
        }

        /**
         * get all messages
         */
        public function viewAllSMS(Request $request): JsonResponse
        {

            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $user = User::where('api_token', $request->input('api_token'))->first();

            if ( ! $user) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.auth.failed'),
                ]);
            }


            if ( ! $user->can('developers')) {
                return $this->error('You do not have permission to access API', 403);
            }


            $timezone = request()->query('timezone', config('app.timezone', 'UTC'));

            // Validate timezone
            if ( ! in_array($timezone, DateTimeZone::listIdentifiers())) {
                return $this->error('Invalid timezone provided.', 422);
            }


            try {
                $start = Carbon::parse(request()->query('start_date', now($timezone)->subDays(30)), $timezone)->timezone($timezone);
                $end   = Carbon::parse(request()->query('end_date', now($timezone)), $timezone)->timezone($timezone);
            } catch (Exception) {
                return $this->error('Invalid datetime format. Use Y-m-d H:i:s', 422);
            }

            if ($start > $end) {
                return $this->error('Start datetime must be before end datetime.', 422);
            }


            $smsType      = request()->query('sms_type');
            $smsDirection = request()->query('direction');

            $query = Reports::where('user_id', $user->id)
                ->whereBetween('created_at', [$start, $end]);

            if ($smsType) {
                $query->where('sms_type', $smsType);
            }

            if ($smsDirection) {
                $query->where('direction', $smsDirection);
            }

            $reports = $query
                ->select([
                    'uid',
                    'to',
                    'from',
                    'message',
                    'sms_type',
                    'direction',
                    'customer_status as status',
                    'sms_count',
                    'cost',
                    'created_at as sent_at',
                ])
                ->orderByDesc('created_at')
                ->paginate(25);

            return $this->success($reports, 'SMS data fetched successfully');
        }


        /*
        |--------------------------------------------------------------------------
        | Version 3.14.0
        |--------------------------------------------------------------------------
        |
        | Send Campaign Using API
        |
        */

        public function campaign(Campaigns $campaign, SendAPICampaign $request)
        {
            if (config('app.stage') === 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $input = $request->all();

            $validator = Validator::make($request->all(), [
                'contact_list_id' => 'required',
                'message'         => 'required',
                'api_token'       => 'required',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status'  => 'error',
                    'message' => $validator->errors()->first(),
                ]);
            }

            $user = User::where('api_token', $request->input('api_token'))->first();

            if ( ! $user) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.auth.failed'),
                ]);
            }

            if ( ! $user->can('developers')) {
                return $this->error('You do not have permission to access API', 403);
            }


            if ( ! isset($user->customer)) {
                return $this->error('Customer not found');
            }


            $activeSubscription = $user->customer->activeSubscription();
            if ($activeSubscription) {
                $plan = Plan::where('status', true)->find($activeSubscription->plan_id);
                if ( ! $plan) {
                    return $this->error('Purchased plan is not active. Please contact support team.');
                }
            }

            $sms_type     = $request->get('type', 'plain');
            $sms_counter  = new SMSCounter();
            $message_data = $sms_counter->count($request->input('message'), $sms_type === 'whatsapp' ? 'WHATSAPP' : null);

            if ($message_data->encoding === 'UTF16') {
                $sms_type = 'unicode';
            }

            if ( ! in_array($sms_type, ['plain', 'unicode', 'voice', 'mms', 'whatsapp', 'viber', 'otp'], true)) {
                return $this->error(__('locale.exceptions.invalid_sms_type'));
            }

            if ($sms_type === 'voice' && ($request->missing('gender') || $request->missing('language'))) {
                return $this->error('Language and gender parameters are required');
            }

            if ($sms_type === 'mms') {
                if ($request->missing('media_url')) {
                    return $this->error('media_url parameter is required');
                }
                if ( ! filter_var($request->input('media_url'), FILTER_VALIDATE_URL)) {
                    return $this->error('Valid media url is required.');
                }
            }

            // Assign sending server based on type
            $coverage = CustomerBasedSendingServer::where('user_id', $user->id)->where('status', 1)->count();
            if ($coverage > 0) {
                $serverMap = [
                    'plain'    => 'api_sending_server',
                    'unicode'  => 'api_sending_server', // maps to same as plain
                    'voice'    => 'api_voice_sending_server',
                    'mms'      => 'api_mms_sending_server',
                    'whatsapp' => 'api_whatsapp_sending_server',
                    'viber'    => 'api_viber_sending_server',
                    'otp'      => 'api_otp_sending_server',
                ];

                $serverField = $serverMap[$sms_type] ?? null;
                if ($serverField && ! empty($user->{$serverField})) {
                    $input['sending_server'] = $user->{$serverField};
                }
            }

            // API metadata
            $input['api_key']  = $user->api_token;
            $input['timezone'] = $user->timezone;
            $input['name']     = 'API_' . time();

            unset($input['sender_id']);

            // Handle originator (sender_id vs phone_number)
            if ($request->filled('sender_id')) {
                if (is_numeric($request->sender_id)) {
                    $input['originator']   = 'phone_number';
                    $input['phone_number'] = [$request->sender_id];
                } else {
                    $input['sender_id']  = [$request->sender_id];
                    $input['originator'] = 'sender_id';
                }
            }

            // Handle scheduling
            if ($request->filled('schedule_time')) {
                $scheduleTime             = Carbon::parse($request->input('schedule_time'));
                $input['schedule']        = true;
                $input['schedule_date']   = $scheduleTime->toDateString();
                $input['schedule_time']   = $scheduleTime->setSeconds(0)->format('H:i');
                $input['frequency_cycle'] = 'onetime';
            }

            $data = $this->campaigns->apiCampaignBuilder($campaign, $input);

            if (isset($data->getData()->status)) {
                return $data->getData()->status === 'success'
                    ? $this->success($data->getData()->data, $data->getData()->message)
                    : $this->error($data->getData()->message);
            }

            return $this->error(__('locale.exceptions.something_went_wrong'));
        }


        /**
         * view campaign
         */
        public function viewCampaign(Campaigns $uid, Request $request): JsonResponse
        {

            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }


            $validator = Validator::make($request->all(), [
                'api_token' => 'required',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status'  => 'error',
                    'message' => $validator->errors()->first(),
                ]);
            }

            $user = User::where('api_token', $request->input('api_token'))->first();

            if ( ! $user) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.auth.failed'),
                ]);
            }

            if ( ! $user->can('developers')) {
                return $this->error('You do not have permission to access API', 403);
            }


            if ( ! isset($user->customer)) {
                return $this->error('Customer not found');
            }


            $activeSubscription = $user->customer->activeSubscription();
            if ($activeSubscription) {
                $plan = Plan::where('status', true)->find($activeSubscription->plan_id);
                if ( ! $plan) {
                    return $this->error('Purchased plan is not active. Please contact support team.');
                }
            }


            if ($user->can('view_reports')) {

                $reportStatusCounts = Reports::where('user_id', $user->id)->where('campaign_id', $uid->id)->selectRaw('
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

                $data = [
                    'id'         => $uid->id,
                    'uid'        => $uid->uid,
                    'name'       => $uid->campaign_name,
                    'message'    => $uid->message,
                    'status'     => $uid->status,
                    'type'       => $uid->sms_type,
                    'created_at' => Tool::customerDateTime($uid->created_at),
                ];

                if ( ! empty($uid->run_at)) {
                    $data['start_at'] = Tool::customerDateTime($uid->run_at);
                }

                if ($uid->status == 'done') {
                    $data['delivery_at'] = Tool::customerDateTime($uid->delivery_at);
                }

                if ($reportStatusCounts) {
                    $data['stats'] = $reportStatusCounts;
                }


                return $this->success($data, 'Campaign successfully retrieved');
            }

            return $this->error(__('locale.http.403.description'), 403);
        }

    }

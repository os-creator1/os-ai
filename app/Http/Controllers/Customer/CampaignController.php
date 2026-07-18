<?php

    namespace App\Http\Controllers\Customer;

    use App\Http\Requests\Campaigns\CampaignBuilderRequest;
    use App\Http\Requests\Campaigns\GenerateAIMessageRequest;
    use App\Http\Requests\Campaigns\ImportRequest;
    use App\Http\Requests\Campaigns\ImportVoiceRequest;
    use App\Http\Requests\Campaigns\MMSCampaignBuilderRequest;
    use App\Http\Requests\Campaigns\MMSImportRequest;
    use App\Http\Requests\Campaigns\MMSQuickSendRequest;
    use App\Http\Requests\Campaigns\OTPCampaignBuilderRequest;
    use App\Http\Requests\Campaigns\OTPQuickSendRequest;
    use App\Http\Requests\Campaigns\QuickSendRequest;
    use App\Http\Requests\Campaigns\ViberCampaignBuilderRequest;
    use App\Http\Requests\Campaigns\ViberQuickSendRequest;
    use App\Http\Requests\Campaigns\VoiceCampaignBuilderRequest;
    use App\Http\Requests\Campaigns\VoiceQuickSendRequest;
    use App\Http\Requests\Campaigns\WhatsAppCampaignBuilderRequest;
    use App\Http\Requests\Campaigns\WhatsAppQuickSendRequest;
    use App\Library\Tool;
    use App\Models\Campaigns;
    use App\Models\ContactGroups;
    use App\Models\Country;
    use App\Models\CsvData;
    use App\Models\CustomerBasedPricingPlan;
    use App\Models\CustomerBasedSendingServer;
    use App\Models\PhoneNumbers;
    use App\Models\Plan;
    use App\Models\PlansCoverageCountries;
    use App\Models\Senderid;
    use App\Models\Templates;
    use App\Models\TemplateTags;
    use App\Models\User;
    use App\Repositories\Contracts\CampaignRepository;
    use Exception;
    use Illuminate\Auth\Access\AuthorizationException;
    use Illuminate\Contracts\Foundation\Application;
    use Illuminate\Contracts\View\Factory;
    use Illuminate\Contracts\View\View;
    use Illuminate\Http\JsonResponse;
    use Illuminate\Http\RedirectResponse;
    use Illuminate\Support\Facades\Auth;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Validator;
    use libphonenumber\NumberParseException;
    use libphonenumber\PhoneNumberUtil;
    use Maatwebsite\Excel\Facades\Excel;
    use Maatwebsite\Excel\Excel as ExcelFormat;
    use OpenAI;
    use stdClass;

    class CampaignController extends CustomerBaseController
    {
        protected CampaignRepository $campaigns;

        /**
         * CampaignController constructor.
         *
         * @param CampaignRepository $campaigns
         */
        public function __construct(CampaignRepository $campaigns)
        {
            $this->campaigns = $campaigns;
        }

        /**
         * quick send message
         *
         *
         * @param Request $request
         *
         * @return Application|Factory|View|RedirectResponse
         * @throws AuthorizationException
         */
        public function quickSend(Request $request): View|Factory|RedirectResponse|Application
        {
            $this->authorize('sms_quick_send');

            $recipient   = $request->input('recipient');
            $countryCode = null;

            if ($recipient) {
                $phone = str_replace(['(', ')', '+', '-', ' '], '', $recipient);

                try {
                    $phoneUtil         = PhoneNumberUtil::getInstance();
                    $phoneNumberObject = $phoneUtil->parse('+' . $phone);

                    if ( ! $phoneUtil->isPossibleNumber($phoneNumberObject)) {
                        return redirect()->route('customer.subscriptions.index')->with([
                            'status'  => 'error',
                            'message' => __('locale.customer.invalid_phone_number'),
                        ]);
                    }

                    $countryCode = $phoneNumberObject->getCountryCode();
                    $recipient   = $phoneNumberObject->isItalianLeadingZero()
                        ? '0' . $phoneNumberObject->getNationalNumber()
                        : $phoneNumberObject->getNationalNumber();

                } catch (NumberParseException $e) {
                    return redirect()->route('customer.subscriptions.index')->with([
                        'status'  => 'error',
                        'message' => $e->getMessage(),
                    ]);
                }
            }

            $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('dashboard'), 'name' => __('locale.menu.SMS')],
                ['name' => __('locale.menu.Quick Send')],
            ];

            $sender_ids = Senderid::where('user_id', auth()->user()->id)->where('status', 'active')->get();

            $phone_numbers = PhoneNumbers::where('user_id', auth()->id())
                ->where('status', 'assigned')
                ->get()
                ->filter(function ($number) {
                    $caps = json_decode($number->capabilities, true);

                    return is_array($caps) && in_array('sms', $caps);
                });

            $activeSubscription = Auth::user()->customer->activeSubscription();
            if ( ! $activeSubscription) {
                return redirect()->route('customer.subscriptions.index')->with([
                    'status'  => 'error',
                    'message' => __('locale.customer.no_active_subscription'),
                ]);
            }

            $plan_id = $activeSubscription->plan_id;

            $coverage = CustomerBasedPricingPlan::where('plan_id', $plan_id)
                ->where('status', true)
                ->where('user_id', Auth::user()->id)
                ->get();

            if ($coverage->count() < 1) {
                $coverage = PlansCoverageCountries::where('plan_id', $plan_id)
                    ->where('status', true)
                    ->get();
            }

            $templates = Templates::where('user_id', auth()->user()->id)->where('status', 1)->get();

            $sendingServers = CustomerBasedSendingServer::where('user_id', auth()->user()->id)->where('status', 1)->get();

            return view('customer.Campaigns.quickSend', compact(
                'breadcrumbs',
                'sender_ids',
                'phone_numbers',
                'recipient',
                'coverage',
                'templates',
                'countryCode',
                'sendingServers'
            ));
        }

        /**
         * quick send message
         *
         * @param Campaigns        $campaign
         * @param QuickSendRequest $request
         *
         * @return RedirectResponse
         */
        public function postQuickSend(Campaigns $campaign, QuickSendRequest $request): RedirectResponse
        {
            if (config('app.stage') === 'demo') {
                return $this->quickSendError('sms', 'Sorry! This option is not available in demo mode');
            }

            $user               = Auth::user();
            $customer           = $user->customer;
            $activeSubscription = $customer->activeSubscription();

            if ( ! $activeSubscription) {
                return $this->quickSendError('sms', __('locale.customer.no_active_subscription'));
            }

            $plan = Plan::where('status', true)->find($activeSubscription->plan_id);
            if ( ! $plan) {
                return $this->quickSendError('sms', __('locale.customer.no_active_subscription'));
            }

            // TRAI-DLT compliance checks
            if (config('app.trai_dlt') && $plan->is_dlt) {
                if (empty($request->input('dlt_template_id'))) {
                    return $this->quickSendError('sms', 'DLT Template ID is required.');
                }

                if (empty($user->dlt_entity_id)) {
                    return $this->quickSendError('sms', 'The DLT Entity ID is mandatory. Kindly reach out to the system administrator for further assistance.');
                }
            }

            $recipients = $this->getRecipients($request);

            if ($recipients->isEmpty()) {
                return $this->quickSendError('sms', __('locale.campaigns.at_least_one_number'));
            }

            if ($recipients->count() > 100) {
                return $this->quickSendError('sms', __('locale.campaigns.too_many_numbers'));
            }

            // Check if customer needs to select a sending server
            $sendingServersExist = CustomerBasedSendingServer::where('user_id', $user->id)->where('status', 1)->exists();
            if ($sendingServersExist && ! $request->has('sending_server')) {
                return $this->quickSendError('sms', 'Please select your sending server.');
            }

            $sendData            = $request->except('_token', 'recipients', 'delimiter');
            $sendData['message'] = str_replace("\r", '', $sendData['message']);

            $validateData = $this->campaigns->checkQuickSendValidation($sendData);
            $responseData = $validateData->getData();

            if ($responseData->status === 'error') {
                return $this->quickSendError('sms', $responseData->message);
            }

            // Pre-set values for all iterations
            $sendData['sender_id'] = $responseData->sender_id;
            $sendData['sms_type']  = $responseData->sms_type;
            $sendData['user']      = User::find($responseData->user_id);

            $errors = [];

            foreach ($recipients as $recipient) {
                $cleanRecipient = str_replace(['(', ')', '+', '-', ' '], '', $recipient);
                $phone          = $this->getPhoneNumber($cleanRecipient, $request->input('country_code'));

                if ( ! is_array($phone)) {
                    $errors[] = $phone;
                    continue;
                }

                $coverage = $plan->plansCoverageCountries->firstWhere('country_id', $phone['country_id']);

                if ( ! $coverage) {
                    $errors[] = __('locale.campaigns.country_not_covered', ['country' => $phone['country_code']]);
                    continue;
                }

                $options = json_decode($coverage['options'], true);
                if (empty($options['plain'])) {
                    $errors[] = __('locale.campaigns.sms_not_covered', ['country' => $phone['country_code'], 'sms_type' => 'Plain']);
                    continue;
                }

                $sendData['country_code'] = $phone['country_code'];
                $sendData['recipient']    = $phone['recipient'];
                $sendData['region_code']  = $phone['region_code'];



\Log::info('QUICKSEND SENDDATA', $sendData);
                $data = $this->campaigns->quickSend($campaign, $sendData);

                if ($data->getData()->status !== 'success') {
                    $errors[] = $data->getData()->message;
                }
            }

            if ( ! empty($errors)) {
                return redirect()->route('customer.sms.quick_send')->with([
                    'status'  => 'warning',
                    'message' => implode('<br>', $errors),
                ]);
            }

            return redirect()->route('customer.reports.all')->with([
                'status'  => 'success',
                'message' => __('locale.campaigns.message_successfully_delivered'),
            ]);
        }


        private function getRecipients($request)
        {
            $delimiter  = $request->input('delimiter');
            $recipients = $request->input('recipients');

            $recipientsArray = match ($delimiter) {
                ',', ';', '|' => collect(explode($delimiter, $recipients)),
                'tab' => collect(explode(' ', $recipients)),
                'new_line' => collect(explode("\n", $recipients)),
                default => collect([$recipients]),
            };

            return $recipientsArray->map(function ($item) {
                return trim($item);
            })->filter(function ($item) {
                return ! empty($item);
            })->unique();

        }

        private function getPhoneNumber($recipient, $countryCodeInput)
        {
            try {
                $countryCode = null;
                if ($countryCodeInput != 0) {
                    $country     = Country::find($countryCodeInput);
                    $countryCode = $country?->country_code;
                }

                $phoneUtil         = PhoneNumberUtil::getInstance();
                $phoneNumberObject = $phoneUtil->parse('+' . $countryCode . $recipient);
                $regionCode        = $phoneUtil->getRegionCodeForNumber($phoneNumberObject);
                $countryCode       = $phoneNumberObject->getCountryCode();


                $nationalNumber = $phoneNumberObject->isItalianLeadingZero()
                    ? '0' . $phoneNumberObject->getNationalNumber()
                    : $phoneNumberObject->getNationalNumber();

                if ( ! $phoneUtil->isPossibleNumber($phoneNumberObject) || empty($countryCode) || empty($regionCode)) {
                    return __('locale.customer.invalid_phone_number', ['phone' => $countryCode . $nationalNumber]);
                }

                $country   = Country::where('country_code', $countryCode)->where('iso_code', $regionCode)->first();
                $countryId = $country?->id;

                return [
                    'country_code' => $countryCode,
                    'region_code'  => $regionCode,
                    'recipient'    => $nationalNumber,
                    'country_id'   => $countryId,
                ];
            } catch (NumberParseException $exception) {
                return $exception->getMessage();
            }
        }


        /**
         * campaign builder
         *
         * @return Application|Factory|View|RedirectResponse
         * @throws AuthorizationException
         */
        public function campaignBuilder(): View|Factory|RedirectResponse|Application
        {
            $this->authorize('sms_campaign_builder');

            $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('dashboard'), 'name' => __('locale.menu.SMS')],
                ['name' => __('locale.menu.Campaign Builder')],
            ];


            $activeSubscription = Auth::user()->customer->activeSubscription();

            if ( ! $activeSubscription) {
                return redirect()->route('customer.subscriptions.index')->with([
                    'status'  => 'error',
                    'message' => __('locale.customer.no_active_subscription'),
                ]);
            }


            $compactData                = $this->getCampaignBuilderData();
            $compactData['breadcrumbs'] = $breadcrumbs;


            return view('customer.Campaigns.campaignBuilder', $compactData);
        }

        /**
         * template info isn't found
         *
         * @param Templates $template
         * @param           $id
         *
         * @return JsonResponse
         */
        public function templateData(Templates $template, $id): JsonResponse
        {
            $data = $template->where('user_id', auth()->user()->id)->find($id);
            if ($data) {
                return response()->json([
                    'status'          => 'success',
                    'dlt_template_id' => $data->dlt_template_id,
                    'message'         => $data->message,
                ]);
            }

            return response()->json([
                'status'  => 'error',
                'message' => __('locale.templates.template_info_not_found'),
            ]);
        }


        /**
         * store campaign
         *
         *
         * @param Campaigns              $campaign
         * @param CampaignBuilderRequest $request
         *
         * @return RedirectResponse
         */
        public function storeCampaign(Campaigns $campaign, CampaignBuilderRequest $request): RedirectResponse
        {
            if (config('app.stage') == 'demo') {
                return redirect()->route('customer.sms.campaign_builder')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $customer           = Auth::user()->customer;
            $activeSubscription = $customer->activeSubscription();

            if ( ! $activeSubscription) {
                return redirect()->route('customer.subscriptions.index')->with([
                    'status'  => 'error',
                    'message' => __('locale.customer.no_active_subscription'),
                ]);
            }

            $plan = Plan::where('status', true)->find($activeSubscription->plan_id);

            if ( ! $plan) {
                return redirect()->route('customer.sms.campaign_builder')->with([
                    'status'  => 'error',
                    'message' => 'Purchased plan is not active. Please contact support team.',
                ]);
            }

            if (config('app.trai_dlt') && $plan->is_dlt && $request->input('dlt_template_id') == null) {
                return redirect()->route('customer.sms.campaign_builder')->with([
                    'status'  => 'error',
                    'message' => 'DLT Template id is required',
                ]);
            }

            if (config('app.trai_dlt') && $activeSubscription->plan->is_dlt && Auth::user()->dlt_entity_id == null) {
                return redirect()->route('customer.sms.campaign_builder')->with([
                    'status'  => 'error',
                    'message' => 'The DLT Entity ID is mandatory. Kindly reach out to the system administrator for further assistance',
                ]);
            }

            $data = $this->campaigns->campaignBuilder($campaign, $request->except('_token'));

            if (isset($data->getData()->status)) {

                if ($data->getData()->status == 'success') {
                    return redirect()->route('customer.reports.campaigns')->with([
                        'status'  => 'success',
                        'message' => $data->getData()->message,
                    ]);
                }

                return redirect()->route('customer.sms.campaign_builder')->with([
                    'status'  => 'error',
                    'message' => $data->getData()->message,
                ]);
            }

            return redirect()->route('customer.sms.campaign_builder')->with([
                'status'  => 'error',
                'message' => __('locale.exceptions.something_went_wrong'),
            ]);
        }

        /**
         * send a message using file
         *
         * @return Application|Factory|View|RedirectResponse
         * @throws AuthorizationException
         */
        public function import(): View|Factory|RedirectResponse|Application
        {
            $this->authorize('sms_bulk_messages');

            $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('dashboard'), 'name' => __('locale.menu.SMS')],
                ['name' => __('locale.menu.Send Using File')],
            ];


            $activeSubscription = Auth::user()->customer->activeSubscription();

            if ( ! $activeSubscription) {
                return redirect()->route('customer.subscriptions.index')->with([
                    'status'  => 'error',
                    'message' => __('locale.customer.no_active_subscription'),
                ]);
            }

            $compactData                = $this->getCampaignBuilderData();
            $compactData['breadcrumbs'] = $breadcrumbs;

            return view('customer.Campaigns.import', $compactData);
        }


        /**
         * send a message using file
         *
         * @param ImportRequest $request
         *
         * @return Application|Factory|View|RedirectResponse
         */
        public function importCampaign(ImportRequest $request): View|Factory|RedirectResponse|Application
        {
            if (config('app.stage') === 'demo') {
                return redirect()->route('customer.sms.import')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            if ( ! $request->file('import_file')->isValid()) {
                return redirect()->route('customer.sms.import')->with([
                    'status'  => 'error',
                    'message' => __('locale.settings.invalid_file'),
                ]);
            }

            $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('dashboard'), 'name' => __('locale.menu.SMS')],
                ['name' => __('locale.menu.Send Using File')],
            ];

            $form_data = $request->except('_token', 'import_file');
            $file      = $request->file('import_file');
            $ref_id    = uniqid();


            /**
             * ------------------------------------
             * READ CSV + FIX BOM + KEEP UTF-8
             * ------------------------------------
             */
            $content = file_get_contents($file->getRealPath());

            // Remove UTF-8 BOM if present
            $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

            // Save cleaned file temporarily
            $tempFile = tempnam(sys_get_temp_dir(), 'csv');
            file_put_contents($tempFile, $content);

            // Load CSV rows as UTF-8 using Laravel Excel
            $data = Excel::toArray(new stdClass(), $tempFile, null, ExcelFormat::CSV)[0];

            if ( ! is_array($data) || count($data) === 0) {
                return redirect()->route('customer.sms.import')->with([
                    'status'  => 'error',
                    'message' => __('locale.settings.invalid_file'),
                ]);
            }


            /**
             * ------------------------------------
             * SLICE FOR PREVIEW + SAVE ORIGINAL
             * ------------------------------------
             */
            $csv_data = array_slice($data, 0, 2);

            $path        = 'app/bulk_sms/';
            $upload_path = storage_path($path);

            if ( ! file_exists($upload_path)) {
                mkdir($upload_path, 0777, true);
            }

            $filename = 'sms-' . $ref_id . '.' . $file->getClientOriginalExtension();

            // Save original uploaded CSV
            $file->move($upload_path, $filename);

            $csv_data_file = CsvData::create([
                'user_id'      => Auth::user()->id,
                'ref_id'       => $ref_id,
                'ref_type'     => CsvData::TYPE_CAMPAIGN,
                'csv_filename' => $filename,
                'csv_header'   => $request->has('header'),
                'csv_data'     => $path . $filename,
            ]);


            return view('customer.Campaigns.import_fields', compact(
                'csv_data',
                'csv_data_file',
                'breadcrumbs',
                'form_data'
            ));
        }

        /**
         * import processed file
         *
         * @param Campaigns $campaign
         * @param Request   $request
         *
         * @return RedirectResponse
         */
        public function importProcess(Campaigns $campaign, Request $request): RedirectResponse
        {

            if (config('app.stage') == 'demo') {
                return redirect()->route('customer.sms.import')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $customer           = Auth::user()->customer;
            $activeSubscription = $customer->activeSubscription();

            if ( ! $activeSubscription) {
                return redirect()->route('customer.subscriptions.index')->with([
                    'status'  => 'error',
                    'message' => __('locale.customer.no_active_subscription'),
                ]);
            }

            $plan = Plan::where('status', true)->find($activeSubscription->plan_id);

            if ( ! $plan) {
                return redirect()->route('customer.sms.import')->with([
                    'status'  => 'error',
                    'message' => 'Purchased plan is not active. Please contact support team.',
                ]);
            }

            $form_data = json_decode($request->input('form_data'), true);

            $data = $this->campaigns->sendUsingFile($campaign, $request->except('_token'));

            $sms_type = $form_data['sms_type'] == 'plain' ? 'sms' : $form_data['sms_type'];
            $status   = isset($data->getData()->status) ? $data->getData()->status : 'error';
            $message  = isset($data->getData()->message) ? $data->getData()->message : __('locale.exceptions.something_went_wrong');

            if ($status == 'error') {
                return redirect()->route('customer.' . $sms_type . '.import')->with([
                    'status'  => $status,
                    'message' => $message,
                ]);
            }

            return redirect()->route('customer.reports.campaigns')->with([
                'status'  => $status,
                'message' => $message,
            ]);

        }


        /*
        |--------------------------------------------------------------------------
        | voice module
        |--------------------------------------------------------------------------
        |
        |
        |
        */

        /**
         *
         * @return Application|Factory|View|RedirectResponse
         * @throws AuthorizationException
         */
        public function voiceQuickSend(): View|Factory|RedirectResponse|Application
        {
            $this->authorize('voice_quick_send');

            $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('dashboard'), 'name' => __('locale.menu.Voice')],
                ['name' => __('locale.menu.Quick Send')],
            ];

            $sender_ids = Senderid::where('user_id', auth()->user()->id)->where('status', 'active')->get();

            $phone_numbers = PhoneNumbers::where('user_id', auth()->id())
                ->where('status', 'assigned')
                ->get()
                ->filter(function ($number) {
                    $caps = json_decode($number->capabilities, true);

                    return is_array($caps) && in_array('voice', $caps);
                });

            $activeSubscription = Auth::user()->customer->activeSubscription();
            if ( ! $activeSubscription) {
                return redirect()->route('customer.subscriptions.index')->with([
                    'status'  => 'error',
                    'message' => __('locale.customer.no_active_subscription'),
                ]);
            }

            $plan_id = $activeSubscription->plan_id;

            $coverage = CustomerBasedPricingPlan::where('status', true)
                ->where('user_id', Auth::user()->id)
                ->get();

            if ($coverage->count() < 1) {
                $coverage = PlansCoverageCountries::where('plan_id', $plan_id)
                    ->where('status', true)
                    ->get();
            }

            $templates      = Templates::where('user_id', auth()->user()->id)->where('status', 1)->get();
            $sendingServers = CustomerBasedSendingServer::where('user_id', auth()->user()->id)->where('status', 1)->get();

            return view('customer.Campaigns.voiceQuickSend', compact('breadcrumbs', 'sender_ids', 'phone_numbers', 'coverage', 'templates', 'sendingServers'));
        }

        /**
         * quick send message
         *
         * @param Campaigns             $campaign
         * @param VoiceQuickSendRequest $request
         *
         * @return RedirectResponse
         */
        public function postVoiceQuickSend(Campaigns $campaign, VoiceQuickSendRequest $request): RedirectResponse
        {
            if (config('app.stage') == 'demo') {
                return $this->quickSendError('voice', 'Sorry! This option is not available in demo mode');
            }

            if ($request->has('show_manual_input')) {
                $rules = [
                    'voice_file' => 'required|mimes:mp3',
                ];
            } else {
                $rules = [
                    'message'  => 'required',
                    'language' => 'required',
                    'gender'   => 'required',
                ];
            }

            $data = $request->except('_token');
            $v    = Validator::make($data, $rules);

            if ($v->fails()) {
                return redirect()->route('customer.voice.quick_send')->withErrors($v->errors())->withInput();
            }

            $activeSubscription = Auth::user()->customer->activeSubscription();

            if ( ! $activeSubscription) {
                return $this->quickSendError('voice', __('locale.customer.no_active_subscription'));
            }

            $plan = Plan::where('status', true)->find($activeSubscription->plan_id);
            if ( ! $plan) {
                return $this->quickSendError('voice', __('locale.customer.no_active_subscription'));
            }

            $recipients = $this->getRecipients($request);

            if ($recipients->count() < 1) {
                return $this->quickSendError('voice', __('locale.campaigns.at_least_one_number'));
            }

            if ($recipients->count() > 100) {
                return $this->quickSendError('voice', __('locale.campaigns.too_many_numbers'));
            }

            $sendData = $request->except('_token', 'recipients', 'delimiter');

            $errors  = [];
            $success = [];

            $sendingServers = CustomerBasedSendingServer::where('user_id', auth()->user()->id)->where('status', 1)->count();

            if ($sendingServers && ! isset($request->sending_server)) {
                return $this->quickSendError('voice', 'Please select a sending server.');
            }

            $validateData = $this->campaigns->checkQuickSendValidation($sendData);

            if ($validateData->getData()->status == 'error') {
                return $this->quickSendError('voice', $validateData->getData()->message);
            }

            if ($request->has('show_manual_input')) {
                $sendData['media_url'] = Tool::uploadImage($request->file('voice_file'));
            }

            $sendData['sender_id'] = $validateData->getData()->sender_id;
            $sendData['sms_type']  = 'voice';
            $sendData['user']      = User::find($validateData->getData()->user_id);

            foreach ($recipients as $recipient) {

                $phone    = $this->getPhoneNumber($recipient, $request->input('country_code'));
                $coverage = $plan->plansCoverageCountries->firstWhere('country_id', $phone['country_id']);

                if ( ! $coverage) {
                    $errors[] = __('locale.campaigns.country_not_covered', ['country' => $phone['country_code']]);
                    continue;
                }

                $options = json_decode($coverage['options'], true);
                if (empty($options['voice'])) {
                    $errors[] = __('locale.campaigns.sms_not_covered', ['country' => $phone['country_code'], 'sms_type' => 'Voice']);
                    continue;
                }

                $sendData['country_code'] = $phone['country_code'];
                $sendData['recipient']    = $phone['recipient'];
                $sendData['region_code']  = $phone['region_code'];

                $data = $this->campaigns->quickSend($campaign, $sendData);


                if ($data->getData()->status === 'error') {
                    $errors[] = $data->getData()->message;
                } else if ($data->getData()->status === 'success' || $data->getData()->status === 'info') {
                    $success[] = $data->getData()->message;
                }
            }

            if ( ! empty($errors)) {
                $errorMessage = implode(' ', $errors);

                return redirect()->route('customer.voice.quick_send')->with([
                    'status'  => 'error',
                    'message' => $errorMessage,
                ]);
            }

            $successMessage = implode(' ', $success);

            return redirect()->route('customer.reports.all')->with([
                'status'  => 'info',
                'message' => $successMessage,
            ]);

        }


        /**
         * @return Application|Factory|View|RedirectResponse
         * @throws AuthorizationException
         */
        public function voiceCampaignBuilder(): View|Factory|RedirectResponse|Application
        {

            $this->authorize('voice_campaign_builder');

            $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('dashboard'), 'name' => __('locale.menu.Voice')],
                ['name' => __('locale.menu.Campaign Builder')],
            ];


            $activeSubscription = Auth::user()->customer->activeSubscription();

            if ( ! $activeSubscription) {
                return redirect()->route('customer.subscriptions.index')->with([
                    'status'  => 'error',
                    'message' => __('locale.customer.no_active_subscription'),
                ]);
            }


            $compactData                = $this->getCampaignBuilderData('voice');
            $compactData['breadcrumbs'] = $breadcrumbs;

            return view('customer.Campaigns.voiceCampaignBuilder', $compactData);
        }

        /**
         * store campaign
         *
         *
         * @param Campaigns                   $campaign
         * @param VoiceCampaignBuilderRequest $request
         *
         * @return RedirectResponse
         */
        public function storeVoiceCampaign(Campaigns $campaign, VoiceCampaignBuilderRequest $request): RedirectResponse
        {
            if (config('app.stage') == 'demo') {
                return redirect()->route('customer.voice.campaign_builder')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }


            if ($request->has('show_manual_input')) {
                $rules = [
                    'voice_file' => 'required|mimes:mp3',
                ];
            } else {
                $rules = [
                    'message'  => 'required',
                    'language' => 'required',
                    'gender'   => 'required',
                ];
            }

            $data = $request->except('_token');
            $v    = Validator::make($data, $rules);

            if ($v->fails()) {
                return redirect()->route('customer.voice.campaign_builder')->withErrors($v->errors())->withInput();
            }


            $customer           = Auth::user()->customer;
            $activeSubscription = $customer->activeSubscription();

            if ( ! $activeSubscription) {
                return redirect()->route('customer.subscriptions.index')->with([
                    'status'  => 'error',
                    'message' => __('locale.customer.no_active_subscription'),
                ]);
            }

            $plan = Plan::where('status', true)->find($activeSubscription->plan_id);

            if ( ! $plan) {
                return redirect()->route('customer.voice.campaign_builder')->with([
                    'status'  => 'error',
                    'message' => 'Purchased plan is not active. Please contact support team.',
                ]);
            }

            $sendData = $request->except('_token');

            if ($request->has('show_manual_input') && $request->hasFile('voice_file')) {
                $sendData['media_url'] = Tool::uploadImage($request->file('voice_file'));
            }


            $data = $this->campaigns->campaignBuilder($campaign, $sendData);

            if (isset($data->getData()->status)) {

                if ($data->getData()->status == 'success') {
                    return redirect()->route('customer.reports.campaigns')->with([
                        'status'  => 'success',
                        'message' => $data->getData()->message,
                    ]);
                }

                return redirect()->route('customer.voice.campaign_builder')->with([
                    'status'  => 'error',
                    'message' => $data->getData()->message,
                ]);
            }

            return redirect()->route('customer.voice.campaign_builder')->with([
                'status'  => 'error',
                'message' => __('locale.exceptions.something_went_wrong'),
            ]);

        }


        /**
         * @return Application|Factory|View|RedirectResponse
         * @throws AuthorizationException
         */
        public function voiceImport(): View|Factory|RedirectResponse|Application
        {
            $this->authorize('voice_bulk_messages');

            $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('dashboard'), 'name' => __('locale.menu.Voice')],
                ['name' => __('locale.menu.Send Using File')],
            ];


            $activeSubscription = Auth::user()->customer->activeSubscription();

            if ( ! $activeSubscription) {
                return redirect()->route('customer.subscriptions.index')->with([
                    'status'  => 'error',
                    'message' => __('locale.customer.no_active_subscription'),
                ]);
            }


            $compactData                = $this->getCampaignBuilderData('voice');
            $compactData['breadcrumbs'] = $breadcrumbs;

            return view('customer.Campaigns.voiceImport', $compactData);
        }


        /**
         * send a message using file
         *
         * @param ImportVoiceRequest $request
         *
         * @return Application|Factory|View|RedirectResponse
         */
        public function importVoiceCampaign(ImportVoiceRequest $request): View|Factory|RedirectResponse|Application
        {
            if (config('app.stage') == 'demo') {
                return redirect()->route('customer.voice.import')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            if ($request->file('import_file')->isValid()) {

                $breadcrumbs = [
                    ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                    ['link' => url('dashboard'), 'name' => __('locale.menu.Voice')],
                    ['name' => __('locale.menu.Send Using File')],
                ];

                $form_data = $request->except('_token', 'import_file');
                $file      = $request->file('import_file');
                $ref_id    = uniqid();


                $content = file_get_contents($request->file('import_file')->getRealPath());
                $content = mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');

                $tempFile = tempnam(sys_get_temp_dir(), 'csv');
                file_put_contents($tempFile, $content);

                $data = Excel::toArray(new stdClass(), $tempFile, null, ExcelFormat::CSV)[0];


                if ( ! is_array($data) && count($data) > 0) {
                    return redirect()->route('customer.voice.import')->with([
                        'status'  => 'error',
                        'message' => __('locale.settings.invalid_file'),
                    ]);
                }

                $csv_data    = array_slice($data, 0, 2);
                $path        = 'app/bulk_sms/';
                $upload_path = storage_path($path);

                if ( ! file_exists($upload_path)) {
                    mkdir($upload_path, 0777, true);
                }

                $filename = 'voice-' . $ref_id . '.' . $file->getClientOriginalExtension();

                // save to server
                $file->move($upload_path, $filename);

                $csv_data_file = CsvData::create([
                    'user_id'      => Auth::user()->id,
                    'ref_id'       => $ref_id,
                    'ref_type'     => CsvData::TYPE_CAMPAIGN,
                    'csv_filename' => $filename,
                    'csv_header'   => $request->has('header'),
                    'csv_data'     => $path . $filename,
                ]);


                return view('customer.Campaigns.import_fields', compact('csv_data', 'csv_data_file', 'breadcrumbs', 'form_data'));
            }

            return redirect()->route('customer.voice.import')->with([
                'status'  => 'error',
                'message' => __('locale.settings.invalid_file'),
            ]);
        }


        /*
        |--------------------------------------------------------------------------
        | MMS module
        |--------------------------------------------------------------------------
        |
        |
        |
        */


        /**
         *
         * @return Application|Factory|View|RedirectResponse
         * @throws AuthorizationException
         */
        public function mmsQuickSend(): View|Factory|RedirectResponse|Application
        {
            $this->authorize('mms_quick_send');

            $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('dashboard'), 'name' => __('locale.menu.MMS')],
                ['name' => __('locale.menu.Quick Send')],
            ];

            $sender_ids = Senderid::where('user_id', auth()->user()->id)->where('status', 'active')->get();

            $phone_numbers = PhoneNumbers::where('user_id', auth()->id())
                ->where('status', 'assigned')
                ->get()
                ->filter(function ($number) {
                    $caps = json_decode($number->capabilities, true);

                    return is_array($caps) && in_array('mms', $caps);
                });

            $activeSubscription = Auth::user()->customer->activeSubscription();
            if ( ! $activeSubscription) {
                return redirect()->route('customer.subscriptions.index')->with([
                    'status'  => 'error',
                    'message' => __('locale.customer.no_active_subscription'),
                ]);
            }

            $plan_id = $activeSubscription->plan_id;

            $coverage = CustomerBasedPricingPlan::where('status', true)
                ->where('user_id', Auth::user()->id)
                ->get();

            if ($coverage->count() < 1) {
                $coverage = PlansCoverageCountries::where('plan_id', $plan_id)
                    ->where('status', true)
                    ->get();
            }

            $templates      = Templates::where('user_id', auth()->user()->id)->where('status', 1)->get();
            $sendingServers = CustomerBasedSendingServer::where('user_id', auth()->user()->id)->where('status', 1)->get();

            return view('customer.Campaigns.mmsQuickSend', compact('breadcrumbs', 'sender_ids', 'phone_numbers', 'coverage', 'templates', 'sendingServers'));
        }

        /**
         * quick send message
         *
         * @param Campaigns           $campaign
         * @param MMSQuickSendRequest $request
         *
         * @return RedirectResponse
         */

        public function postMMSQuickSend(Campaigns $campaign, MMSQuickSendRequest $request): RedirectResponse
        {
            if (config('app.stage') === 'demo') {
                return $this->quickSendError('mms', 'Sorry, this feature is disabled in demo version.');
            }

            $user               = Auth::user();
            $customer           = $user->customer;
            $activeSubscription = $customer->activeSubscription();

            if ( ! $activeSubscription) {
                return $this->quickSendError('mms', __('locale.customer.no_active_subscription'));
            }

            $plan = Plan::where('status', true)->find($activeSubscription->plan_id);
            if ( ! $plan) {
                return $this->quickSendError('mms', __('locale.customer.no_active_subscription'));
            }

            $recipients = $this->getRecipients($request);
            if ($recipients->isEmpty()) {
                return $this->quickSendError('mms', 'No recipients found.');
            }

            if ($recipients->count() > 100) {
                return $this->quickSendError('mms', __('locale.campaigns.too_many_numbers'));
            }

            if ( ! $request->hasFile('mms_file')) {
                return $this->quickSendError('mms', 'MMS media file is required.');
            }

            $sendData              = $request->except('_token', 'recipients', 'delimiter');
            $sendData['media_url'] = Tool::uploadImage($request->file('mms_file'));

            // Validate sending server
            $sendingServersExist = CustomerBasedSendingServer::where('user_id', $user->id)->where('status', 1)->exists();

            if ($sendingServersExist && ! $request->has('sending_server')) {
                return $this->quickSendError('mms', 'No sending server selected.');
            }

            $validateData = $this->campaigns->checkQuickSendValidation($sendData);
            $validated    = $validateData->getData();

            if ($validated->status === 'error') {
                return $this->quickSendError('mms', $validated->message);
            }

            $sendData['sender_id'] = $validated->sender_id;
            $sendData['sms_type']  = 'mms';
            $sendData['user']      = User::find($validated->user_id);

            $errors  = [];
            $success = [];

            foreach ($recipients as $recipient) {
                $phone = $this->getPhoneNumber($recipient, $request->input('country_code'));

                if ( ! is_array($phone)) {
                    $errors[] = $phone;
                    continue;
                }

                $coverage = $plan->plansCoverageCountries->firstWhere('country_id', $phone['country_id']);
                if ( ! $coverage) {
                    $errors[] = __('locale.campaigns.country_not_covered', ['country' => $phone['country_code']]);
                    continue;
                }

                $options = json_decode($coverage['options'], true);
                if (empty($options['mms'])) {
                    $errors[] = __('locale.campaigns.sms_not_covered', ['country' => $phone['country_code'], 'sms_type' => 'MMS']);
                    continue;
                }

                $sendData['country_code'] = $phone['country_code'];
                $sendData['recipient']    = $phone['recipient'];
                $sendData['region_code']  = $phone['region_code'];

                $response = $this->campaigns->quickSend($campaign, $sendData);
                $result   = $response->getData();

                if ($result->status === 'error') {
                    $errors[] = $result->message;
                } else if (in_array($result->status, ['success', 'info'])) {
                    $success[] = $result->message;
                }
            }

            if ( ! empty($errors)) {
                return $this->quickSendError('mms', implode(' ', $errors));
            }

            return redirect()->route('customer.reports.all')->with([
                'status'  => 'info',
                'message' => implode(' ', $success),
            ]);
        }

        /**
         * @return Application|Factory|View|RedirectResponse
         * @throws AuthorizationException
         */
        public function mmsCampaignBuilder(): View|Factory|RedirectResponse|Application
        {

            $this->authorize('mms_campaign_builder');

            $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('dashboard'), 'name' => __('locale.menu.MMS')],
                ['name' => __('locale.menu.Campaign Builder')],
            ];


            $activeSubscription = Auth::user()->customer->activeSubscription();

            if ( ! $activeSubscription) {
                return redirect()->route('customer.subscriptions.index')->with([
                    'status'  => 'error',
                    'message' => __('locale.customer.no_active_subscription'),
                ]);
            }


            $compactData                = $this->getCampaignBuilderData('mms');
            $compactData['breadcrumbs'] = $breadcrumbs;

            return view('customer.Campaigns.mmsCampaignBuilder', $compactData);
        }


        /**
         * store campaign
         *
         *
         * @param Campaigns                 $campaign
         * @param MMSCampaignBuilderRequest $request
         *
         * @return RedirectResponse
         */
        public function storeMMSCampaign(Campaigns $campaign, MMSCampaignBuilderRequest $request): RedirectResponse
        {
            if (config('app.stage') == 'demo') {
                return redirect()->route('customer.mms.quick_send')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $customer           = Auth::user()->customer;
            $activeSubscription = $customer->activeSubscription();

            if ( ! $activeSubscription) {
                return redirect()->route('customer.subscriptions.index')->with([
                    'status'  => 'error',
                    'message' => __('locale.customer.no_active_subscription'),
                ]);
            }

            $plan = Plan::where('status', true)->find($activeSubscription->plan_id);

            if ( ! $plan) {
                return redirect()->route('customer.mms.campaign_builder')->with([
                    'status'  => 'error',
                    'message' => 'Purchased plan is not active. Please contact support team.',
                ]);
            }

            $campaignData             = $request->all();
            $campaignData['sms_type'] = 'mms';

            $data = $this->campaigns->campaignBuilder($campaign, $campaignData);

            if (isset($data->getData()->status)) {

                if ($data->getData()->status == 'success') {
                    return redirect()->route('customer.reports.campaigns')->with([
                        'status'  => 'success',
                        'message' => $data->getData()->message,
                    ]);
                }

                return redirect()->route('customer.mms.campaign_builder')->with([
                    'status'  => 'error',
                    'message' => $data->getData()->message,
                ]);
            }

            return redirect()->route('customer.mms.campaign_builder')->with([
                'status'  => 'error',
                'message' => __('locale.exceptions.something_went_wrong'),
            ]);

        }

        /**
         *
         * @return Application|Factory|View|RedirectResponse
         * @throws AuthorizationException
         */
        public function mmsImport(): View|Factory|RedirectResponse|Application
        {
            $this->authorize('mms_bulk_messages');

            $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('dashboard'), 'name' => __('locale.menu.MMS')],
                ['name' => __('locale.menu.Send Using File')],
            ];


            $activeSubscription = Auth::user()->customer->activeSubscription();

            if ( ! $activeSubscription) {
                return redirect()->route('customer.subscriptions.index')->with([
                    'status'  => 'error',
                    'message' => __('locale.customer.no_active_subscription'),
                ]);
            }


            $compactData                = $this->getCampaignBuilderData('mms');
            $compactData['breadcrumbs'] = $breadcrumbs;

            return view('customer.Campaigns.mmsImport', $compactData);
        }


        /**
         * send a message using file
         *
         * @param MMSImportRequest $request
         *
         * @return Application|Factory|View|RedirectResponse
         */
        public function importMMSCampaign(MMSImportRequest $request): View|Factory|RedirectResponse|Application
        {
            if (config('app.stage') == 'demo') {
                return redirect()->route('customer.mms.import')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            if ($request->file('import_file')->isValid()) {

                $breadcrumbs = [
                    ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                    ['link' => url('dashboard'), 'name' => __('locale.menu.MMS')],
                    ['name' => __('locale.menu.Send Using File')],
                ];

                $media_url              = Tool::uploadImage($request->file('mms_file'));
                $form_data              = $request->except('_token', 'import_file', 'mms_file');
                $form_data['media_url'] = $media_url;
                $file                   = $request->file('import_file');

                $ref_id = uniqid();


                $content = file_get_contents($request->file('import_file')->getRealPath());
                $content = mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');

                $tempFile = tempnam(sys_get_temp_dir(), 'csv');
                file_put_contents($tempFile, $content);

                $data = Excel::toArray(new stdClass(), $tempFile, null, ExcelFormat::CSV)[0];


                if ( ! is_array($data) && count($data) > 0) {
                    return redirect()->route('customer.mms.import')->with([
                        'status'  => 'error',
                        'message' => __('locale.settings.invalid_file'),
                    ]);
                }

                $csv_data    = array_slice($data, 0, 2);
                $path        = 'app/bulk_sms/';
                $upload_path = storage_path($path);

                if ( ! file_exists($upload_path)) {
                    mkdir($upload_path, 0777, true);
                }

                $filename = 'mms-' . $ref_id . '.' . $file->getClientOriginalExtension();

                // save to server
                $file->move($upload_path, $filename);

                $csv_data_file = CsvData::create([
                    'user_id'      => Auth::user()->id,
                    'ref_id'       => $ref_id,
                    'ref_type'     => CsvData::TYPE_CAMPAIGN,
                    'csv_filename' => $filename,
                    'csv_header'   => $request->has('header'),
                    'csv_data'     => $path . $filename,
                ]);

                return view('customer.Campaigns.import_fields', compact('csv_data', 'csv_data_file', 'breadcrumbs', 'form_data'));
            }

            return redirect()->route('customer.mms.import')->with([
                'status'  => 'error',
                'message' => __('locale.settings.invalid_file'),
            ]);
        }


        /*
        |--------------------------------------------------------------------------
        | whatsapp module
        |--------------------------------------------------------------------------
        |
        |
        |
        */


        /**
         * whatsapp quick send
         *
         *
         * @return Application|Factory|View|RedirectResponse
         * @throws AuthorizationException
         */
        public function whatsAppQuickSend(): View|Factory|RedirectResponse|Application
        {
            $this->authorize('whatsapp_quick_send');

            $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('dashboard'), 'name' => __('locale.menu.WhatsApp')],
                ['name' => __('locale.menu.Quick Send')],
            ];

            $sender_ids = Senderid::where('user_id', auth()->user()->id)->where('status', 'active')->get();

            $phone_numbers = PhoneNumbers::where('user_id', auth()->id())
                ->where('status', 'assigned')
                ->get()
                ->filter(function ($number) {
                    $caps = json_decode($number->capabilities, true);

                    return is_array($caps) && in_array('whatsapp', $caps);
                });

            $activeSubscription = Auth::user()->customer->activeSubscription();
            if ( ! $activeSubscription) {
                return redirect()->route('customer.subscriptions.index')->with([
                    'status'  => 'error',
                    'message' => __('locale.customer.no_active_subscription'),
                ]);
            }

            $plan_id = $activeSubscription->plan_id;

            $coverage = CustomerBasedPricingPlan::where('status', true)
                ->where('user_id', Auth::user()->id)
                ->get();

            if ($coverage->count() < 1) {
                $coverage = PlansCoverageCountries::where('plan_id', $plan_id)
                    ->where('status', true)
                    ->get();
            }

            $templates      = Templates::where('user_id', auth()->user()->id)->where('status', 1)->get();
            $sendingServers = CustomerBasedSendingServer::where('user_id', auth()->user()->id)->where('status', 1)->get();

            return view('customer.Campaigns.whatsAppQuickSend', compact('breadcrumbs', 'sender_ids', 'phone_numbers', 'coverage', 'templates', 'sendingServers'));
        }

        /**
         * quick send message
         *
         * @param Campaigns                $campaign
         * @param WhatsAppQuickSendRequest $request
         *
         * @return RedirectResponse
         */
        public function postWhatsAppQuickSend(Campaigns $campaign, WhatsAppQuickSendRequest $request): RedirectResponse
        {
            if (config('app.stage') == 'demo') {
                return $this->quickSendError('whatsapp', 'Sorry! This option is not available in demo mode');
            }

            $activeSubscription = Auth::user()->customer->activeSubscription();
            if ( ! $activeSubscription) {
                return $this->quickSendError('whatsapp', __('locale.customer.no_active_subscription'));
            }

            $plan = Plan::where('status', true)->find($activeSubscription->plan_id);

            if ( ! $plan) {
                return $this->quickSendError('whatsapp', __('locale.customer.no_active_subscription'));
            }

            $recipients = $this->getRecipients($request);

            if ($recipients->count() < 1) {
                return $this->quickSendError('whatsapp', __('locale.campaigns.at_least_one_number'));
            }

            if ($recipients->count() > 100) {
                return $this->quickSendError('whatsapp', __('locale.campaigns.too_many_numbers'));
            }

            $sendData = $request->except('_token', 'recipients', 'delimiter', 'mms_file', 'language');

            if (isset($request->language) && $request->language != '0') {
                $sendData['language'] = $request->language;
            }

            if (isset($request->mms_file)) {
                $sendData['media_url'] = Tool::uploadImage($request->file('mms_file'));
            }

            $errors  = [];
            $success = [];


            $sendingServers = CustomerBasedSendingServer::where('user_id', auth()->user()->id)->where('status', 1)->count();

            if ($sendingServers && ! isset($request->sending_server)) {
                return $this->quickSendError('whatsapp', 'Please select a sending server');
            }

            $validateData = $this->campaigns->checkQuickSendValidation($sendData);

            if ($validateData->getData()->status == 'error') {
                return $this->quickSendError('whatsapp', $validateData->getData()->message);
            }

            $sendData['sender_id'] = $validateData->getData()->sender_id;
            $sendData['sms_type']  = 'whatsapp';
            $sendData['user']      = User::find($validateData->getData()->user_id);


            foreach ($recipients as $recipient) {

                $phone    = $this->getPhoneNumber($recipient, $request->input('country_code'));
                $coverage = $plan->plansCoverageCountries->firstWhere('country_id', $phone['country_id']);

                if ( ! $coverage) {
                    $errors[] = __('locale.campaigns.country_not_covered', ['country' => $phone['country_code']]);
                    continue;
                }

                $options = json_decode($coverage['options'], true);
                if (empty($options['whatsapp'])) {
                    $errors[] = __('locale.campaigns.sms_not_covered', ['country' => $phone['country_code'], 'sms_type' => 'WhatsApp']);
                    continue;
                }


                $sendData['country_code'] = $phone['country_code'];
                $sendData['recipient']    = $phone['recipient'];
                $sendData['region_code']  = $phone['region_code'];

                $data = $this->campaigns->quickSend($campaign, $sendData);

                if ($data->getData()->status === 'error') {
                    $errors[] = $data->getData()->message;
                } else if ($data->getData()->status === 'success' || $data->getData()->status === 'info') {
                    $success[] = $data->getData()->message;
                }
            }

            if ( ! empty($errors)) {
                $errorMessage = implode(' ', $errors);

                return redirect()->route('customer.whatsapp.quick_send')->with([
                    'status'  => 'error',
                    'message' => $errorMessage,
                ]);
            }

            $successMessage = implode(' ', $success);

            return redirect()->route('customer.reports.all')->with([
                'status'  => 'info',
                'message' => $successMessage,
            ]);
        }

        /**
         * whatsapp campaign builder
         *
         * @return Application|Factory|View|RedirectResponse
         * @throws AuthorizationException
         */
        public function whatsappCampaignBuilder(): View|Factory|RedirectResponse|Application
        {

            $this->authorize('whatsapp_campaign_builder');

            $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('dashboard'), 'name' => __('locale.menu.WhatsApp')],
                ['name' => __('locale.menu.Campaign Builder')],
            ];


            $activeSubscription = Auth::user()->customer->activeSubscription();

            if ( ! $activeSubscription) {
                return redirect()->route('customer.subscriptions.index')->with([
                    'status'  => 'error',
                    'message' => __('locale.customer.no_active_subscription'),
                ]);
            }


            $compactData                = $this->getCampaignBuilderData('whatsapp');
            $compactData['breadcrumbs'] = $breadcrumbs;


            return view('customer.Campaigns.whatsAppCampaignBuilder', $compactData);
        }


        /**
         * store campaign
         *
         *
         * @param Campaigns                      $campaign
         * @param WhatsAppCampaignBuilderRequest $request
         *
         * @return RedirectResponse
         */
        public function storeWhatsAppCampaign(Campaigns $campaign, WhatsAppCampaignBuilderRequest $request): RedirectResponse
        {
            if (config('app.stage') == 'demo') {
                return redirect()->route('customer.whatsapp.quick_send')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $customer           = Auth::user()->customer;
            $activeSubscription = $customer->activeSubscription();

            if ( ! $activeSubscription) {
                return redirect()->route('customer.subscriptions.index')->with([
                    'status'  => 'error',
                    'message' => __('locale.customer.no_active_subscription'),
                ]);
            }

            $plan = Plan::where('status', true)->find($activeSubscription->plan_id);

            if ( ! $plan) {
                return redirect()->route('customer.whatsapp.campaign_builder')->with([
                    'status'  => 'error',
                    'message' => 'Purchased plan is not active. Please contact support team.',
                ]);
            }

            $data = $this->campaigns->campaignBuilder($campaign, $request->except('_token'));

            if (isset($data->getData()->status)) {

                if ($data->getData()->status == 'success') {
                    return redirect()->route('customer.reports.campaigns')->with([
                        'status'  => 'success',
                        'message' => $data->getData()->message,
                    ]);
                }

                return redirect()->route('customer.whatsapp.campaign_builder')->with([
                    'status'  => 'error',
                    'message' => $data->getData()->message,
                ]);
            }

            return redirect()->route('customer.whatsapp.campaign_builder')->with([
                'status'  => 'error',
                'message' => __('locale.exceptions.something_went_wrong'),
            ]);

        }

        /**
         * whatsapp send a message using file
         *
         * @return Application|Factory|View|RedirectResponse
         * @throws AuthorizationException
         */
        public function whatsappImport(): View|Factory|RedirectResponse|Application
        {
            $this->authorize('whatsapp_bulk_messages');

            $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('dashboard'), 'name' => __('locale.menu.WhatsApp')],
                ['name' => __('locale.menu.Send Using File')],
            ];


            $activeSubscription = Auth::user()->customer->activeSubscription();

            if ( ! $activeSubscription) {
                return redirect()->route('customer.subscriptions.index')->with([
                    'status'  => 'error',
                    'message' => __('locale.customer.no_active_subscription'),
                ]);
            }


            $compactData                = $this->getCampaignBuilderData('whatsapp');
            $compactData['breadcrumbs'] = $breadcrumbs;

            return view('customer.Campaigns.whatsAppImport', $compactData);
        }


        /**
         * send a message using file
         *
         * @param ImportRequest $request
         *
         * @return Application|Factory|View|RedirectResponse
         */
        public function importWhatsAppCampaign(ImportRequest $request): View|Factory|RedirectResponse|Application
        {
            if ($request->hasFile('import_file') && $request->file('import_file')->isValid()) {

                $breadcrumbs = [
                    ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                    ['link' => url('dashboard'), 'name' => __('locale.menu.WhatsApp')],
                    ['name' => __('locale.menu.Send Using File')],
                ];

                $form_data = $request->except('_token', 'import_file', 'mms_file');

                if ($request->hasFile('mms_file') && $request->file('mms_file')->isValid()) {
                    $media_url              = Tool::uploadImage($request->file('mms_file'));
                    $form_data['media_url'] = $media_url;
                }

                if ($request->input('language') !== null) {
                    $form_data['language'] = $request->input('language');
                }

                $file   = $request->file('import_file');
                $ref_id = uniqid();


                $content = file_get_contents($request->file('import_file')->getRealPath());
                $content = mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');

                $tempFile = tempnam(sys_get_temp_dir(), 'csv');
                file_put_contents($tempFile, $content);

                $data = Excel::toArray(new stdClass(), $tempFile, null, ExcelFormat::CSV)[0];


                if ( ! is_array($data) && count($data) > 0) {
                    return redirect()->route('customer.whatsapp.import')->with([
                        'status'  => 'error',
                        'message' => __('locale.settings.invalid_file'),
                    ]);
                }

                $csv_data    = array_slice($data, 0, 2);
                $path        = 'app/bulk_sms/';
                $upload_path = storage_path($path);

                if ( ! file_exists($upload_path)) {
                    mkdir($upload_path, 0777, true);
                }

                $filename = 'whatsapp-' . $ref_id . '.' . $file->getClientOriginalExtension();

                // save to server
                $file->move($upload_path, $filename);

                $csv_data_file = CsvData::create([
                    'user_id'      => Auth::user()->id,
                    'ref_id'       => $ref_id,
                    'ref_type'     => CsvData::TYPE_CAMPAIGN,
                    'csv_filename' => $filename,
                    'csv_header'   => $request->has('header'),
                    'csv_data'     => $path . $filename,
                ]);


                return view('customer.Campaigns.import_fields', compact('csv_data', 'csv_data_file', 'breadcrumbs', 'form_data'));
            }

            return redirect()->route('customer.whatsapp.import')->with([
                'status'  => 'error',
                'message' => __('locale.settings.invalid_file'),
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Version 3.5
        |--------------------------------------------------------------------------
        |
        | Campaign pause, restart, resend
        |
        */


        /**
         * Pause the Campaign
         *
         * @param Campaigns $campaign
         *
         * @return JsonResponse
         */
        public function campaignPause(Campaigns $campaign)
        {
            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $data = $this->campaigns->pause($campaign);

            if (isset($data->getData()->status)) {

                if ($data->getData()->status == 'success') {
                    return response()->json([
                        'status'  => 'success',
                        'message' => $data->getData()->message,
                    ]);
                }

                return response()->json([
                    'status'  => 'error',
                    'message' => $data->getData()->message,
                ]);
            }

            return response()->json([
                'status'  => 'error',
                'message' => __('locale.exceptions.something_went_wrong'),
            ]);
        }


        /**
         * Restart the Campaign
         *
         * @param Campaigns $campaign
         *
         * @return JsonResponse
         */
        public function campaignRestart(Campaigns $campaign)
        {
            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $data = $this->campaigns->restart($campaign);

            if (isset($data->getData()->status)) {

                if ($data->getData()->status == 'success') {
                    return response()->json([
                        'status'  => 'success',
                        'message' => $data->getData()->message,
                    ]);
                }

                return response()->json([
                    'status'  => 'error',
                    'message' => $data->getData()->message,
                ]);
            }

            return response()->json([
                'status'  => 'error',
                'message' => __('locale.exceptions.something_went_wrong'),
            ]);
        }

        /**
         * Resend the Campaign
         *
         * @param Campaigns $campaign
         *
         * @return JsonResponse
         */
        public function campaignResend(Campaigns $campaign)
        {
            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $data = $this->campaigns->resend($campaign);

            if (isset($data->getData()->status)) {

                if ($data->getData()->status == 'success') {
                    return response()->json([
                        'status'  => 'success',
                        'message' => $data->getData()->message,
                    ]);
                }

                return response()->json([
                    'status'  => 'error',
                    'message' => $data->getData()->message,
                ]);
            }

            return response()->json([
                'status'  => 'error',
                'message' => __('locale.exceptions.something_went_wrong'),
            ]);
        }


        /**
         * @return array|RedirectResponse
         */
        private function getCampaignBuilderData($type = 'sms')
        {

            $customer = Auth::user()->customer;

            if ( ! $customer->activeSubscription()) {
                return redirect()->route('customer.subscriptions.index')->with([
                    'status'  => 'error',
                    'message' => __('locale.customer.no_active_subscription'),
                ]);
            }

            $sender_ids = Senderid::where('user_id', auth()->user()->id)->where('status', 'active')->get();


            $phone_numbers = PhoneNumbers::where('user_id', auth()->id())
                ->where('status', 'assigned')
                ->get()
                ->filter(function ($number) use ($type) {
                    $caps = json_decode($number->capabilities, true);

                    return is_array($caps) && in_array($type, $caps);
                });

            $template_tags  = TemplateTags::get();
            $contact_groups = ContactGroups::where('status', 1)->where('customer_id', auth()->user()->id)->get();
            $templates      = Templates::where('user_id', auth()->user()->id)->where('status', 1)->get();
            $sendingServers = CustomerBasedSendingServer::where('user_id', auth()->user()->id)->where('status', 1)->get();


            $plan_id = $customer->activeSubscription()->plan_id;

            return [
                'sender_ids'     => $sender_ids,
                'phone_numbers'  => $phone_numbers,
                'template_tags'  => $template_tags,
                'contact_groups' => $contact_groups,
                'templates'      => $templates,
                'plan_id'        => $plan_id,
                'sendingServers' => $sendingServers,
            ];

        }



        /*
        |--------------------------------------------------------------------------
        | Viber module
        |--------------------------------------------------------------------------
        |
        |
        |
        */


        /**
         * Viber quickly sends
         *
         *
         * @return Application|Factory|View|RedirectResponse
         * @throws AuthorizationException
         */
        public function viberQuickSend(): View|Factory|RedirectResponse|Application
        {
            $this->authorize('viber_quick_send');

            $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('dashboard'), 'name' => __('locale.menu.Viber')],
                ['name' => __('locale.menu.Quick Send')],
            ];

            $sender_ids = Senderid::where('user_id', auth()->user()->id)->where('status', 'active')->get();

            $phone_numbers = PhoneNumbers::where('user_id', auth()->id())
                ->where('status', 'assigned')
                ->get()
                ->filter(function ($number) {
                    $caps = json_decode($number->capabilities, true);

                    return is_array($caps) && in_array('viber', $caps);
                });

            $activeSubscription = Auth::user()->customer->activeSubscription();
            if ( ! $activeSubscription) {
                return redirect()->route('customer.subscriptions.index')->with([
                    'status'  => 'error',
                    'message' => __('locale.customer.no_active_subscription'),
                ]);
            }

            $plan_id = $activeSubscription->plan_id;

            $coverage = CustomerBasedPricingPlan::where('status', true)
                ->where('user_id', Auth::user()->id)
                ->get();

            if ($coverage->count() < 1) {
                $coverage = PlansCoverageCountries::where('plan_id', $plan_id)
                    ->where('status', true)
                    ->get();
            }

            $templates      = Templates::where('user_id', auth()->user()->id)->where('status', 1)->get();
            $sendingServers = CustomerBasedSendingServer::where('user_id', auth()->user()->id)->where('status', 1)->get();

            return view('customer.Campaigns.viberQuickSend', compact('breadcrumbs', 'sender_ids', 'phone_numbers', 'coverage', 'templates', 'sendingServers'));
        }

        /**
         * quick send message
         *
         * @param Campaigns             $campaign
         * @param ViberQuickSendRequest $request
         *
         * @return RedirectResponse
         */

        public function postViberQuickSend(Campaigns $campaign, ViberQuickSendRequest $request): RedirectResponse
        {
            if (config('app.stage') === 'demo') {
                return $this->quickSendError('viber', 'Sorry! This option is not available in demo mode');
            }

            $user               = Auth::user();
            $customer           = $user->customer;
            $activeSubscription = $customer->activeSubscription();

            if ( ! $activeSubscription) {
                return $this->quickSendError('viber', __('locale.customer.no_active_subscription'));
            }

            $plan = Plan::where('status', true)
                ->where('id', $activeSubscription->plan_id)
                ->first();

            if ( ! $plan) {
                return $this->quickSendError('viber', __('locale.customer.no_active_subscription'));
            }

            $recipients = $this->getRecipients($request);

            if ($recipients->isEmpty()) {
                return $this->quickSendError('viber', __('locale.campaigns.at_least_one_number'));
            }

            if ($recipients->count() > 100) {
                return $this->quickSendError('viber', __('locale.campaigns.too_many_numbers'));
            }

            $sendData = $request->except('_token', 'recipients', 'delimiter', 'mms_file');

            if ($request->hasFile('mms_file')) {
                $sendData['media_url'] = Tool::uploadImage($request->file('mms_file'));
            }

            // Validate sending server
            $sendingServersExist = CustomerBasedSendingServer::where('user_id', $user->id)
                ->where('status', 1)
                ->exists();

            if ($sendingServersExist && ! $request->has('sending_server')) {
                return $this->quickSendError('viber', 'Please select your sending server');
            }

            $validateData = $this->campaigns->checkQuickSendValidation($sendData);
            $validated    = $validateData->getData();

            if ($validated->status === 'error') {
                return $this->quickSendError('viber', $validated->message);
            }

            // Set up send data
            $sendData['sender_id'] = $validated->sender_id;
            $sendData['sms_type']  = 'viber';
            $sendData['user']      = User::find($validated->user_id);

            $errors  = [];
            $success = [];

            foreach ($recipients as $recipient) {
                $phone = $this->getPhoneNumber($recipient, $request->input('country_code'));

                if ( ! is_array($phone)) {
                    $errors[] = $phone;
                    continue;
                }

                $coverage = $plan->plansCoverageCountries->firstWhere('country_id', $phone['country_id']);
                if ( ! $coverage) {
                    $errors[] = __('locale.campaigns.country_not_covered', ['country' => $phone['country_code']]);
                    continue;
                }

                $options = json_decode($coverage['options'], true);
                if (empty($options['viber'])) {
                    $errors[] = __('locale.campaigns.sms_not_covered', ['country' => $phone['country_code'], 'sms_type' => 'Viber']);
                    continue;
                }

                $sendData['country_code'] = $phone['country_code'];
                $sendData['recipient']    = $phone['recipient'];
                $sendData['region_code']  = $phone['region_code'];

                $response = $this->campaigns->quickSend($campaign, $sendData);
                $result   = $response->getData();

                if ($result->status === 'error') {
                    $errors[] = $result->message;
                } else if ($result->status === 'success') {
                    $success[] = $result->message;
                }
            }

            if ( ! empty($errors)) {
                return redirect()->route('customer.viber.quick_send')->with([
                    'status'  => 'error',
                    'message' => implode(' ', $errors),
                ]);
            }

            return redirect()->route('customer.reports.all')->with([
                'status'  => 'info',
                'message' => implode(' ', $success),
            ]);
        }

        /**
         * whatsapp campaign builder
         *
         * @return Application|Factory|View|RedirectResponse
         * @throws AuthorizationException
         */
        public function viberCampaignBuilder(): View|Factory|RedirectResponse|Application
        {

            $this->authorize('viber_campaign_builder');

            $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('dashboard'), 'name' => __('locale.menu.Viber')],
                ['name' => __('locale.menu.Campaign Builder')],
            ];


            $activeSubscription = Auth::user()->customer->activeSubscription();

            if ( ! $activeSubscription) {
                return redirect()->route('customer.subscriptions.index')->with([
                    'status'  => 'error',
                    'message' => __('locale.customer.no_active_subscription'),
                ]);
            }


            $compactData                = $this->getCampaignBuilderData('viber');
            $compactData['breadcrumbs'] = $breadcrumbs;


            return view('customer.Campaigns.viberCampaignBuilder', $compactData);
        }


        /**
         * store campaign
         *
         *
         * @param Campaigns                   $campaign
         * @param ViberCampaignBuilderRequest $request
         *
         * @return RedirectResponse
         */
        public function storeViberCampaign(Campaigns $campaign, ViberCampaignBuilderRequest $request): RedirectResponse
        {
            if (config('app.stage') == 'demo') {
                return redirect()->route('customer.viber.campaign_builder')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $customer           = Auth::user()->customer;
            $activeSubscription = $customer->activeSubscription();

            if ( ! $activeSubscription) {
                return redirect()->route('customer.subscriptions.index')->with([
                    'status'  => 'error',
                    'message' => __('locale.customer.no_active_subscription'),
                ]);
            }

            $plan = Plan::where('status', true)->find($activeSubscription->plan_id);

            if ( ! $plan) {
                return redirect()->route('customer.viber.campaign_builder')->with([
                    'status'  => 'error',
                    'message' => 'Purchased plan is not active. Please contact support team.',
                ]);
            }

            $data = $this->campaigns->campaignBuilder($campaign, $request->except('_token'));

            if (isset($data->getData()->status)) {

                if ($data->getData()->status == 'success') {
                    return redirect()->route('customer.reports.campaigns')->with([
                        'status'  => 'success',
                        'message' => $data->getData()->message,
                    ]);
                }

                return redirect()->route('customer.viber.campaign_builder')->with([
                    'status'  => 'error',
                    'message' => $data->getData()->message,
                ]);
            }

            return redirect()->route('customer.viber.campaign_builder')->with([
                'status'  => 'error',
                'message' => __('locale.exceptions.something_went_wrong'),
            ]);

        }


        /**
         * viber send a message using file
         *
         * @return Application|Factory|View|RedirectResponse
         * @throws AuthorizationException
         */
        public function viberImport(): View|Factory|RedirectResponse|Application
        {
            $this->authorize('viber_bulk_messages');

            $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('dashboard'), 'name' => __('locale.menu.Viber')],
                ['name' => __('locale.menu.Send Using File')],
            ];


            $activeSubscription = Auth::user()->customer->activeSubscription();

            if ( ! $activeSubscription) {
                return redirect()->route('customer.subscriptions.index')->with([
                    'status'  => 'error',
                    'message' => __('locale.customer.no_active_subscription'),
                ]);
            }


            $compactData                = $this->getCampaignBuilderData('viber');
            $compactData['breadcrumbs'] = $breadcrumbs;

            return view('customer.Campaigns.viberImport', $compactData);
        }


        /**
         * send a message using file
         *
         * @param ImportRequest $request
         *
         * @return Application|Factory|View|RedirectResponse
         */
        public function importViberCampaign(ImportRequest $request): View|Factory|RedirectResponse|Application
        {

            if (config('app.stage') == 'demo') {
                return redirect()->route('customer.viber.import')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            if ($request->hasFile('import_file') && $request->file('import_file')->isValid()) {

                $breadcrumbs = [
                    ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                    ['link' => url('dashboard'), 'name' => __('locale.menu.Viber')],
                    ['name' => __('locale.menu.Send Using File')],
                ];

                $form_data = $request->except('_token', 'import_file', 'mms_file');

                if ($request->hasFile('mms_file') && $request->file('mms_file')->isValid()) {
                    $media_url              = Tool::uploadImage($request->file('mms_file'));
                    $form_data['media_url'] = $media_url;
                }

                $file   = $request->file('import_file');
                $ref_id = uniqid();


                $content = file_get_contents($request->file('import_file')->getRealPath());
                $content = mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');

                $tempFile = tempnam(sys_get_temp_dir(), 'csv');
                file_put_contents($tempFile, $content);

                $data = Excel::toArray(new stdClass(), $tempFile, null, ExcelFormat::CSV)[0];


                if ( ! is_array($data) && count($data) > 0) {
                    return redirect()->route('customer.viber.import')->with([
                        'status'  => 'error',
                        'message' => __('locale.settings.invalid_file'),
                    ]);
                }

                $csv_data    = array_slice($data, 0, 2);
                $path        = 'app/bulk_sms/';
                $upload_path = storage_path($path);

                if ( ! file_exists($upload_path)) {
                    mkdir($upload_path, 0777, true);
                }

                $filename = 'viber-' . $ref_id . '.' . $file->getClientOriginalExtension();

                // save to server
                $file->move($upload_path, $filename);

                $csv_data_file = CsvData::create([
                    'user_id'      => Auth::user()->id,
                    'ref_id'       => $ref_id,
                    'ref_type'     => CsvData::TYPE_CAMPAIGN,
                    'csv_filename' => $filename,
                    'csv_header'   => $request->has('header'),
                    'csv_data'     => $path . $filename,
                ]);


                return view('customer.Campaigns.import_fields', compact('csv_data', 'csv_data_file', 'breadcrumbs', 'form_data'));
            }

            return redirect()->route('customer.viber.import')->with([
                'status'  => 'error',
                'message' => __('locale.settings.invalid_file'),
            ]);
        }


        /*
        |--------------------------------------------------------------------------
        | OTP Module
        |--------------------------------------------------------------------------
        |
        |
        |
        */


        /**
         * OTP quick send
         *
         *
         * @return Application|Factory|View|RedirectResponse
         * @throws AuthorizationException
         */
        public function otpQuickSend(): View|Factory|RedirectResponse|Application
        {
            $this->authorize('otp_quick_send');

            $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('dashboard'), 'name' => __('locale.menu.OTP')],
                ['name' => __('locale.menu.Quick Send')],
            ];

            $sender_ids = Senderid::where('user_id', auth()->user()->id)->where('status', 'active')->get();

            $phone_numbers = PhoneNumbers::where('user_id', auth()->id())
                ->where('status', 'assigned')
                ->get()
                ->filter(function ($number) {
                    $caps = json_decode($number->capabilities, true);

                    return is_array($caps) && in_array('otp', $caps);
                });

            $activeSubscription = Auth::user()->customer->activeSubscription();
            if ( ! $activeSubscription) {
                return redirect()->route('customer.subscriptions.index')->with([
                    'status'  => 'error',
                    'message' => __('locale.customer.no_active_subscription'),
                ]);
            }

            $plan_id = $activeSubscription->plan_id;

            $coverage = CustomerBasedPricingPlan::where('status', true)
                ->where('user_id', Auth::user()->id)
                ->get();

            if ($coverage->count() < 1) {
                $coverage = PlansCoverageCountries::where('plan_id', $plan_id)
                    ->where('status', true)
                    ->get();
            }

            $templates      = Templates::where('user_id', auth()->user()->id)->where('status', 1)->get();
            $sendingServers = CustomerBasedSendingServer::where('user_id', auth()->user()->id)->where('status', 1)->get();


            return view('customer.Campaigns.otpQuickSend', compact('breadcrumbs', 'sender_ids', 'phone_numbers', 'coverage', 'templates', 'sendingServers'));
        }

        /**
         * quick send message
         *
         * @param Campaigns           $campaign
         * @param OTPQuickSendRequest $request
         *
         * @return RedirectResponse
         */
        public function postOTPQuickSend(Campaigns $campaign, OTPQuickSendRequest $request): RedirectResponse
        {
            if (config('app.stage') === 'demo') {
                return $this->quickSendError('otp', 'Sorry! This option is not available in demo mode');
            }

            $user               = Auth::user();
            $customer           = $user->customer;
            $activeSubscription = $customer->activeSubscription();

            if ( ! $activeSubscription) {
                return $this->quickSendError('otp', __('locale.customer.no_active_subscription'));
            }

            $plan = Plan::where('id', $activeSubscription->plan_id)
                ->where('status', true)
                ->first();

            if ( ! $plan) {
                return $this->quickSendError('otp', __('locale.customer.no_active_subscription'));
            }

            if (config('app.trai_dlt') && $plan->is_dlt) {
                if (empty($request->input('dlt_template_id'))) {
                    return $this->quickSendError('otp', 'The DLT Template ID is mandatory. Kindly reach out to the system administrator for further assistance');
                }

                if (empty($user->dlt_entity_id)) {
                    return $this->quickSendError('otp', 'The DLT Entity ID is mandatory. Kindly reach out to the system administrator for further assistance');
                }
            }

            $recipients = $this->getRecipients($request);

            if ($recipients->isEmpty()) {
                return $this->quickSendError('otp', __('locale.campaigns.at_least_one_number'));
            }

            if ($recipients->count() > 100) {
                return $this->quickSendError('otp', __('locale.campaigns.too_many_numbers'));
            }

            $sendData = $request->except('_token', 'recipients', 'delimiter');

            // Validate sending server
            $sendingServersExist = CustomerBasedSendingServer::where('user_id', $user->id)
                ->where('status', 1)
                ->exists();

            if ($sendingServersExist && ! $request->has('sending_server')) {
                return $this->quickSendError('otp', 'Please select your sending server');
            }

            $validation = $this->campaigns->checkQuickSendValidation($sendData);
            $validated  = $validation->getData();

            if ($validated->status === 'error') {
                return $this->quickSendError($validated->message);
            }

            // Setup sendData with validated results
            $sendData['sender_id'] = $validated->sender_id;
            $sendData['sms_type']  = 'otp';
            $sendData['user']      = User::find($validated->user_id);

            $errors  = [];
            $success = [];

            foreach ($recipients as $recipient) {
                $phone = $this->getPhoneNumber($recipient, $request->input('country_code'));

                if ( ! is_array($phone)) {
                    $errors[] = $phone;
                    continue;
                }

                $coverage = $plan->plansCoverageCountries->firstWhere('country_id', $phone['country_id']);
                if ( ! $coverage) {
                    $errors[] = __('locale.campaigns.country_not_covered', ['country' => $phone['country_code']]);
                    continue;
                }

                $options = json_decode($coverage['options'], true);
                if (empty($options['otp'])) {
                    $errors[] = __('locale.campaigns.sms_not_covered', ['country' => $phone['country_code'], 'sms_type' => 'OTP']);
                    continue;
                }

                $sendData['country_code'] = $phone['country_code'];
                $sendData['recipient']    = $phone['recipient'];
                $sendData['region_code']  = $phone['region_code'];

                $result  = $this->campaigns->quickSend($campaign, $sendData);
                $status  = $result->getData()->status;
                $message = $result->getData()->message;

                if ($status === 'error') {
                    $errors[] = $message;
                } else if (in_array($status, ['success', 'info'])) {
                    $success[] = $message;
                }
            }

            if ( ! empty($errors)) {
                return redirect()->route('customer.otp.quick_send')->with([
                    'status'  => 'error',
                    'message' => implode(' ', $errors),
                ]);
            }

            return redirect()->route('customer.reports.all')->with([
                'status'  => 'info',
                'message' => implode(' ', $success),
            ]);
        }

        /**
         * OTP campaign builder
         *
         * @return Application|Factory|View|RedirectResponse
         * @throws AuthorizationException
         */
        public function otpCampaignBuilder(): View|Factory|RedirectResponse|Application
        {

            $this->authorize('otp_campaign_builder');

            $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('dashboard'), 'name' => __('locale.menu.OTP')],
                ['name' => __('locale.menu.Campaign Builder')],
            ];


            $activeSubscription = Auth::user()->customer->activeSubscription();

            if ( ! $activeSubscription) {
                return redirect()->route('customer.subscriptions.index')->with([
                    'status'  => 'error',
                    'message' => __('locale.customer.no_active_subscription'),
                ]);
            }


            $compactData                = $this->getCampaignBuilderData('otp');
            $compactData['breadcrumbs'] = $breadcrumbs;


            return view('customer.Campaigns.otpCampaignBuilder', $compactData);
        }


        /**
         * store campaign
         *
         *
         * @param Campaigns                 $campaign
         * @param OTPCampaignBuilderRequest $request
         *
         * @return RedirectResponse
         */
        public function storeOTPCampaign(Campaigns $campaign, OTPCampaignBuilderRequest $request): RedirectResponse
        {
            if (config('app.stage') == 'demo') {
                return redirect()->route('customer.otp.campaign_builder')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $customer           = Auth::user()->customer;
            $activeSubscription = $customer->activeSubscription();

            if ( ! $activeSubscription) {
                return redirect()->route('customer.subscriptions.index')->with([
                    'status'  => 'error',
                    'message' => __('locale.customer.no_active_subscription'),
                ]);
            }

            $plan = Plan::where('status', true)->find($activeSubscription->plan_id);

            if ( ! $plan) {
                return redirect()->route('customer.otp.campaign_builder')->with([
                    'status'  => 'error',
                    'message' => 'Purchased plan is not active. Please contact support team.',
                ]);
            }


            if (config('app.trai_dlt') && $plan->is_dlt && $request->input('dlt_template_id') == null) {
                return redirect()->route('customer.otp.campaign_builder')->with([
                    'status'  => 'error',
                    'message' => 'DLT Template id is required',
                ]);
            }

            if (config('app.trai_dlt') && $activeSubscription->plan->is_dlt && Auth::user()->dlt_entity_id == null) {
                return redirect()->route('customer.otp.campaign_builder')->with([
                    'status'  => 'error',
                    'message' => 'The DLT Entity ID is mandatory. Kindly reach out to the system administrator for further assistance',
                ]);
            }

            $data = $this->campaigns->campaignBuilder($campaign, $request->except('_token'));

            if (isset($data->getData()->status)) {

                if ($data->getData()->status == 'success') {
                    return redirect()->route('customer.reports.campaigns')->with([
                        'status'  => 'success',
                        'message' => $data->getData()->message,
                    ]);
                }

                return redirect()->route('customer.otp.campaign_builder')->with([
                    'status'  => 'error',
                    'message' => $data->getData()->message,
                ]);
            }

            return redirect()->route('customer.otp.campaign_builder')->with([
                'status'  => 'error',
                'message' => __('locale.exceptions.something_went_wrong'),
            ]);

        }


        /**
         * send an otp message using file
         *
         * @return Application|Factory|View|RedirectResponse
         * @throws AuthorizationException
         */
        public function otpImport(): View|Factory|RedirectResponse|Application
        {
            $this->authorize('otp_bulk_messages');

            $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('dashboard'), 'name' => __('locale.menu.OTP')],
                ['name' => __('locale.menu.Send Using File')],
            ];


            $activeSubscription = Auth::user()->customer->activeSubscription();

            if ( ! $activeSubscription) {
                return redirect()->route('customer.subscriptions.index')->with([
                    'status'  => 'error',
                    'message' => __('locale.customer.no_active_subscription'),
                ]);
            }


            $compactData                = $this->getCampaignBuilderData('otp');
            $compactData['breadcrumbs'] = $breadcrumbs;

            return view('customer.Campaigns.otpImport', $compactData);
        }


        /**
         * send a message using file
         *
         * @param ImportRequest $request
         *
         * @return Application|Factory|View|RedirectResponse
         */
        public function importOTPCampaign(ImportRequest $request): View|Factory|RedirectResponse|Application
        {
            if (config('app.stage') == 'demo') {
                return redirect()->route('customer.otp.import')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            if ($request->file('import_file')->isValid()) {

                $breadcrumbs = [
                    ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                    ['link' => url('dashboard'), 'name' => __('locale.menu.OTP')],
                    ['name' => __('locale.menu.Send Using File')],
                ];

                $form_data = $request->except('_token', 'import_file');
                $file      = $request->file('import_file');
                $ref_id    = uniqid();


                $content = file_get_contents($request->file('import_file')->getRealPath());
                $content = mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');

                $tempFile = tempnam(sys_get_temp_dir(), 'csv');
                file_put_contents($tempFile, $content);

                $data = Excel::toArray(new stdClass(), $tempFile, null, ExcelFormat::CSV)[0];


                if ( ! is_array($data) && count($data) > 0) {
                    return redirect()->route('customer.otp.import')->with([
                        'status'  => 'error',
                        'message' => __('locale.settings.invalid_file'),
                    ]);
                }

                $csv_data    = array_slice($data, 0, 2);
                $path        = 'app/bulk_sms/';
                $upload_path = storage_path($path);

                if ( ! file_exists($upload_path)) {
                    mkdir($upload_path, 0777, true);
                }

                $filename = 'otp-' . $ref_id . '.' . $file->getClientOriginalExtension();

                // save to server
                $file->move($upload_path, $filename);

                $csv_data_file = CsvData::create([
                    'user_id'      => Auth::user()->id,
                    'ref_id'       => $ref_id,
                    'ref_type'     => CsvData::TYPE_CAMPAIGN,
                    'csv_filename' => $filename,
                    'csv_header'   => $request->has('header'),
                    'csv_data'     => $path . $filename,
                ]);


                return view('customer.Campaigns.import_fields', compact('csv_data', 'csv_data_file', 'breadcrumbs', 'form_data'));
            }

            return redirect()->route('customer.otp.import')->with([
                'status'  => 'error',
                'message' => __('locale.settings.invalid_file'),
            ]);
        }


        /*Version 3.9*/
        /**
         * Get tags for the given contact ID.
         *
         * @param string $contact_id description
         * @return JsonResponse json response
         */
        public function getTags(string $contact_id): JsonResponse
        {
            $groupIds = explode(',', $contact_id);

            if (empty($groupIds)) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.contacts.contact_group_not_found'),
                ]);
            }

            $tags = ContactGroups::where('status', 1)
                ->where('customer_id', auth()->user()->id)
                ->whereIn('id', $groupIds)
                ->with('getFields')
                ->get()
                ->flatMap(function ($group) {
                    return $group->getFields->map(function ($field) {
                        return [
                            'tag'   => $field['tag'],
                            'label' => $field['label'],
                        ];
                    });
                })
                ->unique('tag')
                ->values();

            if ($tags->isEmpty()) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.contacts.contact_group_not_found'),
                ]);
            }

            return response()->json([
                'status'        => 'success',
                'contactFields' => $tags,
            ]);

        }

        /**
         * DRY error redirect helper for quick send
         */
        private function quickSendError(string $type, string $message): RedirectResponse
        {
            return redirect()->route("customer.{$type}.quick_send")->with([
                'status'  => 'error',
                'message' => $message,
            ]);
        }


        public function generateAIMessage(GenerateAIMessageRequest $request)
        {

            if (config('app.stage') == 'demo') {
                return response()->json([
                    'success' => false,
                    'message' => 'Sorry! This option is not available in demo mode',
                ], 503);
            }

            $prompt = "Generate a concise SMS message with a {$request->tone} tone for the following audience: {$request->audience}. Goal: {$request->goal}. Limit to 160 characters.";

            $openAi = OpenAI::client(config('services.openai.api_key'));

            try {

                $result = $openAi->chat()->create([
                    'model'    => config('services.openai.model'),
                    'messages' => [
                        ['role' => config('services.openai.role'), 'content' => $prompt],
                    ],
                ]);

                $message = trim($result->choices[0]->message->content ?? '');

                return response()->json([
                    'success' => true,
                    'message' => $message,
                ]);

            } catch (Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 500);
            }

        }

        public function searchSenderIDs(Request $request)
        {

            $query = $request->get('term', '');

            $results = Senderid::where('sender_id', 'LIKE', "%{$query}%")
                ->where('status', 'active')
                ->where('user_id', auth()->user()->id)
                ->limit(20)
                ->pluck('sender_id')
                ->map(fn($id) => ['id' => $id, 'text' => $id]);

            return response()->json(['results' => $results]);
        }

    }

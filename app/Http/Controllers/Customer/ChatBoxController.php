<?php

    namespace App\Http\Controllers\Customer;

    use App\Http\Controllers\Controller;
    use App\Http\Requests\ChatBox\SentRequest;
    use App\Library\Tool;
    use App\Models\Blacklists;
    use App\Models\Campaigns;
    use App\Models\ChatBox;
    use App\Models\ChatBoxMessage;
    use App\Models\Contacts;
    use App\Models\Country;
    use App\Models\CustomerBasedPricingPlan;
    use App\Models\CustomerBasedSendingServer;
    use App\Models\PhoneNumbers;
    use App\Models\PlansCoverageCountries;
    use App\Models\SendingServer;
    use App\Models\SpamWord;
    use App\Models\Templates;
    use App\Repositories\Contracts\CampaignRepository;
    use Carbon\Carbon;
    use Illuminate\Auth\Access\AuthorizationException;
    use Illuminate\Contracts\Foundation\Application;
    use Illuminate\Contracts\View\Factory;
    use Illuminate\Contracts\View\View;
    use Illuminate\Http\JsonResponse;
    use Illuminate\Http\RedirectResponse;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Auth;
    use Illuminate\Support\Facades\Validator;
    use libphonenumber\NumberParseException;
    use libphonenumber\PhoneNumberUtil;
    use Throwable;

    class ChatBoxController extends Controller
    {
        protected CampaignRepository $campaigns;

        /**
         * ChatBoxController constructor.
         */
        public function __construct(CampaignRepository $campaigns)
        {
            $this->campaigns = $campaigns;
        }

        /**
         * get all chat box
         *
         * @throws AuthorizationException
         */
        public function index(): View|Factory|Application
        {
            $this->authorize('chat_box');

            $pageConfigs = [
                'pageHeader'    => false,
                'contentLayout' => 'content-left-sidebar',
                'pageClass'     => 'chat-application font-small-3',
            ];

            $pinnedChats = ChatBox::where('user_id', Auth::id())
                ->where('pinned', true)
                ->with(['chatBoxMessages', 'contact'])
                ->orderBy('updated_at', 'desc')
                ->get();
                
                
                
                
                
                
                
                
                
                
                
                
                
                
                
                
                
                
                
                
                
                

            $templates = Templates::where('status', true)->where('user_id', auth()->user()->id)->get();

            return view('customer.ChatBox.index', [
                'pageConfigs' => $pageConfigs,
                'templates'   => $templates,
                'pinnedChats' => $pinnedChats,
            ]);
        }

        /**
         * start new conversation
         *
         * @throws AuthorizationException
         */
        public function new(): View|Factory|RedirectResponse|Application
        {
            $this->authorize('chat_box');

            $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('chat-box'), 'name' => __('locale.menu.Chat Box')],
                ['name' => __('locale.labels.new_conversion')],
            ];

            $phone_numbers = PhoneNumbers::where('user_id', Auth::user()->id)->where('status', 'assigned')->cursor();

            if ( ! Auth::user()->customer->activeSubscription()) {
                return redirect()->route('customer.chatbox.index')->with([
                    'status'  => 'error',
                    'message' => __('locale.customer.no_active_subscription'),
                ]);
            }

            $plan_id = Auth::user()->customer->activeSubscription()->plan_id;

            $coverage = CustomerBasedPricingPlan::where('user_id', Auth::user()->id)->where('status', true)->cursor();
            if ($coverage->count() < 1) {
                $coverage = PlansCoverageCountries::where('plan_id', $plan_id)->where('status', true)->cursor();
            }

            $sendingServers = CustomerBasedSendingServer::where('user_id', auth()->user()->id)->where('status', 1)->get();
            $templates      = Templates::where('status', true)->where('user_id', auth()->user()->id)->get();

            // RFC-005 Milestone 5 §6.1 — a genuinely new compose gets a
            // fresh, independent idempotency token; a retry redirect
            // carrying ?m5_retry_token=<uuid> (set only by sent()'s own
            // 'retain' decision below) reuses that exact same token
            // instead, so the retried POST resolves against the same
            // still-open reservation rather than starting a new one.
            $retryToken = request()->query('m5_retry_token');
            $idempotencyToken = (is_string($retryToken) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $retryToken) === 1)
                ? $retryToken
                : (string) \Illuminate\Support\Str::uuid();

            return view('customer.ChatBox.new', compact('breadcrumbs', 'phone_numbers', 'coverage', 'sendingServers', 'templates', 'idempotencyToken'));
        }

        /**
         * start new conversion
         *
         *
         * @throws AuthorizationException|NumberParseException
         */
        public function sent(Campaigns $campaign, SentRequest $request): RedirectResponse
        {
            if (config('app.stage') === 'demo') {
                return redirect()->route('customer.chatbox.new')->with([
                    'status'  => 'error',
                    'message' => __('locale.demo_mode_not_available'),
                ]);
            }

            $this->authorize('chat_box');

            $sendingServers = CustomerBasedSendingServer::where('user_id', Auth::user()->id)->where('status', 1)->count();

            if ($sendingServers && ! isset($request->sending_server)) {
                return redirect()->route('customer.chatbox.new')->with([
                    'status'  => 'error',
                    'message' => 'Please select your sending server',
                ]);
            }


            $input    = $request->except('_token');
            $senderId = $request->input('sender_id');
            $sms_type = $request->input('sms_type');

            $user    = Auth::user();
            $country = Country::find($request->input('country_code'));

            if ( ! $country) {
                return redirect()->route('customer.chatbox.new')->with([
                    'status'  => 'error',
                    'message' => "Permission to send an SMS has not been enabled for the region indicated by the 'To' number: " . $input['recipient'],
                ]);
            }

            $phoneNumberUtil   = PhoneNumberUtil::getInstance();
            $phoneNumberObject = $phoneNumberUtil->parse('+' . $country->country_code . $request->input('recipient'));
            $countryCode       = $phoneNumberObject->getCountryCode();
            $regionCode        = $phoneNumberUtil->getRegionCodeForNumber($phoneNumberObject);

            if ( ! $phoneNumberUtil->isPossibleNumber($phoneNumberObject) || empty($countryCode) || empty($regionCode)) {

                return redirect()->route('customer.chatbox.new')->with([
                    'status'  => 'error',
                    'message' => __('locale.customer.invalid_phone_number', ['phone' => $country->country_code . $request->input('recipient')]),
                ]);

            }


            if ($phoneNumberObject->isItalianLeadingZero()) {
                $phone = '0' . preg_replace("/^$countryCode/", '', $phoneNumberObject->getNationalNumber());
            } else {
                $phone = preg_replace("/^$countryCode/", '', $phoneNumberObject->getNationalNumber());
            }

            if ($user->customer->getOption('send_spam_message') == 'no') {
                $spamWords = SpamWord::whereRaw("LOWER(?) LIKE CONCAT('%', LOWER(word), '%')", [$request->input('message')])->get();
                if ($spamWords->isNotEmpty()) {
                    return redirect()->route('customer.chatbox.new')->with([
                        'status'  => 'error',
                        'message' => 'Your message contains spam words.',
                    ]);
                }
            }

            $input['country_code'] = $countryCode;
            $input['recipient']    = $phone;
            $input['region_code']  = $regionCode;
            $input['user']         = Auth::user();

            $planId = $user->customer->activeSubscription()->plan_id;

            $coverage = CustomerBasedPricingPlan::where('user_id', $user->id)
                ->where('status', true)
                ->with('sendingServer')
                ->first();

            if ( ! $coverage) {
                $coverage = PlansCoverageCountries::where('plan_id', $planId)
                    ->where('status', true)
                    ->with('sendingServer')
                    ->first();
            }

            if ( ! $coverage) {
                return redirect()->route('customer.chatbox.new')->with([
                    'status'  => 'error',
                    'message' => 'Price Plan unavailable',
                ]);
            }

           $sendingServer = isset($request->sending_server)
    ? SendingServer::find($request->sending_server)
    : $coverage->sendingServer;

            if ( ! $sendingServer) {
                return redirect()->route('customer.chatbox.new')->with([
                    'status'  => 'error',
                    'message' => __('locale.campaigns.sending_server_not_available'),
                ]);
            }

            $db_sms_type = $sms_type == 'unicode' ? 'plain' : $sms_type;

            if ( ! $sendingServer->{$db_sms_type}) {
                return redirect()->route('customer.chatbox.new')->with([
                    'status'  => 'error',
                    'message' => __('locale.sending_servers.sending_server_sms_capabilities', ['type' => strtoupper($db_sms_type)]),
                ]);
            }

            if ($sendingServer->settings === 'Whatsender' || $sendingServer->type === 'whatsapp') {
                $input['sms_type'] = 'whatsapp';
            }

            $db_sms_type       = ($sms_type === 'unicode') ? 'plain' : $sms_type;
            $capabilities_type = ($sms_type === 'plain' || $sms_type === 'unicode') ? 'sms' : $sms_type;

            if ($user->customer->getOption('sender_id_verification') === 'yes') {
                $number = PhoneNumbers::where('user_id', $user->id)
                    ->where('number', $senderId)
                    ->where('status', 'assigned')
                    ->first();

                if ( ! $number) {
                    return redirect()->route('customer.chatbox.new')->with([
                        'status'  => 'error',
                        'message' => __('locale.sender_id.sender_id_invalid', ['sender_id' => $senderId]),
                    ]);
                }

                $capabilities = str_contains($number->capabilities, $capabilities_type);

                if ( ! $capabilities) {
                    return redirect()->route('customer.chatbox.new')->with([
                        'status'  => 'error',
                        'message' => __('locale.sender_id.sender_id_sms_capabilities', ['sender_id' => $senderId, 'type' => $db_sms_type]),
                    ]);
                }

                $input['originator']   = 'phone_number';
                $input['phone_number'] = $senderId;
            }

            $input['reply_by_customer'] = true;

            $data = $this->campaigns->quickSend($campaign, $input, true);

            if (isset($data->getData()->status)) {
                // RFC-005 Milestone 5 §6.1 — 'retain' redirects back to
                // the compose screen carrying the exact same token, so a
                // legitimate follow-up retry resolves against the same
                // still-open reservation; 'clear' (or no m5_token_action
                // at all, e.g. a fully legacy send) uses the existing,
                // unmodified index redirect with no such parameter.
                if (($data->getData()->m5_token_action ?? 'clear') === 'retain') {
                    // §6.1 UI addition — restores the original send-defining
                    // form values (sending_server, country_code, sender_id,
                    // recipient, message) via withInput() so a legitimate
                    // human retry does not need to re-enter them. This is a
                    // UI convenience only: §6 step 0's server-side rule
                    // remains authoritative regardless of what the client
                    // submits on retry.
                    return redirect()->route('customer.chatbox.new', ['m5_retry_token' => $input['idempotency_token'] ?? null])->withInput()->with([
                        'status'  => $data->getData()->status,
                        'message' => $data->getData()->message,
                    ]);
                }

                return redirect()->route('customer.chatbox.index')->with([
                    'status'  => $data->getData()->status,
                    'message' => $data->getData()->message,
                ]);
            }

            return redirect()->route('customer.chatbox.new')->with([
                'status'  => 'error',
                'message' => __('locale.exceptions.something_went_wrong'),
            ]);
        }

        /**
         * get chat messages
         */
        public function messages($id)
{
    $box = \DB::table('chat_boxes')->where('id', $id)->first();

    if (!$box) {
        return response()->json([
            'status' => 'error',
            'data' => [],
            'pinned' => 0
        ]);
    }

    $messages = \DB::table('chat_box_messages')
        ->where('box_id', $id)
        ->orderBy('created_at', 'asc')
        ->get();

    return response()->json([
        'status' => 'success',
        'data' => $messages,
        'pinned' => $box->pinned ?? 0
    ]);
}

        /**
         * get chat messages
         */
        public function messagesWithNotification(ChatBox $box): JsonResponse
        {
            $data = ChatBoxMessage::where('box_id', $box->id)->select('message', 'direction', 'media_url', 'box_id', 'created_at')->latest()->first()->toJson();


            return response()->json([
                'status'       => 'success',
                'data'         => $data,
                'notification' => $box->notification,
            ]);

        }

        /**
         * reply message
         *
         *
         * @throws AuthorizationException
         * @throws NumberParseException
         */
public function reply($id, Campaigns $campaign, Request $request): JsonResponse
{
  $box = ChatBox::find($id);

if (!$box) {
    return response()->json([
        'status' => 'error',
        'message' => 'Chat box not found. Refresh page.'
    ], 404);
}
            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $this->authorize('chat_box');

            if (empty($request->message)) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.campaigns.insert_your_message'),
                ]);
            }

            $user      = Auth::user();
            $sender_id = $box->from;


            if ($user->customer->getOption('send_spam_message') == 'no') {
                $spamWords = SpamWord::whereRaw("LOWER(?) LIKE CONCAT('%', LOWER(word), '%')", [$request->input('message')])->get();
                if ($spamWords->isNotEmpty()) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'Your message contains spam words.',
                    ]);
                }
            }


            $input = [
                'sender_id'    => $sender_id,
                'originator'   => 'phone_number',
                'sms_type'     => 'plain',
                'message'      => $request->message,
                'exist_c_code' => 'yes',
                'user'         => $user,
            ];

            // RFC-005 Milestone 5 §7 — reply() has no dedicated Form
            // Request (unlike sent()/SentRequest), so the idempotency
            // token is read and validated inline, fail-closed: a missing
            // or invalid token must never silently downgrade this reply
            // to legacy sms_unit billing — it must never reach
            // quickSend()/the provider at all.
            if (! $request->filled('idempotency_token') || ! \Illuminate\Support\Str::isUuid($request->input('idempotency_token'))) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('locale.exceptions.something_went_wrong'),
                ], 422);
            }

            $input['idempotency_token'] = $request->input('idempotency_token');

            if ($request->hasFile('media_image')) {

                $v = Validator::make($request->all(), [
                    'media_image' => 'required|mimes:mp4,mov,ogg,qt,jpeg,png,jpg,gif,bmp,webp|max:20000',
                ]);

                if ($v->fails()) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => $v->errors()->first(),
                    ]);
                }

                $input['media_url'] = Tool::uploadImage($request->file('media_image'));
                $input['sms_type']  = 'mms';
            }

           if ($box->sending_server_id) {
    $sending_server = SendingServer::where('status', true)
        ->where('id', $box->sending_server_id)
        ->first();

    \Log::info('CHATBOX SENDING SERVER', [
        'box_server_id' => $box->sending_server_id,
        'found' => $sending_server ? true : false
    ]);

    if (!$sending_server) {
        return response()->json([
            'status' => 'error',
            'message' => 'Sending server not found'
        ]);
    }

    $input['sending_server'] = $sending_server->id;
}

            if ($user->customer->getOption('sender_id_verification') == 'yes') {

                $number = PhoneNumbers::where('user_id', $user->id)->where('number', $sender_id)->where('status', 'assigned')->first();

                if ( ! $number) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => __('locale.sender_id.sender_id_invalid', ['sender_id' => $sender_id]),
                    ]);
                }

                $capabilities = str_contains($number->capabilities, 'sms');

                if ( ! $capabilities) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => __('locale.sender_id.sender_id_sms_capabilities', ['sender_id' => $sender_id, 'type' => 'sms']),
                    ]);
                }

                $input['originator']   = 'phone_number';
                $input['phone_number'] = $sender_id;

            }

     try {

    $phoneUtil = PhoneNumberUtil::getInstance();

    $cleanTo = ltrim($box->to, '+');

    \Log::info('CHATBOX RAW TO', [
        'box_to' => $box->to,
        'clean_to' => $cleanTo
    ]);

    $phoneNumberObject = $phoneUtil->parse('+' . $cleanTo);

    $countryCode = $phoneNumberObject->getCountryCode();
    $regionCode  = $phoneUtil->getRegionCodeForNumber($phoneNumberObject);

    if ($phoneUtil->isPossibleNumber($phoneNumberObject) && !empty($countryCode) && !empty($regionCode)) {

        $input['country_code'] = $countryCode;
        $input['recipient']    = $phoneNumberObject->getNationalNumber();
        $input['region_code']  = $regionCode;

        $data = $this->campaigns->quickSend($campaign, $input, true);


        \Log::info('CHATBOX QUICKSEND RESPONSE', [
    'response' => $data->getData()
]);

        if (isset($data->getData()->status)) {

            if ($data->getData()->status == 'success') {
                return response()->json([
                    'status'    => 'success',
                    'message'   => __('locale.campaigns.message_successfully_delivered'),
                    'media_url' => $data->getData()->data->media_url ?? null,
                ]);
            }

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
        'message' => __('locale.customer.invalid_phone_number', ['phone' => $box->to]),
    ]);

} catch (NumberParseException $exception) {

    \Log::info('CHATBOX PARSE ERROR', [
        'error' => $exception->getMessage(),
        'box_to' => $box->to
    ]);

    return response()->json([
        'status' => 'error',
        'message' => 'Invalid phone number parse'
    ]);
}
        }

        /**
         * delete chatbox messages
         */
        public function delete(ChatBox $box): JsonResponse
        {
            $messages = ChatBoxMessage::where('box_id', $box->id)->delete();
            if ($messages) {
                $box->delete();

                return response()->json([
                    'status'  => 'success',
                    'message' => __('locale.campaigns.sms_was_successfully_deleted'),
                ]);
            }

            return response()->json([
                'status'  => 'error',
                'message' => __('locale.exceptions.something_went_wrong'),
            ]);
        }

        /**
         * add to blacklist
         */
        public function block(ChatBox $box): JsonResponse
        {
            $status = Blacklists::create([
                'user_id' => auth()->user()->id,
                'number'  => $box->to,
                'reason'  => 'Blacklisted by ' . auth()->user()->displayName(),
            ]);

            if ($status) {

                $contact = Contacts::where('phone', $box->to)->first();
                $contact?->update([
                    'status' => 'unsubscribe',
                ]);

                return response()->json([
                    'status'  => 'success',
                    'message' => __('locale.blacklist.blacklist_successfully_added'),
                ]);
            }

            return response()->json([
                'status'  => 'error',
                'message' => __('locale.exceptions.something_went_wrong'),
            ]);
        }

        /**
         * @throws Throwable
         */
        public function loadChatUsers(Request $request)
        {
            $filter = $request->get('filter', 'recents');
            $search = $request->get('search', '');
            $page   = $request->get('page', 1);

// Start the base query
            $query = ChatBox::where('user_id', Auth::id())->where('pinned', false);

// Apply the filter using switch
            switch ($filter) {
                case 'unread':
                    $query->where('notification', '!=', 0);
                    break;
                case 'read':
                    $query->where('notification', 0);
                    break;
                case 'recents':
                    $query->orderBy('updated_at', 'desc');
                    break;
                // 'all' case or any other value does not modify the query
            }

// Apply the search if provided
            if ( ! empty($search)) {
                $query->where(function ($q) use ($search) {
                    $q->where('from', 'LIKE', "%{$search}%")
                        ->orWhere('to', 'LIKE', "%{$search}%");
                });
            }

            $query->with(['chatBoxMessages', 'contact']);


// Paginate the results, limiting to 50 per page
            $chat_box = $query->paginate(50, ['*'], 'page', $page);


            return view('customer.ChatBox.partials._chat_list', compact('chat_box'))->render();
        }


        /**
         * add to blacklist
         */
        public function pin(ChatBox $box): JsonResponse
        {
            $box->update([
                'pinned' => ! $box->pinned,
            ]);

            return response()->json([
                'status'  => 'success',
                'message' => __('locale.labels.added_to_pinned'),
            ]);
        }

    }

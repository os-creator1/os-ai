<?php

    namespace App\Http\Controllers\Customer;

    use App\Events\MessageReceived;
    use App\Http\Controllers\Controller;
    use App\Library\Business\LegacyBusinessResolver;
    use App\Library\SMSCounter;
    use App\Library\SpinText;
    use App\Models\Blacklists;
    use App\Models\Campaigns;
    use App\Models\ChatBox;
    use App\Models\ChatBoxMessage;
    use App\Models\ContactGroups;
    use App\Models\Contacts;
    use App\Models\Country;
    use App\Models\CustomerBasedPricingPlan;
    use App\Models\Keywords;
    use App\Models\Notifications;
    use App\Models\PhoneNumbers;
    use App\Models\PlansCoverageCountries;
    use App\Models\Reports;
    use App\Models\SendingServer;
    use App\Models\User;
    use App\Repositories\Eloquent\EloquentCampaignRepository;
    use Exception;
    use Giggsey\Locale\Locale;
    use Illuminate\Http\JsonResponse;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Http;
    use libphonenumber\NumberParseException;
    use libphonenumber\PhoneNumberUtil;
    use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
    use Throwable;
    use Twilio\TwiML\Messaging\Message;
    use Twilio\TwiML\MessagingResponse;

    class DLRController extends Controller
    {
        /**
         * update dlr
         */
        public static function updateDLR($message_id, $status): JsonResponse
        {

            $status = ucfirst(strtolower($status));

            $customer_status = match (strtoupper($status)) {
                // Delivered
                'DELIVERED', 'DELIVRD', 'SENT' => 'Delivered',

                // Undelivered
                'UNDELIVERABLE', 'UNDELIV' => 'Undelivered',

                // Expired
                'EXPIRED', 'DELETED' => 'Expired',

                // Enroute
                'ENROUTE', 'ATES' => 'Enroute',

                // Skipped
                'SKIPPED' => 'Skipped',

                // Rejected
                'REJECTED', 'REJECTD' => 'Rejected',

                // Accepted
                'ACCEPTED', 'ACCEPTD' => 'Accepted',

                // Default
                default => 'Failed',
            };


            $get_data = Reports::whereLike(['status'], $message_id)->first();

            if ( ! $get_data) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Message not found',
                ]);
            }

            $get_data->update(['status' => $status . '|' . $message_id, 'customer_status' => $customer_status]);

            if ($get_data->campaign_id) {
                Campaigns::find($get_data->campaign_id)->updateCache();
            }

            if ($status !== 'Delivered') {
                $get_data->user->update([
                    'sms_unit' => $get_data->user->sms_unit + $get_data->cost,
                ]);
            }

            return response()->json([
                'status'  => 'success',
                'message' => $status . ' | ' . $message_id,
            ]);

        }

        /**
         *twilio dlr
         *
         *
         * @return string|void
         */
        public function dlrTwilio(Request $request)
        {
            $message_id = $request->input('MessageSid');
            $status     = $request->input('MessageStatus');

            if ( ! isset($message_id) && ! isset($status)) {
                return 'Message ID and status not found';
            }

            if ($status == 'delivered' || $status == 'sent') {
                $status = 'Delivered';
            }

            $this::updateDLR($message_id, $status);

        }

        /**
         * Route mobile DLR
         *
         *
         * @return string|void
         */
        public function dlrRouteMobile(Request $request)
        {
            $message_id = $request->input('sMessageId');
            $status     = $request->input('sStatus');
            $sender_id  = $request->input('sSender');
            $phone      = $request->input('sMobileNo');

            if ( ! isset($message_id) && ! isset($status)) {
                return 'Message ID and status not found';
            }

            if ($status == 'DELIVRD' || $status == 'ACCEPTED') {
                $status = 'Delivered';
            }

            $this::updateDLR($message_id, $status, $sender_id, $phone);
        }

        /**
         * text local DLR
         *
         *
         * @return string|void
         */
        public function dlrTextLocal(Request $request)
        {
            $message_id = $request->input('customID');
            $status     = $request->input('status');
            $phone      = $request->input('number');

            if ( ! isset($message_id) && ! isset($status)) {
                return 'Message ID and status not found';
            }

            $status = match ($status) {
                'D' => 'Delivered',
                'U' => 'Undelivered',
                'P' => 'Pending',
                'I' => 'Invalid',
                'E' => 'Expired',
                default => 'Unknown',
            };

            $this::updateDLR($message_id, $status, null, $phone);
        }

        /**
         * Plivo DLR
         *
         *
         * @return string|void
         */
        public function dlrPlivo(Request $request)
        {
            $message_id = $request->input('MessageUUID');
            $status     = $request->input('Status');
            $phone      = $request->input('To');
            $sender_id  = $request->input('From');

            if ( ! isset($message_id) && ! isset($status)) {
                return 'Message ID and status not found';
            }

            if ($status == 'delivered' || $status == 'sent') {
                $status = 'Delivered';
            }

            $this::updateDLR($message_id, $status, $phone, $sender_id);
        }

        /**
         * SMS Global DLR
         *
         *
         * @return string|void
         */
        public function dlrSMSGlobal(Request $request)
        {
            $message_id = $request->input('msgid');
            $status     = $request->input('dlrstatus');

            if ( ! isset($message_id) && ! isset($status)) {
                return 'Message ID and status not found';
            }

            if ($status == 'DELIVRD') {
                $status = 'Delivered';
            }

            $this::updateDLR($message_id, $status);
        }

        /**
         * Advance Message System Delivery reports
         *
         *
         * @return string|void
         */
        public function dlrAdvanceMSGSys(Request $request)
        {
            $message_id = $request->get('MessageId');
            $status     = $request->get('Status');
            $phone      = $request->get('Destination');
            $sender_id  = $request->get('Source');

            if ( ! isset($message_id) && ! isset($status)) {
                return 'Message ID and status not found';
            }

            if ($status == 'DELIVRD') {
                $status = 'Delivered';
            }

            $this::updateDLR($message_id, $status, $phone, $sender_id);
        }

        /**
         * nexmo now Vonage DLR
         *
         *
         * @return string|void
         */
        public function dlrVonage(Request $request)
        {
            $message_id = $request->input('messageId');
            $status     = $request->input('status');
            $phone      = $request->input('msisdn');
            $sender_id  = $request->input('to');

            if ( ! isset($message_id) && ! isset($status)) {
                return 'Message ID and status not found';
            }

            if ($status == 'delivered' || $status == 'accepted') {
                $status = 'Delivered';
            }

            $this::updateDLR($message_id, $status, $phone, $sender_id);
        }

        /**
         * infobip DLR
         */
        public function dlrInfobip(Request $request)
        {
            $get_data = $request->getContent();

            $get_data = json_decode($get_data, true);
            if (isset($get_data) && is_array($get_data) && array_key_exists('results', $get_data)) {
                $message_id = $get_data['results']['0']['messageId'];

                foreach ($get_data['results'] as $msg) {

                    if (isset($msg['status']['groupName'])) {

                        $status = $msg['status']['groupName'];

                        if ($status == 'DELIVERED') {
                            $status = 'Delivered';
                        }

                        $this::updateDLR($message_id, $status);
                    }

                }
            }
        }

        public function dlrEasySendSMS(Request $request)
        {
            $message_id = $request->input('sms_id');
            $status     = $request->input('response');
            $phone      = $request->input('msisdn');
            $sender_id  = $request->input('source');

            if ( ! isset($message_id) && ! isset($status)) {
                return 'Message ID and status not found';
            }

            if ($status == 'DELIVRD') {
                $status = 'Delivered';
            }

            $this::updateDLR($message_id, $status, $phone, $sender_id);

            return $status;
        }

        /**
         * AfricasTalking delivery reports
         *
         *
         * @return string|void
         */
        public function dlrAfricasTalking(Request $request)
        {
            $message_id = $request->input('id');
            $status     = $request->input('status');
            $phone      = str_replace(['(', ')', '+', '-', ' '], '', $request->input('phoneNumber'));

            if ( ! isset($message_id) && ! isset($status)) {
                return 'Message ID and status not found';
            }

            if ($status == 'Success') {
                $status = 'Delivered';
            }

            $this::updateDLR($message_id, $status, $phone);
        }

        /**
         * 1s2u delivery reports
         *
         *
         * @return string|void
         */
        public function dlr1s2u(Request $request)
        {
            $message_id = $request->input('msgid');
            $status     = $request->input('status');
            $phone      = str_replace(['(', ')', '+', '-', ' '], '', $request->input('mno'));
            $sender_id  = $request->input('sid');

            if ( ! isset($message_id) && ! isset($status)) {
                return 'Message ID and status not found';
            }

            if ($status == 'DELIVRD') {
                $status = 'Delivered';
            }

            $this::updateDLR($message_id, $status, $phone, $sender_id);
        }

        /**
         * dlrKeccelSMS delivery reports
         *
         *
         * @return string|void
         */
        public function dlrKeccelSMS(Request $request)
        {
            $message_id = $request->input('messageID');
            $status     = $request->input('status');

            if ( ! isset($message_id) && ! isset($status)) {
                return 'Message ID and status not found';
            }

            if ($status == 'DELIVERED') {
                $status = 'Delivered';
            }

            $this::updateDLR($message_id, $status);
        }

        /**
         * dlrGatewayApi delivery reports
         *
         *
         * @return string|void
         */
        public function dlrGatewayApi(Request $request)
        {

            $message_id = $request->input('id');
            $status     = $request->input('status');
            $phone      = str_replace(['(', ')', '+', '-', ' '], '', $request->input('msisdn'));

            if ( ! isset($message_id) && ! isset($status)) {
                return 'Message ID and status not found';
            }

            if ($status == 'DELIVRD' || $status == 'DELIVERED') {
                $status = 'Delivered';
            } else {
                $status = ucfirst(strtolower($status));
            }

            $this::updateDLR($message_id, $status, $phone);
        }

        /**
         * bulk sms delivery reports
         */
        public function dlrBulkSMS(Request $request)
        {

            logger($request->all());

        }

        /**
         * SMSVas delivery reports
         */
        public function dlrSMSVas(Request $request)
        {

            logger($request->all());

        }

        /**
         * receive inbound message
         *
         * @param null $from
         * @param null $media_url
         *
         * @throws NumberParseException
         * @throws Throwable
         */
        public static function inboundDLR($to, $message, $sending_server, $cost, $from = null, $media_url = null, int $user_id = 1): JsonResponse|string
        {
            
     
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry!! This option is not available in demo mode',
                ]);
            }

            if ( ! $sending_server) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Active gateway/sending server not found',
                ]);
            }

            $to   = str_replace(['(', ')', '+', '-', ' '], '', trim($to));
            $from = ($from != null) ? str_replace(['(', ')', '+', '-', ' '], '', trim($from)) : null;

            $phoneNumberUtil   = PhoneNumberUtil::getInstance();
            $phoneNumberObject = $phoneNumberUtil->parse('+' . $to);
            $country_code      = $phoneNumberObject->getCountryCode();
            $iso_code          = $phoneNumberUtil->getRegionCodeForNumber($phoneNumberObject);

            if ($phoneNumberObject->isItalianLeadingZero()) {
                $phone = '0' . preg_replace("/^$country_code/", '', $phoneNumberObject->getNationalNumber());
            } else {
                $phone = preg_replace("/^$country_code/", '', $phoneNumberObject->getNationalNumber());
            }

            $success = 'Success';
            $failed  = null;

            $sms_type = ($media_url) ? 'mms' : (($sending_server->type == 'whatsapp') ? 'whatsapp' : 'plain');

            $sms_counter  = new SMSCounter();
            $message_data = $sms_counter->count($message, $sms_type == 'whatsapp' ? 'WHATSAPP' : null);
            $sms_count    = $message_data->messages;

            $phone_number = PhoneNumbers::where('number', $from)
                ->where('status', 'assigned')
                ->first();

            if ( ! $phone_number) {
                $phone_number = PhoneNumbers::where('number', 'like', "%$from%")
                    ->where('status', 'assigned')
                    ->first();
            }

            if ($phone_number) {
                $user_id = $phone_number->user_id;
                $user    = User::find($user_id);


                Reports::create([
                    'user_id'           => $user_id,
                    'business_id'       => app(LegacyBusinessResolver::class)->resolveForCustomer((int) $user_id)?->id,
                    'from'              => $from,
                    'to'                => $to,
                    'message'           => $message,
                    'sms_type'          => $sms_type,
                    'status'            => 'Delivered',
                    'customer_status'   => 'Delivered',
                    'direction'         => Reports::DIRECTION_INCOMING,
                    'cost'              => $cost,
                    'sms_count'         => $sms_count,
                    'media_url'         => $media_url,
                    'sending_server_id' => $sending_server->id,
                ]);

                $chatBox = ChatBox::updateOrCreate(
                    [
                        'user_id' => $user_id,
                        'from'    => $from,
                        'to'      => $to,
                    ],
                    [
                        'reply_by_customer' => true,
                        'sending_server_id' => $sending_server->id,
                    ]
                );
                
                
                
                
                
                
                
                
                
                
                
                // ✅ FORCE updated_at = NOW() AFTER inbound
$chatBox->touch();
                
                
                
         
                
                
                
                
                
                
                
                

                if ($chatBox) {
                    $chatBox->update([
                        'notification' => $chatBox->notification + 1,
                    ]);

                    Notifications::create([
                        'user_id'           => $user_id,
                        'notification_for'  => 'customer',
                        'notification_type' => 'chatbox',
                        'message'           => 'New chat message arrived',
                    ]);

                   try {

    \Log::info('=== BEFORE ChatBoxMessage::create ===', [
        'chatbox_id' => $chatBox->id,
        'user_id' => $user_id,
        'from' => $from,
        'to' => $to,
        'sending_server_id' => $sending_server->id,
        'message' => $message,
    ]);

    ChatBoxMessage::create([
        'box_id'            => $chatBox->id,
        'message'           => $message,
        'media_url'         => $media_url,
        'sms_type'          => $sms_type,
        'direction'         => Reports::DIRECTION_INCOMING,
        'sending_server_id' => $sending_server->id,
    ]);

    \Log::info('=== AFTER ChatBoxMessage::create ===');

} catch (\Throwable $e) {

    \Log::error('=== ChatBoxMessage FAILED ===', [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString(),
    ]);

    throw $e;
}
                    
                    
                DB::table('chat_boxes')
    ->where('id', $chatBox->id)
    ->update([
        'reply_by_customer' => 1,
        'ai_replied' => 0
    ]);

                
                
                    
                    
                    
                    

                    event(new MessageReceived($user, $message, $chatBox));
                    //  $user->notify(new \App\Notifications\MessageReceived($message, $to));

                    if (isset($user->webhook_url)) {
                        $countryName = Locale::getDisplayRegion('-' . $iso_code, 'en');
                        // Prepare data to send to the webhook
                        $webhookData = [
                            'to'           => $from,
                            'from'         => $to,
                            'content'      => $message,
                            'country'      => $iso_code,
                            'country_name' => $countryName,
                        ];

                        $response = Http::post($user->webhook_url, $webhookData);

                        if ($response->failed()) {
                            $failed .= 'Failed to forward SMS to webhook';
                        }

                    }
                } else {
                    $failed .= 'Failed to create chat message ';
                }

                $country = Country::where('country_code', $country_code)
                    ->where('iso_code', $iso_code)
                    ->where('status', 1)
                    ->first();

                if ( ! $country) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => "Permission to send an SMS has not been enabled for the region indicated by the 'To' number: " . $to,
                    ]);
                }

                $coverage = CustomerBasedPricingPlan::where('user_id', $user->id)
                    ->whereHas('country', function ($query) use ($country_code, $iso_code) {
                        $query->where('country_code', $country_code, $iso_code)
                            ->where('iso_code', $iso_code)
                            ->where('status', 1);
                    })
                    ->with('sendingServer')
                    ->first();

                if ( ! $coverage) {
                    $coverage = PlansCoverageCountries::where(function ($query) use ($user, $country_code, $iso_code) {
                        $query->whereHas('country', function ($query) use ($country_code, $iso_code) {
                            $query->where('country_code', $country_code, $iso_code)
                                ->where('iso_code', $iso_code)
                                ->where('status', 1);
                        })->where('plan_id', $user->customer->activeSubscription()->plan_id);
                    })
                        ->with('sendingServer')
                        ->first();
                }

                if ( ! $coverage) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => "Permission to send an SMS has not been enabled for the region indicated by the 'To' number: " . $to,
                    ]);
                }

                if ($user->is_customer && $user->sms_unit != '-1') {

                    $priceOption = json_decode($coverage->options, true);
                    $unit_price  = $priceOption['receive_plain_sms'];

                    $cost = $sms_count * $unit_price;

                    if ($cost > $user->sms_unit) {
                        return __('locale.campaigns.not_enough_balance', [
                            'current_balance' => $user->sms_unit,
                            'campaign_price'  => $cost,
                        ]);
                    }

                    DB::transaction(function () use ($user, $cost) {
                        $remaining_balance = $user->sms_unit - $cost;
                        $user->update(['sms_unit' => $remaining_balance]);
                    });
                }

                //check keywords
                $keyword = Keywords::where('user_id', $user_id)
                    ->select('*')
                    ->selectRaw('lower(keyword_name) as keyword,keyword_name')
                    ->where('keyword_name', strtolower($message))
                    ->where('status', 'assigned')->first();

                if ($keyword) {

                    $optInContacts = ContactGroups::with('optinKeywords')
                        ->whereHas('optinKeywords', function ($query) use ($message) {
                            $query->where('keyword', $message);
                        })
                        ->where('customer_id', $user_id)
                        ->get();

                    $optOutContacts = ContactGroups::with('optoutKeywords')
                        ->whereHas('optoutKeywords', function ($query) use ($message) {
                            $query->where('keyword', $message);
                        })
                        ->where('customer_id', $user_id)
                        ->get();

                    $blacklist = Blacklists::where('user_id', $user_id)->where('number', $to)->first();

                    if ($optInContacts->count()) {
                        foreach ($optInContacts as $contact) {

                            $exist = Contacts::where('group_id', $contact->id)->where('phone', $to)->first();

                            $blacklist?->delete();

                            if ( ! $exist) {
                                $data = Contacts::create([
                                    'customer_id' => $user_id,
                                    'business_id' => app(LegacyBusinessResolver::class)->resolveForCustomer((int) $user_id)?->id,
                                    'group_id'    => $contact->id,
                                    'phone'       => $to,
                                    'status'      => 'subscribe',
                                ]);

                                if ($data) {

                                    $sendMessage = new EloquentCampaignRepository($campaign = new Campaigns());

                                    if ($contact->send_keyword_message) {
                                        if (isset($keyword->reply_text)) {

                                            $spinTax         = new SpinText();
                                            $keyword_message = $spinTax->process($keyword->reply_text);

                                            $sendMessage->quickSend($campaign, [
                                                'phone_number'   => $keyword->sender_id,
                                                'sender_id'      => $keyword->sender_id,
                                                'originator'     => 'phone_number',
                                                'sms_type'       => $sms_type,
                                                'message'        => $keyword_message,
                                                'recipient'      => $phone,
                                                'user'           => $user,
                                                'country_code'   => $country_code,
                                                'sending_server' => $sending_server->id,
                                                'region_code'    => $iso_code,
                                            ]);

                                        }
                                    } else {
                                        if ($contact->send_welcome_sms && $contact->welcome_sms) {

                                            $sendMessage->quickSend($campaign, [
                                                'phone_number'   => $contact->sender_id,
                                                'sender_id'      => $contact->sender_id,
                                                'originator'     => 'phone_number',
                                                'sms_type'       => $sms_type,
                                                'message'        => $contact->welcome_sms,
                                                'recipient'      => $phone,
                                                'user'           => $user,
                                                'country_code'   => $country_code,
                                                'sending_server' => $sending_server->id,
                                                'region_code'    => $iso_code,
                                            ]);
                                        }
                                    }

                                    $contact->updateCache();
                                } else {
                                    $failed .= 'Failed to subscribe contact list';
                                }
                            } else {
                                $sendMessage = new EloquentCampaignRepository($campaign = new Campaigns());

                                $sendMessage->quickSend($campaign, [
                                    'phone_number'   => $keyword->sender_id,
                                    'sender_id'      => $keyword->sender_id,
                                    'sms_type'       => $sms_type,
                                    'message'        => __('locale.contacts.you_have_already_subscribed', ['contact_group' => $contact->name]),
                                    'country_code'   => $country_code,
                                    'originator'     => 'phone_number',
                                    'recipient'      => $phone,
                                    'user'           => $user,
                                    'sending_server' => $sending_server->id,
                                    'region_code'    => $iso_code,
                                ]);

                                $exist->update([
                                    'status' => 'subscribe',
                                ]);
                            }

                        }
                    } else if ($optOutContacts->count()) {

                        foreach ($optOutContacts as $contact) {

                            if ( ! $blacklist) {
                                $exist = Contacts::where('group_id', $contact->id)->where('phone', $to)->first();
                                if ($exist) {

                                    $chatbox_messages = ChatBox::where('user_id', $user_id)->where('to', $to)->get();
                                    foreach ($chatbox_messages as $messages) {
                                        $check_delete = ChatBoxMessage::where('box_id', $messages->id)->delete();
                                        if ($check_delete) {
                                            $messages->delete();
                                        }
                                    }

                                    $sendMessage = new EloquentCampaignRepository($campaign = new Campaigns());

                                    if (isset($contact->send_keyword_message)) {
                                        if (isset($keyword->reply_text)) {
                                            $spinTax         = new SpinText();
                                            $keyword_message = $spinTax->process($keyword->reply_text);

                                            $sendMessage->quickSend($campaign, [
                                                'phone_number'   => $keyword->sender_id,
                                                'sender_id'      => $keyword->sender_id,
                                                'originator'     => 'phone_number',
                                                'sms_type'       => $sms_type,
                                                'message'        => $keyword_message,
                                                'recipient'      => $phone,
                                                'user'           => $user,
                                                'country_code'   => $country_code,
                                                'sending_server' => $sending_server->id,
                                                'region_code'    => $iso_code,
                                            ]);
                                        }
                                    } else {
                                        if ($contact->unsubscribe_notification && $contact->unsubscribe_sms) {

                                            $sendMessage->quickSend($campaign, [
                                                'phone_number'   => $contact->sender_id,
                                                'sender_id'      => $contact->sender_id,
                                                'originator'     => 'phone_number',
                                                'sms_type'       => $sms_type,
                                                'message'        => $contact->unsubscribe_sms,
                                                'recipient'      => $phone,
                                                'user'           => $user,
                                                'country_code'   => $country_code,
                                                'sending_server' => $sending_server->id,
                                                'region_code'    => $iso_code,
                                            ]);
                                        }
                                    }

                                    $data = $exist->update([
                                        'status' => 'unsubscribe',
                                    ]);
                                    if ($data) {
                                        Blacklists::create([
                                            'user_id'     => $user_id,
                                            'business_id' => app(LegacyBusinessResolver::class)->resolveForCustomer((int) $user_id)?->id,
                                            'number'      => $to,
                                            'reason'      => 'Optout by User',
                                        ]);
                                    }
                                }
                            }
                        }
                    } else {

                        if (isset($keyword->reply_text)) {

                            $spinTax         = new SpinText();
                            $keyword_message = $spinTax->process($keyword->reply_text);

                            $sendMessage = new EloquentCampaignRepository($campaign = new Campaigns());
                            $sendMessage->quickSend($campaign, [
                                'phone_number'   => $keyword->sender_id,
                                'sender_id'      => $keyword->sender_id,
                                'originator'     => 'phone_number',
                                'sms_type'       => $sms_type,
                                'message'        => $keyword_message,
                                'recipient'      => $phone,
                                'user'           => $user,
                                'country_code'   => $country_code,
                                'sending_server' => $sending_server->id,
                                'region_code'    => $iso_code,
                            ]);
                        } else {
                            $failed .= 'Related keyword reply message not found.';
                        }
                    }
                }

            } else {

                Reports::create([
                    'user_id'           => $user_id,
                    'business_id'       => app(LegacyBusinessResolver::class)->resolveForCustomer((int) $user_id)?->id,
                    'from'              => $from,
                    'to'                => $to,
                    'message'           => $message,
                    'sms_type'          => $sms_type,
                    'status'            => 'Delivered',
                    'customer_status'   => 'Delivered',
                    'direction'         => Reports::DIRECTION_INCOMING,
                    'cost'              => $cost,
                    'sms_count'         => $sms_count,
                    'media_url'         => $media_url,
                    'sending_server_id' => $sending_server->id,
                ]);
            }


            if (strtolower($message) == 'stop') {
                $blacklist = Blacklists::where('user_id', $user_id)
                    ->where('number', $to)
                    ->first();

                if ( ! $blacklist) {
                    Blacklists::create([
                        'user_id'     => $user_id,
                        'business_id' => app(LegacyBusinessResolver::class)->resolveForCustomer((int) $user_id)?->id,
                        'number'      => $to,
                        'reason'      => 'Optout by User',
                    ]);

                    ChatBox::where('user_id', $user_id)
                        ->where('to', $to)
                        ->delete();

                    ChatBoxMessage::whereHas('chatBox', function ($query) use ($user_id, $to) {
                        $query->where('user_id', $user_id)
                            ->where('to', $to);
                    })->delete();
                }
            }

            if ($failed == null) {
                return $success;
            }

            return $failed;
        }

        private function getSendingServer(string $gateway, string $type)
        {
            $query = SendingServer::query()->where('status', true);

            $column = $type === 'uid' ? 'uid' : 'settings';

            return $query->where($column, $gateway)->first();
        }


        /**
         * twilio inbound sms
         *
         *
         * @throws NumberParseException
         * @throws Throwable
         */
        public function inboundTwilio(Request $request, $gateway = null): Message|MessagingResponse
        {
            $to      = $request->input('From');
            $from    = $request->input('To');
            $message = $request->input('Body');

            if ($message == 'NULL') {
                $message = null;
            }

            $response = new MessagingResponse();

            if ($to == null || $from == null) {
                $response->message('From and To value required');

                return $response;
            }

            $feedback = 'Success';


            $sendingServer = $this->getSendingServer(
                $gateway ?: SendingServer::TYPE_TWILIO,
                $gateway ? 'uid' : SendingServer::TYPE_TWILIO
            );

            $NumMedia = (int) $request->input('NumMedia');
            if ($NumMedia > 0) {
                $cost = 1;
                for ($i = 0; $i < $NumMedia; $i++) {
                    $mediaUrl = $request->input("MediaUrl$i");
                    $feedback = $this::inboundDLR($to, $message, $sendingServer, $cost, $from, $mediaUrl);
                }
            } else {
                $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
                $cost          = ceil($message_count);

                $feedback = $this::inboundDLR($to, $message, $sendingServer, $cost, $from);
            }

            if ($feedback == 'Success') {
                return $response;
            }

            return $response->message($feedback);
        }

        /**
         * twilio inbound sms
         *
         *
         * @throws NumberParseException
         * @throws Throwable
         */
        public function inboundDemo(Request $request, $gateway = null): Message|MessagingResponse
        {
            $to      = $request->input('From');
            $from    = $request->input('To');
            $message = $request->input('Body');

            if ($message == 'NULL') {
                $message = null;
            }

            $response = new MessagingResponse();

            if ($to == null || $from == null) {
                $response->message('From and To value required');

                return $response;
            }

            $feedback = 'Success';


            $sendingServer = $this->getSendingServer(
                $gateway ?: SendingServer::TYPE_FORDEMO,
                $gateway ? 'uid' : SendingServer::TYPE_FORDEMO
            );


            $NumMedia = (int) $request->input('NumMedia');
            if ($NumMedia > 0) {
                $cost = 1;
                for ($i = 0; $i < $NumMedia; $i++) {
                    $mediaUrl = $request->input("MediaUrl$i");
                    $feedback = $this::inboundDLR($to, $message, $sendingServer, $cost, $from, $mediaUrl);
                }
            } else {
                $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
                $cost          = ceil($message_count);

                $feedback = $this::inboundDLR($to, $message, $sendingServer, $cost, $from);
            }

            if ($feedback == 'Success') {
                return $response;
            }

            return $response->message($feedback);
        }

        /**
         * TwilioCopilot inbound sms
         *
         *
         * @throws NumberParseException
         * @throws Throwable
         */
        public function inboundTwilioCopilot(Request $request, $gateway = null): Message|MessagingResponse
        {
            $to      = $request->input('From');
            $from    = $request->input('To');
            $message = $request->input('Body');
            $extra   = $request->input('MessagingServiceSid');

            if ($message == 'NULL') {
                $message = null;
            }

            $response = new MessagingResponse();

            if ($to == null || $from == null || $extra == null) {
                $response->message('From, To, and MessagingServiceSid value required');

                return $response;
            }

            $feedback = 'Success';

            $sendingServer = $this->getSendingServer(
                $gateway ?: SendingServer::TYPE_TWILIOCOPILOT,
                $gateway ? 'uid' : SendingServer::TYPE_TWILIOCOPILOT
            );


            $NumMedia = (int) $request->input('NumMedia');
            if ($NumMedia > 0) {
                $cost = 1;
                for ($i = 0; $i < $NumMedia; $i++) {
                    $mediaUrl = $request->input("MediaUrl$i");
                    $feedback = $this::inboundDLR($to, $message, $sendingServer, $cost, $from, $mediaUrl);
                }
            } else {
                $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
                $cost          = ceil($message_count);

                $feedback = $this::inboundDLR($to, $message, $sendingServer, $cost, $from);
            }

            if ($feedback == 'Success') {
                return $response;
            }

            return $response->message($feedback);
        }

        /**
         * text local inbound sms
         *
         *
         * @throws NumberParseException
         * @throws Throwable
         */
        public function inboundTextLocal(Request $request, $gateway = null): JsonResponse|string
        {
            $to      = $request->input('sender');
            $from    = $request->input('inNumber');
            $message = $request->input('content');

            if ($to == null || $from == null || $message == null) {
                return 'Sender, inNumber and content value required';
            }

            $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
            $cost          = ceil($message_count);


            $sendingServer = $this->getSendingServer(
                $gateway ?: SendingServer::TYPE_TEXTLOCAL,
                $gateway ? 'uid' : SendingServer::TYPE_TEXTLOCAL
            );


            return $this::inboundDLR($to, $message, $sendingServer, $cost, $from);
        }

        /**
         * inbound plivo messages
         *
         *
         * @throws NumberParseException
         * @throws Throwable
         */
        public function inboundPlivo(Request $request, $gateway = null): JsonResponse|string
        {
            $to      = $request->input('From');
            $from    = $request->input('To');
            $message = $request->input('Text');

            if ($to == null || $message == null) {
                return 'Destination number and message value required';
            }

            $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
            $cost          = ceil($message_count);


            $sendingServer = $this->getSendingServer(
                $gateway ?: SendingServer::TYPE_PLIVO,
                $gateway ? 'uid' : SendingServer::TYPE_PLIVO
            );


            return $this::inboundDLR($to, $message, $sendingServer, $cost, $from);
        }

        /**
         * inbound plivo powerpack messages
         *
         *
         * @throws NumberParseException
         * @throws Throwable
         */
        public function inboundPlivoPowerPack(Request $request, $gateway = null): JsonResponse|string
        {
            $to      = $request->input('From');
            $from    = $request->input('To');
            $message = $request->input('Text');

            if ($to == null || $message == null) {
                return 'Destination number and message value required';
            }

            $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
            $cost          = ceil($message_count);

            $sendingServer = $this->getSendingServer(
                $gateway ?: SendingServer::TYPE_PLIVOPOWERPACK,
                $gateway ? 'uid' : SendingServer::TYPE_PLIVOPOWERPACK
            );

            return $this::inboundDLR($to, $message, $sendingServer, $cost, $from);
        }

        /**
         * inbound bulk sms messages
         *
         *
         * @throws NumberParseException
         * @throws Throwable
         */
        public function inboundBulkSMS(Request $request, $gateway = null): JsonResponse|string
        {
            $to      = $request->input('msisdn');
            $from    = $request->input('sender');
            $message = $request->input('message');

            if ($to == null || $message == null) {
                return 'Destination number and message value required';
            }

            $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
            $cost          = ceil($message_count);

            $sendingServer = $this->getSendingServer(
                $gateway ?: SendingServer::TYPE_BULKSMS,
                $gateway ? 'uid' : SendingServer::TYPE_BULKSMS
            );

            return $this::inboundDLR($to, $message, $sendingServer, $cost, $from);
        }

        /**
         * inbound Vonage messages
         *
         *
         * @throws NumberParseException
         * @throws Throwable
         */
        public function inboundVonage(Request $request, $gateway = null): JsonResponse|string
        {
            $to      = $request->input('msisdn');
            $from    = $request->input('to');
            $message = $request->input('text');

            if ($to == null || $message == null) {
                return 'Destination number, Source number and message value required';
            }

            $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
            $cost          = ceil($message_count);


            $sendingServer = $this->getSendingServer(
                $gateway ?: SendingServer::TYPE_VONAGE,
                $gateway ? 'uid' : SendingServer::TYPE_VONAGE
            );


            return $this::inboundDLR($to, $message, $sendingServer, $cost, $from);
        }

        /**
         * inbound messagebird messages
         *
         *
         * @throws NumberParseException
         * @throws Throwable
         */
        public function inboundBird(Request $request, $gateway = null): JsonResponse|string
        {

            $to      = $request->input('originator');
            $from    = $request->input('recipient');
            $message = $request->input('body');

            if ($to == null || $message == null) {
                return 'Destination number, Source number and message value required';
            }

            $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
            $cost          = ceil($message_count);


            $sendingServer = $this->getSendingServer(
                $gateway ?: SendingServer::TYPE_MESSAGEBIRD,
                $gateway ? 'uid' : SendingServer::TYPE_MESSAGEBIRD
            );


            return $this::inboundDLR($to, $message, $sendingServer, $cost, $from);
        }

        /**
         * inbound signalwire messages
         *
         *
         * @throws NumberParseException
         * @throws Throwable
         */
        public function inboundSignalwire(Request $request, $gateway = null): Message|MessagingResponse
        {

            $response = new MessagingResponse();

            $to      = $request->input('From');
            $from    = $request->input('To');
            $message = $request->input('Body');

            if ($to == null || $from == null || $message == null) {
                $response->message('From, To and Body value required');

                return $response;
            }

            $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
            $cost          = ceil($message_count);


            $sendingServer = $this->getSendingServer(
                $gateway ?: SendingServer::TYPE_SIGNALWIRE,
                $gateway ? 'uid' : SendingServer::TYPE_SIGNALWIRE
            );


            $feedback = $this::inboundDLR($to, $message, $sendingServer, $cost, $from);

            if ($feedback == 'Success') {
                return $response;
            }

            return $response->message($feedback);
        }

        /**
         * inbound telnyx messages
         *
         *
         * @throws NumberParseException
         * @throws Throwable
         */
        public function inboundTelnyx(Request $request, $gateway = null): JsonResponse|string
        {
            
            
            
            
            
          
            
            
            
            

            $get_data = $request->getContent();

            $get_data = json_decode($get_data, true);
            
            
            
            
            
            
            

            
      
            
            
            
            
            
            
            

            if (isset($get_data) && is_array($get_data) && array_key_exists('data', $get_data) && array_key_exists('payload', $get_data['data']) && array_key_exists('direction', $get_data['data']['payload'])) {
                if ($get_data['data']['payload']['direction'] == 'inbound') {
                    
               
                    
                    $to      = $get_data['data']['payload']['from']['phone_number'];
                    $from    = $get_data['data']['payload']['to'][0]['phone_number'];
                    $message = $get_data['data']['payload']['text'];
                    
                    
         
                    
                    
              
                    
                    

                    if ($to == '' || $message == '' || $from == '') {
                        return 'Destination or Sender number and message value required';
                    }

                    $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
                    $cost          = ceil($message_count);


                    $sendingServer = $this->getSendingServer(
                        $gateway ?: SendingServer::TYPE_TELNYX,
                        $gateway ? 'uid' : SendingServer::TYPE_TELNYX
                    );





                    
                   $response = $this::inboundDLR($to, $message, $sendingServer, $cost, $from);



      
      
      
                    
                    
                }
                if ($get_data['data']['payload']['direction'] == 'outbound') {
                    $message_id = $get_data['data']['payload']['id'];
                    $status     = $get_data['data']['payload']['to'][0]['status'];

                    if ($status == 'delivered' || $status == 'webhook_delivered') {
                        $status = 'Delivered';
                    }

                    $this::updateDLR($message_id, $status);
                }

                return 'Invalid request';
            }

            return 'Invalid request';
        }
































        /**
         * inbound Teletopiasms messages
         *
         *
         * @throws NumberParseException
         * @throws Throwable
         */
        public function inboundTeletopiasms(Request $request, $gateway = null): JsonResponse|string
        {

            $to      = $request->input('sender');
            $from    = $request->input('recipient');
            $message = $request->input('text');

            if ($to == null || $message == null) {
                return 'Destination number, Source number and message value required';
            }

            $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
            $cost          = ceil($message_count);


            $sendingServer = $this->getSendingServer(
                $gateway ?: SendingServer::TYPE_TELETOPIASMS,
                $gateway ? 'uid' : SendingServer::TYPE_TELETOPIASMS
            );


            return $this::inboundDLR($to, $message, $sendingServer, $cost, $from);
        }

        /**
         * receive FlowRoute message
         *
         *
         * @throws NumberParseException
         * @throws Throwable
         */
        public function inboundFlowRoute(Request $request, $gateway = null)
        {
            $to      = $request->input('from');
            $from    = $request->input('to');
            $message = $request->input('body');

            if ($to == null || $message == null) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Destination number and message value required',
                ]);
            }

            $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
            $cost          = ceil($message_count);


            $sendingServer = $this->getSendingServer(
                $gateway ?: SendingServer::TYPE_FLOWROUTE,
                $gateway ? 'uid' : SendingServer::TYPE_FLOWROUTE
            );


            return $this::inboundDLR($to, $message, $sendingServer, $cost, $from);
        }

        /**
         * receive inboundEasySendSMS message
         *
         *
         * @throws NumberParseException
         * @throws Throwable
         */
        public function inboundEasySendSMS(Request $request, $gateway = null): JsonResponse|string
        {

            $to      = $request->input('From');
            $from    = null;
            $message = $request->input('message');

            if ($message == '' || $to == '') {
                return 'To and Message value required';
            }

            $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
            $cost          = ceil($message_count);


            $sendingServer = $this->getSendingServer(
                $gateway ?: SendingServer::TYPE_EASYSENDSMS,
                $gateway ? 'uid' : SendingServer::TYPE_EASYSENDSMS
            );


            return $this::inboundDLR($to, $message, $sendingServer, $cost, $from);
        }

        /**
         * receive Skyetel message
         *
         *
         * @throws NumberParseException
         * @throws Throwable
         */
        public function inboundSkyetel(Request $request, $gateway = null): JsonResponse|string
        {

            $to      = $request->input('from');
            $from    = $request->input('to');
            $message = $request->input('text');

            if ($to == '' || $from == '') {
                return 'To and From value required';
            }


            $sendingServer = $this->getSendingServer(
                $gateway ?: SendingServer::TYPE_SKYETEL,
                $gateway ? 'uid' : SendingServer::TYPE_SKYETEL
            );


            if (isset($request->media) && is_array($request->media) && array_key_exists('1', $request->media)) {

                $mediaUrl = $request->media[1];

                return $this::inboundDLR($to, $message, $sendingServer, 1, $from, $mediaUrl);
            } else {

                $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
                $cost          = ceil($message_count);

                return $this::inboundDLR($to, $message, $sendingServer, $cost, $from);
            }

        }

        /**
         * receive chat-api message
         *
         * @throws NumberParseException
         * @throws Throwable
         */
        public function inboundChatApi($gateway = null): JsonResponse|bool|string
        {

            $data = json_decode(file_get_contents('php://input'), true);

            foreach ($data['messages'] as $message) {

                $to      = $message['author'];
                $from    = $message['senderName'];
                $message = $message['body'];

                if ($message == '' || $to == '' || $from == '') {
                    return 'Author, Sender Name and Body value required';
                }

                $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
                $cost          = ceil($message_count);


                $sendingServer = $this->getSendingServer(
                    $gateway ?: SendingServer::TYPE_WHATSAPPCHATAPI,
                    $gateway ? 'uid' : SendingServer::TYPE_WHATSAPPCHATAPI
                );


                return $this::inboundDLR($to, $message, $sendingServer, $cost, $from);
            }

            return true;
        }

        /**
         * callr delivery reports
         *
         *
         * @return string|void
         */
        public function dlrCallr(Request $request)
        {

            $get_data = json_decode($request->getContent(), true);

            $message_id = $get_data['data']['user_data'];
            $status     = $get_data['data']['status'];

            if ( ! isset($message_id) && ! isset($status)) {
                return 'Message ID and status not found';
            }

            if ($status == 'RECEIVED' || $status == 'SENT') {
                $status = 'Delivered|' . $message_id;
            }

            $this::updateDLR($message_id, $status);
        }

        /**
         * receive callr message
         *
         *
         * @throws NumberParseException
         * @throws Throwable
         */
        public function inboundCallr(Request $request, $gateway = null): JsonResponse|string
        {

            $get_data = json_decode($request->getContent(), true);

            $to      = str_replace('+', '', $get_data['data']['from']);
            $from    = str_replace('+', '', $get_data['data']['to']);
            $message = $get_data['data']['text'];

            if ($message == '' || $to == '' || $from == '') {
                return 'From, To and Text value required';
            }

            $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
            $cost          = ceil($message_count);

            $sendingServer = $this->getSendingServer(
                $gateway ?: SendingServer::TYPE_CALLR,
                $gateway ? 'uid' : SendingServer::TYPE_CALLR
            );


            return $this::inboundDLR($to, $message, $sendingServer, $cost, $from);
        }

        /**
         * cm com delivery reports
         *
         *
         * @return JsonResponse|string
         */
        public function dlrCM(Request $request)
        {

            $get_data = json_decode($request->getContent(), true);
            if (is_array($get_data) && array_key_exists('messages', $get_data)) {
                $message_id = $get_data['messages']['msg']['reference'];
                $status     = $get_data['messages']['msg']['status']['errorDescription'];

                if ( ! isset($message_id) && ! isset($status)) {
                    return 'Message ID and status not found';
                }

                if ($status == 'Delivered') {
                    $status = 'Delivered|' . $message_id;
                }

                return $this::updateDLR($message_id, $status);
            }

            return 'Null Value Return';
        }

        /**
         * receive cm com message
         *
         *
         * @throws NumberParseException
         * @throws Throwable
         */
        public function inboundCM(Request $request, $gateway = null): JsonResponse|string
        {

            $get_data = json_decode($request->getContent(), true);

            $to      = str_replace('+', '', $get_data['from']['number']);
            $from    = str_replace('+', '', $get_data['to']['number']);
            $message = $get_data['message']['text'];

            if ($message == '' || $to == '' || $from == '') {
                return 'From, To and Text value required';
            }

            $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
            $cost          = ceil($message_count);


            $sendingServer = $this->getSendingServer(
                $gateway ?: SendingServer::TYPE_CMCOM,
                $gateway ? 'uid' : SendingServer::TYPE_CMCOM
            );


            return $this::inboundDLR($to, $message, $sendingServer, $cost, $from);
        }

        /**
         * receive bandwidth message
         *
         *
         * @throws NumberParseException
         * @throws Throwable
         */
        public function inboundBandwidth(Request $request, $gateway = null): bool|JsonResponse|string|null
        {

            $data = $request->all();

            if (isset($data) && is_array($data) && count($data) > 0) {
                if ($data['0']['type'] == 'message-received') {
                    if (isset($data[0]['message']) && is_array($data[0]['message'])) {
                        $to      = $data[0]['message']['from'];
                        $from    = $data[0]['to'];
                        $message = $data[0]['message']['text'];

                        if ($message == '' || $to == '' || $from == '') {
                            return 'From, To and Text value required';
                        }

                        $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
                        $cost          = ceil($message_count);

                        $sendingServer = $this->getSendingServer(
                            $gateway ?: SendingServer::TYPE_BANDWIDTH,
                            $gateway ? 'uid' : SendingServer::TYPE_BANDWIDTH
                        );

                        return $this::inboundDLR($to, $message, $sendingServer, $cost, $from);
                    } else {
                        return $request->getContent();
                    }
                } else {
                    return $request->getContent();
                }
            } else {
                return $request->getContent();
            }

        }

        /**
         * receive Solucoesdigitais message
         *
         *
         * @param Request $request
         * @param null    $gateway
         * @return bool|false
         *
         * @throws NumberParseException
         * @throws Throwable
         */
        public function inboundSolucoesdigitais(Request $request, $gateway = null): bool
        {
            $data        = $request->all();
            $id_campanha = $data['id_campanha'];
            $report      = Reports::where('status', 'LIKE', "%$id_campanha%")->first();

            $message       = $data['sms_resposta'];
            $to            = $data['nro_telefone'];
            $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
            $cost          = ceil($message_count);


            $sendingServer = $this->getSendingServer(
                $gateway ?: SendingServer::TYPE_SOLUCOESDIGITAIS,
                $gateway ? 'uid' : SendingServer::TYPE_SOLUCOESDIGITAIS
            );

            if ($report) {
                $from = $report->from;

                if ($message == '' || $to == '' || $from == '') {
                    return 'From, To and Text value required';
                }

                return $this::inboundDLR($to, $message, $sendingServer, $cost, $from, null, $report->user_id);
            }

            return $this::inboundDLR($to, $message, $sendingServer, $cost);
        }

        /**
         * receive inboundGatewayApi message
         *
         *
         * @param Request $request
         * @param null    $gateway
         * @return bool|false
         *
         * @throws NumberParseException
         * @throws Throwable
         */
        public function inboundGatewayApi(Request $request, $gateway = null): bool
        {

            $to      = $request->input('msisdn');
            $from    = $request->input('receiver');
            $message = $request->input('message');

            if ($message == '' || $to == '') {
                return 'To and Message value required';
            }

            $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
            $cost          = ceil($message_count);


            $sendingServer = $this->getSendingServer(
                $gateway ?: SendingServer::TYPE_GATEWAYAPI,
                $gateway ? 'uid' : SendingServer::TYPE_GATEWAYAPI
            );

            return $this::inboundDLR($to, $message, $sendingServer, $cost, $from);
        }

        /**
         * @return JsonResponse|string
         *
         * @throws NumberParseException
         * @throws Throwable
         */
        public function inboundInteliquent(Request $request, $gateway = null)
        {

            if ($request->input('deliveryReceipt') !== null && $request->input('deliveryReceipt') == 'true') {

                $text = $request->input('text');

                if (preg_match('/stat:(\w+)/', $text, $matches)) {
                    $status     = $matches[1];
                    $message_id = $request->input('referenceId');

                    if ( ! isset($message_id) && ! isset($status)) {
                        return 'Message ID and status not found';
                    }

                    if ($status == 'DELIVRD') {
                        $status = 'Delivered';
                    } else {
                        $status = ucfirst(strtolower($status));
                    }
                    $this::updateDLR($message_id, $status);

                    return $status;
                }

                return $text;

            } else {

                $from    = $request->input('to')[0];
                $to      = $request->input('from');
                $message = $request->input('text');

                if ($message == '' || $to == '' || $from == '') {
                    return 'From, To and Message value required';
                }

                $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
                $cost          = ceil($message_count);


                $sendingServer = $this->getSendingServer(
                    $gateway ?: SendingServer::TYPE_INTELIQUENT,
                    $gateway ? 'uid' : SendingServer::TYPE_INTELIQUENT
                );


                return $this::inboundDLR($to, $message, $sendingServer, $cost, $from);
            }

        }

        /**
         * Version 3.5
         *
         *
         * @return string
         */
        public function dlrD7networks(Request $request)
        {

            $message_id = $request->input('request_id');
            $status     = $request->input('status');

            if ( ! isset($message_id) && ! isset($status)) {
                return 'Message ID and status not found';
            }

            if ($status == 'delivered' || $status == 'accepted') {
                $status = 'Delivered';
            }
            $this::updateDLR($message_id, $status);

            return $status;
        }

        /**
         * Inbound sms for Tele API
         *
         *
         * @return JsonResponse|string
         *
         * @throws NumberParseException
         * @throws Throwable
         */
        public function inboundTeleAPI(Request $request, $gateway = null)
        {

            $to      = $request->input('destination');
            $from    = $request->input('source');
            $message = $request->input('message');

            if ($message == '' || $to == '' || $from == '') {
                return 'Source, Destination and Message value required';
            }

            $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
            $cost          = ceil($message_count);


            $sendingServer = $this->getSendingServer(
                $gateway ?: SendingServer::TYPE_TELEAPI,
                $gateway ? 'uid' : SendingServer::TYPE_TELEAPI
            );


            return $this::inboundDLR($to, $message, $sendingServer, $cost, $from);
        }

        /*Version 3.6*/

        public function dlrAmazonSNS(Request $request)
        {
            logger($request->all());
        }


        /**
         * dlrNimbuz delivery reports
         *
         *
         * @return mixed
         */
        public function dlrNimbuz(Request $request)
        {
            $message_id = $request->input('requestid');
            $status     = $request->input('status');
            $phone      = str_replace(['(', ')', '+', '-', ' '], '', $request->input('mobile'));

            if ( ! isset($message_id) && ! isset($status)) {
                return 'Message ID and status not found';
            }

            $this::updateDLR($message_id, $status, $phone);

            return $status;

        }

        /**
         * dlrGatewaySa delivery reports
         *
         *
         * @return mixed
         */
        public function dlrGatewaySa(Request $request)
        {
            $message_id = $request->input('messageId');
            $status     = $request->input('status');
            $phone      = str_replace(['(', ')', '+', '-', ' '], '', $request->input('mobile'));

            if ( ! isset($message_id) && ! isset($status)) {
                return 'Message ID and status not found';
            }

            if ($status == 'DELIVRD') {
                $status = 'Delivered';
            }

            $this::updateDLR($message_id, $status, $phone);

            return $status;

        }

        /**
         * receive inboundWhatsender message
         *
         *
         * @return JsonResponse|string
         *
         * @throws NumberParseException
         * @throws Throwable
         */
        public function inboundWhatsender(Request $request, $gateway = null)
        {
            $get_data = $request->getContent();

            if (empty($get_data)) {
                return 'Invalid request';
            }

            $get_data = json_decode($get_data, true);

            if (isset($get_data['event'], $get_data['data'], $get_data['device'])) {
                if ($get_data['event'] == 'message:in:new') {
                    $deviceId = $get_data['device']['id'];
                    $server   = SendingServer::where('settings', 'Whatsender')
                        ->where('status', 1)
                        ->where('device_id', $deviceId)
                        ->first();

                    if ( ! $server) {
                        return 'Sending server not found';
                    }

                    $from     = $get_data['data']['toNumber'];
                    $to       = $get_data['data']['fromNumber'];
                    $mediaUrl = '';

                    switch ($get_data['data']['type']) {
                        case 'image':
                        case 'video':
                        case 'audio':
                            $message     = $get_data['data']['media']['caption'];
                            $media_url   = $get_data['data']['media']['links']['download'];
                            $file_name   = $get_data['data']['media']['filename'];
                            $gateway_url = 'https://api.whatsender.io' . $media_url;

                            if ($message == null) {
                                $message = $file_name;
                            }

                            $curl = curl_init();
                            curl_setopt_array($curl, [
                                CURLOPT_URL            => $gateway_url,
                                CURLOPT_RETURNTRANSFER => true,
                                CURLOPT_ENCODING       => '',
                                CURLOPT_MAXREDIRS      => 10,
                                CURLOPT_TIMEOUT        => 30,
                                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                                CURLOPT_CUSTOMREQUEST  => 'GET',
                                CURLOPT_HTTPHEADER     => [
                                    'Token: ' . $server->api_token,
                                ],
                            ]);

                            $response = curl_exec($curl);

                            $path        = 'mms/';
                            $upload_path = public_path($path);

                            if ( ! file_exists($upload_path)) {
                                mkdir($upload_path, 0777, true);
                            }

                            $saveTo = $upload_path . $file_name;

                            file_put_contents($saveTo, $response);

                            $mediaUrl = asset('/mms') . '/' . $file_name;
                            curl_close($curl);

                            break;

                        default:
                            $message = $get_data['data']['body'];
                            if ($message == null) {
                                return 'Message not found';
                            }
                            break;
                    }

                    if (empty($to) || empty($from)) {
                        return 'Destination or Sender number and message value required';
                    }

                    $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
                    $cost          = ceil($message_count);


                    $sendingServer = $this->getSendingServer(
                        $gateway ?: SendingServer::TYPE_WHATSENDER,
                        $gateway ? 'uid' : SendingServer::TYPE_WHATSENDER
                    );


                    return $this::inboundDLR($to, $message, $sendingServer, $cost, $from, $mediaUrl);
                }
            }

            return 'Invalid request';
        }

        /**
         * inbound Cheapglobalsms messages
         *
         *
         * @throws NumberParseException
         * @throws Throwable
         */
        public function inboundCheapglobalsms(Request $request, $gateway = null): JsonResponse|string
        {
            $to      = $request->input('sender');
            $from    = $request->input('recipient');
            $message = $request->input('message');

            if ($to == null || $message == null) {
                return 'Destination number and message value required';
            }

            $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
            $cost          = ceil($message_count);


            $sendingServer = $this->getSendingServer(
                $gateway ?: SendingServer::TYPE_CHEAPGLOBALSMS,
                $gateway ? 'uid' : SendingServer::TYPE_CHEAPGLOBALSMS
            );


            return $this::inboundDLR($to, $message, $sendingServer, $cost, $from);
        }

        /**
         * dlrSMSMode delivery reports
         *
         *
         * @return mixed
         */
        public function dlrSMSMode(Request $request)
        {
            $message_id = $request->input('messageId');
            $status     = $request->input('status')['value'];
            $phone      = str_replace(['(', ')', '+', '-', ' '], '', $request->input('from'));

            if ( ! isset($message_id) && ! isset($status)) {
                return 'Message ID and status not found';
            }

            if ($status == 'DELIVERED') {
                $status = 'Delivered';
            }

            $this::updateDLR($message_id, $status, $phone);

            return $status;

        }

        /**
         * SMS Mode Inbound SMS
         *
         *
         * @return JsonResponse|string
         *
         * @throws NumberParseException
         * @throws Throwable
         */
        public function inboundSMSMode(Request $request, $gateway = null)
        {

            $to      = $request->input('from');
            $from    = $request->input('recipient')['to'];
            $message = $request->input('body')['text'];

            if ($to == null || $message == null) {
                return 'Destination number and message value required';
            }

            $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
            $cost          = ceil($message_count);


            $sendingServer = $this->getSendingServer(
                $gateway ?: SendingServer::TYPE_SMSMODE,
                $gateway ? 'uid' : SendingServer::TYPE_SMSMODE
            );

            return $this::inboundDLR($to, $message, $sendingServer, $cost, $from);
        }

        /**
         * Infobip Inbound SMS
         *
         *
         * @return JsonResponse|string
         *
         * @throws NumberParseException
         * @throws Throwable
         */
        public function inboundInfobip(Request $request, $gateway = null)
        {
            $to      = $request->input('results')['0']['from'];
            $from    = $request->input('results')['0']['to'];
            $message = $request->input('results')['0']['text'];

            if ($to == null || $message == null) {
                return 'Destination number and message value required';
            }

            $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
            $cost          = ceil($message_count);

            $sendingServer = $this->getSendingServer(
                $gateway ?: SendingServer::TYPE_INFOBIP,
                $gateway ? 'uid' : SendingServer::TYPE_INFOBIP
            );

            return $this::inboundDLR($to, $message, $sendingServer, $cost, $from);
        }

        /**
         * Voximplant Inbound SMS
         *
         *
         * @return JsonResponse|string
         *
         * @throws NumberParseException
         * @throws Throwable
         */
        public function inboundVoximplant(Request $request, $gateway = null)
        {
            $data = $request->input('callbacks.0');

            if ($data == null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Message not found',
                ]);
            }

            if (array_key_exists('type', $data) && $data['type'] == 'sms_inbound') {
                $data = $data['sms_inbound'];

                if ($data == null) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Message not found',
                    ]);
                }

                $to      = $data['source_number'];
                $from    = $data['destination_number'];
                $message = $data['sms_body'];

                if ($to == null || $message == null) {

                    return response()->json([
                        'success' => false,
                        'message' => 'Destination number and message value required',
                    ]);
                }


                $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
                $cost          = ceil($message_count);

                $sendingServer = $this->getSendingServer(
                    $gateway ?: SendingServer::TYPE_VOXIMPLANT,
                    $gateway ? 'uid' : SendingServer::TYPE_VOXIMPLANT
                );

                return $this::inboundDLR($to, $message, $sendingServer, $cost, $from);

            }

            return response()->json([
                'success' => false,
                'message' => 'Message not found',
            ]);

        }

        /*Version 3.8*/
        public function dlrHutchLK(Request $request)
        {
            logger($request->all());
        }

        /**
         * @return JsonResponse|string
         *
         * @throws NumberParseException
         * @throws Throwable
         */
        public function inboundClickSend(Request $request, $gateway = null)
        {
            $to      = $request->input('from');
            $from    = $request->input('to');
            $message = $request->input('body');

            if ($to == null || $message == null) {
                return 'Destination number and message value required';
            }

            $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
            $cost          = ceil($message_count);

            $sendingServer = $this->getSendingServer(
                $gateway ?: SendingServer::TYPE_CLICKSEND,
                $gateway ? 'uid' : SendingServer::TYPE_CLICKSEND
            );

            return $this::inboundDLR($to, $message, $sendingServer, $cost, $from);
        }

        /**
         * @return string
         */
        public function dlrMoceanAPI(Request $request)
        {
            $message_id = $request->get('mocean-msgid');
            $status     = $request->get('mocean-dlr-status');
            $phone      = str_replace(['(', ')', '+', '-', ' '], '', $request->input('mocean-to'));

            if ( ! isset($message_id) && ! isset($status)) {
                return 'Message ID and status not found';
            }

            $status = match ($status) {
                '1' => 'Delivered',
                '2' => 'Failed',
                '3' => 'Expired',
            };

            $this::updateDLR($message_id, $status, $phone);

            return $status;
        }

        /**
         *airtelindia dlr
         */
        public function dlrAirtelIndia(Request $request)
        {
            logger($request->all());
        }

        /**
         * @return JsonResponse|string
         *
         * @throws NumberParseException
         * @throws Throwable
         */
        public function inboundClickatell(Request $request, $gateway = null)
        {
            $to      = $request->input('fromNumber');
            $from    = $request->input('toNumber');
            $message = $request->input('text');

            if (strlen($message) == mb_strlen($message, 'utf-8')) {
                if (preg_match('/%[0-9A-Fa-f]{2}/', $message)) {
                    // String is URL-encoded
                    $message = urldecode($message);
                }
            }

            if ($to == null || $message == null) {
                return 'Destination number and message value required';
            }

            $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
            $cost          = ceil($message_count);

            $sendingServer = $this->getSendingServer(
                $gateway ?: SendingServer::TYPE_CLICKATELLTOUCH,
                $gateway ? 'uid' : SendingServer::TYPE_CLICKATELLTOUCH
            );


            return $this::inboundDLR($to, $message, $sendingServer, $cost, $from);
        }

        /**
         * SimpleTexting delivery reports
         *
         *
         * @return string
         */
        public function dlrSimpleTexting(Request $request)
        {
            logger($request->all());

            return 'Debugging';
        }

        /**
         * @return JsonResponse|string
         *
         * @throws NumberParseException
         * @throws Throwable
         */
        public function inboundSimpleTexting(Request $request, $gateway = null)
        {

            $type   = $request->input('type');
            $values = $request->input('values');
            if (isset($type) && $type == 'INCOMING_MESSAGE' && isset($values) && is_array($values)) {

                $from    = $values['accountPhone'];
                $to      = $values['contactPhone'];
                $message = $values['text'];

                if ($to == null || $message == null) {
                    return 'Destination number and message value required';
                }

                $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
                $cost          = ceil($message_count);


                $sendingServer = $this->getSendingServer(
                    $gateway ?: SendingServer::TYPE_SIMPLETEXTING,
                    $gateway ? 'uid' : SendingServer::TYPE_SIMPLETEXTING
                );

                return $this::inboundDLR($to, $message, $sendingServer, $cost, $from);
            }

            return $type;
        }

        public function dlrDinstar(Request $request)
        {
            logger($request->all());
        }

        /**
         * Processes the DLR message.
         *
         * @param Request $request The request object containing the message ID and status.
         * @return string The updated status of the message.
         */
        public function dlrMP(Request $request)
        {

            $message_id = $request->get('id');
            $status     = $request->get('status');

            if ( ! isset($message_id) && ! isset($status)) {
                return 'Message ID and status not found';
            }

            $status = match ($status) {
                '2' => 'Delivered',
                '5' => 'Undelivered',
                default => 'Failed',
            };

            $this::updateDLR($message_id, $status);

            return $status;
        }

        /**
         * Processes the DLR message.
         *
         * @param Request $request The request object containing the message ID and status.
         * @return string The updated status of the message.
         */
        public function dlrBasedBroad(Request $request)
        {

            if ( ! empty($request->get('reportDetail'))) {
                $message_id = $request->get('reportDetail')['batchId'];
                $status     = $request->get('reportDetail')['status'];

                if ( ! isset($message_id) && ! isset($status)) {
                    return 'Message ID and status not found';
                }

                if ($status == 'DELIVRD') {
                    $status = 'Delivered';
                }

                $this::updateDLR($message_id, $status);

                return $status;
            }

            return 'Invalid request';

        }

        /**
         * @throws Throwable
         * @throws NumberParseException
         */
        public function inboundSmsdenver(Request $request, $gateway = null)
        {
            $to      = $request->input('from');
            $from    = $request->input('to');
            $message = $request->input('text');

            if ($to == null || $message == null) {
                return 'Destination number and message value required';
            }

            $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
            $cost          = ceil($message_count);

            $sendingServer = $this->getSendingServer(
                $gateway ?: SendingServer::TYPE_SMSDENVER,
                $gateway ? 'uid' : SendingServer::TYPE_SMSDENVER
            );


            return $this::inboundDLR($to, $message, $sendingServer, $cost, $from);

        }

        public function dlrSmsdenver(Request $request)
        {

            if (count($request->all()) <= 0) {

                return response()->json([
                    'status'  => 'error',
                    'message' => 'Request is empty',
                ]);
            }

            logger($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Success',
            ]);
        }

        public function dlrTopying(Request $request)
        {

            if (count($request->all()) <= 0) {

                return response()->json([
                    'status'  => 'error',
                    'message' => 'Request is empty',
                ]);
            }

            $data = $request->all();

            if (isset($request->cnt) && array_key_exists('array', $data)) {
                foreach ($data['array'] as $item) {
                    if (array_key_exists(0, $item) && array_key_exists(4, $item)) {
                        if ($item['4'] == 'success') {
                            $item['4'] = 'Delivered';
                        }

                        $this::updateDLR($item['0'], $item['4']);
                    }
                }

                return response()->json([
                    'status'  => 'success',
                    'message' => 'success',
                ]);
            }

            return response()->json([
                'status'  => 'error',
                'message' => 'Invalid Request',
            ]);
        }

        /**
         * Handle the DLR SMS TO request.
         *
         * @param Request $request The HTTP request object.
         * @return JsonResponse The JSON response.
         */
        public function dlrSmsTO(Request $request)
        {

            if (count($request->all()) <= 0) {

                return response()->json([
                    'status'  => 'error',
                    'message' => 'Request is empty',
                ]);
            }

            $message_id = $request->get('messageId');
            $status     = $request->get('status');

            if ( ! isset($message_id) && ! isset($status)) {

                return response()->json([
                    'status'  => 'error',
                    'message' => 'Message ID and status not found',
                ]);
            }

            if ($status == 'SENT') {
                $status = 'Delivered';
            }

            $this::updateDLR($message_id, $status);

            return response()->json([
                'success' => true,
                'message' => 'Success',
            ]);
        }

        /**
         * Processes an inbound text using TextBelt.
         *
         * @param Request $request The request object containing the input data.
         * @return string The result of the inbound text processing.
         *
         * @throws NumberParseException
         * @throws Throwable
         */
        public function inboundTextbelt(Request $request, $gateway = null)
        {

            if (count($request->all()) <= 0 && $request->input('textId') == null) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Request is empty',
                ]);

            }
            $message_id = $request->input('textId');

            $get_data = Reports::whereLike(['status'], $message_id)->first();

            if ( ! $get_data) {
                return 'Message ID not found';
            }

            $to      = $request->input('fromNumber');
            $from    = $get_data->from;
            $message = $request->input('text');

            if ($to == null || $message == null) {
                return 'Destination number and message value required';
            }

            $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
            $cost          = ceil($message_count);

            $sendingServer = $this->getSendingServer(
                $gateway ?: SendingServer::TYPE_TEXTBELT,
                $gateway ? 'uid' : SendingServer::TYPE_TEXTBELT
            );

            return $this::inboundDLR($to, $message, $sendingServer, $cost, $from);
        }

        /**
         * Processes an inbound text using Burst SMS.
         *
         * @param Request $request The request object containing the input data.
         * @return string The result of the inbound text processing.
         *
         * @throws NumberParseException
         * @throws Throwable
         */
        public function inboundBurstSMS(Request $request, $gateway = null)
        {
            $to      = $request->input('mobile');
            $from    = $request->input('longcode');
            $message = $request->input('response');

            if ($to == null || $message == null || $from == null) {
                return 'Destination, source number and message value required';
            }

            $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
            $cost          = ceil($message_count);

            $sendingServer = $this->getSendingServer(
                $gateway ?: SendingServer::TYPE_BURSTSMS,
                $gateway ? 'uid' : SendingServer::TYPE_BURSTSMS
            );

            return $this::inboundDLR($to, $message, $sendingServer, $cost, $from);
        }

        /**
         * Processes an inbound text using 800 Com.
         *
         * @param Request $request The request object containing the input data.
         * @return string The result of the inbound text processing.
         *
         * @throws NumberParseException
         * @throws Throwable
         */
        public function inbound800com(Request $request, $gateway = null)
        {
            $inbound = $request->input('inbound');

            if ( ! $inbound) {
                return 'Not inbound message';
            }

            $to      = $request->input('recipient');
            $from    = $request->input('sender');
            $message = $request->input('message');

            if ($to == null || $message == null || $from == null) {
                return 'Destination, source number and message value required';
            }

            $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
            $cost          = ceil($message_count);

            $sendingServer = $this->getSendingServer(
                $gateway ?: SendingServer::TYPE_800COM,
                $gateway ? 'uid' : SendingServer::TYPE_800COM
            );

            return $this::inboundDLR($to, $message, $sendingServer, $cost, $from);
        }

        /**
         * Processes an inbound text using Sinch.
         *
         * @param Request $request The request object containing the input data.
         * @return string The result of the inbound text processing.
         *
         * @throws NumberParseException
         * @throws Throwable
         */
        public function inboundSinch(Request $request, $gateway = null)
        {
            $inbound = $request->input('type');

            if ($inbound != 'mo_text') {
                return 'Not inbound message';
            }

            $to      = $request->input('from');
            $from    = $request->input('to');
            $message = $request->input('body');

            if ($to == null || $message == null || $from == null) {
                return 'Destination, source number and message value required';
            }

            $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
            $cost          = ceil($message_count);

            $sendingServer = $this->getSendingServer(
                $gateway ?: SendingServer::TYPE_SINCH,
                $gateway ? 'uid' : SendingServer::TYPE_SINCH
            );

            return $this::inboundDLR($to, $message, $sendingServer, $cost, $from);
        }

        /**
         * Processes an inbound text using Sinch.
         *
         * @param Request $request The request object containing the input data.
         * @return string The result of the inbound text processing.
         *
         * @throws NumberParseException
         * @throws Throwable
         */
        public function inboundNotifyre(Request $request, $gateway = null)
        {

            $data    = $request->all();
            $inbound = $request->input('Event');

            if ($inbound == 'sms_received' && is_array($request->input('Payload'))) {

                $to      = $data['Payload']['SenderNumber'];
                $from    = $data['Payload']['RecipientNumber'];
                $message = $data['Payload']['Message'];

                if ($to == null || $message == null || $from == null) {
                    return 'Destination, source number and message value required';
                }

                $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
                $cost          = ceil($message_count);

                $sendingServer = $this->getSendingServer(
                    $gateway ?: SendingServer::TYPE_NOTIFYRE,
                    $gateway ? 'uid' : SendingServer::TYPE_NOTIFYRE
                );

                return $this::inboundDLR($to, $message, $sendingServer, $cost, $from);
            }

            return response()->json([
                'status'  => 'success',
                'message' => 'SMS Sent Event fired',
            ]);
        }


        /**
         * @throws Throwable
         * @throws NumberParseException
         */
        public function inboundSMSGateway(Request $request, $gateway = null)
        {

            $data = $request->get('messages');

            if (empty($data)) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Request is empty',
                ]);
            }

// Decode the JSON string into an associative array
            $messages = json_decode($data, true);

// Array to store the matched results
            $matchedResults = [];

            foreach ($messages as $message) {
                // Check if the number is in E.164 format (e.g., starts with '+')
                if (isset($message['number']) && preg_match('/^\+\d+$/', $message['number']) && isset($message['status']) && $message['status'] == 'Received') {
                    $matchedResults[] = [
                        'number'   => str_replace(['+', '(', ')', '-'], '', $message['number']),
                        'deviceID' => $message['deviceID'],
                        'message'  => $message['message'],
                        'simSlot'  => $message['simSlot'],
                    ];
                }
            }

            if (count($matchedResults) <= 0) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'No number found',
                ]);
            }

            foreach ($matchedResults as $result) {

                $to       = $result['number'];
                $deviceID = $result['deviceID'];
                $message  = $result['message'];
                $simSlot  = $result['simSlot'];

                $sending_server = SendingServer::where('settings', SendingServer::TYPE_EASYSMSXYZ)
                    ->where('status', 1)
                    ->where('device_id', $deviceID)
                    ->first();

                if ($sending_server) {

                    $gateway_url = str_replace('/services/send.php', '/services/get-devices.php', $sending_server->api_link);

                    $parameters = [
                        'key' => $sending_server->api_key,
                    ];

                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $gateway_url);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36');
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($parameters));
                    $response = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);


                    if (curl_errno($ch)) {
                        return response()->json([
                            'status'  => 'error',
                            'message' => curl_error($ch),
                        ]);
                    } else {
                        if ($httpCode == 200) {
                            $json = json_decode($response, true);

                            if ( ! $json) {
                                if (empty($response)) {
                                    return response()->json([
                                        'status'  => 'error',
                                        'message' => 'Missing data in request.',
                                    ]);
                                } else {
                                    return response()->json([
                                        'status'  => 'error',
                                        'message' => $response,
                                    ]);
                                }
                            } else {
                                if ($json['success']) {

// Extract the E.164 phone numbers from the `sims` array
                                    $sims         = $json['data']['devices'][0]['sims'];
                                    $phoneNumbers = array_map(function ($sim) {
                                        // Extract the phone number using a regular expression
                                        preg_match('/\+\d+/', $sim, $matches);

                                        return $matches[0] ?? null;
                                    }, $sims);

                                    if (count($phoneNumbers) > 0) {
                                        $from = str_replace(['+', '(', ')', '-'], '', $phoneNumbers[$simSlot]);


                                        if ($to == null || $message == null || $from == null) {
                                            return response()->json([
                                                'status'  => 'error',
                                                'message' => 'Destination, source number and message value required',
                                            ]);
                                        }

                                        $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
                                        $cost          = ceil($message_count);


                                        $sendingServer = $this->getSendingServer(
                                            $gateway ?: $sending_server->settings,
                                            $gateway ? 'uid' : $sending_server->settings
                                        );


                                        return $this::inboundDLR($to, $message, $sendingServer, $cost, $from);
                                    }

                                } else {

                                    return response()->json([
                                        'status'  => 'error',
                                        'message' => $json['error']['message'],
                                    ]);
                                }
                            }
                        } else {
                            return response()->json([
                                'status'  => 'error',
                                'message' => 'Error Code: ' . $httpCode,
                            ]);
                        }
                    }
                    curl_close($ch);
                }

                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sending server not found',
                ]);
            }

            return response()->json([
                'status'  => 'error',
                'message' => 'Request is empty',
            ]);

        }


        /**
         * @throws Throwable
         * @throws NumberParseException
         */
        public function inboundEjoin(Request $request, $gateway = null)
        {

            $to      = $request->input('from');
            $from    = $request->input('receiver');
            $content = $request->input('content');


            if ($to == null || $from == null || $content == null) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Destination, source number and message value required',
                ]);
            }

            $to   = str_replace(['(', ')', '+', '-', ' '], '', trim($to));
            $from = str_replace(['(', ')', '+', '-', ' '], '', trim($from));

// Use regular expression to remove lines that start with 'Sender', 'Receiver', 'SMSC', or 'SCTS'
            $filteredContent = preg_replace('/^(Sender|Receiver|SMSC|SCTS|Slot):.*$/m', '', $content);

// Trim any leftover newlines or spaces
            $message = trim($filteredContent);


            $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
            $cost          = ceil($message_count);

            $sendingServer = $this->getSendingServer(
                $gateway ?: SendingServer::TYPE_EJOIN,
                $gateway ? 'uid' : SendingServer::TYPE_EJOIN
            );

            return $this::inboundDLR($to, $message, $sendingServer, $cost, $from);
        }


        public function inboundTxtria(Request $request)
        {
            logger($request->all());
        }

        /**
         * @throws Throwable
         * @throws NumberParseException
         */
        public function inboundD7networks(Request $request, $gateway = null)
        {

            $to      = $request->input('receiver');
            $from    = $request->input('sender');
            $message = $request->input('text');


            if ($to == null || $from == null || $message == null) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Destination, source number and message value required',
                ]);
            }

            $to   = str_replace(['(', ')', '+', '-', ' '], '', trim($to));
            $from = str_replace(['(', ')', '+', '-', ' '], '', trim($from));


            $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
            $cost          = ceil($message_count);

            $sendingServer = $this->getSendingServer(
                $gateway ?: SendingServer::TYPE_D7NETWORKS,
                $gateway ? 'uid' : SendingServer::TYPE_D7NETWORKS
            );


            return $this::inboundDLR($to, $message, $sendingServer, $cost, $from);
        }

        /**
         * inbound Diafaan messages
         *
         *
         * @throws NumberParseException
         * @throws Throwable
         */
        public function inboundDiafaan(Request $request, $gateway = null): JsonResponse|string
        {
            $to      = $request->input('to');
            $from    = $request->input('from');
            $message = $request->input('message');

            if ($to == null || $from == null || $message == null) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Destination, source number and message value required',
                ]);
            }

            $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
            $cost          = ceil($message_count);


            $sendingServer = $this->getSendingServer(
                $gateway ?: SendingServer::TYPE_DIAFAAN,
                $gateway ? 'uid' : SendingServer::TYPE_DIAFAAN
            );

            return $this::inboundDLR($to, $message, $sendingServer, $cost, $from);
        }


        public function inboundReceiveWebhook(User $user, Request $request)
        {

            $response = new MessagingResponse();

            try {

                $to      = $request->input('From');
                $from    = $request->input('To');
                $message = $request->input('Body');

                if ($message == 'NULL') {
                    $message = null;
                }

                if ($to == null || $from == null) {
                    $response->message('From and To value required');

                    return $response;
                }


                if (isset($user->webhook_url)) {

                    $to = str_replace(['(', ')', '+', '-', ' '], '', trim($to));

                    $phoneNumberUtil   = PhoneNumberUtil::getInstance();
                    $phoneNumberObject = $phoneNumberUtil->parse('+' . $to);
                    $iso_code          = $phoneNumberUtil->getRegionCodeForNumber($phoneNumberObject);


                    $countryName = Locale::getDisplayRegion('-' . $iso_code, 'en');
                    // Prepare data to send to the webhook
                    $webhookData = [
                        'to'           => $from,
                        'from'         => $to,
                        'content'      => $message,
                        'country'      => $iso_code,
                        'country_name' => $countryName,
                    ];

                    $httpResponse = Http::post($user->webhook_url, $webhookData);

                    if ($httpResponse->failed()) {
                        $response->message('Failed to forward SMS to webhook');

                        return $response;
                    }

                }

                $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
                $cost          = ceil($message_count);

                $feedback = $this::inboundDLR($to, $message, 'Twilio', $cost, $from);

                return $response->message($feedback);
            } catch (Exception|Throwable|NotFoundHttpException $e) {
                $response->message($e->getMessage());

                return $response;
            }
        }


        public function dlrClosum(Request $request)
        {

            if (count($request->all()) <= 0) {

                return response()->json([
                    'status'  => 'error',
                    'message' => 'Request is empty',
                ]);
            }

            $message_id = $request->get('msgid');
            $status     = $request->get('status');

            if ( ! isset($message_id) && ! isset($status)) {

                return response()->json([
                    'status'  => 'error',
                    'message' => 'Message ID and status not found',
                ]);
            }

            if ($status == 'delivered' || $status == 'accepted') {
                $status = 'Delivered';
            }

            $this::updateDLR($message_id, $status);

            return response()->json([
                'success' => true,
                'message' => 'Success',
            ]);
        }


        /**
         * Fortytwo delivery reports
         *
         * @return JsonResponse|void
         */
        public function dlrFortytwo(Request $request)
        {
            $message_id = $request->input('api_job_id');
            $status     = $request->input('data.0.status');

            if ( ! isset($message_id) && ! isset($status)) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Message ID and status not found',
                ]);
            }
            if ($status == 'DELIVRD') {
                $status = 'Delivered';
            } else {
                $status = ucfirst(strtolower($status));
            }

            $this::updateDLR($message_id, $status);

        }

        /**
         * Fortytwo inbound messages
         *
         * @param Request $request
         * @param null    $gateway
         * @return JsonResponse|string
         * @throws NumberParseException
         * @throws Throwable
         */
        public function inboundFortytwo(Request $request, $gateway = null)
        {
            $data = $request->all();

            if (isset($data['api_job_id']) && isset($data['data']) && is_array($data['data'])) {
                foreach ($data['data'] as $message) {
                    if (isset($message['type']) && $message['type'] === 'MESSENGER') {
                        $to          = $message['to'];
                        $from        = $message['messenger_from'] ?? $message['sms_from'];
                        $messageText = $message['message'] ?? '';

                        if (empty($to) || empty($from) || empty($messageText)) {
                            return response()->json([
                                'status'  => 'error',
                                'message' => 'Missing required parameters',
                            ]);
                        }

                        $message_count = strlen(preg_replace('/\s+/', ' ', trim($messageText))) / 160;
                        $cost          = ceil($message_count);


                        $sendingServer = $this->getSendingServer(
                            $gateway ?: SendingServer::TYPE_FORTYTWO,
                            $gateway ? 'uid' : SendingServer::TYPE_FORTYTWO
                        );

                        return $this::inboundDLR($to, $messageText, $sendingServer, $cost, $from);
                    }
                }
            }

            return response()->json([
                'status'  => 'error',
                'message' => 'Invalid request format',
            ]);
        }


        /**
         * @throws Throwable
         * @throws NumberParseException
         */
        public function inboundTextGrid(Request $request, $gateway = null)
        {

            if ($request->has('SmsStatus') && $request->has('To') && $request->has('From') && $request->has('Body') && $request->get('SmsStatus') == 'received') {

                $to      = $request->input('From');
                $from    = $request->input('To');
                $message = $request->input('Body');

                if ($to == null || $from == null || $message == null) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'Missing required parameters',
                    ]);
                }

                $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
                $cost          = ceil($message_count);


                $sendingServer = $this->getSendingServer(
                    $gateway ?: SendingServer::TYPE_TEXTGRID,
                    $gateway ? 'uid' : SendingServer::TYPE_TEXTGRID
                );

                return $this::inboundDLR($to, $message, $sendingServer, $cost, $from);
            }

            return response()->json([
                'status'  => 'error',
                'message' => 'Invalid request format',
            ]);

        }


        public function dlrArkesel(Request $request)
        {

            logger($request->all());

            $message_id = $request->input('sms_id');
            $status     = $request->input('status');

            if ( ! isset($message_id) && ! isset($status)) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Message ID and status not found',
                ]);
            }
            if ($status == 'DELIVERED') {
                $status = 'Delivered';
            } else {
                $status = ucfirst(strtolower($status));
            }

            $this::updateDLR($message_id, $status);

            return response()->json([
                'success' => true,
                'message' => 'Success',
                'status'  => $status,
            ]);
        }

        public function inboundLinkmobility(Request $request)
        {

            logger($request->all());

            return response()->json([
                'status'  => 'success',
                'message' => 'Success',
            ]);

        }

        public function dlrDotgo(Request $request)
        {
            $message_id = $request->input('id');
            $status     = $request->input('status');
            $ref_id     = $request->input('ref_id');

            if ( ! isset($message_id) && ! isset($status) && ! isset($ref_id)) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Message ID and status not found',
                ]);
            }

            $status = ucfirst(strtolower($status));

            $this::updateDLR($message_id, $status);

            return response()->json([
                'success' => true,
                'message' => 'Success',
                'status'  => $status,
            ]);

        }

        public function dlrSMSala(Request $request)
        {

            $message_id = $request->input('messageId');
            $status     = $request->input('dlrStatus');

            if ( ! isset($message_id) && ! isset($status)) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Message ID and status not found',
                ]);
            }

            $status = ucfirst(strtolower($status));

            $this::updateDLR($message_id, $status);

            return response()->json([
                'success' => true,
                'message' => 'Success',
                'status'  => $status,
            ]);

        }

        /**
         * Handle inbound WhatsApp messages (Twilio & Meta + Handover + Echoes)
         *
         * @throws Throwable
         */
        public function inboundWhatsapp(Request $request, $gateway = null)
        {
            logger()->info('Inbound WhatsApp Payload', [
                'gateway' => $gateway,
                'data'    => $request->all(),
            ]);

            // Load the server config
            $sendingServer = $this->getSendingServer(
                $gateway ?: SendingServer::TYPE_WHATSAPP,
                $gateway ? 'uid' : SendingServer::TYPE_WHATSAPP
            );

            // Handle Meta verification challenge
            if ($request->has('hub_mode') && $request->get('hub_mode') === 'subscribe') {
                $verifyToken = $sendingServer->c1 ?? null;

                return $request->get('hub_verify_token') === $verifyToken
                    ? response($request->get('hub_challenge'), 200)
                    : response('Invalid verification token', 403);
            }

            $payload = $request->all();

            /*
             |--------------------------------------------------------------------------
             | Handle WhatsApp "message_echoes" (outbound bot messages)
             |--------------------------------------------------------------------------
             | DO NOT log these as inbound messages.
             */
            if (data_get($payload, 'field') === 'message_echoes') {
                $echoMessage = data_get($payload, 'value.message_echoes.0.text.body');

                logger()->info('Skipping message_echo (outbound message)', [
                    'echo_message' => $echoMessage,
                ]);

                return response()->json(['status' => 'ignored-echo']);
            }

            /*
             |--------------------------------------------------------------------------
             | Handle WhatsApp "messaging_handovers"
             |--------------------------------------------------------------------------
             | When Meta passes control between apps (handover protocol)
             | No inbound message → only log handover event.
             */
            if (data_get($payload, 'field') === 'messaging_handovers') {
                logger()->info('WhatsApp Handover Event', [
                    'handover' => $payload['value'],
                ]);

                return response()->json(['status' => 'handover-event']);
            }

            /*
             |--------------------------------------------------------------------------
             | Determine message format & extract values
             |--------------------------------------------------------------------------
             */

            // Twilio Format
            $to      = $request->input('From');
            $from    = $request->input('To');
            $message = $request->input('Body');

            // Meta Format (messages.0 supports text, reaction, image, etc.)
            if ( ! $message) {
                $to   = $to ?: data_get($payload, 'contacts.0.wa_id');
                $from = $from ?: data_get($payload, 'metadata.display_phone_number');

                $message = $message ?: data_get($payload, 'messages.0.text.body');
                $message = $message ?: data_get($payload, 'messages.0.button.text');
                $message = $message ?: data_get($payload, 'messages.0.interactive.button_reply.title');
                $message = $message ?: data_get($payload, 'messages.0.interactive.list_reply.title');
                $message = $message ?: data_get($payload, 'messages.0.reaction.emoji');
            }

            /*
             |--------------------------------------------------------------------------
             | Validate extracted fields
             |--------------------------------------------------------------------------
             */
            if ( ! $from || ! $to || ! $message) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sender, receiver and message body are required',
                ], 422);
            }

            /*
             |--------------------------------------------------------------------------
             | Calculate cost (WhatsApp messages treated as SMS segment equivalent)
             |--------------------------------------------------------------------------
             */
            $cleanMsg      = preg_replace('/\s+/', ' ', trim($message));
            $message_count = strlen($cleanMsg) / 160;
            $cost          = ceil($message_count);

            /*
             |--------------------------------------------------------------------------
             | Forward to inbound DLR handler
             |--------------------------------------------------------------------------
             */

            return $this::inboundDLR($to, $message, $sendingServer, $cost, $from);
        }


        /**
         * @throws Throwable
         * @throws NumberParseException
         */
        public function inboundEvolutionApi(Request $request, $gateway = null)
        {

            if (config('app.stage') == 'demo') {

                return response()->json([
                    'status'  => 'warning',
                    'message' => 'This feature is not available on demo mode',
                ]);
            }

            logger()->info('Evolution API Inbound Payload', [
                'gateway' => $gateway,
                'payload' => $request->all(),
            ]);

            $payload = $request->all();

            // Only process Evolution API inbound messages
            if (data_get($payload, 'event') === 'messages.upsert') {

                // Extract raw Evolution API values
                $senderRaw   = data_get($payload, 'data.key.remoteJid');  // FROM
                $receiverRaw = data_get($payload, 'sender');              // TO
                $message     = data_get($payload, 'data.message.conversation');

                // Clean ☑ remove @s.whatsapp.net
                $from = $senderRaw ? str_replace('@s.whatsapp.net', '', $senderRaw) : null;
                $to   = $receiverRaw ? str_replace('@s.whatsapp.net', '', $receiverRaw) : null;

                // Validate
                if ( ! $from || ! $to || ! $message) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'Missing Evolution API fields: from, to, or message.',
                    ], 422);
                }

                // WhatsApp cost calculation (same logic as SMS)
                $cleanMsg      = preg_replace('/\s+/', ' ', trim($message));
                $message_count = strlen($cleanMsg) / 160;
                $cost          = ceil($message_count);

                // Load sending server
                $sendingServer = $this->getSendingServer(
                    $gateway ?: SendingServer::TYPE_WHATSAPP,
                    $gateway ? 'uid' : SendingServer::TYPE_WHATSAPP
                );

                // Forward to your inbound message handler
                return $this::inboundDLR($to, $message, $sendingServer, $cost, $from);
            }

            // Ignore anything else
            return response()->json([
                'status' => 'ignored',
                'reason' => 'Not an Evolution API inbound message',
            ]);

        }

    }

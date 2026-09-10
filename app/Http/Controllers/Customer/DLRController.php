<?php

    namespace App\Http\Controllers\Customer;

    use App\Enums\Messaging\MessagingProvider;
    use App\Enums\Messaging\WebhookRejectionReason;
    use App\Events\MessageReceived;
    use App\Http\Controllers\Controller;
    use App\Library\Business\LegacyBusinessResolver;
    use App\Library\Messaging\InboundWebhookAttributionResolver;
    use App\Library\Messaging\ManagedMessageDispatcher;
    use App\Library\Messaging\MessagingWebhookRejectionRecorder;
    use Illuminate\Support\Str;
    use App\Models\CustomerBasedSendingServer;
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
        public static function updateDLR($message_id, $status, ?SendingServer $sendingServer = null): JsonResponse
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


            // Customer Experience Slice 3 — Security Correction 36, P0.
            //
            // What was here: `Reports::whereLike(['status'], $message_id)
            // ->first()`. On an UNAUTHENTICATED delivery-callback route that
            // was a platform-wide wildcard search over every tenant's
            // reports, where `%` and `_` in the attacker's own message id
            // were live LIKE metacharacters and `->first()` picked a victim
            // by insertion order. Combined with the unconditional
            // sms_unit credit below it, it was a repeatable
            // billing-credit primitive: send the same failed callback N
            // times, get N refunds.
            $get_data = self::resolveReportForProviderMessage($message_id, $sendingServer);

            if ( ! $get_data) {
                // Zero matches, several matches, or an unresolvable id — all
                // fail closed, with no mutation and no refund.
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Message not found',
                ]);
            }

            $applied = self::applyDeliveryTransition($get_data, $status, $customer_status, $message_id);

            if ($applied['campaign_id']) {
                Campaigns::find($applied['campaign_id'])?->updateCache();
            }

            return response()->json([
                'status'  => 'success',
                'message' => $status . ' | ' . $message_id,
            ]);

        }

        /**
         * The single authoritative resolution seam for a provider delivery
         * callback, shared by every legacy DLR path.
         *
         * TWO STRATEGIES, STRONGEST FIRST.
         *
         * 1. The managed correlation this branch introduced:
         *    `business_messaging_operations` carries the provider message id
         *    under a GLOBAL unique index and a durable `report_id` foreign
         *    key. That is an exact, unambiguous join and needs no string
         *    matching at all.
         *
         * 2. Failing that, the legacy packed `status` column, which stores
         *    `"{status}|{provider_message_id}"`. This is the only correlation
         *    a pre-Slice-3 report has, so it cannot simply be dropped — but
         *    it is now used under four constraints together:
         *
         *      * the attacker's id is ESCAPED, so `%` and `_` are literal
         *        characters and not wildcards;
         *      * the pattern anchors the id to the END of the packed value
         *        (`%|<id>`), so it cannot match a substring anywhere else;
         *      * the candidate set is scoped to the resolved SendingServer
         *        whenever the caller knows one;
         *      * every candidate is then re-checked by EXACT parsed
         *        equality, and the lookup succeeds only if EXACTLY ONE
         *        survives.
         *
         * Zero or several survivors fail closed. There is no "first row", no
         * cross-tenant fallback and no default tenant.
         */
        private static function resolveReportForProviderMessage(
            $providerMessageId,
            ?SendingServer $sendingServer = null
        ): ?Reports {
            if ( ! is_string($providerMessageId)) {
                return null;
            }

            $providerMessageId = trim($providerMessageId);

            if ($providerMessageId === '') {
                return null;
            }

            // 1. Managed correlation — exact, unique, durable.
            $operations = DB::table(ManagedMessageDispatcher::TABLE)
                ->where('provider_message_id', $providerMessageId)
                ->whereNotNull('report_id')
                ->limit(2)
                ->get();

            if ($operations->count() === 1) {
                return Reports::find((int) $operations->first()->report_id);
            }

            if ($operations->count() > 1) {
                // Structurally impossible under the global unique index, and
                // if it ever happens it is ambiguity, not a tie to break.
                return null;
            }

            // 2. Legacy packed-status correlation, bounded and escaped.
            $escaped = addcslashes($providerMessageId, '%_\\');

            $query = Reports::query()->where('status', 'like', '%|' . $escaped);

            if ($sendingServer !== null) {
                $query->where('sending_server_id', $sendingServer->id);
            }

            // Three is enough to tell "one" from "more than one" without
            // reading an unbounded candidate set into memory.
            $candidates = $query->limit(3)->get();

            $exact = $candidates->filter(static function ($report) use ($providerMessageId): bool {
                $packed = (string) $report->status;
                $separator = strrpos($packed, '|');

                return $separator !== false
                    && substr($packed, $separator + 1) === $providerMessageId;
            })->values();

            return $exact->count() === 1 ? $exact->first() : null;
        }

        /**
         * `customer_status` values that mean "this message did not arrive",
         * and therefore that the legacy path has credited its cost back.
         *
         * Derived from updateDLR()'s own mapping above, so the two cannot
         * drift: every branch of that match() except 'Delivered' lands here,
         * plus 'Enroute' and 'Accepted', which are NOT terminal and must
         * never trigger a credit.
         */
        private const NON_DELIVERED_TERMINAL_STATUSES = [
            'Undelivered',
            'Expired',
            'Skipped',
            'Rejected',
            'Failed',
        ];

        /**
         * Apply a delivery-status transition and any legitimate legacy
         * credit, exactly once, under a row lock.
         *
         * THE BUG THIS REPLACES. The credit used to be unconditional on
         * `$status !== 'Delivered'`, with no record of whether it had already
         * happened. Replaying one failed callback ten times credited the
         * customer ten times. On a route with no authenticity check, that is
         * free money.
         *
         * WHAT MAKES IT IDEMPOTENT NOW. The refund fires only on the
         * TRANSITION INTO a non-delivered terminal state — the row is
         * re-read under `lockForUpdate()` inside the transaction, and the
         * previous `customer_status` decides. A second identical callback
         * finds the row already terminal and credits nothing. That state is
         * durable and already persisted; no new column is invented, and
         * nothing is inferred from a value that could be lost.
         *
         * THE REVERSE DIRECTION IS HANDLED TOO. A late 'Delivered' after a
         * refund would otherwise leave the customer with a free message, so
         * the cost is re-debited on that transition. Existing legacy billing
         * semantics are preserved rather than quietly changed.
         *
         * No provider or network work happens inside this transaction.
         *
         * @return array{campaign_id: int|null, credited: bool, debited: bool}
         */
        private static function applyDeliveryTransition(
            Reports $report,
            string $status,
            string $customerStatus,
            string $providerMessageId
        ): array {
            return DB::transaction(static function () use ($report, $status, $customerStatus, $providerMessageId): array {
                $locked = Reports::whereKey($report->getKey())->lockForUpdate()->first();

                if ($locked === null) {
                    return ['campaign_id' => null, 'credited' => false, 'debited' => false];
                }

                $wasNonDelivered = in_array(
                    (string) $locked->customer_status,
                    self::NON_DELIVERED_TERMINAL_STATUSES,
                    true
                );

                $isNonDelivered = in_array($customerStatus, self::NON_DELIVERED_TERMINAL_STATUSES, true);

                $locked->update([
                    'status'          => $status . '|' . $providerMessageId,
                    'customer_status' => $customerStatus,
                ]);

                $credited = false;
                $debited = false;

                if ($isNonDelivered && ! $wasNonDelivered) {
                    // First entry into a non-delivered terminal state.
                    $locked->user?->update([
                        'sms_unit' => $locked->user->sms_unit + $locked->cost,
                    ]);
                    $credited = true;
                } elseif (! $isNonDelivered && $wasNonDelivered && $customerStatus === 'Delivered') {
                    // A late delivery after a refund — take the cost back,
                    // or the message was free.
                    $locked->user?->update([
                        'sms_unit' => $locked->user->sms_unit - $locked->cost,
                    ]);
                    $debited = true;
                }

                return [
                    'campaign_id' => $locked->campaign_id ? (int) $locked->campaign_id : null,
                    'credited'    => $credited,
                    'debited'     => $debited,
                ];
            });
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

            // Customer Experience Slice 3 §4.6.5 — the substring fallback is
            // GONE, and this is a security fix rather than a tidy-up.
            //
            // What used to be here, after an exact match failed:
            //
            //     PhoneNumbers::where('number', 'like', "%$from%")->first()
            //
            // On an UNAUTHENTICATED webhook, that attributed an inbound
            // message to whichever assigned number merely CONTAINED the
            // submitted string. A caller who sent `555` reached any tenant
            // whose number contains 555; two tenants on similar numbers
            // could receive each other's messages; and `->first()` picked a
            // winner by insertion order.
            //
            // Attribution is now exactly what its name says: one exact match
            // on an assigned number, or nothing. Zero matches fail closed.
            // Several matches fail closed too — an ambiguous mapping is not
            // resolved by picking one, which was the whole defect.
            //
            // `$from` was already normalized above by the same
            // str_replace() the rest of this method uses, so no second
            // normalization scheme is introduced here.
            $assignedMatches = PhoneNumbers::where('number', $from)
                ->where('status', 'assigned')
                ->limit(2)
                ->get();

            $phone_number = $assignedMatches->count() === 1 ? $assignedMatches->first() : null;

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

                // The THIRD blank-uid writer. `chat_boxes.uid` is a NOT NULL
                // char(36) with no database default and ChatBox mints none,
                // so this inbound writer was also relying on a non-strict
                // MySQL connection to coerce the missing value to ''. The
                // other two live in EloquentCampaignRepository.
                //
                // The uid goes in the UPDATE-OR-CREATE VALUES, not in the
                // match attributes, and `updateOrCreate` only applies those
                // values to a row it CREATES... which is not true — it
                // applies them on update too. So it is supplied through the
                // firstOrNew/save pair instead, which lets an existing
                // conversation keep the uid it already has: replaying an
                // inbound message must not re-issue a new identifier for a
                // conversation that already exists.
                $chatBox = ChatBox::firstOrNew([
                    'user_id' => $user_id,
                    'from'    => $from,
                    'to'      => $to,
                ]);

                if (! $chatBox->exists) {
                    $chatBox->uid = (string) Str::uuid();
                }

                $chatBox->reply_by_customer = true;
                $chatBox->sending_server_id = $sending_server->id;
                $chatBox->save();
                
                
                
                
                
                
                
                
                
                
                
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
                // Customer Experience Slice 3 §4.6.5 — the shared fail-open
                // boundary fix, for every provider that flows through this
                // method. Previously an unattributable inbound message was
                // written against whatever $user_id happened to be in scope
                // (defaulting to 1), silently handing one tenant's message to
                // another. There is no authoritative attribution here, so
                // nothing is attributed: no Reports row, no ChatBox row, and
                // no STOP/blacklist processing.
                //
                // This is deliberately NOT a claim that the other ~58
                // providers now have signature verification — they do not.
                // Only the unattributed write is removed.
                // The provider is taken from the calling server, never
                // assumed: this method serves ~60 gateways and a row that
                // named the wrong one would be worse than no row at all.
                app(MessagingWebhookRejectionRecorder::class)->record(
                    WebhookRejectionReason::UnknownMapping,
                    $sending_server->settings,
                    (string) json_encode([
                        'sending_server_id' => $sending_server->id,
                        'to' => $to,
                    ]),
                    null,
                    $from,
                );

                // The method's existing generic "processed" response shape, so
                // no legacy provider's polling/webhook expectations break.
                if ($failed == null) {
                    return $success;
                }

                return $failed;
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

        /**
         * Customer Experience Slice 3 §4.6.1 — the managed Telnyx inbound
         * route's entry point.
         *
         * Delegates immediately to the dual-signal attribution resolver: this
         * method deliberately contains no attribution logic of its own, so the
         * fail-closed rules live in exactly one place.
         */
        public function inboundTelnyxManaged(Request $request): JsonResponse
        {
            return app(InboundWebhookAttributionResolver::class)->handle($request);
        }

        /**
         * Slice 3 §4.6.5 — true Twilio request-signature verification.
         *
         * Returns false when no active Twilio sending server's auth_token
         * validates the signature, including when the header is absent.
         */
        private function twilioSignatureIsValid(Request $request, string $provider = SendingServer::TYPE_TWILIO): bool
        {
            $signature = $request->header('X-Twilio-Signature');

            if (! is_string($signature) || $signature === '') {
                return false;
            }

            $url = $request->fullUrl();
            $params = $request->isMethod('POST') ? $request->post() : [];

            // The provider discriminator lives in `settings`, not in the
            // `type` column — `type` is the transport enum
            // (http/smpp/whatsapp/viber/otp). getSendingServer() above reads
            // the same `settings` column for exactly this reason.
            //
            // `$provider` lets the TwilioCopilot sibling reuse this exact
            // validator rather than growing a second copy of it (Security
            // Correction 36). It defaults to plain Twilio, so every existing
            // caller is unchanged.
            $servers = SendingServer::query()
                ->where('status', true)
                ->where('settings', $provider)
                ->get();

            foreach ($servers as $server) {
                $authToken = $server->auth_token ?? null;

                if (! is_string($authToken) || $authToken === '') {
                    continue;
                }

                $validator = new \Twilio\Security\RequestValidator($authToken);

                if ($validator->validate($signature, $url, $params)) {
                    return true;
                }
            }

            return false;
        }

        /**
         * Slice 3 §4.6.5 — read-only check for whether a sending server is a
         * Business-facing BYO connection.
         *
         * Read-only by design: no column, cast or method is added to
         * SendingServer or CustomerBasedSendingServer.
         */
        /**
         * The only media types this inbound handler genuinely supports —
         * the image/video/audio cases its own switch declares — mapped to
         * the extension the STORED file gets.
         *
         * Deliberately small. A type that is not here cannot be stored,
         * which is the point: an allowlist that grows to accommodate
         * whatever arrives is not an allowlist.
         */
        private const INBOUND_MEDIA_ALLOWLIST = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
            'video/mp4'  => 'mp4',
            'video/3gpp' => '3gp',
            'audio/mpeg' => 'mp3',
            'audio/ogg'  => 'ogg',
            'audio/mp4'  => 'm4a',
            'audio/aac'  => 'aac',
        ];

        /** 16 MB, comfortably above any real MMS and far below a disk-filling one. */
        private const INBOUND_MEDIA_MAX_BYTES = 16 * 1024 * 1024;

        /**
         * Store inbound media under a generated name, deriving the extension
         * from the bytes actually received rather than from anything the
         * provider claimed.
         *
         * @return string|null the generated filename, or null when the
         *                     payload is missing, oversized, or of a type
         *                     this handler does not support — in which case
         *                     NOTHING is written
         */
        private static function storeInboundMediaSafely($payload): ?string
        {
            if ( ! is_string($payload) || $payload === '') {
                return null;
            }

            if (strlen($payload) > self::INBOUND_MEDIA_MAX_BYTES) {
                return null;
            }

            // The real type, from the content. finfo reads magic bytes; it
            // does not ask the caller what they think they sent.
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($payload);

            if ( ! is_string($mime) || ! array_key_exists($mime, self::INBOUND_MEDIA_ALLOWLIST)) {
                return null;
            }

            $extension = self::INBOUND_MEDIA_ALLOWLIST[$mime];

            // Generated, not derived from provider input. No separators, no
            // traversal, no NUL, nothing to sanitize — because nothing the
            // caller sent reaches this name.
            $storedName = bin2hex(random_bytes(16)) . '.' . $extension;

            $directory = public_path('mms');

            if ( ! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
                return null;
            }

            $target = $directory . DIRECTORY_SEPARATOR . $storedName;

            // Belt and braces: the resolved parent must be the directory we
            // intended, so no symlink or race can redirect the write.
            if (realpath($directory) === false || dirname($target) !== $directory) {
                return null;
            }

            if (file_put_contents($target, $payload) === false) {
                return null;
            }

            @chmod($target, 0644);

            return $storedName;
        }

        /**
         * Security Correction 36 — whether a legacy Telnyx callback on this
         * connection can be proven authentic AT ALL.
         *
         * It cannot. This application stores no per-connection Ed25519
         * public key for a legacy Telnyx SendingServer — there is no column
         * for one — so nothing about such a request is verifiable, whether
         * the connection is a customer's BYO one or an admin/legacy one.
         *
         * The honest consequence is that neither gets to change state. An
         * admin connection is not given an unauthenticated exception merely
         * to preserve old behaviour: "we have always done it" is not a
         * signature. Managed Telnyx traffic has the signed managed route
         * (§4.6.1) and does not come through here.
         *
         * When BYO Telnyx inbound is upgraded in Slice 9 and real
         * verification material exists, this is the one place that changes.
         */
        private function hasVerifiableTelnyxAuthenticity(SendingServer $sendingServer): bool
        {
            return false;
        }

        private function isBusinessFacingByoConnection(SendingServer $sendingServer): bool
        {
            // The link column is `sending_server`, holding the SendingServer's
            // own id — see CustomerBasedSendingServer::sendingServer().
            return CustomerBasedSendingServer::query()
                ->where('sending_server', $sendingServer->id)
                ->exists();
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

            // Customer Experience Slice 3 §4.6.5 — BYO Twilio, Option A.
            // The route carries no tenant identifier and more than one Twilio
            // SendingServer may be active, so verification iterates the active
            // Twilio servers and accepts the first whose auth_token validates
            // this request's signature. A genuine HMAC match against an
            // independently-set secret is itself strong evidence of which
            // account produced the request. A request that validates against
            // none of them never reaches inboundDLR().
            if (! $this->twilioSignatureIsValid($request)) {
                // Twilio, recorded as Twilio. This row exists to answer
                // "which provider is sending us traffic we cannot verify?",
                // so naming a different company in it would defeat its
                // entire purpose.
                // NOTE ON `$from`, because it reads backwards and an audit
                // has already misread it once: this method assigns
                // `$to = $request->input('From')` and
                // `$from = $request->input('To')` (see the top of this
                // method). The local `$from` therefore holds the RECEIVING
                // number — our own — which is exactly what
                // `destinationNumber` wants. `$to` is the external sender
                // and must NOT be recorded here.
                app(MessagingWebhookRejectionRecorder::class)->record(
                    WebhookRejectionReason::InvalidSignature,
                    SendingServer::TYPE_TWILIO,
                    $request->getContent(),
                    null,
                    destinationNumber: $from,
                );

                return $response->message('Invalid signature');
            }

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

            // Security Correction 36 — the Twilio sibling bypass.
            //
            // TwilioCopilot IS a Twilio webhook: same signature scheme, same
            // `X-Twilio-Signature` header, same validator this repository
            // already uses for inboundTwilio(). It simply never called it, so
            // the gate §4.6.5 put on one Twilio door left the identical door
            // beside it open.
            //
            // No new signature scheme is invented here; the canonical
            // validator is reused, scoped to the TwilioCopilot servers.
            if (! $this->twilioSignatureIsValid($request, SendingServer::TYPE_TWILIOCOPILOT)) {
                app(MessagingWebhookRejectionRecorder::class)->record(
                    WebhookRejectionReason::InvalidSignature,
                    SendingServer::TYPE_TWILIOCOPILOT,
                    $request->getContent(),
                    null,
                    destinationNumber: $from,
                );

                return $response->message('Invalid signature');
            }


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

                    // Customer Experience Slice 3 §4.6.5 — BYO Telnyx,
                    // Option B: fail closed until upgraded. No SendingServer
                    // column exists to hold a BYO customer's own Ed25519
                    // webhook public key, so this request's authenticity
                    // cannot be verified at all. Rather than process an
                    // unverifiable inbound message, a Business-facing BYO
                    // connection has inbound processing disabled outright.
                    // Outbound sending is unaffected, and the relocated
                    // advanced-settings UI states this honestly.
                    //
                    // An admin-only/legacy Telnyx connection with no
                    // CustomerBasedSendingServer link is outside this gate and
                    // keeps its pre-existing behaviour, including the
                    // now-fixed shared default-to-user-1 removal.
                    if ($sendingServer && $this->isBusinessFacingByoConnection($sendingServer)) {
                        // Derived from the server rather than hardcoded.
                        // This one really is Telnyx, but taking it from the
                        // row keeps every rejection site honest by the same
                        // mechanism instead of by the reader's trust.
                        // As in inboundTwilio(): this method assigns
                        // `$to = payload.from.phone_number` and
                        // `$from = payload.to[0].phone_number`, so the local
                        // `$from` is the RECEIVING number and is the correct
                        // value for `destinationNumber`. Named explicitly so
                        // the inverted legacy naming cannot mislead again.
                        app(MessagingWebhookRejectionRecorder::class)->record(
                            WebhookRejectionReason::UnknownMapping,
                            $sendingServer->settings,
                            $request->getContent(),
                            null,
                            destinationNumber: $from,
                        );

                        // Nothing actionable to tell Telnyx: this is not a
                        // signature-verified party we owe a retry signal to.
                        return 'Inbound processing is disabled for this connection';
                    }





                    
                   $response = $this::inboundDLR($to, $message, $sendingServer, $cost, $from);



      
      
      
                    
                    
                }
                if ($get_data['data']['payload']['direction'] == 'outbound') {
                    // Security Correction 36, P0 — the sibling branch's
                    // bypass.
                    //
                    // §4.6.5 already disabled the INBOUND branch above for a
                    // Business-facing BYO Telnyx connection, because this
                    // application holds no per-connection Ed25519 material
                    // and therefore cannot verify that a callback really came
                    // from Telnyx. This branch was left calling updateDLR()
                    // directly — so the same unverifiable request could still
                    // mutate a customer-visible Report and move sms_unit,
                    // just through the delivery-status door instead of the
                    // inbound one. Closing one and leaving the other open
                    // closes nothing.
                    //
                    // Authenticity is a property of the CONNECTION, not of
                    // the payload's direction field, so the same gate
                    // applies. A managed Telnyx callback belongs on the
                    // signed managed route and does not need this path.
                    $telnyxServer = $this->getSendingServer(
                        $gateway ?: SendingServer::TYPE_TELNYX,
                        $gateway ? 'uid' : SendingServer::TYPE_TELNYX
                    );

                    if ($telnyxServer === null || ! $this->hasVerifiableTelnyxAuthenticity($telnyxServer)) {
                        app(MessagingWebhookRejectionRecorder::class)->record(
                            WebhookRejectionReason::InvalidSignature,
                            $telnyxServer?->settings ?? SendingServer::TYPE_TELNYX,
                            $request->getContent(),
                            null,
                            destinationNumber: $get_data['data']['payload']['to'][0]['phone_number'] ?? null,
                        );

                        return 'Delivery callbacks are disabled for this connection';
                    }

                    $message_id = $get_data['data']['payload']['id'];
                    $status     = $get_data['data']['payload']['to'][0]['status'];

                    if ($status == 'delivered' || $status == 'webhook_delivered') {
                        $status = 'Delivered';
                    }

                    $this::updateDLR($message_id, $status, $telnyxServer);
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
            $id_campanha = $data['id_campanha'] ?? null;

            $message       = $data['sms_resposta'] ?? null;
            $to            = $data['nro_telefone'] ?? null;
            $message_count = strlen(preg_replace('/\s+/', ' ', trim((string) $message))) / 160;
            $cost          = ceil($message_count);


            $sendingServer = $this->getSendingServer(
                $gateway ?: SendingServer::TYPE_SOLUCOESDIGITAIS,
                $gateway ? 'uid' : SendingServer::TYPE_SOLUCOESDIGITAIS
            );

            // Security Correction 36, P0.
            //
            // What was here: `Reports::where('status','LIKE',"%$id_campanha%")
            // ->first()`, on an unauthenticated route, with the matched row's
            // `from` then trusted as this Business's own sender. A caller who
            // sent `id_campanha=1` matched any tenant whose packed status
            // contained a 1 — and `%`/`_` were live wildcards, so a single
            // `%` matched everything. The winner was chosen by insertion
            // order, and the inbound message was then written against that
            // stranger's identity, with its STOP/keyword/blacklist side
            // effects.
            //
            // It now goes through the same exact, escaped,
            // sending-server-scoped, exactly-one resolution seam every other
            // delivery callback uses. Zero or ambiguous matches resolve to
            // null and nothing is written.
            $report = self::resolveReportForProviderMessage($id_campanha, $sendingServer);

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
                            curl_close($curl);

                            // Security Correction 36, P0 — arbitrary file
                            // write into the public web root.
                            //
                            // What was here: the PROVIDER-SUPPLIED
                            // `media.filename` concatenated straight onto
                            // `public_path('mms/')` and handed to
                            // file_put_contents(). A filename of
                            // `../../evil.php` wrote outside the directory
                            // entirely; a filename of `evil.php` wrote
                            // executable PHP inside the web root. On an
                            // unauthenticated route that is remote code
                            // execution, not a path bug.
                            //
                            // basename() alone would not have been a fix: it
                            // stops traversal but still lets the caller
                            // choose `.php`, and it still trusts the
                            // provider's claimed extension over the bytes
                            // actually received.
                            //
                            // The provider's filename now controls nothing.
                            // The stored name is generated, the extension is
                            // derived from the VERIFIED content, and content
                            // outside a small allowlist for the media types
                            // this handler genuinely supports is refused —
                            // in which case nothing is written at all.
                            $storedName = self::storeInboundMediaSafely($response);

                            if ($storedName === null) {
                                // Failed validation writes nothing, and the
                                // message is still delivered without media
                                // rather than dropped.
                                $mediaUrl = '';

                                break;
                            }

                            $mediaUrl = asset('/mms') . '/' . $storedName;

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

            // Security Correction 36, P0 — the identical defect
            // inboundSolucoesdigitais had. `whereLike(['status'], $textId)`
            // on an unauthenticated route selected any tenant's report whose
            // packed status merely contained the attacker's string, with
            // `%`/`_` live as wildcards, and its `from` was then trusted as
            // the sender for a forged inbound message.
            //
            // Resolved through the same exact, escaped,
            // sending-server-scoped, exactly-one seam. A foreign, partial,
            // wildcard or ambiguous textId resolves to nothing and writes
            // nothing.
            $textbeltServer = $this->getSendingServer(
                $gateway ?: SendingServer::TYPE_TEXTBELT,
                $gateway ? 'uid' : SendingServer::TYPE_TEXTBELT
            );

            $get_data = self::resolveReportForProviderMessage($message_id, $textbeltServer);

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

                // Security Correction 36 — the second Twilio bypass.
                //
                // This passed the literal STRING 'Twilio' where every other
                // caller passes a resolved SendingServer. A provider name
                // typed into an argument is not evidence that the request
                // came from that provider; it just skipped the signature
                // path entirely, and inboundDLR() then dereferenced a string
                // as if it were a model.
                //
                // The connection is resolved authoritatively and the request
                // is validated with the same canonical Twilio validator, or
                // nothing happens.
                $webhookServer = $this->getSendingServer(
                    SendingServer::TYPE_TWILIO,
                    SendingServer::TYPE_TWILIO
                );

                if ($webhookServer === null || ! $this->twilioSignatureIsValid($request)) {
                    app(MessagingWebhookRejectionRecorder::class)->record(
                        WebhookRejectionReason::InvalidSignature,
                        SendingServer::TYPE_TWILIO,
                        $request->getContent(),
                        null,
                        destinationNumber: $from,
                    );

                    return $response->message('Invalid signature');
                }

                $feedback = $this::inboundDLR($to, $message, $webhookServer, $cost, $from);

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
            // Customer Experience Slice 3 — secret-logging fix.
            //
            // This used to log `$request->all()`, which on the Meta
            // verification handshake below contains `hub_verify_token` — the
            // shared secret this endpoint exists to check. Logging the whole
            // request wrote that secret, in clear, to a log file that
            // outlives the request and is read by people who have no
            // business seeing it.
            //
            // Only minimized, non-secret metadata is recorded now: enough to
            // tell an operator that a request arrived and roughly what shape
            // it had, and nothing that could be replayed. The payload's
            // top-level KEYS are safe to name; its values are not, so they
            // are never touched.
            logger()->info('Inbound WhatsApp Payload', [
                'gateway' => $gateway,
                'mode' => $request->get('hub_mode'),
                'payload_keys' => array_values(array_diff(
                    array_keys($request->all()),
                    ['hub_verify_token', 'hub.verify_token'],
                )),
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

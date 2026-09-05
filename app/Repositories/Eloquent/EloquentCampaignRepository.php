<?php

    namespace App\Repositories\Eloquent;

    use App\Jobs\ImportCampaign;
    use App\Library\SMSCounter;
    use App\Library\SpinText;
    use App\Library\Tool;
    use App\Library\Usage\UsageWalletManager;
    use App\Models\Blacklists;
    use App\Models\BlockSenderId;
    use App\Models\Campaigns;
    use App\Models\CampaignsList;
    use App\Models\CampaignsSenderid;
    use App\Models\ChatBox;
    use App\Models\ChatBoxMessage;
    use App\Models\ContactGroups;
    use App\Models\Contacts;
    use App\Models\Country;
    use App\Models\CsvData;
    use App\Models\CustomerBasedPricingPlan;
    use App\Models\PhoneNumbers;
    use App\Models\PlansCoverageCountries;
    use App\Models\Reports;
    use App\Models\ScheduleMessage;
    use App\Models\Senderid;
    use App\Models\SendingServer;
    use App\Models\SendingServerBasedPricingPlans;
    use App\Models\SpamWord;
    use App\Models\Templates;
    use App\Models\TrackingLog;
    use App\Models\User;
    use App\Notifications\SendCampaignCopy;
    use App\Repositories\Contracts\BusinessUsageReservationRepository;
    use App\Repositories\Contracts\BusinessUsageWalletRepository;
    use App\Repositories\Contracts\CampaignRepository;
    use App\Repositories\Contracts\UsageMeterRepository;
    use App\Enums\Usage\UsageReservationStatus;
    use Carbon\Carbon;
    use Exception;
    use Illuminate\Http\JsonResponse;
    use Illuminate\Support\Facades\Auth;
    use Illuminate\Support\Facades\DB;
    use libphonenumber\NumberParseException;
    use libphonenumber\PhoneNumberUtil;
    use Throwable;

    class EloquentCampaignRepository extends EloquentBaseRepository implements CampaignRepository
    {
        /**
         * RFC-005 Milestone 5 — resolved lazily via app() inside the one
         * new guard-chain region only (§7/§9 of
         * docs/automation/RFC-005-M5-CONTRACT.md), never eagerly in the
         * constructor: every other quickSend() caller (bulk Quick Send,
         * contact-group welcome SMS, DLR auto-reply, third-party API)
         * never exercises this path, and this repository's own
         * constructor otherwise takes no dependency beyond Campaigns.
         */
        private function usageWalletManager(): UsageWalletManager
        {
            return app(UsageWalletManager::class);
        }

        private function usageMeterRepository(): UsageMeterRepository
        {
            return app(UsageMeterRepository::class);
        }

        private function usageReservationRepository(): BusinessUsageReservationRepository
        {
            return app(BusinessUsageReservationRepository::class);
        }

        private function usageWalletRepository(): BusinessUsageWalletRepository
        {
            return app(BusinessUsageWalletRepository::class);
        }

        /**
         * EloquentCampaignRepository constructor.
         */
        public function __construct(Campaigns $campaigns)
        {
            parent::__construct($campaigns);
        }

        /**
         * send quick message
         *
         *
         * @throws Throwable
         */
        public function quickSend(Campaigns $campaign, array $input, bool $conversationContext = false): JsonResponse
        {

            $user        = $input['user'];
            $sms_type    = $input['sms_type'];
            $sender_id   = $input['sender_id'];
            $region_code = $input['region_code'];

            $message = null;
            if (isset($input['message'])) {
                $message = $input['message'];
            }

            // RFC-005 Milestone 5 §6 step 0 — same-token pre-check, strictly
            // before any qualifying-tuple evaluation (including sms_type)
            // or legacy fallback. Unconditional on payload content: a
            // changed sms_type/country/server/sender/message under the
            // same token cannot escape an already-existing reservation's
            // own authoritative state. Only ever reached for a trusted
            // Conversation-context call carrying a client idempotency
            // token; every other caller (conversationContext defaults
            // false) skips this unchanged.
            if ($conversationContext && ! empty($input['idempotency_token'])) {
                $existingResponse = $this->resolveExistingConversationsReservation((int) $user->id, (string) $input['idempotency_token']);

                if ($existingResponse !== null) {
                    return $existingResponse;
                }
            }

            $country = Country::where('country_code', $input['country_code'])
                ->where('iso_code', $region_code)
                ->where('status', 1)
                ->first();

            if (empty($country)) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Permission to send an SMS has not been enabled for the region indicated by the 'To' number: " . $input['country_code'] . $input['recipient'],
                ]);
            }


            // Fetch the active subscription once and use it throughout
            $activeSubscriptionPlanId = $user->customer->activeSubscription()->plan_id;

            // You can chain where's like this for better readability
            $coverage = CustomerBasedPricingPlan::where([
                ['user_id', $user->id],
            ])->where('country_id', $country->id)->with('sendingServer')->first([
                'options',
                'country_id',
                'sending_server',
                'voice_sending_server',
                'mms_sending_server',
                'whatsapp_sending_server',
                'viber_sending_server',
                'otp_sending_server',
            ]);

            // If there's no coverage, query from PlansCoverageCountries
            if (empty($coverage)) {
                $coverage = PlansCoverageCountries::where('plan_id', $activeSubscriptionPlanId)
                    ->with('sendingServer')
                    ->where('country_id', $country->id)
                    ->first([
                        'options',
                        'country_id',
                        'sending_server',
                        'voice_sending_server',
                        'mms_sending_server',
                        'whatsapp_sending_server',
                        'viber_sending_server',
                        'otp_sending_server',
                    ]);
            }

            // Return error if coverage is still empty
            if (empty($coverage)) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Permission to send an SMS has not been enabled for the region indicated by the 'To' number: " . $input['country_code'] . $input['recipient'],
                ]);
            }

            // Define a map of $sms_type to sending server relationships
            $smsTypeToServerMap = [
                'unicode'  => 'plain',
                'voice'    => 'voiceSendingServer',
                'mms'      => 'mmsSendingServer',
                'whatsapp' => 'whatsappSendingServer',
                'viber'    => 'viberSendingServer',
                'otp'      => 'otpSendingServer',
            ];

            // Set a default sending server in case the $sms_type is not found in the map
            $defaultServer = 'sendingServer';
            $db_sms_type   = $sms_type == 'unicode' ? 'plain' : $sms_type;

            // Check if $input['sending_server'] is provided
            if (isset($input['sending_server'])) {
                $sending_server = SendingServer::where('status', true)->find($input['sending_server']);
            } else {
                // Use the map to get the sending server or fallback to the default
                $serverKey      = $smsTypeToServerMap[$db_sms_type] ?? $defaultServer;
                $sending_server = $coverage->{$serverKey};
            }

            if ( ! $sending_server) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.campaigns.sending_server_not_available'),
                ]);
            }

            if ( ! $sending_server->{$db_sms_type}) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.sending_servers.sending_server_sms_capabilities', ['type' => strtoupper($db_sms_type)]),
                ]);
            }


            if ($sending_server->settings != SendingServer::TYPE_VOICEANDTEXT && $sending_server->settings != SendingServer::TYPE_TERMII && $sending_server->settings != SendingServer::TYPE_ARKESEL && $message == null) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Your sending server is not capable to send upload file option. Please try with text to space option',
                ]);
            }


            $phone = str_replace(['(', ')', '+', '-', ' '], '', $input['country_code'] . $input['recipient']);

            $blacklist = Blacklists::where('user_id', $user->id)
                ->where('number', $phone)
                ->first();

            if ($blacklist) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Number contains in the blacklist',
                ]);
            }

            if (Tool::containsSpintaxPattern($message)) {
                $spintax = new SpinText();
                $message = $spintax->process($message);
            }

            // Decode the options
            $priceOption = json_decode($coverage['options'], true);

            if (config('app.gateway_wise_billing')) {
                $getCoverage = SendingServerBasedPricingPlans::where('sending_server', $sending_server->id)->where('country_id', $country->id)->first();
                if ( ! $getCoverage) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => "Permission to send an SMS has not been enabled for the region indicated by the 'To' number: " . $input['country_code'] . $input['recipient'],
                    ]);
                }

                $priceOption = json_decode($getCoverage->options, true);
            }

            $sms_counter  = new SMSCounter();
            $message_data = $sms_counter->count($message, $sms_type == 'whatsapp' ? 'WHATSAPP' : null);
            $sms_count    = $message_data->messages;

            $unit_price = 0;

            switch ($sms_type) {
                case 'plain':
                case 'unicode':
                    $unit_price = $priceOption['plain_sms'];
                    break;

                case 'voice':
                    $unit_price = $priceOption['voice_sms'];
                    if ($sms_count == 0) {
                        $sms_count = 1;
                    }
                    break;

                case 'mms':
                    $unit_price = $priceOption['mms_sms'];
                    if ($sms_count == 0) {
                        $sms_count = 1;
                    }
                    break;

                case 'whatsapp':
                    $unit_price = $priceOption['whatsapp_sms'];
                    break;

                case 'viber':
                    $unit_price = $priceOption['viber_sms'];
                    break;

                case 'otp':
                    $unit_price = $priceOption['otp_sms'];
                    break;
            }

            $price = $sms_count * $unit_price;

            // RFC-005 Milestone 5 §5.1/§6/§9 — full qualifying-send guard
            // chain, evaluated BEFORE the legacy sms_unit pre-check below
            // (charging exclusivity: a qualifying M5 send must never be
            // blocked by legacy balance, and must never charge both
            // systems). Evaluated only for a trusted Conversation-context
            // plain/unicode send that did not already resolve via step 0
            // above. Any failed condition (or $conversationContext being
            // false, the default for every non-ChatBox caller) leaves $m5
            // null/non-qualifying and both the legacy pre-check below and
            // the switch dispatch fall through to the completely
            // unmodified legacy path — zero new branches, zero new
            // queries, zero new columns written for a non-qualifying send.
            $m5 = null;

            if ($conversationContext && ($sms_type === 'plain' || $sms_type === 'unicode')) {
                $m5 = $this->qualifyConversationsMeterReservation(
                    $user,
                    $country,
                    $sending_server,
                    (string) $sms_count,
                    $input['idempotency_token'] ?? null,
                );

                if ($m5 !== null && $m5['response'] !== null) {
                    return $m5['response'];
                }
            }

            // Legacy sms_unit pre-check — replaced (not run alongside) for
            // a qualifying M5 send, which is funded by the RFC-005 wallet
            // instead (§5.1/§H exclusivity).
            if ($user->sms_unit != '-1' && $price > $user->sms_unit && ! ($m5 !== null && $m5['qualifies'])) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.campaigns.not_enough_balance', [
                        'current_balance' => $user->sms_unit,
                        'campaign_price'  => $price,
                    ]),
                ]);
            }

            $preparedData = [
                'user_id'        => $user->id,
                'phone'          => $phone,
                'sender_id'      => $sender_id,
                'message'        => $message,
                'sms_count'      => $sms_count,
                'cost'           => $price,
                'sending_server' => $sending_server,
                'sms_type'       => $sms_type,
            ];

            if (isset($input['api_key'])) {
                $preparedData['api_key'] = $input['api_key'];
            }

            if (isset($input['dlt_template_id'])) {
                $preparedData['dlt_template_id'] = $input['dlt_template_id'];
            }

            if (isset($user->dlt_entity_id)) {
                $preparedData['dlt_entity_id'] = $user->dlt_entity_id;
            }

            if (isset($user->dlt_telemarketer_id)) {
                $preparedData['dlt_telemarketer_id'] = $user->dlt_telemarketer_id;
            }

            $data = null;

            // RFC-005 Milestone 5 §C — null means "not an M5-qualifying
            // send, do not attach m5_token_action to the response at all";
            // any non-null value is the exact contract-locked action for
            // every response branch this call can still reach below.
            $m5TokenAction = null;

            // Exceptional correction — the response `status` a qualifying
            // M5 send returns must reflect the actual settled outcome, not
            // the legacy Delivered-substring heuristic alone:
            // accepted -> success, definitive_rejection -> error,
            // ambiguous_exception/absent marker -> processing. null means
            // "not an M5-qualifying send, preserve existing legacy status
            // exactly." m5_token_action's own 'clear' covers both accepted
            // and definitive_rejection, so it cannot alone distinguish
            // which response status applies — this reads the actual
            // transient $data->m5_outcome instead.
            $m5ResponseStatus = null;

            switch ($sms_type) {
                case 'plain':
                case 'unicode':
                    if ($m5 !== null && $m5['qualifies']) {
                        $preparedData['m5_conversations_usage_tracking'] = true;
                    }

                    $data = $campaign->sendPlainSMS($preparedData);

                    if ($m5 !== null && $m5['qualifies']) {
                        $m5TokenAction = $this->settleConversationsMeterReservation($m5['reservation_id'], $m5['business_id'], $data, (string) $sms_count);
                        $m5ResponseStatus = match (is_object($data) ? ($data->m5_outcome ?? null) : null) {
                            'accepted' => 'success',
                            'definitive_rejection' => 'error',
                            default => 'processing',
                        };
                    }
                    break;

                case 'voice':
                    $preparedData['language'] = $input['language'];
                    $preparedData['gender']   = $input['gender'];

                    if (isset($input['media_url'])) {
                        $preparedData['media_url'] = $input['media_url'];
                    }

                    $data = $campaign->sendVoiceSMS($preparedData);
                    break;

                case 'mms':
                    $preparedData['media_url'] = $input['media_url'];
                    $data                      = $campaign->sendMMS($preparedData);
                    break;

                case 'whatsapp':
                    if (isset($input['media_url'])) {
                        $preparedData['media_url'] = $input['media_url'];
                    }
                    if (isset($input['language'])) {
                        $preparedData['language'] = $input['language'];
                    }
                    $data = $campaign->sendWhatsApp($preparedData);
                    break;

                case 'viber':
                    if (isset($input['media_url'])) {
                        $preparedData['media_url'] = $input['media_url'];
                    }

                    $data = $campaign->sendViber($preparedData);
                    break;

                case 'otp':
                    $data = $campaign->sendOTP($preparedData);
                    break;

            }


            if (is_object($data) && ! empty($data->status)) {
                if (substr_count($data->status, 'Delivered') == 1) {
                    // RFC-005 Milestone 5 §H — charging exclusivity: a
                    // qualifying M5 send already charged the RFC-005
                    // wallet via settleConversationsMeterReservation()
                    // above; legacy sms_unit must not also be decremented
                    // for that same send. Every non-qualifying send
                    // ($m5 === null or $m5['qualifies'] === false) keeps
                    // this exact, unmodified legacy deduction.
                    if ($user->sms_unit != '-1' && ! ($m5 !== null && $m5['qualifies'])) {
                        DB::transaction(function () use ($user, $price) {
                            $remaining_balance = $user->sms_unit - $price;
                            $user->update(['sms_unit' => $remaining_balance]);
                        });
                    }

                    if ($sending_server->two_way && isset($input['originator']) && $input['originator'] == 'phone_number' && ($sms_type == 'plain' || $sms_type == 'unicode' || $sms_type == 'mms')) {

                        $chatbox = ChatBox::firstOrNew([
                            'user_id'           => $user->id,
                            'from'              => $sender_id,
                            'to'                => $phone,
                            'sending_server_id' => $sending_server->id,
                        ]);

                        if ( ! $chatbox->exists) {
                            $chatbox->reply_by_customer = false;
                            $chatbox->save();
                        }

                        ChatBoxMessage::create([
                            'box_id'            => $chatbox->id,
                            'message'           => $message,
                            'direction'         => Reports::DIRECTION_OUTGOING,
                            'sms_type'          => 'plain',
                            'sending_server_id' => $sending_server->id,
                            'media_url'         => $input['media_url'] ?? null,
                            'send_by'           => $user->id,
                        ]);

                        $chatbox->update([
                            'reply_by_customer' => false,
                        ]);
                    }

                    // Prepare response data from the existing object
                    $responseData = [
                        'id'        => $data->id ?? null,
                        'uid'       => $data->uid ?? null,
                        'to'        => $data->to ?? null,
                        'from'      => $data->from ?? null,
                        'message'   => $data->message ?? null,
                        'status'    => $data->customer_status ?? null,
                        'cost'      => $data->cost ?? 0,
                        'sms_count' => $data->sms_count ?? 1,
                        'media_url' => $data->media_url ?? null,
                    ];

                    $successPayload = [
                        'status'  => $m5ResponseStatus ?? 'success',
                        'data'    => (object) $responseData,
                        'message' => __('locale.campaigns.message_successfully_delivered'),
                    ];

                    if ($m5TokenAction !== null) {
                        $successPayload['m5_token_action'] = $m5TokenAction;
                    }

                    return response()->json($successPayload);
                } else {
                    $infoPayload = [
                        'status'  => $m5ResponseStatus ?? 'info',
                        'message' => $data->customer_status,
                    ];

                    if ($m5TokenAction !== null) {
                        $infoPayload['m5_token_action'] = $m5TokenAction;
                    }

                    return response()->json($infoPayload);
                }
            }

            return response()->json([
                'status'  => 'info',
                'message' => __('locale.exceptions.something_went_wrong'),
            ]);
        }

        /**
         * RFC-005 Milestone 5 §6.1's exact locked derivation —
         * business-namespaced (never user-namespaced), so a raw client
         * token can never resolve another Business's reservation even in
         * the astronomical-collision case. Shared by both the step-0
         * pre-check and the qualifying-tuple reservation attempt below.
         */
        private function conversationsIdempotencyKey(int $businessId, string $idempotencyToken): string
        {
            return hash('sha256', 'conversations_plain_sms:' . $businessId . ':' . $idempotencyToken);
        }

        /**
         * RFC-005 Milestone 5 §6 step 0. Returns a terminal JsonResponse
         * if an existing reservation already governs this exact token —
         * Committed/Pending/Released/Expired each get their own locked
         * response, with zero provider call, regardless of what the
         * current request's payload now says. Returns null only when no
         * reservation exists yet for this key, letting the qualifying-
         * send chain run for a genuinely new attempt.
         *
         * Resolves the Business needed for key namespacing via the raw,
         * unfiltered primaryBusiness() — deliberately NOT the cardinality/
         * pilot-tuple-qualified resolution — because an already-existing
         * reservation's own state must govern regardless of whether the
         * Workspace's Business cardinality has since changed (§E). A null
         * primaryBusiness here means no reservation could ever have been
         * created under it; the §5.1 qualifying chain's own fail-closed
         * handling is the sole authority for that case.
         */
        private function resolveExistingConversationsReservation(int $userId, string $idempotencyToken): ?JsonResponse
        {
            $business = $this->resolvePrimaryBusiness($userId);

            if ($business === null) {
                return null;
            }

            $key = $this->conversationsIdempotencyKey((int) $business->id, $idempotencyToken);
            $reservation = $this->usageReservationRepository()->findByIdempotencyKey($key);

            if ($reservation === null) {
                return null;
            }

            return match ($reservation->status) {
                UsageReservationStatus::Committed => response()->json([
                    'status' => 'success',
                    'message' => __('locale.campaigns.message_successfully_delivered'),
                    'm5_token_action' => 'clear',
                ]),
                UsageReservationStatus::Pending => response()->json([
                    'status' => 'processing',
                    'message' => 'Your previous message is still being sent.',
                    'm5_token_action' => 'retain',
                ]),
                default => response()->json([
                    'status' => 'error',
                    'message' => 'That send did not complete. Please try again.',
                    'm5_token_action' => 'clear',
                ]),
            };
        }

        /**
         * RFC-005 Milestone 5 §3.5 — the sending Customer's raw
         * primaryBusiness(), with NO cardinality or pilot-tuple check.
         * Used both for step-0 idempotency-key namespacing (unconditional
         * on the current request's tuple/payload) and as the shared entry
         * point for §3.5/§6 step 1's Business resolution. Cardinality/
         * pilot-tuple qualification is a separate, later check performed
         * only by qualifyConversationsMeterReservation() — never folded
         * into this raw resolution, so a null result here always means
         * "no primaryBusiness at all" (the fail-closed case), never "not
         * eligible due to Workspace shape."
         */
        private function resolvePrimaryBusiness(int $userId): ?\App\Models\Business
        {
            $user = User::with('customer.primaryBusiness.workspace')->find($userId);

            return $user?->customer?->primaryBusiness;
        }

        /**
         * RFC-005 Milestone 5 §5.1/§3.14/§6/§10 — the full qualifying
         * chain, plus (if every condition holds) the actual reserve()
         * call. Returns an array with:
         *  - 'response': non-null JsonResponse to return immediately
         *    (a fail-closed denial, an entitlement denial, or a
         *    wallet-state denial from reserve() itself), or null to
         *    continue to the provider call;
         *  - 'qualifies': bool — true only when reserve() itself created
         *    (or, on a genuine same-key race, resolved to) a usable
         *    Pending reservation this invocation may act on;
         *  - 'reservation_id': int|null;
         *  - 'business_id': int|null — set whenever 'qualifies' is true,
         *    for settleConversationsMeterReservation()'s own logging.
         */
        private function qualifyConversationsMeterReservation($user, ?Country $country, ?SendingServer $sendingServer, string $smsCount, ?string $idempotencyToken): ?array
        {
            if (empty($idempotencyToken)) {
                return ['response' => null, 'qualifies' => false, 'reservation_id' => null, 'business_id' => null];
            }

            // §3.5/§6 step 1 — Business resolution and ownership-scope
            // guard. A null primaryBusiness is a separate, fail-closed
            // case (unreachable once the null-primaryBusiness precondition
            // holds; if hit anyway in production, deny outright and never
            // fall back to legacy sms_unit) — distinct from the
            // multi-Business-Workspace case immediately below, which falls
            // through to legacy instead of denying.
            $business = $this->resolvePrimaryBusiness((int) $user->id);

            if ($business === null) {
                \Log::warning('m5_conversations_null_primary_business', ['user_id' => $user->id]);

                return [
                    'response' => response()->json([
                        'status' => 'error',
                        'message' => __('locale.campaigns.not_enough_balance', [
                            'current_balance' => 0,
                            'campaign_price' => 0,
                        ]),
                        'm5_token_action' => 'retain',
                    ]),
                    'qualifies' => false,
                    'reservation_id' => null,
                    'business_id' => null,
                ];
            }

            $pilotBusinessId = config('usage_billing.conversations_metering.pilot_business_id');
            $pilotCountryId = config('usage_billing.conversations_metering.pilot_country_id');
            $pilotSendingServerId = config('usage_billing.conversations_metering.pilot_sending_server_id');

            if ($pilotBusinessId === null || $pilotCountryId === null || $pilotSendingServerId === null) {
                return ['response' => null, 'qualifies' => false, 'reservation_id' => null, 'business_id' => null];
            }

            if ($business->workspace === null || $business->workspace->businesses()->count() !== 1) {
                // Multi-Business Workspace: not a qualifying send at all —
                // falls through to legacy, never a guessed attribution.
                return ['response' => null, 'qualifies' => false, 'reservation_id' => null, 'business_id' => null];
            }

            if ((int) $business->id !== (int) $pilotBusinessId) {
                return ['response' => null, 'qualifies' => false, 'reservation_id' => null, 'business_id' => null];
            }

            if ($country === null || (int) $country->id !== (int) $pilotCountryId) {
                return ['response' => null, 'qualifies' => false, 'reservation_id' => null, 'business_id' => null];
            }

            if ($sendingServer === null || (int) $sendingServer->id !== (int) $pilotSendingServerId) {
                return ['response' => null, 'qualifies' => false, 'reservation_id' => null, 'business_id' => null];
            }

            if ($sendingServer->settings !== SendingServer::TYPE_TWILIO && $sendingServer->settings !== SendingServer::TYPE_TWILIOCOPILOT) {
                return ['response' => null, 'qualifies' => false, 'reservation_id' => null, 'business_id' => null];
            }

            $meterKey = 'conversations.pilot.' . $pilotBusinessId;
            $meter = $this->usageMeterRepository()->findByMeterKey($meterKey);

            if ($meter === null || ! $meter->is_metered || $meter->active_rate_id === null) {
                return ['response' => null, 'qualifies' => false, 'reservation_id' => null, 'business_id' => null];
            }

            if ($meter->business_id !== null && (int) $meter->business_id !== (int) $business->id) {
                return ['response' => null, 'qualifies' => false, 'reservation_id' => null, 'business_id' => null];
            }

            $wallet = $this->usageWalletRepository()->findByBusinessId((int) $business->id);

            if ($wallet === null || (int) $meter->currency_id !== (int) $wallet->currency_id) {
                return ['response' => null, 'qualifies' => false, 'reservation_id' => null, 'business_id' => null];
            }

            // §3.3/§10/§12 allowlist read-only dependency — EntitlementManager
            // remains the feature-availability/plan-entitlement authority;
            // Amendment 1 only decoupled wallet health from this decision,
            // it did not remove the decision itself. A denial here means
            // zero reservation and zero provider call — no reservation row
            // was ever created for this attempt, so the token remains safe
            // to retain.
            $decision = app(\App\Library\Entitlement\EntitlementManager::class)->decide(
                $business->workspace,
                $business,
                \App\Enums\Entitlement\PlatformFeature::Conversations->value,
                (int) $user->id,
            );

            if (! $decision->allowed) {
                return [
                    'response' => response()->json([
                        'status' => 'error',
                        'message' => $decision->reason,
                        'm5_token_action' => 'retain',
                    ]),
                    'qualifies' => false,
                    'reservation_id' => null,
                    'business_id' => null,
                ];
            }

            $key = $this->conversationsIdempotencyKey((int) $business->id, $idempotencyToken);

            $result = $this->usageWalletManager()->reserve($business, $meterKey, $key, $smsCount);

            if (! $result->granted) {
                // §6 step 4 case E — no reservation row was created for
                // this attempt; the token remains safe to retain.
                return [
                    'response' => response()->json([
                        'status' => 'error',
                        'message' => 'Your usage wallet cannot fund this message.',
                        'm5_token_action' => 'retain',
                    ]),
                    'qualifies' => false,
                    'reservation_id' => null,
                    'business_id' => null,
                ];
            }

            if (! $result->createdByThisInvocation) {
                // Genuine same-key race: another invocation already won
                // the insert. This invocation never calls the provider —
                // resolve exactly as step 0 would for the now-existing row.
                return [
                    'response' => $this->resolveExistingConversationsReservation((int) $user->id, $idempotencyToken)
                        ?? response()->json(['status' => 'processing', 'message' => 'Your message is being sent.', 'm5_token_action' => 'retain']),
                    'qualifies' => false,
                    'reservation_id' => null,
                    'business_id' => null,
                ];
            }

            return ['response' => null, 'qualifies' => true, 'reservation_id' => $result->reservationId, 'business_id' => (int) $business->id];
        }

        /**
         * RFC-005 Milestone 5 §3.9/§6 step 6 — Twilio/TwilioCopilot outcome
         * classification, read back off the non-persisted marker
         * SendCampaignSMS::sendPlainSMS() attaches to its returned Reports
         * row. 'accepted' commits; 'definitive_rejection' releases (a
         * genuine, non-throwing provider "not accepted" response is
         * definitive); 'ambiguous_exception' or an unexpectedly absent
         * marker leaves the reservation Pending for later manual
         * resolution (§6.2) rather than guessing, since a caught exception
         * leaves real provider acceptance genuinely uncertain. Returns the
         * exact m5_token_action for the caller to attach to its response.
         */
        private function settleConversationsMeterReservation(?int $reservationId, ?int $businessId, $data, string $smsCount): string
        {
            if ($reservationId === null) {
                return 'retain';
            }

            $outcome = is_object($data) ? ($data->m5_outcome ?? null) : null;

            if ($outcome === 'accepted') {
                $this->usageWalletManager()->commit($reservationId, $smsCount);

                return 'clear';
            }

            if ($outcome === 'definitive_rejection') {
                $this->usageWalletManager()->release($reservationId);

                return 'clear';
            }

            // 'ambiguous_exception', or no recognized marker at all: leave
            // Pending. ExpireStaleUsageReservations (§3.7) and the
            // operator's ResolveAmbiguousUsageReservation command (§6.2)
            // are the only mechanisms that ever move it out of that state.
            \Log::warning('m5_conversations_ambiguous_outcome', [
                'reservation_id' => $reservationId,
                'business_id' => $businessId,
            ]);

            return 'retain';
        }

        public function campaignBuilder(Campaigns $campaign, array $input): JsonResponse
        {
            $user     = Auth::user();
            $sms_type = $input['sms_type'];

            $validateData = $this->validateCampaignBuilder($user, $input);

            if ($validateData->getData()->status == 'error') {
                return response()->json([
                    'status'  => 'error',
                    'message' => $validateData->getData()->message,
                ]);
            }

            // Reduce database queries for contact group details
            $contactGroupIds = [];

            if ( ! empty($input['contact_groups'])) {
                $contactGroupIds = array_map('intval', $input['contact_groups']);
            }

            if (count($contactGroupIds) === 0) {

                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.campaign.select_group'),
                ]);
            }

            // Check if all contact group IDs belong to the user and insert campaign-to-contact-group associations
            $invalidGroupIds = array_diff($contactGroupIds, $user->customer->lists()->pluck('id')->toArray());

            if (count($invalidGroupIds) > 0) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.campaign.invalid_group'),
                ]);
            }

            $sender_id = $validateData->getData()->sender_id;

            if ( ! isset($input['message'])) {
                $message = null;
            } else {
                $message = $input['message'];
            }

            //create campaign
            $new_campaign = Campaigns::create([
                'user_id'       => $user->id,
                'campaign_name' => $input['name'],
                'message'       => $message,
                'sms_type'      => $sms_type,
                'status'        => Campaigns::STATUS_NEW,
            ]);

            if ( ! $new_campaign) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.exceptions.something_went_wrong'),
                ]);
            }

            if (isset($sender_id) && is_array($sender_id)) {
                $originator = $input['originator'] ?? null;
                foreach ($sender_id as $id) {
                    if (empty($id)) {
                        continue;
                    }

                    $new_campaign->senderids()->create([
                        'sender_id'  => $id,
                        'originator' => $originator,
                    ]);
                }
            }

            if (isset($input['dlt_template_id'])) {
                $new_campaign->dlt_template_id = $input['dlt_template_id'];
            }

            if (isset($input['sending_server'])) {
                $new_campaign->sending_server_id = $input['sending_server'];
            }

            if (isset($input['media_url'])) {
                $new_campaign->media_url = $input['media_url'];
            }

            $associations = [];
            foreach ($contactGroupIds as $groupId) {
                $associations[] = [
                    'campaign_id'     => $new_campaign->id,
                    'contact_list_id' => $groupId,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ];
            }

            CampaignsList::insert($associations);

            $getContacts = Contacts::whereIn('group_id', $contactGroupIds)->where('status', 'subscribe');
            $total       = $getContacts->count();

            if ($total == 0) {

                $new_campaign->delete();

                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.campaigns.contact_not_found'),
                ]);
            }

            if ($user->sms_unit != '-1') {
                $coverage = CustomerBasedPricingPlan::where('user_id', $user->id)
                    ->pluck('options', 'country_id')
                    ->toArray();

                if (count($coverage) < 1) {
                    $coverage = PlansCoverageCountries::where('plan_id', $input['plan_id'])
                        ->pluck('options', 'country_id')
                        ->toArray();
                }

                if (empty($coverage)) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'Please add coverage on your plan.',
                    ]);
                }

                $subscriber = $getContacts->first();

                try {
                    $phoneUtil         = PhoneNumberUtil::getInstance();
                    $phoneNumberObject = $phoneUtil->parse('+' . $subscriber->phone);
                    $country_code      = $phoneNumberObject->getCountryCode();
                    $iso_code          = $phoneUtil->getRegionCodeForNumber($phoneNumberObject);

                    $country = Country::where('country_code', $country_code)
                        ->where('iso_code', $iso_code)
                        ->where('status', 1)
                        ->first();


                    if (empty($country) || array_key_exists($country->id, $coverage) === false) {
                        return response()->json([
                            'status'  => 'error',
                            'message' => "Permission to send an SMS has not been enabled for the region indicated by the 'To' number: " . $subscriber->phone,
                        ]);
                    }

                    if (isset($coverage[$country->id])) {
                        $priceOption = json_decode($coverage[$country->id], true);
                        $sms_count   = 1;

                        if (isset($input['message'])) {
                            $sms_counter  = new SMSCounter();
                            $message_data = $sms_counter->count($input['message'], $sms_type == 'whatsapp' ? 'WHATSAPP' : null);
                            $sms_count    = $message_data->messages;
                        }

                        $sms_type_prices = [
                            'plain'    => 'plain_sms',
                            'unicode'  => 'plain_sms',
                            'voice'    => 'voice_sms',
                            'mms'      => 'mms_sms',
                            'whatsapp' => 'whatsapp_sms',
                            'viber'    => 'viber_sms',
                            'otp'      => 'otp_sms',
                        ];

                        if (isset($sms_type_prices[$sms_type])) {
                            $unit_price = $priceOption[$sms_type_prices[$sms_type]];
                            $price      = $total * $unit_price;
                            $price      *= $sms_count;

                            $balance = $user->sms_unit;

                            if ($price > $balance) {
                                $new_campaign->delete();

                                return response()->json([
                                    'status'  => 'error',
                                    'message' => __('locale.campaigns.not_enough_balance', [
                                        'current_balance' => $balance,
                                        'campaign_price'  => $price,
                                    ]),
                                ]);
                            }
                        } else {
                            return response()->json([
                                'status'  => 'error',
                                'message' => 'Invalid SMS type: ' . $sms_type,
                            ]);
                        }
                    } else {
                        return response()->json([
                            'status'  => 'error',
                            'message' => "Permission to send an SMS has not been enabled for the region indicated by the 'To' number: " . $subscriber->phone,
                        ]);
                    }
                } catch (NumberParseException $exception) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => $exception->getMessage(),
                    ]);
                }
            }

            if (isset($input['advanced']) && $input['advanced'] == 'true') {
                if (isset($input['send_copy']) && $input['send_copy'] == 'true') {
                    $user->notify(new SendCampaignCopy($input['message'], route('customer.reports.campaign.edit', $new_campaign->uid)));
                }
                // if advanced set true then work with send copy to email and create template
                if (isset($input['create_template']) && $input['create_template'] == 'true') {
                    // create sms template
                    Templates::create([
                        'user_id' => $user->id,
                        'name'    => $input['name'],
                        'message' => $input['message'],
                        'status'  => true,
                    ]);
                }
            }

            // if schedule is available then check date, time and timezone
            if (isset($input['schedule']) && $input['schedule'] == 'true') {

                $schedule_date = $input['schedule_date'] . ' ' . $input['schedule_time'];
                $schedule_time = Tool::systemTimeFromString($schedule_date, $input['timezone']);

                $new_campaign->timezone      = $input['timezone'];
                $new_campaign->status        = Campaigns::STATUS_SCHEDULED;
                $new_campaign->schedule_time = $schedule_time;
                $new_campaign->run_at        = $schedule_time;

                if ($input['frequency_cycle'] == 'onetime') {
                    // working with onetime schedule
                    $new_campaign->schedule_type = Campaigns::TYPE_ONETIME;
                } else {
                    // working with recurring schedule
                    //if schedule time frequency is not one time then check frequency details
                    $recurring_date = $input['recurring_date'] . ' ' . $input['recurring_time'];
                    $recurring_end  = Tool::systemTimeFromString($recurring_date, $input['timezone']);

                    $new_campaign->schedule_type = Campaigns::TYPE_RECURRING;
                    $new_campaign->recurring_end = $recurring_end;

                    if (isset($input['frequency_cycle'])) {
                        if ($input['frequency_cycle'] != 'custom') {
                            $schedule_cycle                 = $campaign::scheduleCycleValues();
                            $limits                         = $schedule_cycle[$input['frequency_cycle']];
                            $new_campaign->frequency_cycle  = $input['frequency_cycle'];
                            $new_campaign->frequency_amount = $limits['frequency_amount'];
                            $new_campaign->frequency_unit   = $limits['frequency_unit'];
                        } else {
                            $new_campaign->frequency_cycle  = $input['frequency_cycle'];
                            $new_campaign->frequency_amount = $input['frequency_amount'];
                            $new_campaign->frequency_unit   = $input['frequency_unit'];
                        }
                    }
                }
            } else {
                $new_campaign->status = Campaigns::STATUS_QUEUING;
                $new_campaign->run_at = Carbon::now(config('app.timezone'))->format('Y-m-d H:i');
            }

            //update cache
            $new_campaign->cache = json_encode([
                'ContactCount'         => $total,
                'DeliveredCount'       => 0,
                'FailedDeliveredCount' => 0,
                'NotDeliveredCount'    => 0,
            ]);

            if ($sms_type == 'voice') {
                $new_campaign->language = $input['language'];
                $new_campaign->gender   = $input['gender'];
            }

            if ($sms_type == 'mms') {
                $new_campaign->media_url = Tool::uploadImage($input['mms_file']);
            }

            if ($sms_type == 'whatsapp') {

                if (isset($input['language']) && $input['language'] != '0') {
                    $new_campaign->language = $input['language'];
                }

                if (isset($input['mms_file'])) {
                    $new_campaign->media_url = Tool::uploadImage($input['mms_file']);
                }
            }

            if ($sms_type == 'viber') {
                if (isset($input['mms_file'])) {
                    $new_campaign->media_url = Tool::uploadImage($input['mms_file']);
                }
            }

            //finally, store data and return response
            $camp = $new_campaign->save();

            if ($camp) {
                
                
                
                
                
                
                
                $contacts = Contacts::whereIn('group_id', $contactGroupIds)
    ->where('status', 'subscribe')
    ->get();

$boxIds = [];

foreach ($contacts as $contact) {
    $phone = preg_replace('/\D+/', '', $contact->phone);

    $boxId = DB::table('chat_boxes')->insertGetId([
        'user_id'    => $user->id,
        'to'         => $phone,
        'from'       => $sender_id[0] ?? null,
        'ai_stage'   => 1, // THIS is what makes "Stage 1" count
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $boxIds[] = $boxId;
}

// now map them to campaign
$mapRows = [];

foreach ($boxIds as $boxId) {
    $mapRows[] = [
        'box_id'     => $boxId,
        'campaign_id'=> $new_campaign->id,
        'created_at' => now(),
    ];
}

DB::table('ai_box_campaign_map')->insert($mapRows);
                
                
                
                
                
                
                
                
                
                
                
                
                
                
                
                
                
                
                
                
                
                
                
                
                
                
                
                

                $getCount = $new_campaign->subscribersToSend()->count();
                if ($getCount == 0) {
                    $new_campaign->delete();

                    return response()->json([
                        'status'  => 'error',
                        'message' => __('locale.campaigns.contact_not_found'),
                    ]);
                }


                try {
                    $new_campaign->execute();

                    return response()->json([
                        'status'  => 'success',
                        'message' => __('locale.campaigns.campaign_send_successfully'),
                    ]);
                } catch (Throwable $exception) {
                    $new_campaign->delete();

                    return response()->json([
                        'status'  => 'error',
                        'message' => $exception->getMessage(),
                    ]);
                }
            }

            $new_campaign->delete();

            return response()->json([
                'status'  => 'success',
                'message' => __('locale.exceptions.something_went_wrong'),
            ]);
        }

        public function validateCampaignBuilder($user, $input)
        {

            $customer = $user->customer;

            if ($user->sms_unit != '-1' && $user->sms_unit == 0) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.campaigns.sending_limit_exceed'),
                ]);
            }

            $sms_type = $input['sms_type'];

            if (isset($input['sending_server'])) {
                $sending_server = SendingServer::where('status', true)->find($input['sending_server']);

                if ( ! $sending_server) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => __('locale.campaigns.sending_server_not_available'),
                    ]);
                }

                $db_sms_type = $sms_type == 'unicode' ? 'plain' : $sms_type;

                if ( ! $sending_server->{$db_sms_type}) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => __('locale.sending_servers.sending_server_sms_capabilities', ['type' => strtoupper($db_sms_type)]),
                    ]);
                }

            }

            if ($customer->getOption('send_spam_message') == 'no' && isset($input['message'])) {
                $spamWordCount = SpamWord::whereIn('word', array_map('strtolower', explode(' ', $input['message'])))->count();

                if ($spamWordCount > 0) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'Your message contains spam words.',
                    ]);
                }
            }

            $capabilities_type = in_array($sms_type, ['plain', 'unicode']) ? 'sms' : $sms_type;

            $sender_id = null;

            if ($customer->getOption('sender_id_verification') == 'yes') {
                if (isset($input['originator'])) {
                    if ($input['originator'] == 'sender_id') {
                        if ( ! isset($input['sender_id'])) {
                            return response()->json([
                                'status'  => 'error',
                                'message' => __('locale.sender_id.sender_id_required'),
                            ]);
                        }

                        $sender_id = $input['sender_id'];

                        if (is_array($sender_id) && count($sender_id) > 0) {
                            $senderids = Senderid::where('user_id', $user->id)
                                ->where('status', 'active')
                                ->pluck('sender_id')
                                ->all();

                            $invalid = array_diff($sender_id, $senderids);

                            if (count($invalid)) {
                                return response()->json([
                                    'status'  => 'error',
                                    'message' => __('locale.sender_id.sender_id_invalid', ['sender_id' => $invalid[0]]),
                                ]);
                            }
                        } else {
                            return response()->json([
                                'status'  => 'error',
                                'message' => __('locale.sender_id.sender_id_required'),
                            ]);
                        }
                    } else {
                        if ( ! isset($input['phone_number'])) {
                            return response()->json([
                                'status'  => 'error',
                                'message' => __('locale.sender_id.phone_numbers_required'),
                            ]);
                        }

                        $sender_id = $input['phone_number'];

                        if (is_array($sender_id) && count($sender_id) > 0) {
                            $type_supported = [];
                            $numbers        = PhoneNumbers::where('user_id', $user->id)
                                ->where('status', 'assigned')
                                ->cursor();

                            foreach ($numbers as $number) {
                                if (in_array($number->number, $sender_id) && ! str_contains($number->capabilities, $capabilities_type)) {
                                    $type_supported[] = $number->number;
                                }
                            }

                            if (count($type_supported)) {
                                return response()->json([
                                    'status'  => 'error',
                                    'message' => __('locale.sender_id.sender_id_sms_capabilities', ['sender_id' => $type_supported[0], 'type' => $sms_type]),
                                ]);
                            }
                        } else {
                            return response()->json([
                                'status'  => 'error',
                                'message' => __('locale.sender_id.sender_id_required'),
                            ]);
                        }
                    }
                } else {
                    return response()->json([
                        'status'  => 'error',
                        'message' => __('locale.sender_id.sender_id_required'),
                    ]);
                }
            } else if ($user->can('view_numbers') && isset($input['originator']) && $input['originator'] == 'phone_number' && isset($input['phone_number'])) {
                $sender_id = $input['phone_number'];

                if (is_array($sender_id) && count($sender_id) > 0) {
                    $type_supported = [];
                    $numbers        = PhoneNumbers::where('user_id', $user->id)
                        ->where('status', 'assigned')
                        ->cursor();

                    foreach ($numbers as $number) {
                        if (in_array($number->number, $sender_id) && ! str_contains($number->capabilities, $capabilities_type)) {
                            $type_supported[] = $number->number;
                        }
                    }

                    if (count($type_supported)) {
                        return response()->json([
                            'status'  => 'error',
                            'message' => __('locale.sender_id.sender_id_sms_capabilities', ['sender_id' => $type_supported[0], 'type' => $sms_type]),
                        ]);
                    }
                } else {
                    return response()->json([
                        'status'  => 'error',
                        'message' => __('locale.sender_id.sender_id_required'),
                    ]);
                }
            } else {
                if (isset($input['originator'])) {
                    if ($input['originator'] == 'sender_id') {
                        if ( ! isset($input['sender_id'])) {
                            return response()->json([
                                'status'  => 'error',
                                'message' => __('locale.sender_id.sender_id_required'),
                            ]);
                        }

                        $sender_id = $input['sender_id'];
                    } else {
                        if ( ! isset($input['phone_number'])) {
                            return response()->json([
                                'status'  => 'error',
                                'message' => __('locale.sender_id.phone_numbers_required'),
                            ]);
                        }

                        $sender_id = $input['phone_number'];
                    }

                    if ( ! is_array($sender_id) || count($sender_id) <= 0) {
                        return response()->json([
                            'status'  => 'error',
                            'message' => __('locale.sender_id.sender_id_required'),
                        ]);
                    }
                }

                if (isset($input['sender_id'])) {
                    $sender_id           = $input['sender_id'];
                    $input['originator'] = 'sender_id';
                }
            }


            if (isset($sender_id) && isset($input['originator']) && $input['originator'] == 'sender_id' && is_array($sender_id) && BlockSenderId::where('sender_id', $sender_id['0'])->exists()) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.block_senderid.sender_id_blocked', ['sender_id' => $sender_id['0']]),
                ], 403);
            }

            return response()->json([
                'status'    => 'success',
                'sender_id' => $sender_id,
                'sms_type'  => $sms_type,
            ]);

        }

        /**
         * @throws Throwable
         */
        public function sendApi(Campaigns $campaign, array $input): JsonResponse
        {
            $user = User::where('status', true)->where('api_token', $input['api_key'])->first();

            if ( ! $user) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.auth.user_not_exist'),
                ]);
            }

            if ($user->sms_unit != '-1' && $user->sms_unit == 0) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.campaigns.sending_limit_exceed'),
                ]);
            }

            $sending_server = null;
            if (isset($input['sending_server'])) {
                $sending_server = SendingServer::find($input['sending_server']);
                if ( ! $sending_server) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => __('locale.campaigns.sending_server_not_available'),
                    ]);
                }
            }

            $sms_type = $input['sms_type'];

            if ($user->customer->getOption('send_spam_message') == 'no') {
                $spamWords = SpamWord::whereRaw("LOWER(?) LIKE CONCAT('%', LOWER(word), '%')", [$input['message']])->get();
                if ($spamWords->isNotEmpty()) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'Your message contains spam words.',
                    ]);
                }
            }

            if ( ! $user->customer->activeSubscription()) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.subscription.no_active_subscription'),
                ]);
            }

            $db_sms_type       = $sms_type == 'unicode' ? 'plain' : $sms_type;
            $capabilities_type = in_array($sms_type, ['plain', 'unicode']) ? 'sms' : $sms_type;

            $sender_id = null;
            if ($user->customer->getOption('sender_id_verification') == 'yes') {
                if (isset($input['originator'])) {
                    if ($input['originator'] == 'sender_id' && isset($input['sender_id'])) {
                        $sender_id = $input['sender_id'];
                    } else if ($input['originator'] == 'phone_number' && isset($input['phone_number'])) {
                        $sender_id = $input['phone_number'];
                    }
                } else if (isset($input['sender_id'])) {
                    $sender_id = $input['sender_id'];
                }

                $check_sender_id = Senderid::where('user_id', $user->id)->where('sender_id', $sender_id)->where('status', 'active')->first();
                if ( ! $check_sender_id) {
                    $number = PhoneNumbers::where('user_id', $user->id)->where('number', $sender_id)->where('status', 'assigned')->first();

                    if ( ! $number) {
                        return response()->json([
                            'status'  => 'error',
                            'message' => __('locale.sender_id.sender_id_invalid', ['sender_id' => $sender_id]),
                        ]);
                    }

                    $capabilities = str_contains($number->capabilities, $capabilities_type);

                    if ( ! $capabilities) {
                        return response()->json([
                            'status'  => 'error',
                            'message' => __('locale.sender_id.sender_id_sms_capabilities', ['sender_id' => $sender_id, 'type' => $db_sms_type]),
                        ]);
                    }

                }
            } else if ($user->can('view_numbers') && isset($input['originator']) && $input['originator'] == 'phone_number' && isset($input['phone_number'])) {

                $sender_id = $input['phone_number'];

                $number = PhoneNumbers::where('user_id', $user->id)->where('number', $sender_id)->where('status', 'assigned')->first();

                if ( ! $number) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => __('locale.sender_id.sender_id_invalid', ['sender_id' => $sender_id]),
                    ]);
                }

                $capabilities = str_contains($number->capabilities, $capabilities_type);

                if ( ! $capabilities) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => __('locale.sender_id.sender_id_sms_capabilities', ['sender_id' => $sender_id, 'type' => $db_sms_type]),
                    ]);
                }

            } else if (isset($input['sender_id'])) {
                $sender_id = $input['sender_id'];
            }

            // update manual input numbers
            $recipients = explode(',', $input['recipient']);
            $recipients = array_unique($recipients);

            if (count($recipients) == 0) {

                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.campaigns.contact_not_found'),
                ]);
            }

            if (count($recipients) > 100) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'You cannot send more than 100 SMS in a single request.',
                ]);
            }

            $cost                  = 0;
            $sms_count             = 1;
            $total_unit            = 0;
            $message               = null;
            $prepareForTemplateTag = [];
            $errors                = [];

            if (isset($input['message'])) {
                $message      = $input['message'];
                $sms_counter  = new SMSCounter();
                $message_data = $sms_counter->count($message, $sms_type == 'whatsapp' ? 'WHATSAPP' : null);
                $sms_count    = $message_data->messages;
            }

            $coverage = [];

            $plan_coverage = CustomerBasedPricingPlan::where('user_id', $user->id)->with('sendingServer')->get();

            if ($plan_coverage->count() < 1) {
                $plan_coverage = PlansCoverageCountries::where('plan_id', $user->customer->activeSubscription()->plan->id)->with('sendingServer')->get();
            }

            foreach ($plan_coverage as $pCoverage) {
                $coverage[$pCoverage->country->country_code] = json_decode($pCoverage->options, true);
                if ($sending_server == null) {
                    $coverage[$pCoverage->country->country_code]['sending_server'] = $pCoverage->sendingServer;
                } else {
                    $coverage[$pCoverage->country->country_code]['sending_server'] = $sending_server;
                }
            }

            foreach ($recipients as $number) {

                $phone = str_replace(['+', '(', ')', '-', ' '], '', $number);

                $preparedData = [
                    'user_id'   => $user->id,
                    'phone'     => $phone,
                    'sender_id' => $sender_id,
                    'message'   => $message,
                    'sms_count' => $sms_count,
                    'status'    => null,
                    'sms_type'  => $sms_type,
                ];

                if (Tool::validatePhone($phone)) {

                    try {
                        $phoneUtil         = PhoneNumberUtil::getInstance();
                        $phoneNumberObject = $phoneUtil->parse('+' . $phone);
                        $country_code      = $phoneNumberObject->getCountryCode();

                        if (is_array($coverage) && array_key_exists($country_code, $coverage) && array_key_exists('sending_server', $coverage[$country_code]) && $coverage[$country_code]['sending_server'] != null) {

                            if ($sms_type == 'plain' || $sms_type == 'unicode') {
                                $cost = $coverage[$country_code]['plain_sms'];
                            }

                            if ($sms_type == 'voice') {

                                $preparedData['language'] = $input['language'];
                                $preparedData['gender']   = $input['gender'];

                                $cost = $coverage[$country_code]['voice_sms'];
                            }

                            if ($sms_type == 'mms') {

                                $preparedData['media_url'] = $input['media_url'];

                                $cost = $coverage[$country_code]['mms_sms'];
                            }

                            if ($sms_type == 'whatsapp') {
                                $cost = $coverage[$country_code]['whatsapp_sms'];
                            }

                            if ($sms_type == 'viber') {
                                $cost = $coverage[$country_code]['viber_sms'];
                            }

                            if ($sms_type == 'otp') {
                                $cost = $coverage[$country_code]['otp_sms'];
                            }

                            $price      = $cost * $sms_count;
                            $total_unit += $price;

                            $preparedData['cost']           = $price;
                            $preparedData['sending_server'] = $coverage[$country_code]['sending_server'];

                            if (isset($input['dlt_template_id'])) {
                                $preparedData['dlt_template_id'] = $input['dlt_template_id'];
                            }

                            if (isset($user->dlt_entity_id)) {
                                $preparedData['dlt_entity_id'] = $user->dlt_entity_id;
                            }

                            if (isset($user->dlt_telemarketer_id)) {
                                $preparedData['dlt_telemarketer_id'] = $user->dlt_telemarketer_id;
                            }

                            $preparedData['api_key'] = $input['api_key'];
                            $prepareForTemplateTag[] = $preparedData;

                        } else {
                            $errors[] = "Permission to send an SMS has not been enabled for the region indicated by the 'To' number: " . $phone;
                        }
                    } catch (NumberParseException $exception) {

                        $errors[] = $exception->getMessage();

                    }
                } else {

                    $errors[] = __('locale.customer.invalid_phone_number', ['phone' => $phone]);
                }
            }

            if ($user->sms_unit != '-1' && $total_unit > $user->sms_unit) {

                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.campaigns.not_enough_balance', [
                        'current_balance' => $user->sms_unit,
                        'campaign_price'  => $total_unit,
                    ]),
                ]);
            }

            DB::transaction(function () use ($user, $total_unit) {
                $remaining_balance = $user->sms_unit - $total_unit;
                $user->lockForUpdate();
                $user->update(['sms_unit' => $remaining_balance]);
            });

            if (isset($input['schedule']) && $input['schedule']) {
                foreach ($prepareForTemplateTag as $data) {
                    $data['from']           = $data['sender_id'];
                    $data['to']             = $data['phone'];
                    $data['sending_server'] = $data['sending_server']->id;
                    $data['direction']      = Reports::DIRECTION_API;
                    $schedule_date          = $input['schedule_date'] . ' ' . $input['schedule_time'];
                    $data['schedule_on']    = Tool::systemTimeFromString($schedule_date, $input['timezone']);

                    unset($data['phone']);
                    unset($data['sender_id']);

                    ScheduleMessage::create($data);

                }

                if ( ! empty($errors)) {
                    $message = implode(' ', $errors);
                } else {
                    $message = __('locale.campaigns.message_is_scheduled_successfully');
                }

                return response()->json([
                    'status'  => 'success',
                    'message' => $message,
                ]);
            } else {
                try {
                    $failed_cost   = 0;
                    $response_data = [];

                    collect($prepareForTemplateTag)->each(function ($sendData) use (&$failed_cost, $campaign, $sms_type, &$response_data) {
                        $status = null;
                        if ($sms_type == 'plain' || $sms_type == 'unicode') {
                            $status = $campaign->sendPlainSMS($sendData);
                        }

                        if ($sms_type == 'voice') {
                            $status = $campaign->sendVoiceSMS($sendData);
                        }

                        if ($sms_type == 'mms') {
                            $status = $campaign->sendMMS($sendData);
                        }

                        if ($sms_type == 'whatsapp') {
                            $status = $campaign->sendWhatsApp($sendData);
                        }

                        if ($sms_type == 'viber') {
                            $status = $campaign->sendViber($sendData);
                        }

                        if ($sms_type == 'otp') {
                            $status = $campaign->sendOTP($sendData);
                        }

                        if ( ! substr_count($status, 'Delivered')) {
                            $failed_cost += $sendData['cost'];
                        }

                        $reports = Reports::select('id', 'uid', 'to', 'from', 'message', 'customer_status', 'cost', 'sms_count')->find($status->id);
                        if ($reports) {
                            $response_data[] = $reports;
                        }

                    });

                    if ($user->sms_unit != '-1') {

                        DB::transaction(function () use ($user, $failed_cost) {
                            $remaining_balance = $user->sms_unit + $failed_cost;
                            $user->lockForUpdate();
                            $user->update(['sms_unit' => $remaining_balance]);
                        });
                    }

                    if ( ! empty($errors)) {
                        $message = implode(' ', $errors);
                    } else {
                        $message = __('locale.campaigns.message_is_scheduled_successfully');
                    }

                    return response()->json([
                        'status'  => 'success',
                        'data'    => $response_data,
                        'message' => $message,
                    ]);

                } catch (Exception $exception) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => $exception->getMessage(),
                    ]);
                }
            }

        }

        /**
         * send message using file
         */
        public function sendUsingFile(Campaigns $campaign, array $input): JsonResponse
        {

            $user          = Auth::user();
            $csv_file_info = CsvData::find($input['csv_data_file_id']);

            if ( ! $csv_file_info) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.filezone.insert_valid_csv_file'),
                ]);
            }

            $form_data = json_decode($input['form_data'], true);

            if (isset($input['message'])) {
                $form_data['message'] = $input['message'];
            }

            $validateData = $this->validateCampaignBuilder($user, $form_data);

            if ($validateData->getData()->status == 'error') {
                return response()->json([
                    'status'  => 'error',
                    'message' => $validateData->getData()->message,
                ]);
            }

            $db_fields = $input['fields'];

            if (is_array($db_fields) && ! in_array('phone', $db_fields)) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.filezone.phone_number_column_require'),
                ]);
            }

            $sender_id = $validateData->getData()->sender_id;
            $sms_type  = $validateData->getData()->sms_type;

            //create campaign
            $new_campaign = Campaigns::create([
                'user_id'       => $user->id,
                'campaign_name' => $form_data['name'],
                'sms_type'      => $form_data['sms_type'],
                'message'       => $input['message'],
                'upload_type'   => 'file',
                'status'        => Campaigns::STATUS_NEW,
            ]);

            if ( ! $new_campaign) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.exceptions.something_went_wrong'),
                ]);
            }

            if (isset($sender_id) && is_array($sender_id)) {
                $originator = $input['originator'] ?? null;
                foreach ($sender_id as $id) {
                    if (empty($id)) {
                        continue;
                    }

                    $new_campaign->senderids()->create([
                        'sender_id'  => $id,
                        'originator' => $originator,
                    ]);
                }
            }

            if (isset($form_data['sending_server'])) {
                $new_campaign->sending_server_id = $form_data['sending_server'];
            }

            // if schedule is available then check date, time and timezone
            if (isset($form_data['schedule']) && $form_data['schedule'] == 'true') {

                $schedule_date = $form_data['schedule_date'] . ' ' . $form_data['schedule_time'];
                $schedule_time = Tool::systemTimeFromString($schedule_date, $form_data['timezone']);

                $new_campaign->timezone      = $form_data['timezone'];
                $new_campaign->status        = Campaigns::STATUS_SCHEDULED;
                $new_campaign->schedule_time = $schedule_time;
                $new_campaign->run_at        = $schedule_time;
                $new_campaign->schedule_type = Campaigns::TYPE_ONETIME;

            } else {
                $new_campaign->status = Campaigns::STATUS_QUEUING;
                $new_campaign->run_at = Carbon::now(config('app.timezone'))->format('Y-m-d H:i');
            }

            //update cache
            $new_campaign->cache = json_encode([
                'ContactCount'         => 0,
                'DeliveredCount'       => 0,
                'FailedDeliveredCount' => 0,
                'NotDeliveredCount'    => 0,
            ]);

            if ($sms_type == 'voice') {
                $new_campaign->language = $form_data['language'];
                $new_campaign->gender   = $form_data['gender'];
            }

            if ($sms_type == 'mms') {
                $new_campaign->media_url = $form_data['media_url'];
            }

            if ($sms_type == 'whatsapp') {

                if (isset($form_data['language']) && $form_data['language'] != '0') {
                    $new_campaign->language = $form_data['language'];
                }

                if (isset($form_data['media_url'])) {
                    $new_campaign->media_url = $form_data['media_url'];
                }
            }

            if ($sms_type == 'viber') {
                if (isset($form_data['media_url'])) {
                    $new_campaign->media_url = $form_data['media_url'];
                }
            }

            //finally, store data and return response
            $camp = $new_campaign->save();

            if ($camp) {

                try {
                    if (isset($schedule_time)) {
                        $delay_minutes = Carbon::now()->diffInMinutes($schedule_time);
                        dispatch(new ImportCampaign($new_campaign, $csv_file_info, $db_fields, $form_data['plan_id']))->delay(now()->addMinutes($delay_minutes));
                    } else {
                        dispatch(new ImportCampaign($new_campaign, $csv_file_info, $db_fields, $form_data['plan_id']));
                    }

                    return response()->json([
                        'status'  => 'success',
                        'message' => __('locale.campaigns.campaign_send_successfully'),
                    ]);
                } catch (Throwable $exception) {
                    $new_campaign->delete();

                    return response()->json([
                        'status'  => 'error',
                        'message' => $exception->getMessage(),
                    ]);
                }

            }

            return response()->json([
                'status'  => 'error',
                'message' => __('locale.exceptions.something_went_wrong'),
            ]);
        }

        /**
         * Pause the Campaign
         * @throws Exception
         */
        public function pause(Campaigns $campaign): JsonResponse
        {

            $campaign->cancelAndDeleteJobs();

            $campaign->status = Campaigns::STATUS_PAUSED;
            $campaign->reason = __('locale.campaigns.campaign_paused_by_user');
            if ( ! $campaign->save()) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.exceptions.something_went_wrong'),
                ]);
            }

            $campaign->logger()->warning('Campaign paused by ' . Auth::user()->displayName());

            return response()->json([
                'status'  => 'success',
                'message' => __('locale.campaigns.campaign_was_successfully_paused'),
            ]);
        }

        /**
         * Restart the Campaign
         * @throws Throwable
         */
        public function restart(Campaigns $campaign): JsonResponse
        {

            $sms_unit = Auth::user()->sms_unit;
            $max_unit = Auth::user()->customer->getOption('sms_max');

            if ($max_unit != '-1' && $sms_unit <= 0) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.campaigns.sending_limit_exceed'),
                ]);
            }

            if ($campaign->schedule_type == Campaigns::TYPE_RECURRING) {
                $campaign->update(['status' => Campaigns::STATUS_SCHEDULED]);
            } else {
                $campaign->execute();
            }

            return response()->json([
                'status'  => 'success',
                'message' => __('locale.campaigns.campaign_was_successfully_restart'),
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Version 3.7
        |--------------------------------------------------------------------------
        |
        | Send Campaign Using API
        |
        */

        /**
         * Resend the Campaign
         * @throws Throwable
         */
        public function resend(Campaigns $campaign): JsonResponse
        {
            TrackingLog::where('campaign_id', $campaign->id)->where('customer_id', Auth::user()->id)->where('status', 'not like', '%Delivered%')->delete();
            Reports::where('campaign_id', $campaign->id)->where('user_id', Auth::user()->id)->where('status', 'not like', '%Delivered%')->delete();

            $campaign->execute();

            return response()->json([
                'status'  => 'success',
                'message' => __('locale.campaigns.campaign_was_successfully_resend'),
            ]);
        }

        /*Version 3.8*/
        /*
        |--------------------------------------------------------------------------
        | Quick Send Validation
        |--------------------------------------------------------------------------
        |
        |
        |
        */

        public function apiCampaignBuilder(Campaigns $campaign, array $input): JsonResponse
        {

            $user     = User::where('status', true)->where('api_token', $input['api_key'])->first();
            $customer = $user->customer;

            if ($user->sms_unit != '-1' && $user->sms_unit == 0) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.campaigns.sending_limit_exceed'),
                ]);
            }

            if (isset($input['sending_server'])) {
                $sending_server = SendingServer::find($input['sending_server']);
                if ( ! $sending_server) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => __('locale.campaigns.sending_server_not_available'),
                    ]);
                }
            }

            $sms_type = $input['type'];

            if ($customer->getOption('send_spam_message') == 'no') {
                $spamWordCount = SpamWord::whereIn('word', array_map('strtolower', explode(' ', $input['message'])))->count();

                if ($spamWordCount > 0) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'Your message contains spam words.',
                    ]);
                }
            }

            $db_sms_type       = ($sms_type === 'unicode') ? 'plain' : $sms_type;
            $capabilities_type = in_array($sms_type, ['plain', 'unicode']) ? 'sms' : $sms_type;

            $sender_id = null;

            if ($customer->getOption('sender_id_verification') == 'yes') {
                if (isset($input['originator'])) {
                    if ($input['originator'] == 'sender_id') {
                        if ( ! isset($input['sender_id'])) {
                            return response()->json([
                                'status'  => 'error',
                                'message' => __('locale.sender_id.sender_id_required'),
                            ]);
                        }

                        $sender_id = $input['sender_id'];

                        if (is_array($sender_id) && count($sender_id) > 0) {
                            $senderids = Senderid::where('user_id', $user->id)
                                ->where('status', 'active')
                                ->pluck('sender_id')
                                ->all();

                            $invalid = array_diff($sender_id, $senderids);

                            if (count($invalid)) {
                                return response()->json([
                                    'status'  => 'error',
                                    'message' => __('locale.sender_id.sender_id_invalid', ['sender_id' => $invalid[0]]),
                                ]);
                            }
                        } else {
                            return response()->json([
                                'status'  => 'error',
                                'message' => __('locale.sender_id.sender_id_required'),
                            ]);
                        }
                    } else {
                        if ( ! isset($input['phone_number'])) {
                            return response()->json([
                                'status'  => 'error',
                                'message' => __('locale.sender_id.phone_numbers_required'),
                            ]);
                        }

                        $sender_id = $input['phone_number'];

                        if (is_array($sender_id) && count($sender_id) > 0) {
                            $type_supported = [];
                            $numbers        = PhoneNumbers::where('user_id', $user->id)
                                ->where('status', 'assigned')
                                ->cursor();

                            foreach ($numbers as $number) {
                                if (in_array($number->number, $sender_id) && ! str_contains($number->capabilities, $capabilities_type)) {
                                    $type_supported[] = $number->number;
                                }
                            }

                            if (count($type_supported)) {
                                return response()->json([
                                    'status'  => 'error',
                                    'message' => __('locale.sender_id.sender_id_sms_capabilities', ['sender_id' => $type_supported[0], 'type' => $db_sms_type]),
                                ]);
                            }
                        } else {
                            return response()->json([
                                'status'  => 'error',
                                'message' => __('locale.sender_id.sender_id_required'),
                            ]);
                        }
                    }
                } else {
                    return response()->json([
                        'status'  => 'error',
                        'message' => __('locale.sender_id.sender_id_required'),
                    ]);
                }
            } else if ($user->can('view_numbers') && isset($input['originator']) && $input['originator'] == 'phone_number' && isset($input['phone_number'])) {
                $sender_id = $input['phone_number'];

                if (is_array($sender_id) && count($sender_id) > 0) {
                    $type_supported = [];
                    $numbers        = PhoneNumbers::where('user_id', $user->id)
                        ->where('status', 'assigned')
                        ->cursor();

                    foreach ($numbers as $number) {
                        if (in_array($number->number, $sender_id) && ! str_contains($number->capabilities, $capabilities_type)) {
                            $type_supported[] = $number->number;
                        }
                    }

                    if (count($type_supported)) {
                        return response()->json([
                            'status'  => 'error',
                            'message' => __('locale.sender_id.sender_id_sms_capabilities', ['sender_id' => $type_supported[0], 'type' => $db_sms_type]),
                        ]);
                    }
                } else {
                    return response()->json([
                        'status'  => 'error',
                        'message' => __('locale.sender_id.sender_id_required'),
                    ]);
                }
            } else {
                if (isset($input['originator'])) {
                    if ($input['originator'] == 'sender_id') {
                        if ( ! isset($input['sender_id'])) {
                            return response()->json([
                                'status'  => 'error',
                                'message' => __('locale.sender_id.sender_id_required'),
                            ]);
                        }

                        $sender_id = $input['sender_id'];
                    } else {
                        if ( ! isset($input['phone_number'])) {
                            return response()->json([
                                'status'  => 'error',
                                'message' => __('locale.sender_id.phone_numbers_required'),
                            ]);
                        }

                        $sender_id = $input['phone_number'];
                    }

                    if ( ! is_array($sender_id) || count($sender_id) <= 0) {
                        return response()->json([
                            'status'  => 'error',
                            'message' => __('locale.sender_id.sender_id_required'),
                        ]);
                    }
                }

                if (isset($input['sender_id'])) {
                    $sender_id           = $input['sender_id'];
                    $input['originator'] = 'sender_id';
                }
            }

            $contactGroupUIDs = explode(',', $input['contact_list_id']);

            if (is_array($contactGroupUIDs) && count($contactGroupUIDs) == 0) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.campaigns.contact_not_found'),
                ]);
            }

            // Check if all contact group IDs belong to the user and insert campaign-to-contact-group associations
            $invalidGroupIds = array_diff($contactGroupUIDs, $customer->lists()->pluck('uid')->toArray());

            if (count($invalidGroupIds) > 0) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.campaign.invalid_group'),
                ]);
            }

            //create campaign
            $new_campaign = Campaigns::create([
                'user_id'       => $user->id,
                'campaign_name' => $input['name'],
                'message'       => $input['message'],
                'sms_type'      => $sms_type,
                'status'        => Campaigns::STATUS_NEW,
            ]);

            if ( ! $new_campaign) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.exceptions.something_went_wrong'),
                ]);
            }
            if (isset($input['sending_server'])) {
                $new_campaign->sending_server_id = $input['sending_server'];
            }

            $sender_ids = array_filter($sender_id);

            foreach ($sender_ids as $id) {
                $data = [
                    'campaign_id' => $new_campaign->id,
                    'sender_id'   => $id,
                ];

                if (isset($input['originator'])) {
                    $data['originator'] = $input['originator'];
                }

                CampaignsSenderid::create($data);
            }

            if (isset($input['dlt_template_id'])) {
                $new_campaign->dlt_template_id = $input['dlt_template_id'];
            }

            $groups = ContactGroups::whereIn('uid', $contactGroupUIDs)->get(['id']);

            $associations = $groups->map(function ($group) use ($new_campaign) {
                return [
                    'campaign_id'     => $new_campaign->id,
                    'contact_list_id' => $group->id,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ];
            })->toArray();

            CampaignsList::insert($associations);

            $contactGroupIds = array_column($associations, 'contact_list_id');
            $getContacts     = Contacts::whereIn('group_id', $contactGroupIds)->where('status', 'subscribe');
            $total           = $getContacts->count();
            $subscriber      = $getContacts->first();

            if ($total == 0) {

                $new_campaign->delete();

                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.campaigns.contact_not_found'),
                ]);
            }

            if ($user->sms_unit != '-1') {
                $coverage = CustomerBasedPricingPlan::where('user_id', $user->id)
                    ->pluck('options', 'country_id')
                    ->toArray();

                if (count($coverage) < 1) {
                    $coverage = PlansCoverageCountries::where('plan_id', $user->customer->activeSubscription()->plan_id)
                        ->pluck('options', 'country_id')
                        ->toArray();
                }

                if (empty($coverage)) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'Please add coverage on your plan.',
                    ]);
                }

                try {
                    $phoneUtil         = PhoneNumberUtil::getInstance();
                    $phoneNumberObject = $phoneUtil->parse('+' . $subscriber->phone);
                    $country_code      = $phoneNumberObject->getCountryCode();
                    $country_ids       = Country::where('country_code', $country_code)
                        ->where('status', 1)
                        ->pluck('id')
                        ->toArray();
                    $country_id        = array_intersect($country_ids, array_keys($coverage));

                    if (empty($country_id)) {
                        return response()->json([
                            'status'  => 'error',
                            'message' => "Permission to send an SMS has not been enabled for the region indicated by the 'To' number: " . $subscriber->phone,
                        ]);
                    }

                    $country = Country::find($country_id[0]);

                    if (isset($coverage[$country->id])) {
                        $priceOption = json_decode($coverage[$country->id], true);
                        $sms_count   = 1;

                        if (isset($input['message'])) {
                            $sms_counter  = new SMSCounter();
                            $message_data = $sms_counter->count($input['message'], $sms_type == 'whatsapp' ? 'WHATSAPP' : null);
                            $sms_count    = $message_data->messages;
                        }

                        $sms_type_prices = [
                            'plain'    => 'plain_sms',
                            'unicode'  => 'plain_sms',
                            'voice'    => 'voice_sms',
                            'mms'      => 'mms_sms',
                            'whatsapp' => 'whatsapp_sms',
                            'viber'    => 'viber_sms',
                            'otp'      => 'otp_sms',
                        ];

                        if (isset($sms_type_prices[$sms_type])) {
                            $unit_price = $priceOption[$sms_type_prices[$sms_type]];
                            $price      = $total * $unit_price;
                            $price      *= $sms_count;

                            $balance = $user->sms_unit;

                            if ($price > $balance) {
                                $new_campaign->delete();

                                return response()->json([
                                    'status'  => 'error',
                                    'message' => __('locale.campaigns.not_enough_balance', [
                                        'current_balance' => $balance,
                                        'campaign_price'  => $price,
                                    ]),
                                ]);
                            }
                        } else {
                            return response()->json([
                                'status'  => 'error',
                                'message' => 'Invalid SMS type: ' . $sms_type,
                            ]);
                        }
                    } else {
                        return response()->json([
                            'status'  => 'error',
                            'message' => "Permission to send an SMS has not been enabled for the region indicated by the 'To' number: " . $subscriber->phone,
                        ]);
                    }
                } catch (NumberParseException $exception) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => $exception->getMessage(),
                    ]);
                }
            }

            // if schedule is available then check date, time and timezone
            if (isset($input['schedule']) && $input['schedule'] == 'true') {

                $schedule_date = $input['schedule_date'] . ' ' . $input['schedule_time'];
                $schedule_time = Tool::systemTimeFromString($schedule_date, $input['timezone']);

                $new_campaign->timezone      = $input['timezone'];
                $new_campaign->status        = Campaigns::STATUS_SCHEDULED;
                $new_campaign->schedule_time = $schedule_time;
                $new_campaign->run_at        = $schedule_time;

                if ($input['frequency_cycle'] == 'onetime') {
                    // working with onetime schedule
                    $new_campaign->schedule_type = Campaigns::TYPE_ONETIME;
                } else {
                    // working with recurring schedule
                    //if schedule time frequency is not one time then check frequency details
                    $recurring_date = $input['recurring_date'] . ' ' . $input['recurring_time'];
                    $recurring_end  = Tool::systemTimeFromString($recurring_date, $input['timezone']);

                    $new_campaign->schedule_type = Campaigns::TYPE_RECURRING;
                    $new_campaign->recurring_end = $recurring_end;

                    if (isset($input['frequency_cycle'])) {
                        if ($input['frequency_cycle'] != 'custom') {
                            $schedule_cycle                 = $campaign::scheduleCycleValues();
                            $limits                         = $schedule_cycle[$input['frequency_cycle']];
                            $new_campaign->frequency_cycle  = $input['frequency_cycle'];
                            $new_campaign->frequency_amount = $limits['frequency_amount'];
                            $new_campaign->frequency_unit   = $limits['frequency_unit'];
                        } else {
                            $new_campaign->frequency_cycle  = $input['frequency_cycle'];
                            $new_campaign->frequency_amount = $input['frequency_amount'];
                            $new_campaign->frequency_unit   = $input['frequency_unit'];
                        }
                    }
                }
            } else {
                $new_campaign->status = Campaigns::STATUS_QUEUED;
                $new_campaign->run_at = Tool::systemTimeFromString(Carbon::now()->format('Y-m-d H:i'), $input['timezone']);
            }

            //update cache
            $new_campaign->cache = json_encode([
                'ContactCount'         => $total,
                'DeliveredCount'       => 0,
                'FailedDeliveredCount' => 0,
                'NotDeliveredCount'    => 0,
            ]);

            if ($sms_type == 'voice') {
                $new_campaign->language = $input['language'];
                $new_campaign->gender   = $input['gender'];
            }

            if ($sms_type == 'mms') {
                $new_campaign->media_url = Tool::uploadImage($input['mms_file']);
            }

            if ($sms_type == 'whatsapp') {

                if (isset($input['language'])) {
                    $new_campaign->language = $input['language'];
                }

                if (isset($input['mms_file'])) {
                    $new_campaign->media_url = Tool::uploadImage($input['mms_file']);
                }
            }

            if ($sms_type == 'viber') {
                if (isset($input['mms_file'])) {
                    $new_campaign->media_url = Tool::uploadImage($input['mms_file']);
                }
            }

            //finally, store data and return response
            $camp = $new_campaign->save();

            if ($camp) {

                try {
                    $new_campaign->execute();

                    return response()->json([
                        'status'  => 'success',
                        'data'    => $new_campaign->only([
                            'id',
                            'uid',
                            'campaign_name',
                            'status',
                            'message',
                            'created_at',
                            'sms_type',
                        ]),
                        'message' => __('locale.campaigns.campaign_send_successfully'),
                    ]);
                } catch (Throwable $exception) {
                    $new_campaign->delete();

                    return response()->json([
                        'status'  => 'error',
                        'message' => $exception->getMessage(),
                    ]);
                }
            }

            $new_campaign->delete();

            return response()->json([
                'status'  => 'success',
                'message' => __('locale.exceptions.something_went_wrong'),
            ]);

        }

        public function checkQuickSendValidation(array $input)
        {
            $user     = isset($input['user_id']) ? User::find($input['user_id']) : Auth::user();
            $sms_type = $input['sms_type'];

            if ($user->customer->getOption('send_spam_message') == 'no') {
                $spamWords = SpamWord::whereRaw("LOWER(?) LIKE CONCAT('%', LOWER(word), '%')", [$input['message']])->get();
                if ($spamWords->isNotEmpty()) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'Your message contains spam words.',
                    ]);
                }
            }

            if ($user->sms_unit == 0) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.campaigns.sending_limit_exceed'),
                ]);
            }

            if ( ! $user->customer->activeSubscription()) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.subscription.no_active_subscription'),
                ]);
            }

            $db_sms_type       = $sms_type == 'unicode' ? 'plain' : $sms_type;
            $capabilities_type = in_array($sms_type, ['plain', 'unicode']) ? 'sms' : $sms_type;

            $sender_id = null;
            if ($user->customer->getOption('sender_id_verification') == 'yes') {
                if (isset($input['originator'])) {
                    if ($input['originator'] == 'sender_id' && isset($input['sender_id'])) {
                        $sender_id = $input['sender_id'];
                    } else if ($input['originator'] == 'phone_number' && isset($input['phone_number'])) {
                        $sender_id = $input['phone_number'];
                    }
                } else if (isset($input['sender_id'])) {
                    $sender_id = $input['sender_id'];
                }

                $check_sender_id = Senderid::where('user_id', $user->id)->where('sender_id', $sender_id)->where('status', 'active')->first();
                if ( ! $check_sender_id) {
                    $number = PhoneNumbers::where('user_id', $user->id)->where('number', $sender_id)->where('status', 'assigned')->first();

                    if ( ! $number) {
                        return response()->json([
                            'status'  => 'error',
                            'message' => __('locale.sender_id.sender_id_invalid', ['sender_id' => $sender_id]),
                        ]);
                    }

                    $capabilities = str_contains($number->capabilities, $capabilities_type);

                    if ( ! $capabilities) {
                        return response()->json([
                            'status'  => 'error',
                            'message' => __('locale.sender_id.sender_id_sms_capabilities', ['sender_id' => $sender_id, 'type' => $db_sms_type]),
                        ]);
                    }

                }
            } else if ($user->can('view_numbers') && isset($input['originator']) && $input['originator'] == 'phone_number' && isset($input['phone_number'])) {

                $sender_id = $input['phone_number'];

                $number = PhoneNumbers::where('user_id', $user->id)->where('number', $sender_id)->where('status', 'assigned')->first();

                if ( ! $number) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => __('locale.sender_id.sender_id_invalid', ['sender_id' => $sender_id]),
                    ]);
                }

                $capabilities = str_contains($number->capabilities, $capabilities_type);

                if ( ! $capabilities) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => __('locale.sender_id.sender_id_sms_capabilities', ['sender_id' => $sender_id, 'type' => $db_sms_type]),
                    ]);
                }

            } else if (isset($input['sender_id'])) {
                $sender_id = $input['sender_id'];
            }

            if (BlockSenderId::where('sender_id', $sender_id)->exists()) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.block_senderid.sender_id_blocked', ['sender_id' => $sender_id]),
                ], 403);
            }

            return response()->json([
                'status'    => 'success',
                'sender_id' => $sender_id,
                'sms_type'  => $sms_type,
                'user_id'   => $user->id,
            ]);
        }

    }

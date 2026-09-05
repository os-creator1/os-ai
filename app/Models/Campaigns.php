<?php

    namespace App\Models;

    use App\Exceptions\CampaignPausedException;
    use App\Helpers\Helper;
    use App\Jobs\LoadCampaign;
    use App\Jobs\RunCampaign;
    use App\Jobs\ScheduleCampaign;
    use App\Jobs\SendFileMessage;
    use App\Jobs\SendMessage;
    use App\Library\Contracts\CampaignInterface;
    use App\Library\Lockable;
    use App\Library\Log;
    use App\Library\SMSCounter;
    use App\Library\SpinText;
    use App\Library\Tool;
    use App\Library\Traits\HasCache;
    use App\Library\Traits\HasUid;
    use App\Library\Traits\TrackJobs;
    use Carbon\Carbon;
    use Closure;
    use DB;
    use Exception;
    use Illuminate\Bus\Batch;
    use Illuminate\Database\Eloquent\Relations\BelongsTo;
    use Illuminate\Database\Eloquent\Relations\HasMany;
    use Illuminate\Support\Facades\Cache;
    use libphonenumber\NumberParseException;
    use libphonenumber\PhoneNumberUtil;
    use Monolog\Formatter\LineFormatter;
    use Monolog\Handler\RotatingFileHandler;
    use Monolog\Logger;
    use Throwable;

    //  use App\Models\ChatBox;
    //  use App\Models\ChatBoxMessage;

    /**
     * @method static where(string $string, string $uid)
     * @method static create(array $array)
     * @method static find($campaign_id)
     * @method static cursor()
     * @method static whereIn(string $string, mixed $ids)
     * @method static count()
     * @method static scheduled()
     * @method getContactList()
     *
     * @property string|null       $status
     * @property false|string|null $cache
     */
    class Campaigns extends SendCampaignSMS implements CampaignInterface
    {
        use HasCache, HasUid, TrackJobs;

        protected $logger;

        /**
         * Campaign status
         */
        public const STATUS_NEW        = 'new';
        public const STATUS_QUEUED     = 'queued';
        public const STATUS_SENDING    = 'sending';
        public const STATUS_FAILED     = 'failed';
        public const STATUS_DELIVERED  = 'delivered';
        public const STATUS_CANCELLED  = 'cancelled';
        public const STATUS_SCHEDULED  = 'scheduled';
        public const STATUS_PROCESSING = 'processing';
        public const STATUS_PAUSED     = 'paused';
        public const STATUS_QUEUING    = 'queuing'; // equiv. to 'queue'
        public const STATUS_ERROR      = 'error';
        public const STATUS_DONE       = 'done';

        public const JOB_TYPE_RUN_CAMPAIGN               = 'run-campaign';
        public const JOB_TYPE_DISPATCH_AND_SEND_MESSAGES = 'dispatch-and-send-messages';


        /*
         * Campaign type
         */
        const TYPE_ONETIME   = 'onetime';
        const TYPE_RECURRING = 'recurring';

        public static array $serverPools = [];

        protected $sendingSevers = null;
        protected $senderIds     = null;

        protected $currentSubscription;

        protected $fillable = [
            'user_id',
            'business_id',
            'campaign_name',
            'message',
            'media_url',
            'language',
            'gender',
            'sms_type',
            'upload_type',
            'status',
            'reason',
            'api_key',
            'cache',
            'timezone',
            'schedule_time',
            'schedule_type',
            'frequency_cycle',
            'frequency_amount',
            'frequency_unit',
            'recurring_end',
            'run_at',
            'delivery_at',
            'batch_id',
            'running_pid',
            'dlt_template_id',
            'recurring_created',
            'sending_server_id',
            'last_error',
        ];

        protected $casts = [
            'created_at'        => 'datetime',
            'updated_at'        => 'datetime',
            'run_at'            => 'datetime',
            'delivery_at'       => 'datetime',
            'schedule_time'     => 'datetime',
            'recurring_end'     => 'datetime',
            'recurring_created' => 'boolean',
        ];

        /**
         * get user
         */
        public function user(): BelongsTo
        {
            return $this->belongsTo(User::class);
        }

        /**
         * get customer
         */
        public function customer(): BelongsTo
        {
            return $this->belongsTo(Customer::class, 'user_id');
        }

        /**
         * Business Data Tenancy Foundation, Pass 1 — additive only.
         */
        public function business(): BelongsTo
        {
            return $this->belongsTo(Business::class);
        }

        /**
         * get sending server
         */
        public function sendingServer(): BelongsTo
        {
            return $this->belongsTo(SendingServer::class);
        }

        /**
         * get reports
         */
        public function reports(): HasMany
        {
            return $this->hasMany(Reports::class, 'campaign_id', 'id');
        }

        /**
         * associate with contact groups
         */
        public function contactList(): HasMany
        {
            return $this->hasMany(CampaignsList::class, 'campaign_id');
        }

        public function senderids(): HasMany
        {
            return $this->hasMany(CampaignsSenderid::class, 'campaign_id');
        }

        /**
         * associate with recipients
         */
        public function recipients(): HasMany
        {
            return $this->hasMany(CampaignsRecipients::class, 'campaign_id');
        }

        /**
         * Scope
         */
        public function scopeScheduled($query)
        {
            return $query->where('status', static::STATUS_SCHEDULED)->where('schedule_type', '!=', static::TYPE_RECURRING);
        }

        /**
         * Get schedule recurs available values.
         */
        public static function scheduleCycleValues(): array
        {
            return [
                'daily'   => [
                    'frequency_amount' => 1,
                    'frequency_unit'   => 'day',
                ],
                'monthly' => [
                    'frequency_amount' => 1,
                    'frequency_unit'   => 'month',
                ],
                'yearly'  => [
                    'frequency_amount' => 1,
                    'frequency_unit'   => 'year',
                ],
            ];
        }

        /**
         * Frequency time unit options.
         */
        public static function timeUnitOptions(): array
        {
            return [
                ['value' => 'day', 'text' => 'day'],
                ['value' => 'week', 'text' => 'week'],
                ['value' => 'month', 'text' => 'month'],
                ['value' => 'year', 'text' => 'year'],
            ];
        }

        public function contactCount($cache = false)
        {
            if ($cache) {
                return $this->readCache('ContactCount', 0);
            }
            $list_ids = $this->contactList()->select('contact_list_id')->cursor()->pluck('contact_list_id')->all();

            return Contacts::whereIn('group_id', $list_ids)->where('status', 'subscribe')->count();

        }

        /**
         * show delivered count
         *
         * @param false $cache
         */
        public function deliveredCount(bool $cache = false): int
        {
            if ($cache) {
                return $this->readCache('DeliveredCount', 0);
            }

            return $this->reports()->where('campaign_id', $this->id)->where('status', 'like', '%Delivered%')->count();
        }

        /**
         * show failed count
         *
         * @param false $cache
         */
        public function failedCount(bool $cache = false): int
        {
            if ($cache) {
                return $this->readCache('FailedDeliveredCount', 0);
            }

            return $this->reports()->where('campaign_id', $this->id)->where('status', 'not like', '%Delivered%')->count();
        }

        /**
         * show not delivered count
         *
         * @param false $cache
         */
        public function notDeliveredCount(bool $cache = false): int
        {
            if ($cache) {
                return $this->readCache('NotDeliveredCount', 0);
            }

            return $this->reports()->where('campaign_id', $this->id)->where('status', 'like', '%Sent%')->count();
        }

        public function nextScheduleDate($startDate, $interval, $intervalCount)
        {

            return match ($interval) {
                'month' => $startDate->addMonthsNoOverflow($intervalCount),
                'day' => $startDate->addDay($intervalCount),
                'week' => $startDate->addWeek($intervalCount),
                'year' => $startDate->addYearsNoOverflow($intervalCount),
                default => null,
            };
        }

        /**
         * Update Campaign cached data.
         *
         * @param null $key
         */
        public function updateCache($key = null): void
        {
            // cache indexes
            $index = [
                'DeliveredCount'       => function ($campaign) {
                    return $campaign->deliveredCount();
                },
                'FailedDeliveredCount' => function ($campaign) {
                    return $campaign->failedCount();
                },
                'NotDeliveredCount'    => function ($campaign) {
                    return $campaign->notDeliveredCount();
                },
                'ContactCount'         => function ($campaign) {
                    return $campaign->contactCount(true);
                },
            ];

            // retrieve cached data
            $cache = json_decode($this->cache, true);
            if (is_null($cache)) {
                $cache = [];
            }

            if (is_null($key)) {
                foreach ($index as $key => $callback) {
                    $cache[$key] = $callback($this);
                }
            } else {
                $callback    = $index[$key];
                $cache[$key] = $callback($this);
            }

            // write back to the DB
            $this->cache = json_encode($cache);
            $this->save();
        }

        /**
         * Retrieve Campaign cached data.
         *
         * @param null $default
         */
        public function readCache($key, $default = null): mixed
        {
            $cache = json_decode($this->cache, true);
            if (is_null($cache)) {
                return $default;
            }
            if (array_key_exists($key, $cache)) {
                if (is_null($cache[$key])) {
                    return $default;
                } else {
                    return $cache[$key];
                }
            } else {
                return $default;
            }
        }

        /**
         * get active customer sending servers
         */
        public function activeCustomerSendingServers(): SendingServer
        {
            return SendingServer::where('user_id', $this->user->id)->where('status', true);
        }

        public function getCurrentSubscription()
        {
            if (empty($this->currentSubscription)) {
                $this->currentSubscription = $this->user->customer->activeSubscription();
            }

            return $this->currentSubscription;
        }

        /**
         * @throws Exception
         */
        public function getSendingServers()
        {
            if ( ! is_null($this->sendingSevers)) {
                return $this->sendingSevers;
            }

            $sending_server_id = CampaignsSendingServer::where('campaign_id', $this->id)->first()->sending_server_id;
            $sendingSever      = SendingServer::find($sending_server_id);

            $this->sendingSevers = $sendingSever;

            return $this->sendingSevers;
        }

        /**
         * get sender ids
         */
        public function getSenderIds(): array
        {

            if ( ! is_null($this->senderIds)) {
                return $this->senderIds;
            }

            $result = CampaignsSenderid::where('campaign_id', $this->id)->cursor()->map(function ($sender_id) {
                return [$sender_id->sender_id, $sender_id->id];
            })->all();

            $assoc = [];
            foreach ($result as $server) {
                [$key, $fitness] = $server;
                $assoc[$key] = $fitness;
            }

            $this->senderIds = $assoc;

            return $this->senderIds;
        }

        /**
         * mark campaign as queued to processing
         */
        public function running(): void
        {
            $this->status = self::STATUS_PROCESSING;
            $this->run_at = Carbon::now();
            $this->save();
        }

        /**
         * mark campaign as failed
         *
         * @param null $reason
         */
        public function failed($reason = null): void
        {
            $this->status = self::STATUS_FAILED;
            $this->reason = $reason;
            $this->save();
        }

        /**
         * set campaign warning
         *
         * @param null $reason
         */
        public function warning($reason = null): void
        {
            $this->reason = $reason;
            $this->save();
        }

        /**
         * @return $this
         */
        public function refreshStatus(): Campaigns
        {
            $campaign     = self::find($this->id);
            $this->status = $campaign->status;
            $this->save();

            return $this;
        }

        /**
         * Mark the campaign as delivered.
         */
        public function delivered(): void
        {
            $this->status      = self::STATUS_DELIVERED;
            $this->delivery_at = Carbon::now();
            $this->reason      = null;
            $this->save();
        }

        /**
         * Mark the campaign as delivered.
         */
        public function cancelled(): void
        {
            $this->status = self::STATUS_CANCELLED;
            $this->save();
        }

        /**
         * Mark the campaign as processing.
         */
        public function processing(): void
        {
            $this->status      = self::STATUS_PROCESSING;
            $this->running_pid = getmypid();
            $this->run_at      = Carbon::now();
            $this->save();
        }

        /**
         * check if the campaign is in the "Processing Status"
         */
        public function isProcessing(): bool
        {
            return $this->status == self::STATUS_PROCESSING;
        }

        /**
         * get coverage
         */
        public function getCoverage(): array
        {
            $data          = [];
            $plan_coverage = PlansCoverageCountries::where('plan_id', $this->user->customer->activeSubscription()->plan->id)->cursor();
            foreach ($plan_coverage as $coverage) {
                $data[$coverage->country->country_code] = json_decode($coverage->options, true);
            }

            return $data;

        }

        /**
         * reset server pools
         */
        public static function resetServerPools(): void
        {
            self::$serverPools = [];
        }

        /**
         * pick sender id
         */
        public function pickSenderIds(): int|string
        {
            $selection = array_values(array_flip($this->getSenderIds()));
            shuffle($selection);
            while (true) {
                $element = array_pop($selection);
                if ($element) {
                    return (string) $element;
                }
            }
        }

        /**
         * get sms type
         */
        public function getSMSType(): string
        {
            $sms_type = $this->sms_type;

            if ($sms_type == 'plain') {
                return '<span class="badge bg-primary text-uppercase">' . __('locale.labels.plain') . '</span>';
            }
            if ($sms_type == 'unicode') {
                return '<span class="badge bg-primary text-uppercase">' . __('locale.labels.unicode') . '</span>';
            }

            if ($sms_type == 'voice') {
                return '<span class="badge bg-success text-uppercase">' . __('locale.labels.voice') . '</span>';
            }

            if ($sms_type == 'mms') {
                return '<span class="badge bg-info text-uppercase">' . __('locale.labels.mms') . '</span>';
            }

            if ($sms_type == 'whatsapp') {
                return '<span class="badge bg-warning text-uppercase">' . __('locale.labels.whatsapp') . '</span>';
            }
            if ($sms_type == 'viber') {
                return '<span class="badge bg-secondary text-uppercase">' . __('locale.menu.Viber') . '</span>';
            }
            if ($sms_type == 'otp') {
                return '<span class="badge bg-dark text-uppercase">' . __('locale.menu.OTP') . '</span>';
            }

            return '<span class="badge bg-danger text-uppercase">' . __('locale.labels.invalid') . '</span>';
        }

        /**
         * get sms type
         */
        public function getCampaignType(): string
        {
            $sms_type = $this->schedule_type;

            if ($sms_type == 'onetime') {
                return '<div class="d-flex flex-column align-items-start mb-0">
                        <span class="badge badge-light-info text-uppercase">' . __('locale.labels.scheduled') . '</span>
                        <span class="emp_post text-truncate text-muted">' . Tool::customerDateTime($this->schedule_time) . '</span>
                    </div>';
            }
            if ($sms_type == 'recurring') {
                return '<div class="d-flex flex-column align-items-start mb-0">
                        <span class="badge badge-light-success text-uppercase">' . __('locale.labels.recurring') . '</span>
                        <span class="emp_post text-truncate text-muted">' . __('locale.labels.every') . ' ' . $this->displayFrequencyTime() . '</span>
                        <span class="emp_post text-truncate text-muted">' . __('locale.labels.next_schedule_time') . ': ' . Tool::customerDateTime($this->schedule_time->add($this->frequency_unit, $this->frequency_amount)) . '</span>
                        <span class="emp_post text-truncate text-muted">' . __('locale.labels.end_time') . ': ' . Tool::customerDateTime($this->recurring_end) . '</span>
                    </div>';
            }

            return '<span class="badge badge-light-primary text-uppercase">' . __('locale.labels.normal') . '</span>';
        }

        /**
         * Display frequency time
         */
        public function displayFrequencyTime(): string
        {
            return $this->frequency_amount . ' ' . Tool::getPluralParse($this->frequency_unit, $this->frequency_amount);
        }

        /**
         * get campaign status
         */
        public function getStatus(): string
        {
            $status = $this->status;

            if ($status == self::STATUS_FAILED || $status == self::STATUS_CANCELLED || $status == self::STATUS_ERROR) {
                return '<div class="d-flex flex-column align-items-start mb-0">
                        <span class="badge bg-danger text-uppercase">' . __('locale.labels.' . $status) . '</span>
                        <span class="emp_post text-truncate text-muted">' . str_limit($this->last_error, 40) . '</span>
                    </div>';
            }
            if ($status == self::STATUS_SENDING || $status == self::STATUS_PROCESSING) {
                return '<div class="d-flex flex-column align-items-start mb-0">
                        <span class="badge bg-primary text-uppercase mr-1">' . __('locale.labels.' . $status) . '</span>
                        <span class="emp_post text-truncate text-muted">' . __('locale.labels.run_at') . ': ' . Tool::customerDateTime($this->run_at) . '</span>
                    </div>';
            }

            if ($status == self::STATUS_SCHEDULED) {
                return '<span class="badge bg-info text-uppercase mr-1">' . __('locale.labels.scheduled') . '</span>';
            }

            if ($status == self::STATUS_PAUSED) {
                return '<div class="d-flex flex-column align-items-start mb-0">
                        <span class="badge bg-warning text-uppercase">' . __('locale.labels.paused') . '</span>
                        <span class="emp_post text-truncate text-muted">' . __('locale.labels.paused_at') . ': ' . Tool::customerDateTime($this->updated_at) . '</span>
                    </div>';
            }
            if ($status == self::STATUS_NEW || $status == self::STATUS_QUEUED) {
                return '<span class="badge bg-warning text-uppercase">' . __('locale.labels.' . $status) . '</span>';
            }

            if ($status == self::STATUS_QUEUING) {
                return '<span class="badge bg-warning text-uppercase">' . __('locale.labels.' . $status) . '</span>';
            }

            return '<div class="d-flex flex-column align-items-start mb-0">
                        <span class="badge bg-success text-uppercase mr-1">' . __('locale.labels.done') . '</span>
                        <span class="emp_post text-truncate text-muted">' . __('locale.labels.delivered_at') . ': ' . Tool::customerDateTime($this->delivery_at == null ? $this->updated_at : $this->delivery_at) . '</span>
                    </div>';
        }

        /**
         * make ready to send
         *
         * @return $this
         */
        public function queued(): static
        {
            $this->status = self::STATUS_QUEUED;
            $this->save();

            return $this;
        }

        /**
         * Check if the campaign is ready to start.
         */
        public function isQueued(): bool
        {
            return $this->status == self::STATUS_QUEUED;
        }

        /**
         * get another running process
         */
        public function occupiedByOtherAnotherProcess(): bool
        {
            if ( ! function_exists('posix_getpid')) {
                return false;
            }

            return ! is_null($this->running_pid) && posix_getpgid($this->running_pid);
        }

        /**
         * Get the delay time before sending.
         */
        public function getDelayInSeconds(): float|int
        {
            $now = Carbon::now();

            if ($now->gte($this->run_at)) {
                return 0;
            } else {
                return $this->run_at->diffInSeconds($now);
            }
        }

        /**
         * Overwrite the delete() method to also clear the pending jobs.
         */
        public function delete(): ?bool
        {
            $this->cancelAndDeleteJobs(ScheduleCampaign::class);

            return parent::delete();
        }

        /**
         * Check if campaign is paused.
         */
        public function isPaused(): bool
        {
            return $this->status == self::STATUS_PAUSED;
        }

        public function track_message($response, $subscriber, $server)
        {

            $params = [
                'message_id'        => $response->id,
                'customer_id'       => $this->user->id,
                'business_id'       => $this->business_id,
                'sending_server_id' => $server->id,
                'campaign_id'       => $this->id,
                'contact_id'        => $subscriber->id,
                'contact_group_id'  => $subscriber->group_id,
                'status'            => $response->status,
                'sms_count'         => $response->sms_count,
                'cost'              => $response->cost,
            ];

            TrackingLog::create($params);

            if (substr_count($response['status'], 'Delivered') == 1) {
                if ($this->user->sms_unit != '-1') {
                    $this->user->countSMSUnit($response['cost']);
                }
            }
        }

        /**
         * Get Pending Subscribers
         * Select only subscribers that are ready for sending.
         * Those whose status is `blacklisted`, `pending` or `unconfirmed` are not included.
         */

        /**
         * Subscribers query excluding already sent and blacklisted contacts.
         */
        public function subscribersToSend()
        {
            $contactsTable     = (new Contacts())->getTable();
            $trackingLogsTable = (new TrackingLog())->getTable();
            $blacklistTable    = (new Blacklists())->getTable();

            return $this->subscribers()
                ->whereNotIn("{$contactsTable}.phone", function ($q) use ($trackingLogsTable, $contactsTable) {
                    $q->select('c.phone')
                        ->from("{$trackingLogsTable} as t")
                        ->join("{$contactsTable} as c", 't.contact_id', '=', 'c.id')
                        ->where('t.campaign_id', $this->id);
                })
                ->whereNotIn("{$contactsTable}.phone", function ($q) use ($blacklistTable) {
                    $q->select('number')->from($blacklistTable);
                });
        }


        /**
         * update Contact count after delivery
         */
        public function updateContactCount(): void
        {
            $rCount = Reports::where('campaign_id', $this->id)->count();

            if ($rCount) {
                $data                 = json_decode($this->cache, true);
                $data['ContactCount'] = $rCount;
                $this->cache          = json_encode($data);
                $this->save();
            }
        }

        /**
         * @throws CampaignPausedException
         * @throws Exception
         */
        public function send($subscriber, $priceOption, $sending_server)
        {
            $message = $this->generateMessage($subscriber);

            if (Tool::containsSpintaxPattern($message)) {
                $spainTax = new SpinText();
                $message  = $spainTax->process($message);
            }

            $sender_id = $this->pickSenderId();

            $cost = $this->getCost($priceOption);

            $sms_counter  = new SMSCounter();
            $message_data = $sms_counter->count($message, $this->sms_type == 'whatsapp' ? 'WHATSAPP' : null);
            $sms_count    = $message_data->messages;

            if ($sms_count == 0) {
                $sms_count = 1;
            }

            $price = $cost * $sms_count;

            $preparedData = [
                'user_id'        => $this->user_id,
                'phone'          => $this->normalizePhoneNumber($subscriber->phone),
                'sender_id'      => $sender_id,
                'message'        => $message,
                'sms_type'       => $this->sms_type,
                'cost'           => $price,
                'sms_count'      => $sms_count,
                'campaign_id'    => $this->id,
                'sending_server' => $sending_server,
            ];

            $this->addOptionalData($preparedData);

            $getData = $this->sendSMS($preparedData);

            $this->updateCache(substr_count($getData->status, 'Delivered') == 1 ? 'DeliveredCount' : 'FailedDeliveredCount');


//            if ($sending_server->two_way && substr_count($getData->status, 'Delivered') == 1 && is_numeric($sender_id) && ($this->sms_type == 'plain' || $this->sms_type == 'unicode')) {
//
//                $chatbox = ChatBox::firstOrNew([
//                    'user_id'           => $this->user->id,
//                    'from'              => $sender_id,
//                    'to'                => $subscriber->phone,
//                    'sending_server_id' => $sending_server->id,
//                ]);
//
//                if ( ! $chatbox->exists) {
//                    if (isset($input['reply_by_customer'])) {
//                        $chatbox->reply_by_customer = true;
//                    }
//
//                    $chatbox->save();
//                }
//
//                ChatBoxMessage::create([
//                    'box_id'            => $chatbox->id,
//                    'message'           => $message,
//                    'send_by'           => 'from',
//                    'sms_type'          => 'plain',
//                    'sending_server_id' => $sending_server->id,
//                ]);
//            }

            return $getData;
        }

        private function generateMessage($subscriber): array|string
        {

            $tags    = [];
            $message = $this->message;

            foreach ($subscriber->contactGroup->getFields as $field) {
                $tags[$field->tag] = $subscriber->getValueByField($field);
            }

// Use array keys (tags) and values directly in str_replace
            return str_replace(array_map(fn($tag) => '{' . $tag . '}', array_keys($tags)), array_values($tags), $message);

        }

        private function pickSenderId(): int|string|null
        {
            $check_sender_id = $this->getSenderIds();

            return count($check_sender_id) > 0 ? $this->pickSenderIds() : null;
        }

        public function getCost($priceOption)
        {
            $cost = 0;

            switch ($this->sms_type) {
                case 'plain':
                case 'unicode':
                    $cost = $priceOption['plain_sms'];
                    break;
                case 'voice':
                    $cost = $priceOption['voice_sms'];
                    break;
                case 'mms':
                    $cost = $priceOption['mms_sms'];
                    break;
                case 'whatsapp':
                    $cost = $priceOption['whatsapp_sms'];
                    break;
                case 'viber':
                    $cost = $priceOption['viber_sms'];
                    break;
                case 'otp':
                    $cost = $priceOption['otp_sms'];
                    break;
            }

            return $cost;
        }

        /**
         * @return array|string|string[]
         */
        private function normalizePhoneNumber($phoneNumber): array|string
        {
            return str_replace(['+', '(', ')', '-', ' '], '', $phoneNumber);
        }

        public function addOptionalData(&$preparedData): void
        {
            if (isset($this->dlt_template_id)) {
                $preparedData['dlt_template_id'] = $this->dlt_template_id;
            }

            if (isset($this->user->dlt_entity_id)) {
                $preparedData['dlt_entity_id'] = $this->user->dlt_entity_id;
            }

            if (isset($this->user->dlt_telemarketer_id)) {
                $preparedData['dlt_telemarketer_id'] = $this->user->dlt_telemarketer_id;
            }

            if (isset($this->api_key)) {
                $preparedData['api_key'] = $this->api_key;
            }

            if ($this->sms_type == 'voice') {
                $preparedData['language'] = $this->language;
                $preparedData['gender']   = $this->gender;
            }

            if ($this->sms_type == 'mms' || $this->sms_type == 'whatsapp' || $this->sms_type == 'viber' || $this->sms_type == 'voice') {
                if (isset($this->media_url)) {
                    $preparedData['media_url'] = $this->media_url;
                }

                if (isset($this->language)) {
                    $preparedData['language'] = $this->language;
                }
            }
        }

        /**
         * @throws Exception
         */
        public function sendSMS($preparedData)
        {
            $getData = null;

            if ($this->sms_type == 'plain' || $this->sms_type == 'unicode') {
                $getData = $this->sendPlainSMS($preparedData);
            }

            if ($this->sms_type == 'voice') {
                $getData = $this->sendVoiceSMS($preparedData);
            }

            if ($this->sms_type == 'mms') {
                $getData = $this->sendMMS($preparedData);
            }

            if ($this->sms_type == 'whatsapp') {
                $getData = $this->sendWhatsApp($preparedData);
            }

            if ($this->sms_type == 'viber') {
                $getData = $this->sendViber($preparedData);
            }

            if ($this->sms_type == 'otp') {
                $getData = $this->sendOTP($preparedData);
            }

            return $getData;
        }

        /*Version 3.5*/

        /**
         * return contacts data
         */
        public function subscribers()
        {
            if ($this->contactList->isEmpty()) {
                return (new Contacts)->limit(0);
            }

            $list_id = (new CampaignsList)->where('campaign_id', $this->id)->pluck('contact_list_id')->unique()->all();

            return Contacts::whereIn('group_id', $list_id)->where('status', 'subscribe');
        }

        /*
        |--------------------------------------------------------------------------
        | Version 3.6
        |--------------------------------------------------------------------------
        |
        | Make faster campaigns
        |
        */

        /**
         * Clear existing jobs
         */
        public function cancelAndDeleteJobs($jobType = null): void
        {
            $query = $this->jobMonitors();

            if ( ! is_null($jobType)) {
                $query = $query->byJobType($jobType);
            }
            if ($query->get()->count()) {
                foreach ($query->get() as $job) {
                    $job->delete();
                }
            }
        }

        /**
         * Re-queue the campaign for sending.
         * @throws Throwable
         */
        public function requeue(): void
        {
            // Delete previous ScheduleCampaign jobs
            $this->cancelAndDeleteJobs(ScheduleCampaign::class);

            // Schedule Job initialize
            $scheduler = (new ScheduleCampaign($this))->delay($this->run_at);

            // Dispatch using the method provided by TrackJobs
            // to also generate job-monitor record
            $this->dispatchWithMonitor($scheduler);

            $this->queued();
        }

        /**
         * Pause campaign.
         *
         * @param null $reason
         */
        public function pause($reason = null): void
        {
            $this->cancelAndDeleteJobs();
            $this->setPaused($reason);
        }

        public function setPaused($reason = null): static
        {
            // set campaign status
            $this->status = self::STATUS_PAUSED;
            $this->reason = $reason;
            $this->save();

            return $this;
        }

        // Should be called by campaigns

        /**
         * @throws Throwable
         */
        public function run(bool $check = true): void
        {
            // --- 1. Lock campaign state before running ---
            if ($check) {
                $this->withLock(function () {
                    if ($this->isSending()) {
                        throw new Exception('Campaign is already in progress');
                    }
                    $this->setSending();
                });
            }

            // --- 2. Cancel any previous batch jobs safely ---
            $this->jobMonitors()
                ->byJobType(static::JOB_TYPE_DISPATCH_AND_SEND_MESSAGES)
                ->get()
                ->each(fn($job) => $job->cancelWithoutDeleteBatch());

            // --- 3. Reset delay flag ---
            $this->setDelayFlag(null);

            // --- 4. Determine subscriber source ---
            $isFileCampaign   = $this->upload_type === 'file';
            $subscribersQuery = $isFileCampaign
                ? $this->getFileCampaignData()
                : $this->subscribersToSend();

            $perPage         = 50;
            $maxPageToLoad   = 2;
            $pageCount       = 0;
            $campaignLoaders = [];

            // --- 5. Paginate and prepare LoadCampaign jobs ---
            Helper::paginate_query($subscribersQuery, $perPage, $isFileCampaign ? 'id' : 'contacts.id',
                function ($pageNumber, $subscribers) use (&$campaignLoaders, &$pageCount) {
                    $pageCount++;
                    $listOfIds         = $subscribers->pluck($subscribers->first()?->getKeyName() ?? 'id')->toArray();
                    $campaignLoaders[] = new LoadCampaign($this, $pageNumber, $listOfIds);
                },
                $maxPageToLoad
            );

            if ($pageCount === 0) {
                // Fallback empty batch if no contact found
                $campaignLoaders[] = new LoadCampaign($this, 0, []);
            }

            $campaignId = $this->uid;
            $className  = static::class;

            // --- 6. Define reusable callbacks ---
            $thenCallback = function () use ($campaignId, $className, $isFileCampaign) {
                $campaign = $className::findByUid($campaignId)?->refresh();
                if ( ! $campaign) {
                    Log::warning("Campaign not found for UID: {$campaignId}");

                    return false;
                }

                if ($campaign->isPaused()) {
                    $campaign->logger()->warning('Campaign is paused');

                    return false;
                }

                $count = $isFileCampaign
                    ? $campaign->getFileCampaignData()->count()
                    : $campaign->subscribersToSend()->count();

                if ($count > 0) {
                    $campaign->logger()->info("Remaining {$count} contacts — starting another batch");
                    $campaign->run(false);
                } else {
                    $campaign->logger()->info('No contact left — campaign finished successfully');
                    $campaign->setDone();

                    $campaign->debug(function (array $info) {
                        $startAt            = Carbon::parse($info['start_at'] ?? now());
                        $finishAt           = now();
                        $info['finish_at']  = $finishAt->toDateTimeString();
                        $info['total_time'] = $finishAt->diffInSeconds($startAt);

                        return $info;
                    });
                }

                return false;
            };

            $catchCallback = function (Batch $batch, Throwable $e) use ($campaignId, $className) {
                if ( ! $campaign = $className::findByUid($campaignId)) {
                    Log::error("Campaign UID {$campaignId} missing during catch callback.");

                    return;
                }

                $errorMsg = sprintf("Campaign stopped: %s\n%s", $e->getMessage(), $e->getTraceAsString());
                $campaign->setError(substr($errorMsg, 0, 1000));
                $campaign->logger()->error($errorMsg);
            };

            $finallyCallback = function () use ($campaignId, $className) {
                if ($campaign = $className::findByUid($campaignId)) {
                    $campaign->logger()->info('Finally callback executed — updating cache');
                    $campaign->updateCache();
                }
            };

            // --- 7. Dispatch batch with monitor ---
            $this->dispatchWithBatchMonitor(
                static::JOB_TYPE_DISPATCH_AND_SEND_MESSAGES,
                $campaignLoaders,
                $thenCallback,
                $catchCallback,
                $finallyCallback
            );
        }

        /**
         * @throws Exception
         */
        public function prepare($callback, $loadLimit = null): void
        {
            Tool::resetMaxExecutionTime();

            if ( ! is_null($loadLimit)) {
                $subscribers = $this->subscribersToSend()->limit($loadLimit)->get();

                foreach ($subscribers as $subscriber) {
                    $this->processSubscriber($subscriber, $callback);
                }

                return; // Important
            }

            $query = $this->subscribersToSend();

            Helper::cursorIterate($query, 'contacts.id', 100, function ($subscribers) use ($callback) {
                foreach ($subscribers as $subscriber) {
                    $this->processSubscriber($subscriber, $callback);
                }
            });
        }

        private function processSubscriber($subscriber, $callback): void
        {
            try {
                $phoneUtil         = PhoneNumberUtil::getInstance();
                $phoneNumberObject = $phoneUtil->parse('+' . $subscriber->phone);
                $countryCode       = $phoneNumberObject->getCountryCode();
                $isoCode           = $phoneUtil->getRegionCodeForNumber($phoneNumberObject);

                if ( ! empty($countryCode) && ! empty($isoCode)) {

                    $coverage = CustomerBasedPricingPlan::where('user_id', $this->user->id)
                        ->whereHas('country', function ($query) use ($countryCode, $isoCode) {
                            $query->where('country_code', $countryCode)
                                ->where('iso_code', $isoCode)
                                ->where('status', 1);
                        })
                        ->with('sendingServer')
                        ->first();

                    if ( ! $coverage) {
                        $coverage = PlansCoverageCountries::where(function ($query) use ($countryCode, $isoCode) {
                            $query->whereHas('country', function ($query) use ($countryCode, $isoCode) {
                                $query->where('country_code', $countryCode)
                                    ->where('iso_code', $isoCode)
                                    ->where('status', 1);
                            })->where('plan_id', $this->user->customer->activeSubscription()->plan_id);
                        })
                            ->with('sendingServer')
                            ->first();
                    }
                    if ($coverage) {
                        $sending_server = isset($this->sending_server_id) ? $this->sendingServer : $coverage->sendingServer;
                        $priceOption    = json_decode($coverage->options, true);

                        $callback($this, $subscriber, $sending_server, $priceOption);
                    }
                } else {

                    $params = [
                        'customer_id'       => $this->user->id,
                        'business_id'       => $this->business_id,
                        'sending_server_id' => 1,
                        'campaign_id'       => $this->id,
                        'contact_id'        => $subscriber->id,
                        'contact_group_id'  => $subscriber->group_id,
                        'status'            => 'Invalid phone number',
                        'sms_count'         => 1,
                        'cost'              => 0,
                    ];

                    TrackingLog::create($params);

                    $reportsData = [
                        'user_id'           => $this->user->id,
                        'business_id'       => $this->business_id,
                        'to'                => $subscriber->phone,
                        'message'           => $this->message,
                        'sms_type'          => $this->sms_type,
                        'status'            => 'Invalid phone number',
                        'customer_status'   => 'Invalid phone number',
                        'sms_count'         => 1,
                        'cost'              => 0,
                        'from'              => $this->pickSenderId(),
                        'sending_server_id' => null,
                        'campaign_id'       => $this->id,
                        'direction'         => Reports::DIRECTION_OUTGOING,
                    ];

                    Reports::create($reportsData);
                }
            } catch (NumberParseException $e) {

                $params = [
                    'customer_id'       => $this->user->id,
                    'business_id'       => $this->business_id,
                    'sending_server_id' => 1,
                    'campaign_id'       => $this->id,
                    'contact_id'        => $subscriber->id,
                    'contact_group_id'  => $subscriber->group_id,
                    'status'            => $e->getMessage(),
                    'sms_count'         => 1,
                    'cost'              => 0,
                ];

                TrackingLog::create($params);

                $reportsData = [
                    'user_id'           => $this->user->id,
                    'business_id'       => $this->business_id,
                    'to'                => $subscriber->phone,
                    'message'           => $this->message,
                    'sms_type'          => $this->sms_type,
                    'status'            => $e->getMessage(),
                    'customer_status'   => 'Invalid phone number',
                    'sms_count'         => 1,
                    'cost'              => 0,
                    'from'              => $this->pickSenderId(),
                    'sending_server_id' => null,
                    'campaign_id'       => $this->id,
                    'direction'         => Reports::DIRECTION_OUTGOING,
                ];

                Reports::create($reportsData);
            }

        }

        public function stopOnError(): bool
        {
            return $this->skip_failed_message == false;
        }

        /*Version 3.8*/

        public function setQueuing()
        {
            $this->status = self::STATUS_QUEUING;
            $this->save();

            return $this;
        }

        public function setSending()
        {
            $this->status      = self::STATUS_SENDING;
            $this->running_pid = getmypid();
            $this->delivery_at = Carbon::now();
            $this->save();
        }

        public function isSending()
        {
            return $this->status == self::STATUS_SENDING;
        }

        public function isDone()
        {
            return $this->status == self::STATUS_DONE;
        }

        /**
         * Execute the campaign.
         *
         * @throws Throwable
         */
        public function execute(bool $force = false): void
        {
            // Avoid starting if scheduled in the future
            if ($this->run_at && $this->run_at->isFuture()) {
                $scheduledAt = $this->run_at->timezone($this->user->timezone);
                $this->logger()->warning(sprintf(
                    'Campaign is scheduled at %s (%s)',
                    $scheduledAt->format('Y-m-d H:i'),
                    $scheduledAt->diffForHumans()
                ));

                return;
            }

            // Prevent re-running unless forced
//            if (($this->isSending() || $this->isQueued()) && ! $force) {
//                throw new Exception('Cannot execute: campaign is already in "sending" or "queued" status');
//            }

            if ($force) {
                $this->logger()->warning('Force running campaign');
            }

            // Run core logic in a single atomic transaction
            DB::transaction(function () {
                // Cancel previous related jobs
                $this->cancelAndDeleteJobs(static::JOB_TYPE_RUN_CAMPAIGN);
                $this->cancelAndDeleteJobs(static::JOB_TYPE_DISPATCH_AND_SEND_MESSAGES);

                // Dispatch the campaign runner
                $job = new RunCampaign($this);
                $this->dispatchWithMonitor($job, static::JOB_TYPE_RUN_CAMPAIGN);

                // Mark as queued after successful dispatch
                $this->setQueued();
            });
        }


        public function setDone()
        {
            $this->status     = self::STATUS_DONE;
            $this->last_error = null;
            $this->save();
        }

        public function setQueued()
        {
            $this->status = self::STATUS_QUEUED;
            $this->save();

            return $this;
        }

        /**
         * @throws Throwable
         */
        public function resume()
        {
            $this->execute();
        }


        public function loadDeliveryJobs(Closure $callback, int $loadLimit = null)
        {

            Tool::resetMaxExecutionTime();

            if (is_null($loadLimit)) {
                $query = $this->subscribersToSend();

                Helper::cursorIterate($query, 'contacts.id', 100, function ($subscribers) use ($callback) {
                    foreach ($subscribers as $subscriber) {
                        $this->processSubscriber($subscriber, $callback);
                    }
                });
            } else {
                $subscribers    = $this->subscribersToSend()->limit($loadLimit)->get();
                $sending_server = isset($this->sending_server_id) ? $this->sendingServer : null;

                foreach ($subscribers as $subscriber) {

                    try {
                        $phoneUtil         = PhoneNumberUtil::getInstance();
                        $phoneNumberObject = $phoneUtil->parse('+' . $subscriber->phone);
                        $countryCode       = $phoneNumberObject->getCountryCode();
                        $isoCode           = $phoneUtil->getRegionCodeForNumber($phoneNumberObject);

                        if ( ! empty($isoCode) && ! empty($countryCode)) {

                            $coverage = CustomerBasedPricingPlan::where('user_id', $this->user->id)
                                ->whereHas('country', function ($query) use ($countryCode, $isoCode) {
                                    $query->where('country_code', $countryCode)
                                        ->where('iso_code', $isoCode)
                                        ->where('status', 1);
                                })
                                ->with('sendingServer')
                                ->first();

                            if ( ! $coverage) {
                                $coverage = PlansCoverageCountries::where(function ($query) use ($countryCode, $isoCode) {
                                    $query->whereHas('country', function ($query) use ($countryCode, $isoCode) {
                                        $query->where('country_code', $countryCode)
                                            ->where('iso_code', $isoCode)
                                            ->where('status', 1);
                                    })->where('plan_id', $this->user->customer->activeSubscription()->plan_id);
                                })
                                    ->with('sendingServer')
                                    ->first();
                            }


                            if ($coverage) {

                                if ($sending_server == null) {

                                    $sms_type = $this->sms_type;

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

                                    // Use the map to get the sending server or fallback to the default
                                    $serverKey      = $smsTypeToServerMap[$db_sms_type] ?? $defaultServer;
                                    $sending_server = $coverage->{$serverKey};
                                }

                                if ($sending_server) {
                                    $priceOption = json_decode($coverage->options, true);
                                    $job         = new SendMessage($this, $subscriber, $sending_server, $priceOption);

                                    //   $stopOnError = $this->stopOnError();
                                    //     $job->setStopOnError($stopOnError);
                                    $callback($job);
                                }
                            }
                        } else {

                            $params = [
                                'customer_id'       => $this->user->id,
                                'business_id'       => $this->business_id,
                                'sending_server_id' => null,
                                'campaign_id'       => $this->id,
                                'contact_id'        => $subscriber->id,
                                'contact_group_id'  => $subscriber->group_id,
                                'status'            => 'Invalid phone number',
                                'sms_count'         => 1,
                                'cost'              => 0,
                            ];

                            TrackingLog::create($params);

                            $reportsData = [
                                'user_id'           => $this->user->id,
                                'business_id'       => $this->business_id,
                                'to'                => $subscriber->phone,
                                'message'           => $this->message,
                                'sms_type'          => $this->sms_type,
                                'status'            => 'Invalid phone number',
                                'customer_status'   => 'Invalid phone number',
                                'sms_count'         => 1,
                                'cost'              => 0,
                                'from'              => $this->pickSenderId(),
                                'sending_server_id' => null,
                                'campaign_id'       => $this->id,
                                'direction'         => Reports::DIRECTION_OUTGOING,
                            ];

                            Reports::create($reportsData);
                        }
                    } catch (NumberParseException|Exception $e) {
                        $params = [
                            'customer_id'       => $this->user->id,
                            'business_id'       => $this->business_id,
                            'sending_server_id' => null,
                            'campaign_id'       => $this->id,
                            'contact_id'        => $subscriber->id,
                            'contact_group_id'  => $subscriber->group_id,
                            'status'            => $e->getMessage(),
                            'sms_count'         => 1,
                            'cost'              => 0,
                        ];

                        TrackingLog::create($params);

                        $reportsData = [
                            'user_id'           => $this->user->id,
                            'business_id'       => $this->business_id,
                            'to'                => $subscriber->phone,
                            'message'           => $this->message,
                            'sms_type'          => $this->sms_type,
                            'status'            => $e->getMessage(),
                            'customer_status'   => $e->getMessage(),
                            'sms_count'         => 1,
                            'cost'              => 0,
                            'from'              => $this->pickSenderId(),
                            'sending_server_id' => null,
                            'campaign_id'       => $this->id,
                            'direction'         => Reports::DIRECTION_OUTGOING,
                        ];

                        Reports::create($reportsData);
                    }

                }
            }
        }

        public function getFileCampaignData()
        {
            return FileCampaignData::where('campaign_id', $this->id);
        }

//        /**
//         * Start the campaign. Called by daemon job
//         *
//         * @throws NumberParseException
//         * @throws Exception
//         */
////        public function loadBulkDeliveryJobsIds(Closure $callback, int $loadLimit = null)
////        {
////
////            Tool::resetMaxExecutionTime();
////
////            $subscribers = $this->getFileCampaignData()->limit($loadLimit)->get();
////
////            foreach ($subscribers as $subscriber) {
////                $job = new SendFileMessage($this, $subscriber);
////
////                $stopOnError = $this->stopOnError();
////                // $stopOnError = Setting::isYes('campaign.stop_on_error'); // true or false
////                $job->setStopOnError($stopOnError);
////                $callback($job);
////            }
////        }

        public function setScheduled()
        {
            // TODO: Implement setScheduled() method.
        }

        public function setError($error = null)
        {
            $this->status     = self::STATUS_ERROR;
            $this->last_error = $error;
            $this->save();

            return $this;
        }

        public function isError()
        {
            return $this->status == self::STATUS_ERROR;
        }

        public function extractErrorMessage()
        {
            return explode("\n", $this->last_error)[0];
        }

        /**
         * @throws Exception|Throwable
         */
        public static function checkAndExecuteScheduledCampaigns()
        {
            $lockFile        = storage_path('tmp/check-and-execute-scheduled-campaign');
            $lock            = new Lockable($lockFile);
            $timeout         = 5; // seconds
            $timeoutCallback = function () {
            };

            $lock->getExclusiveLock(function () {
                foreach (static::scheduled()->get() as $campaign) {
                    $campaign->execute();
                }
            }, $timeout, $timeoutCallback);
        }

        /*
        |--------------------------------------------------------------------------
        | Version 3.9
        |--------------------------------------------------------------------------
        |
        |
        |
        */

        /**
         * Retrieves the contact group names and generates HTML links to each group's page.
         *
         * @return string The HTML string containing the links to the contact group pages.
         */
        public function contactGroupsName()
        {
            $contactGroups = $this->contactList()->with('contactGroups:id,name,uid')->get();

            $returnValue = '';

            foreach ($contactGroups as $contactGroup) {
                foreach ($contactGroup->contactGroups as $group) {
                    $returnValue .= '<br><a href="' . route('customer.contacts.show', $group->uid) . '" class="text-primary text-decoration-underline" target="_blank">' . $group->name . '</a>';
                }
            }

            return $returnValue;
        }


        private function getDebugCacheKey()
        {
            return 'debug-campaign-' . $this->uid;
        }

        /**
         * @throws Exception
         */
        public function debug(Closure $callback = null)
        {
            $lockKey = "lock-for-debug-campaign-{$this->uid}";
            $key     = $this->getDebugCacheKey();
            // Read only
            if (is_null($callback)) {
                return Cache::get($key);
            }

            // Do not use Cache::lock()->block($wait) here which is not suitable for multi process with very high traffic
            // As it do not retry fast enough if a lock cannot be get. i.e. it waits for another second to try for example
            // Use Lockable instead which retries almost immediately with "while (true)"
            $result = null;
            Helper::with_cache_lock($lockKey, function () use (&$result, $callback, $key) {
                $info = Cache::get($key, [
                    'start_at'                       => null,
                    'last_activity_at'               => Carbon::now()->toString(),
                    'finish_at'                      => null,
                    'total_time'                     => null,
                    'last_message_sent_at'           => null,
                    'messages_sent_per_second'       => null,
                    'send_message_count'             => 0,
                    'send_message_total_time'        => 0,
                    'send_message_prepare_avg_time'  => null,
                    'send_message_lock_avg_time'     => null,
                    'send_message_delivery_avg_time' => null,
                    'send_message_avg_time'          => null,
                    'send_message_min_time'          => null,
                    'send_message_max_time'          => null,
                    'delay_note'                     => null,
                ]);

                // update and return debug info
                $result = $callback($info);

                // Update cache, in case of Redis
                // Redis::multi()->set($key, json_encode($result))->get($key)->exec();
                Cache::put($key, $result);
            }, 10);

            return $result; // return the get value (second element in result)
        }


        public function getDelayFlagKey()
        {
            return "campaign-delay-flag-{$this->uid}";
        }

        public function checkDelayFlag()
        {
            return Cache::get($this->getDelayFlagKey()) ?? false;
        }

        public function setDelayFlag($value)
        {
            if (is_null($value)) {
                Cache::forget($this->getDelayFlagKey());
            } else {
                Cache::put($this->getDelayFlagKey(), $value);
            }
        }


        /**
         * @throws Exception
         */
        public function logger()
        {
            if ( ! is_null($this->logger)) {
                return $this->logger;
            }

            $formatter = new LineFormatter("[%datetime%] %channel%.%level_name%: %message%\n");

            $logfile = $this->getLogFile();
            $stream  = new RotatingFileHandler($logfile, 0, config('custom.log_level'));
            $stream->setFormatter($formatter);

            $pid    = getmypid();
            $logger = new Logger($pid);
            $logger->pushHandler($stream);
            $this->logger = $logger;

            return $this->logger;
        }

        /**
         * @throws Exception
         */


        public function loadDeliveryJobsByIds(Closure $callback, int $page, array $listOfIds): int
        {
            $subscribers = $this->subscribersToSend()
                ->whereIn('contacts.id', $listOfIds)
                ->select(['id', 'phone', 'group_id'])
                ->get();

            if ($subscribers->isEmpty()) {
                $this->logger()->info("Page {$page}: no contacts in this page");

                return 0;
            }

            $this->logger()->info(sprintf(
                "Page %d: processing %d contacts (ID %d → %d)",
                $page,
                $subscribers->count(),
                $subscribers->first()->id,
                $subscribers->last()->id
            ));

            // Reuse heavy objects once per page
            $phoneUtil = PhoneNumberUtil::getInstance();
            $userId    = $this->user->id;
            $smsType   = $this->sms_type;
            //   $stopOnError   = $this->stopOnError();
            $sendingServer = $this->sending_server_id ? $this->sendingServer : null;

            // Cache coverage lookups to prevent N+1 plan queries per campaign/user
            [$userCoveragePlans, $globalCoveragePlans] = $this->getCachedCoveragePlans($userId);

            $processed = 0;

            foreach ($subscribers as $subscriber) {
                try {
                    $phoneNumber = '+' . ltrim($subscriber->phone, '+');
                    $numberObj   = $phoneUtil->parse($phoneNumber);
                    $countryCode = $numberObj->getCountryCode();
                    $isoCode     = $phoneUtil->getRegionCodeForNumber($numberObj);

                    if ( ! $countryCode || ! $isoCode) {
                        $this->createFailedLog($subscriber, 'Invalid phone number');
                        continue;
                    }

                    $coverageKey = "{$countryCode}-{$isoCode}";
                    $coverage    = $userCoveragePlans[$coverageKey] ?? $globalCoveragePlans[$coverageKey] ?? null;

                    if ( ! $coverage) {
                        $this->createFailedLog($subscriber, __('locale.sending_servers.sending_server_not_found'));
                        continue;
                    }

                    $server = $sendingServer ?? $this->resolveSendingServer($coverage, $smsType);
                    if ( ! $server) {
                        $this->createFailedLog($subscriber, __('locale.sending_servers.sending_server_not_found'));
                        continue;
                    }

                    $priceOption = $this->resolvePriceOption($server, $coverage, $subscriber->phone);
                    if ($priceOption === null) {
                        continue;
                    }

                    $job = new SendMessage($this, $subscriber, $server, $priceOption);
                    //     $job->setStopOnError($stopOnError);

                    $callback($job);
                    $processed++;

                } catch (NumberParseException) {
                    $this->createFailedLog($subscriber, 'Invalid phone number format');
                } catch (Exception $e) {
                    $this->createFailedLog($subscriber, $e->getMessage());
                }
            }

            $this->logger()->info("Page {$page}: successfully queued {$processed} messages");

            return $processed;
        }

        /**
         * Retrieve cached user & global coverage plans to minimize DB hits.
         */
        protected function getCachedCoveragePlans(int $userId): array
        {
            $cacheKeyUser   = "user_coverage_plans_{$userId}";
            $planId         = $this->user->customer->activeSubscription() ? $this->user->customer->activeSubscription()->plan_id : 'none';
            $cacheKeyGlobal = "global_coverage_plans_{$planId}";

            $userCoveragePlans = Cache::remember($cacheKeyUser, now()->addMinutes(30), function () use ($userId) {
                return CustomerBasedPricingPlan::where('user_id', $userId)
                    ->with(['country', 'sendingServer'])
                    ->get()
                    ->keyBy(fn($plan) => "{$plan->country->country_code}-{$plan->country->iso_code}")
                    ->all();
            });

            $globalCoveragePlans = Cache::remember($cacheKeyGlobal, now()->addMinutes(30), function () {
                $planId = optional($this->user->customer->activeSubscription())->plan_id;
                if ( ! $planId) return [];

                return PlansCoverageCountries::where('plan_id', $planId)
                    ->with(['country', 'sendingServer'])
                    ->get()
                    ->keyBy(fn($plan) => "{$plan->country->country_code}-{$plan->country->iso_code}")
                    ->all();
            });

            return [$userCoveragePlans, $globalCoveragePlans];
        }

        /**
         * Create failed tracking log and report entry.
         *
         * @param mixed  $subscriber The subscriber that failed
         * @param string $status The status of the failure
         * @return void
         */
        private function createFailedLog(mixed $subscriber, string $status): void
        {
            $params = [
                'customer_id'       => $this->user->id,
                'business_id'       => $this->business_id,
                'sending_server_id' => null,
                'campaign_id'       => $this->id,
                'contact_id'        => $subscriber->id,
                'contact_group_id'  => $subscriber->group_id,
                'status'            => $status,
                'sms_count'         => 1,
                'cost'              => 0,
            ];

            TrackingLog::create($params);

            Reports::create([
                'user_id'           => $this->user->id,
                'business_id'       => $this->business_id,
                'to'                => $subscriber->phone,
                'message'           => $this->message,
                'sms_type'          => $this->sms_type,
                'status'            => $status,
                'customer_status'   => $status,
                'sms_count'         => 1,
                'cost'              => 0,
                'from'              => $this->pickSenderId(),
                'sending_server_id' => null,
                'campaign_id'       => $this->id,
                'direction'         => Reports::DIRECTION_OUTGOING,
            ]);
        }

        /**
         * Resolve the sending server based on SMS type and coverage.
         */
        private function resolveSendingServer($coverage, string $smsType)
        {
            $map = [
                'unicode'  => 'plain',
                'voice'    => 'voiceSendingServer',
                'mms'      => 'mmsSendingServer',
                'whatsapp' => 'whatsappSendingServer',
                'viber'    => 'viberSendingServer',
                'otp'      => 'otpSendingServer',
            ];

            $dbType = $smsType === 'unicode' ? 'plain' : $smsType;
            $key    = $map[$dbType] ?? 'sendingServer';

            return $coverage->{$key} ?? null;
        }

        /**
         * Resolve pricing based on gateway-wise billing settings.
         */
        private function resolvePriceOption($sendingServer, $coverage, string $phone)
        {
            $priceOption = json_decode($coverage->options, true);

            if ( ! config('app.gateway_wise_billing')) {
                return $priceOption;
            }

            $serverPricing = SendingServerBasedPricingPlans::where('sending_server', $sendingServer->id)
                ->where('country_id', $coverage->country_id)
                ->first();

            if ( ! $serverPricing) {
                $this->createFailedLog((object) ['phone' => $phone, 'id' => null, 'group_id' => null],
                    "Permission to send SMS has not been enabled for the region indicated by number: {$phone}");

                return null;
            }

            return json_decode($serverPricing->options, true);
        }


        public function cleanupDebug()
        {
            $key = $this->getDebugCacheKey();
            Cache::forget($key);
        }


        /**
         * @throws Exception
         */
        public function getLogFile()
        {
            return storage_path(Helper::join_paths('logs', php_sapi_name(), '/campaign-' . $this->uid . '.log'));
        }

        /**
         * @throws Exception
         */
        public function withLock(Closure $task)
        {
            $key = "lock-campaign-{$this->uid}";
            Helper::with_cache_lock($key, function () use ($task) {
                $task();
            });
        }

        /**
         * @throws Exception
         */
        public function loadBulkDeliveryJobsByIds(Closure $callback, int $page, array $listOfIds)
        {

            // Query subscribers
            $subscribers = $this->getFileCampaignData()->whereIn('id', $listOfIds)->get();

            if (sizeof($subscribers) == 0) {
                $this->logger()->info("Page {$page}, no contacts in this page");
            } else {
                $this->logger()->info("Page {$page}, from guy {$subscribers[0]->id} to {$subscribers[sizeof($subscribers) - 1]->id}");
            }


            foreach ($subscribers as $subscriber) {
                $job = new SendFileMessage($this, $subscriber);

                // $stopOnError = $this->stopOnError();
                // $stopOnError = Setting::isYes('campaign.stop_on_error'); // true or false
                //   $job->setStopOnError($stopOnError);
                $callback($job);

            }

            // Important
            return 0;
        }

        public function lockContact($contact): bool
        {
            // Attempt to acquire a lock for this contact ID
            $lockKey = sprintf('campaign:%s:contact:%s', $this->id, $contact->id);

            return cache()->lock($lockKey, 30)->get(); // 30s lock
        }


    }

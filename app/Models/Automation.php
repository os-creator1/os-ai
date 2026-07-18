<?php

    namespace App\Models;


    use App\Exceptions\CampaignPausedException;
    use App\Helpers\Helper;
    use App\Jobs\AutomationJob;
    use App\Library\SMSCounter;
    use App\Library\Tool;
    use App\Library\Traits\HasUid;
    use App\Library\Traits\TrackJobs;
    use Carbon\Carbon;
    use DateTime;
    use DateTimeZone;
    use Exception;
    use Illuminate\Database\Eloquent\Relations\BelongsTo;
    use Illuminate\Database\Eloquent\Relations\HasMany;
    use Illuminate\Support\Facades\DB;

    /**
     * @method static where(string $string, string $uid)
     * @method static create(array $array)
     * @method static whereIn(string $string, array $ids)
     * @method static find(int $int)
     */
    class Automation extends SendCampaignSMS
    {
        use TrackJobs;
        use HasUid;


        // Automation status
        public const STATUS_ACTIVE   = 'active';
        public const STATUS_INACTIVE = 'inactive';
        public const STATUS_ERROR    = 'error';
        protected $senderIds = null;

        protected $fillable = [
            'user_id',
            'name',
            'contact_list_id',
            'message',
            'media_url',
            'language',
            'gender',
            'sms_type',
            'status',
            'reason',
            'sender_id',
            'cache',
            'timezone',
            'data',
            'running_pid',
            'dlt_template_id',
            'sending_server_id',
            'last_error',
        ];

        protected $casts = [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];


        /**
         * get user
         *
         * @return BelongsTo
         *
         */
        public function user(): BelongsTo
        {
            return $this->belongsTo(User::class);
        }

        /**
         * get customer
         *
         * @return BelongsTo
         *
         */
        public function customer(): BelongsTo
        {
            return $this->belongsTo(Customer::class, 'user_id');
        }


        /**
         * associate with contact groups
         *
         * @return BelongsTo
         */
        public function contactList(): BelongsTo
        {
            return $this->belongsTo(ContactGroups::class);
        }

        /**
         * get sending server
         *
         * @return BelongsTo
         *
         */
        public function sendingServer(): BelongsTo
        {
            return $this->belongsTo(SendingServer::class, 'sending_server_id');
        }


        /**
         * get reports
         *
         * @return HasMany
         */
        public function reports(): HasMany
        {
            return $this->hasMany(Reports::class, 'automation_id', 'id');
        }

        /**
         * get tracking log
         *
         * @return HasMany
         */
        public function trackingLogs(): HasMany
        {
            return $this->hasMany(TrackingLog::class, 'automation_id', 'id');
        }

        /**
         * Frequency time unit options.
         *
         * @return array
         * @noinspection PhpUnused
         */
        public static function timeUnitOptions(): array
        {
            return [
                ['value' => 'minute', 'text' => 'minute'],
                ['value' => 'day', 'text' => 'day'],
                ['value' => 'week', 'text' => 'week'],
                ['value' => 'month', 'text' => 'month'],
                ['value' => 'year', 'text' => 'year'],
            ];
        }


        /**
         * Get delay or before options.
         */
        public static function getDelayBeforeOptions(): array
        {
            return [
                ['text' => trans_choice('locale.automations.0_day', 0), 'value' => '0 day'],
                ['text' => trans_choice('locale.automations.day', 1), 'value' => '1 day'],
                ['text' => trans_choice('locale.automations.day', 2), 'value' => '2 days'],
                ['text' => trans_choice('locale.automations.day', 3), 'value' => '3 days'],
                ['text' => trans_choice('locale.automations.day', 4), 'value' => '4 days'],
                ['text' => trans_choice('locale.automations.day', 5), 'value' => '5 days'],
                ['text' => trans_choice('locale.automations.day', 6), 'value' => '6 days'],
                ['text' => trans_choice('locale.automations.week', 1), 'value' => '1 week'],
                ['text' => trans_choice('locale.automations.week', 2), 'value' => '2 weeks'],
                ['text' => trans_choice('locale.automations.month', 1), 'value' => '1 month'],
                ['text' => trans_choice('locale.automations.month', 2), 'value' => '2 months'],
            ];
        }


        /**
         * Update Automation cached data.
         *
         * @param null $key
         *
         * @noinspection PhpUnused
         */
        public function updateCache($key = null)
        {
            // cache indexes
            $index = [
                'DeliveredCount'       => function ($automation) {
                    return $automation->deliveredCount();
                },
                'FailedDeliveredCount' => function ($automation) {
                    return $automation->failedCount();
                },
                'NotDeliveredCount'    => function ($automation) {
                    return $automation->notDeliveredCount();
                },
                'ContactCount'         => function ($automation) {
                    return $automation->contactCount(true);
                },
                'PendingContactCount'  => function ($automation) {
                    return $automation->pendingContactCount(true);
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
         * Retrieve Automation cached data.
         *
         * @param      $key
         * @param null $default
         *
         * @return mixed
         */
        public function readCache($key, $default = null)
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


        public function contactCount($cache = false)
        {
            if ($cache) {
                return $this->readCache('ContactCount', 0);
            }

            return Contacts::where('group_id', $this->contactList()->id)->where('status', 'subscribe')->count();

        }

        /**
         * show delivered count
         *
         * @param false $cache
         *
         * @return int
         *
         */
        public function deliveredCount(bool $cache = false)
        {
            if ($cache) {
                return $this->readCache('DeliveredCount', 0);
            }

            return $this->reports()->where('automation_id', $this->id)->where('status', 'like', '%Delivered%')->count();
        }

        /**
         * show failed count
         *
         * @param false $cache
         *
         * @return int
         */
        public function failedCount(bool $cache = false)
        {
            if ($cache) {
                return $this->readCache('FailedDeliveredCount', 0);
            }

            return $this->reports()->where('automation_id', $this->id)->where('status', 'not like', '%Delivered%')->count();
        }

        /**
         * show not delivered count
         *
         * @param false $cache
         *
         * @return int
         */
        public function notDeliveredCount(bool $cache = false)
        {
            if ($cache) {
                return $this->readCache('NotDeliveredCount', 0);
            }

            return $this->reports()->where('automation_id', $this->id)->where('status', 'like', '%Sent%')->count();
        }

        /**
         * Pending Contact
         *
         * @return int
         */
        public function pendingContactCount()
        {
            return $this->readCache('ContactCount') - ($this->readCache('DeliveredCount') + $this->readCache('FailedDeliveredCount'));
        }

        /**
         * get sms type
         *
         * @return string
         */
        public function getSMSType()
        {
            $sms_type = $this->sms_type;

            if ($sms_type == 'plain') {
                return '<span class="badge bg-primary text-uppercase me-1 mb-1">' . __('locale.labels.plain') . '</span>';
            }
            if ($sms_type == 'unicode') {
                return '<span class="badge bg-primary text-uppercase me-1 mb-1">' . __('locale.labels.unicode') . '</span>';
            }

            if ($sms_type == 'voice') {
                return '<span class="badge bg-success text-uppercase me-1 mb-1">' . __('locale.labels.voice') . '</span>';
            }

            if ($sms_type == 'mms') {
                return '<span class="badge bg-info text-uppercase me-1 mb-1">' . __('locale.labels.mms') . '</span>';
            }

            if ($sms_type == 'whatsapp') {
                return '<span class="badge bg-warning text-uppercase mb-1">' . __('locale.labels.whatsapp') . '</span>';
            }

            if ($sms_type == 'viber') {
                return '<span class="badge bg-secondary text-uppercase mb-1">' . __('locale.menu.Viber') . '</span>';
            }

            if ($sms_type == 'otp') {
                return '<span class="badge bg-warning text-uppercase mb-1">' . __('locale.menu.OTP') . '</span>';
            }

            return '<span class="badge bg-danger text-uppercase mb-1">' . __('locale.labels.invalid') . '</span>';
        }


        /**
         * get campaign status
         *
         * @return string
         */
        public function getStatus()
        {
            $status = $this->status;

            if ($status == self::STATUS_INACTIVE) {
                return '<div>
                        <span class="badge bg-warning text-uppercase mr-1 mb-1">' . __('locale.labels.paused') . '</span>
                        <p class="text-muted">' . __('locale.labels.paused_at') . ': ' . Tool::customerDateTime($this->updated_at) . '</p>
                    </div>';
            }

            return '<span class="badge bg-info text-uppercase mr-1 mb-1">' . __('locale.labels.running') . '</span>';
        }

        public function subscribers()
        {
            return Contacts::where('group_id', $this->contact_list_id)->select('contacts.*');
        }

        /**
         * @throws Exception
         */
        public function subscribersNotTriggeredThisYear()
        {
            $thisYear = $this->user->getCurrentTime()->format('Y');

            return $this->subscribers()->where('contacts.status', 'subscribe')->leftJoin('tracking_logs', function ($join) {
                $join->on('tracking_logs.contact_id', 'contacts.id');
                $join->where('tracking_logs.automation_id', $this->id);
            })->where(function ($query) use ($thisYear) {
                $query->whereNull('tracking_logs.id')
                    ->orWhereRaw(sprintf('year(%s) < %s', Helper::table('tracking_logs.created_at'), $thisYear));
            });
        }

        public function getOptions()
        {
            return json_decode($this->data, true)['options'];
        }


        /**
         * @throws Exception
         */
        public function start()
        {

            $currentTime = new DateTime(null, new DateTimeZone($this->timezone));
            $triggerTime = new DateTime($this->getOptions()['at'], new DateTimeZone($this->timezone));

            if ($currentTime < $triggerTime) {
                return;
            }

            $interval   = $this->getOptions()['before'];
            $dobFieldId = ContactGroupFields::findByUid($this->getOptions()['birthday_field'])->id;
            $today      = Carbon::now($this->timezone)->modify($interval);

            if ($dobFieldId == null) {
                return;
            }

            $contacts = $this->subscribersNotTriggeredThisYear()
                ->join('contacts_custom_field', 'contacts.id', 'contacts_custom_field.contact_id')
                ->where('contacts_custom_field.field_id', $dobFieldId)
                ->whereIn(
                    DB::raw("DATE_FORMAT(STR_TO_DATE(" . Helper::table('contacts_custom_field.value') . ", '" . config('custom.date_format_sql') . "'), '%m-%d')"),
                    [$today->format('m-d')]
                )->get();


            if ($contacts->count() == 0) {
                return;
            }

            // Delete previous ScheduleCampaign jobs
            $this->cancelAndDeleteJobs(AutomationJob::class);

            // Schedule Job initialize
            $scheduler = (new AutomationJob($this, $contacts))->delay($triggerTime);

            // Dispatch using the method provided by TrackJobs
            // to also generate job-monitor record
            $this->dispatchWithMonitor($scheduler);
        }


        /**
         * Clear existing jobs
         *
         * @return void
         */
        public function cancelAndDeleteJobs()
        {
            JobMonitor::where('subject_name', self::class)->where('subject_id', $this->id)->delete();
        }

        public function getSubscribersWithTriggerInfo()
        {
            return $this->subscribers()
                ->leftJoin('tracking_logs', function ($join) {
                    $join->on('tracking_logs.contact_id', 'contacts.id');
                    $join->where('tracking_logs.automation_id', $this->id);
                })
                ->addSelect('tracking_logs.id as auto_trigger_id')
                ->addSelect('tracking_logs.status as status')
                ->addSelect('tracking_logs.created_at as triggered_at');
        }

        /**
         * Check if campaign is paused.
         *
         * @return bool
         */
        public function isPaused(): bool
        {
            return $this->status == self::STATUS_INACTIVE;
        }

        /**
         * @return $this
         */
        public function refreshStatus(): Automation
        {
            $automation   = self::find($this->id);
            $this->status = $automation->status;
            $this->save();

            return $this;
        }


        /**
         * @throws CampaignPausedException
         * @throws Exception
         */
        public function send($subscriber, $priceOption, $sending_server)
        {
            if ($this->refreshStatus()->isPaused()) {
                $this->updateCache();
                throw new CampaignPausedException();
            }

            $message = $this->generateMessage($subscriber);

            $sender_id = $this->pickSenderId();

            $cost = $this->getCost($priceOption);

            $sms_counter  = new SMSCounter();
            $message_data = $sms_counter->count($message, $this->sms_type == 'whatsapp' ? 'WHATSAPP' : null);
            $sms_count    = $message_data->messages;

            $price = $cost * $sms_count;

            $preparedData = [
                'user_id'        => $this->user_id,
                'phone'          => $this->normalizePhoneNumber($subscriber->phone),
                'sender_id'      => $sender_id,
                'message'        => $message,
                'sms_type'       => $this->sms_type,
                'cost'           => $price,
                'sms_count'      => $sms_count,
                'automation_id'  => $this->id,
                'sending_server' => $sending_server,
            ];

            $this->addOptionalData($preparedData);

            $getData = $this->sendSMS($preparedData);

            $this->updateCache(substr_count($getData->status, 'Delivered') == 1 ? 'DeliveredCount' : 'FailedDeliveredCount');

            $this->updateCache();

            return $getData;
        }

        private function generateMessage($subscriber)
        {

            $tags    = [];
            $message = $this->message;

            foreach ($this->contactList->getFields as $field) {
                $tags[$field->tag] = $subscriber->getValueByField($field);
            }

            foreach ($tags as $tag => $value) {
                $message = str_replace('{' . $tag . '}', $value, $message);
            }

            return $message;

        }


        /**
         * pick sender id
         *
         *
         */
        private function pickSenderId(): int|string
        {
            $selection = array_values($this->getSenderIds());
            shuffle($selection);
            while (true) {
                $element = array_pop($selection);
                if ($element) {
                    return (string) $element;
                }
            }
        }

        /**
         * get sender ids
         *
         * @return array
         */
        public function getSenderIds(): array
        {

            if ( ! is_null($this->senderIds)) {
                return $this->senderIds;
            }

            $result = json_decode($this->sender_id, true);

            $this->senderIds = $result;

            return $this->senderIds;
        }


        private function getCost($priceOption)
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
         * @param $phoneNumber
         *
         * @return array|string|string[]
         */
        private function normalizePhoneNumber($phoneNumber): array|string
        {
            return str_replace(['+', '(', ')', '-', ' '], '', $phoneNumber);
        }

        /**
         * @param $preparedData
         *
         * @return void
         */
        private function addOptionalData(&$preparedData): void
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

            if ($this->sms_type == 'mms' || $this->sms_type == 'whatsapp' || $this->sms_type == 'viber') {
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
        private function sendSMS($preparedData)
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

        /**
         * @throws Exception
         */
        public function track_message($response, $subscriber, $server)
        {

            $params = [
                'message_id'        => $response->id,
                'customer_id'       => $this->user->id,
                'sending_server_id' => $server->id,
                'automation_id'     => $this->id,
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
         * Pause campaign.
         *
         * @return void
         */
        public function pause($reason = null)
        {
            $this->cancelAndDeleteJobs();
            $this->setPaused($reason);
        }

        private function setPaused($reason = null)
        {
            // set campaign status
            $this->status = self::STATUS_INACTIVE;
            $this->reason = $reason;
            $this->save();

            return $this;
        }

        public function setError($error = null)
        {
            $this->status     = self::STATUS_ERROR;
            $this->last_error = $error;
            $this->save();

            return $this;
        }

    }

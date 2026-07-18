<?php

    namespace App\Models;

    use App\Enums\BillingCycleEnum;
    use App\Library\QuotaManager;
    use App\Library\RateLimit;
    use App\Library\Tool;
    use Carbon\Carbon;
    use Exception;
    use Illuminate\Contracts\Foundation\Application;
    use Illuminate\Contracts\Translation\Translator;
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\Eloquent\Relations\BelongsTo;
    use Illuminate\Database\Eloquent\Relations\HasMany;

    /**
     * @method static where(string $string, bool $true)
     * @method static select(string $string)
     * @method static count()
     * @method static offset(mixed $start)
     * @method static whereLike(string[] $array, mixed $search)
     * @method static cursor()
     * @method static find(mixed $plan_id)
     * @method static create(array $plan)
     * @method static whereIn(string $string, array $ids)
     *
     * @property mixed      frequency_unit
     * @property mixed      frequency_amount
     * @property bool|mixed status
     * @property mixed      id
     * @property mixed      name
     * @property mixed      currency
     * @property mixed      price
     */
    class Plan extends Model
    {
        // Plan status
        const STATUS_INACTIVE = false;

        const STATUS_ACTIVE = true;

        /**
         * The attributes that are mass assignable.
         *
         * @var array
         */
        protected $fillable = [
            'user_id',
            'name',
            'description',
            'billing_cycle',
            'frequency_amount',
            'frequency_unit',
            'price',
            'currency_id',
            'options',
            'status',
            'is_popular',
            'tax_billing_required',
            'show_in_customer',
            'is_dlt',
            'transaction_id',
        ];

        protected $casts = [
            'status'               => 'boolean',
            'show_in_customer'     => 'boolean',
            'is_popular'           => 'boolean',
            'tax_billing_required' => 'boolean',
            'is_dlt'               => 'boolean',
        ];

        /**
         * Bootstrap any application services.
         */
        public static function boot()
        {
            parent::boot();

            // Create uid when creating list.
            static::creating(function ($item) {
                // Create new uid
                $uid = uniqid();
                while (self::where('uid', $uid)->count() > 0) {
                    $uid = uniqid();
                }
                $item->uid = $uid;

                // Update custom order
                self::getAll()->increment('custom_order');
                $item->custom_order = 0;
            });
        }

        /**
         * Active status scope
         *
         *
         * @return mixed
         */
        public function scopeStatus($query, bool $status)
        {
            return $query->where('status', $status);
        }

        /**
         * get user
         */
        public function user(): BelongsTo
        {
            return $this->belongsTo(User::class);
        }

        /**
         * Plan Coverage countries
         */
        public function plansCoverageCountries(): HasMany
        {
            return $this->hasMany(PlansCoverageCountries::class);
        }

        /**
         * Currency
         */
        public function currency(): BelongsTo
        {
            return $this->belongsTo(Currency::class);
        }

        /**
         * get all plans
         *
         * @return Plan
         */
        public static function getAll()
        {
            return self::select('*');
        }

        /**
         * Find item by uid.
         */
        public static function findByUid($uid): object
        {
            return self::where('uid', $uid)->first();
        }

        /**
         * Frequency time unit options.
         */
        public static function timeUnitOptions(): array
        {
            return [
                ['value' => 'day', 'text' => __('locale.labels.day')],
                ['value' => 'week', 'text' => __('locale.labels.week')],
                ['value' => 'month', 'text' => __('locale.labels.month')],
                ['value' => 'year', 'text' => __('locale.labels.year')],
            ];
        }

        /**
         * Get sending limit types.
         */
        public static function sendingLimitValues(): array
        {
            return [
                'unlimited'      => [
                    'quota_value' => -1,
                    'quota_base'  => -1,
                    'quota_unit'  => 'day',
                ],
                '100_per_minute' => [
                    'quota_value' => 100,
                    'quota_base'  => 1,
                    'quota_unit'  => 'minute',
                ],
                '1000_per_hour'  => [
                    'quota_value' => 1000,
                    'quota_base'  => 1,
                    'quota_unit'  => 'hour',
                ],
                '10000_per_hour' => [
                    'quota_value' => 10000,
                    'quota_base'  => 1,
                    'quota_unit'  => 'hour',
                ],
                '50000_per_hour' => [
                    'quota_value' => 50000,
                    'quota_base'  => 1,
                    'quota_unit'  => 'hour',
                ],
                '10000_per_day'  => [
                    'quota_value' => 10000,
                    'quota_base'  => 1,
                    'quota_unit'  => 'day',
                ],
                '100000_per_day' => [
                    'quota_value' => 100000,
                    'quota_base'  => 1,
                    'quota_unit'  => 'day',
                ],
            ];
        }

        /**
         * Get billing recurs available values.
         */
        public static function billingCycleValues(): array
        {
            return [
                'daily'      => [
                    'frequency_amount' => 1,
                    'frequency_unit'   => 'day',
                ],
                'monthly'    => [
                    'frequency_amount' => 1,
                    'frequency_unit'   => 'month',
                ],
                'yearly'     => [
                    'frequency_amount' => 1,
                    'frequency_unit'   => 'year',
                ],
                'non_expiry' => [
                    'frequency_amount' => 999,
                    'frequency_unit'   => 'year',
                ],
            ];
        }

        /**
         * Check if plan time is unlimited.
         */
        public function isTimeUnlimited(): bool
        {
            return in_array($this->frequency_unit, ['unlimited', 'unlim']) || $this->billing_cycle == BillingCycleEnum::NON_EXPIRY->value;
        }

        /**
         * Display frequency time
         *
         * @return array|Application|Translator|string|null
         */
        public function displayFrequencyTime()
        {
            // unlimited
            if ($this->isTimeUnlimited()) {
                return __('locale.labels.non_expiry');
            }
            if ($this->frequency_amount == 1) {
                return Tool::getPluralParse(__('locale.labels.' . $this->frequency_unit), $this->frequency_amount);
            }

            return $this->frequency_amount . ' ' . Tool::getPluralParse(__('locale.labels.' . $this->frequency_unit), $this->frequency_amount);
        }

        /**
         * Display total quota
         *
         * @return Application|array|string|Translator|null
         */
        public function displayTotalQuota()
        {
            if ($this->getOption('sms_max') == -1) {
                return __('locale.labels.unlimited');
            } else {
                return Tool::format_number($this->getOption('sms_max'));
            }
        }

        /**
         * Display total quota
         *
         * @return Application|array|string|Translator|null
         */
        public function displayWhatsAppQuota()
        {
            if ($this->getOption('sms_max') == -1) {
                return __('locale.labels.unlimited');
            } else {
                return Tool::format_number($this->getOption('whatsapp_max'));
            }
        }

        /**
         * Display max lists.
         *
         * @return array|Application|Translator|string|null
         */
        public function displayMaxList()
        {
            if ($this->getOption('list_max') == -1) {
                return __('locale.labels.unlimited');
            } else {
                return Tool::format_number($this->getOption('list_max'));
            }
        }

        /**
         * Display max subscribers.
         *
         * @return array|Application|Translator|string|null
         */
        public function displayMaxContact()
        {
            if ($this->getOption('subscriber_max') == -1) {
                return __('locale.labels.unlimited');
            } else {
                return Tool::format_number($this->getOption('subscriber_max'));
            }
        }

        /**
         * Display max subscribers per list
         *
         * @return array|Application|Translator|string|null
         */
        public function displayMaxContactPerList()
        {
            if ($this->getOption('subscriber_per_list_max') == -1) {
                return __('locale.labels.unlimited');
            } else {
                return Tool::format_number($this->getOption('subscriber_per_list_max'));
            }
        }

        /**
         * get single option
         */
        public function getOption($name): string
        {
            return $this->getOptions()[$name];
        }

        /**
         * Get sending limit select options.
         */
        public function getSendingLimitSelectOptions(): array
        {
            $options = [];

            foreach (self::sendingLimitValues() as $key => $data) {
                $wording   = __('locale.plans.' . $key);
                $options[] = ['text' => $wording, 'value' => $key];
            }

            // exist
            if ($this->getOption('sending_limit') == 'custom') {
                $wording = __('locale.plans.custom_sending_limit_phrase', [
                    'quota_value' => Tool::format_number($this->getOption('sending_quota')),
                    'quota_base'  => Tool::format_number($this->getOption('sending_quota_time')),
                    'quota_unit'  => $this->getOption('sending_quota_time_unit'),
                ]);

                $options[] = ['text' => $wording, 'value' => 'other'];
            }

            // Custom
            $options[] = ['text' => 'Custom', 'value' => 'custom'];

            return $options;
        }

        /**
         * Get options.
         */
        public function getOptions(): array
        {
            if (empty($this->options)) {
                return self::defaultOptions();
            } else {
                $default_options = self::defaultOptions();
                $saved_options   = json_decode($this->options, true);
                foreach ($default_options as $x => $group) {
                    if (isset($saved_options[$x])) {
                        $default_options[$x] = $saved_options[$x];
                    }
                }

                return $default_options;
            }
        }

        /**
         * Default options for new plan.
         */
        public static function defaultOptions(): array
        {
            return [
                'sms_max'                    => '5',
                'whatsapp_max'               => '5',
                'list_max'                   => '-1',
                'subscriber_max'             => '-1',
                'subscriber_per_list_max'    => '-1',
                'segment_per_list_max'       => '3',
                'billing_cycle'              => 'monthly',
                'sending_limit'              => '1000_per_hour',
                'sending_quota'              => '1000',
                'sending_quota_time'         => '1',
                'sending_quota_time_unit'    => 'hour',
                'max_process'                => '1',
                'list_import'                => 'yes',
                'list_export'                => 'yes',
                'api_access'                 => 'no',
                'create_sub_account'         => 'yes',
                'delete_sms_history'         => 'yes',
                'add_previous_balance'       => 'no',
                'sender_id_verification'     => 'yes',
                'send_spam_message'          => 'no',
                'sender_id'                  => null,
                'sender_id_price'            => null,
                'sender_id_billing_cycle'    => null,
                'sender_id_frequency_amount' => null,
                'sender_id_frequency_unit'   => null,
            ];
        }

        /**
         * Disable plan.
         */
        public function disable(): bool
        {
            $this->status = self::STATUS_INACTIVE;

            return $this->save();
        }

        /**
         * Enable plan.
         */
        public function enable(): bool
        {
            $this->status = self::STATUS_ACTIVE;

            return $this->save();
        }

        /**
         * Get country coverage
         *
         * @return HasMany|Model|object|null
         */
        public function pricingCoverage()
        {
            $pss = $this->plansCoverageCountries()->first();

            return is_object($pss) ? $pss : null;
        }

        public function hasPricingCoverage(): bool
        {
            return is_object($this->pricingCoverage());
        }

        /**
         * get plan id
         */
        public function getBillableId(): string
        {
            return $this->id;
        }

        /**
         * get plan name
         */
        public function getBillableName(): string
        {
            return $this->name;
        }

        /**
         * get plan interval.
         */
        public function getBillableInterval(): string
        {
            return $this->frequency_unit;
        }

        /**
         * get plan interval count.
         */
        public function getBillableIntervalCount(): string
        {
            return $this->frequency_amount;
        }

        /**
         *  get currency.
         */
        public function getBillableCurrency(): string
        {
            return $this->currency->code;
        }

        /**
         * get plan interval count.
         */
        public function getBillableAmount(): string
        {
            return $this->price;
        }

        /**
         * get plan interval count.
         */
        public function getBillableFormattedPrice(): string
        {
            return Tool::format_price($this->price, $this->currency->format);
        }

        /**
         * get subscriptions
         */
        public function subscriptions(): HasMany
        {
            return $this->hasMany(Subscription::class, 'plan_id', 'id')
                ->where(function ($query) {
                    $query->whereNull('end_at')
                        ->orWhere('end_at', '>=', Carbon::now());
                })
                ->orderBy('created_at', 'desc');
        }

        /**
         * Customers count.
         */
        public function customersCount(): int
        {
            return $this->subscriptions()->distinct('user_id')->count('user_id');
        }

        /**
         * check valid
         */
        public function isValid(): bool
        {
            return true;
        }

        /**
         * Check status of sending server
         *
         * @return void
         */
        public function checkStatus()
        {
            // disable sending server if it is not valid
            if ( ! $this->isValid()) {
                $this->disable();
            }
        }

        /**
         * get route key by uid
         */
        public function getRouteKeyName(): string
        {
            return 'uid';
        }

        public function getQuotaSettings(): ?array
        {
            $quota   = [];
            $options = $this->getOptions();
            // Take limits from sending credits
            $sendingCredits = $options['sending_quota'];
            if ($sendingCredits != QuotaManager::QUOTA_UNLIMITED) {
                $quota[] = [
                    'name'         => "Plan's sending limit",
                    'period_unit'  => $this->frequency_unit,
                    'period_value' => $this->frequency_amount,
                    'limit'        => $sendingCredits,
                ];
            }

            $timeValue = $options['sending_quota_time'];
            if ($timeValue != QuotaManager::QUOTA_UNLIMITED) {
                $timeUnit = $options['sending_quota_time_unit'];
                $limit    = $options['sending_quota'];

                $quota[] = [
                    'name'         => "Sending limit of {$limit} per {$timeValue} {$timeUnit}",
                    'period_unit'  => $timeUnit,
                    'period_value' => $timeValue,
                    'limit'        => $limit,
                ];
            }

            return $quota;
        }

        public function PlanCreditPrice(int $countryId = null): HasMany
        {
            $query = $this->hasMany(PlanSendingCreditPrice::class, 'plan_id');

            // If countryId is passed, filter the results by country_id
            if ($countryId) {
                $query->where('country_id', $countryId);
            }

            return $query;
        }

        public function getCreditPrices($countryId = null)
        {
            return $this->PlanCreditPrice($countryId);
        }

        public function hasCreditPrices()
        {
            $pss = $this->getCreditPrices()->first();

            return is_object($pss);
        }

        /**
         * @throws Exception
         */
        public function getSendSMSRateLimits()
        {
            $limits  = [];
            $options = $this->getOptions();

            // Sending speed limit (Settings > Security)
            if ($options['sending_quota'] != RateLimit::UNLIMITED) {
                $timeUnit = $options['sending_quota_time_unit'];
                $limit    = $options['sending_quota'];

                $limits[] = new RateLimit(
                    $limit,
                    $options['sending_quota_time'],
                    $timeUnit,
                    "Plan ({$this->name}) sending speed limit of {$limit} per {$options['sending_quota_time']} {$timeUnit}"
                );
            }

            return $limits;
        }

    }

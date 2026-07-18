<?php

    namespace App\Models;

    use App\Enums\BillingCycleEnum;
    use App\Library\Tool;
    use App\Library\Traits\HasUid;
    use Illuminate\Contracts\Foundation\Application;
    use Illuminate\Contracts\Translation\Translator;
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\Eloquent\Relations\BelongsTo;

    /**
     * @method static whereLike(string[] $array, $search)
     * @method static where(string $string, string $uid)
     * @method static select(string $string)
     * @method static count()
     * @method static offset(mixed $start)
     * @method static cursor()
     * @method static create(array $number)
     * @property mixed name
     */
    class PhoneNumbers extends Model
    {

        use HasUid;

        /**
         * The attributes for assign table
         *
         * @var string
         */

        protected $table = 'phone_numbers';

        /**
         * The attributes that are mass assignable.
         *
         * @var array
         */

        protected $fillable = [
            'user_id',
            'number',
            'status',
            'capabilities',
            'price',
            'billing_cycle',
            'frequency_amount',
            'frequency_unit',
            'validity_date',
            'currency_id',
            'transaction_id',
        ];

        /**
         * @var string[]
         */
        protected $casts = [
            'capabilities'  => 'object',
            'validity_date' => 'date',
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
         * Currency
         *
         * @return BelongsTo
         *
         */
        public function currency(): BelongsTo
        {
            return $this->belongsTo(Currency::class);
        }


        /**
         * get all plans
         *
         * @return PhoneNumbers
         */

        public static function getAll()
        {
            return self::select('*');
        }

        /**
         * get numbers capabilities
         *
         * @return string
         */
        public function getCapabilities(): string
        {
            $return_data  = '';
            $capabilities = json_decode($this->capabilities, true);
            foreach ($capabilities as $capability) {
                if ($capability == 'sms') {
                    $return_data .= '<span class="badge bg-primary text-uppercase me-1"><span>' . __('locale.labels.sms') . '</span></span>';
                }
                if ($capability == 'voice') {
                    $return_data .= '<span class="badge bg-success text-uppercase me-1"><span>' . __('locale.labels.voice') . '</span></span>';
                }
                if ($capability == 'mms') {
                    $return_data .= '<span class="badge bg-info text-uppercase me-1"><span>' . __('locale.labels.mms') . '</span></span>';
                }
                if ($capability == 'whatsapp') {
                    $return_data .= '<span class="badge bg-warning text-uppercase me-1"><span>' . __('locale.labels.whatsapp') . '</span></span>';
                }

                if ($capability == 'viber') {
                    $return_data .= '<span class="badge bg-secondary text-uppercase me-1"><span>' . __('locale.menu.Viber') . '</span></span>';
                }

                if ($capability == 'otp') {
                    $return_data .= '<span class="badge bg-dark text-uppercase"><span>' . __('locale.menu.OTP') . '</span></span>';
                }
            }

            return $return_data;
        }

        /**
         * Frequency time unit options.
         *
         * @return array
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

        /**
         * Check if phone number validity time is unlimited.
         *
         * @return bool
         */
        public function isTimeUnlimited(): bool
        {
            return in_array($this->frequency_unit, ['unlimited', 'unlim']) || $this->billing_cycle == BillingCycleEnum::NON_EXPIRY->value;
        }


        /**
         * Get billing recurs available values.
         *
         * @return array
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

    }

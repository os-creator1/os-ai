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
     * @method static offset($start)
     * @method static cursor()
     * @method static create(array $senderId)
     * @method static find(array|null $sender_id)
     * @property mixed name
     * @property mixed status
     */
    class Senderid extends Model
    {

        use HasUid;

        const STATUS_PENDING          = 'pending';
        const STATUS_ACTIVE           = 'active';
        const STATUS_INACTIVE         = 'inactive';
        const STATUS_BLOCKED          = 'block';
        const STATUS_PAYMENT_REQUIRED = 'payment_required';
        const STATUS_EXPIRED          = 'expired';

        /**
         * The attributes for assign table
         *
         * @var string
         */

        protected $table = 'senderid';

        /**
         * The attributes that are mass assignable.
         *
         * @var array
         */

        protected $fillable = [
            'user_id',
            'sender_id',
            'status',
            'price',
            'billing_cycle',
            'frequency_amount',
            'frequency_unit',
            'payment_claimed',
            'validity_date',
            'currency_id',
            'transaction_id',
            'description',
            'entity_id',
            'document',
        ];

        /**
         * @var string[]
         */
        protected $casts = [
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
         * @return Senderid
         */

        public static function getAll()
        {
            return self::select('*');
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

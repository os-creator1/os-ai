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
 * @method static where(string $string, $uid)
 * @method static select(string $string)
 * @method static count()
 * @method static offset(mixed $start)
 * @method static whereLike(string[] $array, mixed $search)
 * @method static cursor()
 * @method static create(array $senderId)
 * @method static insert(array $keyword_data)
 * @property mixed name
 */
class Keywords extends Model
{
    use HasUid;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */

    protected $fillable = [
            'user_id',
            'title',
            'keyword_name',
            'sender_id',
            'reply_text',
            'reply_voice',
            'reply_mms',
            'status',
            'price',
            'billing_cycle',
            'frequency_amount',
            'frequency_unit',
            'currency_id',
            'validity_date',
            'transaction_id',
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
     * @return Keywords
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

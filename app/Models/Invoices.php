<?php

    namespace App\Models;

    use App\Library\Traits\HasUid;
    use Carbon\Carbon;
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\Eloquent\Relations\BelongsTo;

    /**
     * @method static where(string $string, string $uid)
     * @method static create(array $array)
     * @method static CurrentMonth()
     * @method static whereLike(string[] $array, mixed $search)
     * @method static offset(mixed $start)
     * @method static count()
     * @method static whereIn(string $string, mixed $ids)
     * @method static insert(array[] $invoices)
     */
    class Invoices extends Model
    {
        use HasUid;

        /**
         * Invoice status
         */
        const STATUS_PAID      = 'paid';
        const STATUS_CANCELLED = 'cancelled';
        const STATUS_UNPAID    = 'unpaid';
        const STATUS_PENDING   = 'pending';

        /**
         * Invoice types
         */
        const TYPE_SENDERID     = 'senderid';
        const TYPE_KEYWORD      = 'keyword';
        const TYPE_SUBSCRIPTION = 'subscription';
        const TYPE_NUMBERS      = 'number';

        /**
         * fillable value
         *
         * @var string[]
         */
        protected $fillable = [
            'uid',
            'user_id',
            'currency_id',
            'payment_method',
            'amount',
            'total_amount',
            'tax',
            'type',
            'description',
            'transaction_id',
            'status',
            'created_at',
            'updated_at',
        ];

        /**
         * get user
         */
        public function user(): BelongsTo
        {
            return $this->belongsTo(User::class);
        }

        /**
         * Currency
         */
        public function currency(): BelongsTo
        {
            return $this->belongsTo(Currency::class);
        }

        /**
         * Payment method
         */
        public function paymentMethod(): BelongsTo
        {
            return $this->belongsTo(PaymentMethods::class, 'payment_method', 'id');
        }

        /**
         * get status
         */
        public function getStatus(): string
        {

            $status = $this->status;

            if ($status == self::STATUS_PENDING) {
                return '<span class="badge rounded-pill badge-light-warning text-capitalize mr-1 mb-1">' . __('locale.labels.pending') . '</span>';
            }

            return '<span class="badge rounded-pill badge-light-success text-capitalize mr-1 mb-1">' . __('locale.labels.paid') . '</span>';
        }

        public function scopeCurrentMonth($query)
        {
            return $query->where('created_at', '>=', Carbon::now()->firstOfMonth());
        }

    }

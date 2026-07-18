<?php

    namespace App\Models;

    use App\Library\Tool;
    use App\Library\Traits\HasUid;
    use Carbon\Carbon;
    use Illuminate\Database\Eloquent\Casts\Attribute;
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\Eloquent\Relations\BelongsTo;
    use Illuminate\Database\Eloquent\Relations\HasMany;
    use Illuminate\Support\Str;

    /**
     * @method static where(string $string, string $uid)
     * @method static create(array $array)
     * @method static select(string $string, string $string1, string $string2, string $string3, string $string4, string $string5)
     * @method static whereIn(string $string, mixed $ids)
     * @method static cursor()
     * @method static currentMonth()
     * @method static count()
     * @method static offset(mixed $start)
     * @method static whereLike(string[] $array, mixed $search)
     * @method static insert(array $data)
     */
    class Reports extends Model
    {
        use HasUid;

        const DIRECTION_OUTGOING = 'outgoing';
        const DIRECTION_INCOMING = 'incoming';
        const DIRECTION_API      = 'api';

        protected $fillable = [
            'user_id',
            'campaign_id',
            'automation_id',
            'from',
            'to',
            'message',
            'media_url',
            'sms_type',
            'status',
            'customer_status',
            'direction',
            'cost',
            'api_key',
            'sending_server_id',
            'sms_count',
            'sender_id',
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
         * get sending server
         *
         * @return BelongsTo
         *
         */
        public function sendingServer(): BelongsTo
        {
            return $this->belongsTo(SendingServer::class);
        }

        /**
         * get campaign
         *
         * @return HasMany
         *
         */
        public function campaign(): HasMany
        {
            return $this->hasMany(Campaigns::class);
        }

        public function scopeCurrentMonth($query)
        {
            return $query->where('created_at', ">=", Carbon::now()->firstOfMonth());
        }

        /**
         * get sms type
         *
         * @return string
         */
        public function getSMSType(): string
        {
            $sms_type = $this->sms_type;

            if ($sms_type == 'plain') {
                return '<span class="badge bg-primary text-uppercase mr-1 mb-1">' . __('locale.labels.plain') . '</span>';
            }
            if ($sms_type == 'unicode') {
                return '<span class="badge bg-primary text-uppercase mr-1 mb-1">' . __('locale.labels.unicode') . '</span>';
            }

            if ($sms_type == 'voice') {
                return '<span class="badge bg-success text-uppercase mr-1 mb-1">' . __('locale.labels.voice') . '</span>';
            }

            if ($sms_type == 'mms') {
                return '<span class="badge bg-info text-uppercase mr-1 mb-1">' . __('locale.labels.mms') . '</span>';
            }

            if ($sms_type == 'whatsapp') {
                return '<span class="badge bg-warning text-uppercase mb-1">' . __('locale.labels.whatsapp') . '</span>';
            }

            if ($sms_type == 'viber') {
                return '<span class="badge bg-secondary text-uppercase mb-1">' . __('locale.menu.Viber') . '</span>';
            }

            if ($sms_type == 'otp') {
                return '<span class="badge bg-dark text-uppercase mb-1">' . __('locale.menu.OTP') . '</span>';
            }

            return '<span class="badge bg-danger text-uppercase mb-1">' . __('locale.labels.invalid') . '</span>';
        }

        /**
         * get sms direction
         *
         * @return string
         */
        public function getDirection(): string
        {
            $sms_type = $this->direction;

            if ($sms_type == Reports::DIRECTION_OUTGOING) {
                return '<span class="badge bg-primary text-uppercase mr-1 mb-1">' . __('locale.labels.outgoing') . '</span>';
            }

            if ($sms_type == Reports::DIRECTION_INCOMING) {
                return '<span class="badge bg-success text-uppercase mr-1 mb-1">' . __('locale.labels.incoming') . '</span>';
            }

            if ($sms_type == Reports::DIRECTION_API) {
                return '<span class="badge bg-info text-uppercase mr-1 mb-1">' . __('locale.labels.api') . '</span>';
            }

            return '<span class="badge bg-danger text-uppercase mb-1">' . __('locale.labels.invalid') . '</span>';
        }


        /*Version 3.6*/

        public function escapedFrom(): Attribute
        {
            return Attribute::make(
                get: fn() => Str::of($this->from)->markdown([
                    'html_input'         => 'escape',
                    'allow_unsafe_links' => false,
                    'max_nesting_level'  => 5,
                ])

            );
        }

        public function escapedMessage(): Attribute
        {
            return Attribute::make(
                get: fn() => Str::of($this->from)->markdown([
                    'html_input'         => 'escape',
                    'allow_unsafe_links' => false,
                    'max_nesting_level'  => 5,
                ])

            );
        }

        public function scopeFilterByUser($query, $userId)
        {
            if ($userId && $userId !== '0') {
                return $query->where('user_id', $userId);
            }

            return $query;
        }

        public function scopeFilterBySendingServer($query, $serverId)
        {
            if ($serverId && $serverId !== '0') {
                return $query->where('sending_server_id', $serverId);
            }

            return $query;
        }

        public function scopeFilterByDirection($query, $direction)
        {

            if (config('app.stage') == 'demo' && $direction == 0) {
                $direction = Reports::DIRECTION_OUTGOING;
            }

            if ($direction && $direction !== '0') {
                return $query->where('direction', $direction);
            }

            return $query;
        }

        public function scopeFilterByType($query, $type)
        {
            if ($type && $type !== '0') {
                return $query->where('sms_type', $type);
            }

            return $query;
        }

        public function scopeFilterByStatus($query, $status)
        {
            if ($status) {
                return $query->where('customer_status', 'LIKE', $status . '%');
            }

            return $query;
        }

        public function scopeFilterByAdminStatus($query, $status)
        {
            if ($status) {
                return $query->whereRaw('LOWER(status) LIKE ?', ['%' . strtolower($status) . '%']);
            }

            return $query;
        }


        public function scopeFilterByFrom($query, $from)
        {
            if ($from) {
                return $query->where('from', 'like', "%$from%");
            }

            return $query;
        }

        public function scopeFilterByTo($query, $to)
        {
            if ($to) {
                return $query->where('to', 'like', "%$to%");
            }

            return $query;
        }

        public function scopeFilterByDateRange($query, $startDate, $startTime, $endDate, $endTime)
        {
            if ($startDate && $endDate) {
                $start_date = Tool::systemTimeFromString($startDate . ' ' . $startTime, config('app.timezone'));
                $end_date   = Tool::systemTimeFromString($endDate . ' ' . $endTime, config('app.timezone'));

                return $query->whereBetween('created_at', [$start_date, $end_date]);
            }

            return $query;
        }

        public function scopeFilterByInputDateRange($query, $dateRange)
        {
            if ( ! empty($dateRange)) {
                $dates = array_map('trim', explode(' to ', $dateRange));

                if (count($dates) == 1) {
                    $startDate = date('Y-m-d', strtotime($dates[0]));

                    $query->whereDate('created_at', $startDate);
                } else {
                    $startDate = date('Y-m-d', strtotime($dates[0]));
                    $endDate   = date('Y-m-d', strtotime($dates[1]));

                    $query->whereBetween('created_at', [$startDate, $endDate]);
                }
            }

            return $query;
        }

        // In Reports.php (model)
        public function scopeFilterByMessageId($query, $messageId)
        {
            if ($messageId) {
                return $query->where('uid', 'like', "%{$messageId}%");
            }

            return $query;
        }

    }

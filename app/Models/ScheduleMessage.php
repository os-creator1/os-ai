<?php

    namespace App\Models;

    use App\Library\Traits\HasUid;
    use Carbon\Carbon;
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\Eloquent\Relations\BelongsTo;

    /**
     * @method static where(string $string, string $uid)
     * @method static create(array $array)
     * @method static whereBetween(string $string, array $array)
     */
    class ScheduleMessage extends Model
    {
        use HasUid;

        protected $fillable = [
            'user_id',
            'from',
            'to',
            'message',
            'media_url',
            'sms_type',
            'direction',
            'cost',
            'api_key',
            'status',
            'language',
            'gender',
            'schedule_on',
            'sending_server',
            'dlt_template_id',
            'sms_count',
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


        public function scopeCurrentMonth($query)
        {
            return $query->where('created_at', ">=", Carbon::now()->firstOfMonth());
        }

    }

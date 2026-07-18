<?php

    namespace App\Models;

    use App\Library\Traits\HasUid;
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\Eloquent\Relations\BelongsTo;

    /**
     * @method static create(array $array)
     * @method static whereLike(string[] $array, mixed $search)
     * @method static offset(mixed $start)
     * @method static count()
     * @method static where(string $string, mixed $id)
     * @method static insert(array[] $plan_coverage)
     */
    class PlansCoverageCountries extends Model
    {
        use HasUid;

        protected $fillable = [
            'country_id',
            'plan_id',
            'options',
            'status',
            'sending_server',
            'voice_sending_server',
            'mms_sending_server',
            'whatsapp_sending_server',
            'viber_sending_server',
            'otp_sending_server',
        ];

        protected $casts = [
            'status' => 'boolean',
        ];


        /**
         * Country
         *
         * @return BelongsTo
         */

        public function country(): BelongsTo
        {
            return $this->belongsTo(Country::class);
        }

        /**
         * Country
         *
         * @return BelongsTo
         */

        public function plan(): BelongsTo
        {
            return $this->belongsTo(Plan::class);
        }

        /**
         * sending_server
         *
         * @return BelongsTo
         */

        public function sendingServer(): BelongsTo
        {
            return $this->belongsTo(SendingServer::class, 'sending_server', 'id');
        }

        /**
         * sending_server
         *
         * @return BelongsTo
         */

        public function voiceSendingServer(): BelongsTo
        {
            return $this->belongsTo(SendingServer::class, 'voice_sending_server', 'id');
        }

        /**
         * sending_server
         *
         * @return BelongsTo
         */

        public function mmsSendingServer(): BelongsTo
        {
            return $this->belongsTo(SendingServer::class, 'mms_sending_server', 'id');
        }

        /**
         * sending_server
         *
         * @return BelongsTo
         */

        public function whatsappSendingServer(): BelongsTo
        {
            return $this->belongsTo(SendingServer::class, 'whatsapp_sending_server', 'id');
        }

        /**
         * sending_server
         *
         * @return BelongsTo
         */

        public function viberSendingServer(): BelongsTo
        {
            return $this->belongsTo(SendingServer::class, 'viber_sending_server', 'id');
        }

        /**
         * sending_server
         *
         * @return BelongsTo
         */

        public function otpSendingServer(): BelongsTo
        {
            return $this->belongsTo(SendingServer::class, 'otp_sending_server', 'id');
        }

    }

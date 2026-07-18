<?php

    namespace App\Models;

    use App\Library\Traits\HasUid;
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\Eloquent\Relations\BelongsTo;

    /**
     * @method static whereIn(string $string, mixed $countryList)
     * @method static insert(array|array[] $records)
     * @method static where(string $string, mixed $id)
     * @method static create(array $array)
     */
    class SendingServerBasedPricingPlans extends Model
    {


        use HasUid;

        protected $fillable = [
            'uid',
            'country_id',
            'sending_server',
            'status',
            'options',
        ];

        protected $casts = [
            'status' => 'boolean',
        ];

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
         * Country
         *
         * @return BelongsTo
         */

        public function country(): BelongsTo
        {
            return $this->belongsTo(Country::class);
        }

    }

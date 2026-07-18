<?php

namespace App\Models;

use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @method static where(string $string, mixed $id)
 * @method static create(array $params)
 * @method static whereIn(string $string, int[] $array)
 */
class TrackingLog extends Model
{
    use HasUid;

    protected $touches = ['automation'];

    protected $fillable = [
            'runtime_message_id',
            'message_id',
            'automation_id',
            'customer_id',
            'sending_server_id',
            'campaign_id',
            'contact_id',
            'contact_group_id',
            'status',
            'error',
            'from',
            'cost',
            'sms_count',
    ];


    /**
     * Associations
     *
     * @return BelongsTo
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     *
     *
     * @return BelongsTo
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaigns::class);
    }

    /**
     *
     *
     * @return BelongsTo
     */
    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }


    /**
     *
     *
     * @return BelongsTo
     */
    public function contactGroup(): BelongsTo
    {
        return $this->belongsTo(ContactGroups::class);
    }

    /**
     *
     *
     * @return BelongsTo
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contacts::class);
    }


    /**
     *
     *
     * @return BelongsTo
     */
    public function sendingServer(): BelongsTo
    {
        return $this->belongsTo(SendingServer::class);
    }
}

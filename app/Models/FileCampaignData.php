<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @method static where(string $string, mixed $id)
 */
class FileCampaignData extends Model
{

    protected $fillable = [
            'user_id',
            'sending_server_id',
            'campaign_id',
            'sender_id',
            'phone',
            'sms_count',
            'cost',
            'message',
    ];


    /**
     * Associations
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     *
     *
     * @return BelongsTo
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaigns::class, 'campaign_id');
    }


    /**
     *
     *
     * @return BelongsTo
     */
    public function sendingServer(): BelongsTo
    {
        return $this->belongsTo(SendingServer::class, 'sending_server_id');
    }
}

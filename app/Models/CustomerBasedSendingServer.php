<?php

namespace App\Models;

use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @method static where(string $string, $id)
 * @method static create(array $array)
 */
class CustomerBasedSendingServer extends Model
{
    use HasUid;

    protected $fillable = [
            'user_id',
            'business_id',
            'sending_server',
            'status',
    ];

    protected $casts = [
            'status' => 'boolean',
    ];


    /**
     * User
     *
     * @return BelongsTo
     */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Business Data Tenancy Foundation, Pass 1 — additive only.
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
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


}

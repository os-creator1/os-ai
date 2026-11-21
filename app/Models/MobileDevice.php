<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A phone that signed into MotionGrove Mobile. Holds the push token only; it is
 * never exposed back through the API and is hidden from serialization.
 */
class MobileDevice extends Model
{
    protected $fillable = [
        'uid', 'user_id', 'personal_access_token_id', 'installation_id', 'platform',
        'push_provider', 'push_token', 'push_token_hash', 'app_version', 'device_name', 'last_seen_at',
    ];

    protected $hidden = ['push_token', 'push_token_hash'];

    protected $casts = ['last_seen_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

<?php

namespace App\Models;

use App\Enums\PlatformOwner\AccountClosureState;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One Platform Owner request to delete a customer account, and its durable,
 * retryable lifecycle. Written only by PlatformAccountClosureManager.
 */
class PlatformAccountClosure extends Model
{
    use HasUid;

    protected $guarded = [];

    protected $casts = [
        'state' => AccountClosureState::class,
        'preflight' => 'array',
        'steps' => 'array',
        'pending_items' => 'array',
        'attempts' => 'integer',
        'claimed_at' => 'datetime',
        'last_attempt_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function generateUid(): void
    {
        $this->uid = (string) Str::uuid();
    }
}

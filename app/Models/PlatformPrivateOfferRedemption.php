<?php

namespace App\Models;

use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One customer's acceptance of a Private Offer, with the EXACT terms they
 * accepted frozen in `terms_snapshot`.
 *
 * Lifecycle: reserved -> redeemed, or reserved -> released. Only the redemption
 * manager moves it, always under a row lock; nothing is mass-assignable.
 */
class PlatformPrivateOfferRedemption extends Model
{
    use HasUid;

    public const STATE_RESERVED = 'reserved';
    public const STATE_REDEEMED = 'redeemed';
    public const STATE_RELEASED = 'released';

    public const SCHEDULE_NONE = 'none';
    public const SCHEDULE_PENDING = 'pending';
    public const SCHEDULE_APPLIED = 'applied';
    public const SCHEDULE_FAILED = 'failed';

    protected $table = 'platform_private_offer_redemptions';

    protected $guarded = ['*'];

    protected $casts = [
        'terms_snapshot' => 'array',
        'accepted_at' => 'datetime',
        'reserved_until' => 'datetime',
        'promo_ends_at' => 'datetime',
        'last_reconciled_at' => 'datetime',
        'redeemed_at' => 'datetime',
        'released_at' => 'datetime',
        'trial_granted' => 'boolean',
    ];

    public function generateUid(): void
    {
        $this->uid = (string) Str::uuid();
    }

    public function isActiveState(): bool
    {
        return in_array($this->state, [self::STATE_RESERVED, self::STATE_REDEEMED], true);
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(PlatformPrivateOffer::class, 'platform_private_offer_id');
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(PlatformSubscription::class, 'platform_subscription_id');
    }

    /** @return array<int, array<string, mixed>> */
    public function phases(): array
    {
        return (array) ($this->terms_snapshot['phases'] ?? []);
    }
}

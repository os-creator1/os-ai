<?php

namespace App\Models;

use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A Private Offer: special terms the Platform Owner extends to ONE customer.
 *
 * The link token is never stored — only its SHA-256 (`token_hash`) — so a
 * database read cannot reconstruct a working link. Everything that determines
 * the price lives here as the owner entered it; what a particular customer
 * actually ACCEPTED is frozen on their PlatformPrivateOfferRedemption, so
 * editing or revoking an offer can never rewrite an accepted agreement.
 */
class PlatformPrivateOffer extends Model
{
    use HasUid;

    public const KIND_NEW_SUBSCRIPTION = 'new_subscription';
    public const KIND_BILLING_CHANGE = 'billing_change';

    public const DISCOUNT_FIXED = 'fixed';
    public const DISCOUNT_PERCENT = 'percent';

    public const DURATION_FOREVER = 'forever';
    public const DURATION_ONCE = 'once';
    public const DURATION_PERIODS = 'periods';

    protected $table = 'platform_private_offers';

    /** Nothing about identity, status or the token is mass-assignable. */
    protected $guarded = ['*'];

    protected $casts = [
        'trial_days' => 'integer',
        'custom_price' => 'decimal:2',
        'discount_value' => 'decimal:2',
        'discount_periods' => 'integer',
        'redemption_limit' => 'integer',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'token_rotated_at' => 'datetime',
    ];

    /** @return array{0: string, 1: string} [plain token, sha256 of it] */
    public static function mintToken(): array
    {
        // 256 bits from the CSPRNG, URL-safe. Unguessable by construction.
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return [$token, self::hashToken($token)];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function generateUid(): void
    {
        $this->uid = (string) Str::uuid();
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->lessThanOrEqualTo(now());
    }

    public function isBillingChange(): bool
    {
        return $this->kind === self::KIND_BILLING_CHANGE;
    }

    /** active | expired | revoked — revocation outranks expiry. */
    public function status(): string
    {
        return $this->isRevoked() ? 'revoked' : ($this->isExpired() ? 'expired' : 'active');
    }

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(WorkspacePlanCatalog::class, 'workspace_plan_catalog_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'target_workspace_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(PlatformPrivateOfferRedemption::class, 'platform_private_offer_id');
    }
}

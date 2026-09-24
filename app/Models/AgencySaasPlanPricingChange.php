<?php

namespace App\Models;

use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Lane C §C3.3 — the audit of what an Agency published, when, and why.
 *
 * It exists so that "we changed the price" is answerable months later, and so
 * that the answer is visibly SEPARATE from what any existing subscriber is
 * actually charged: a subscriber's terms live on their own subscription row's
 * snapshot, and nothing in this table can reach them.
 */
class AgencySaasPlanPricingChange extends Model
{
    use HasUid;

    protected $fillable = [
        'agency_saas_plan_id',
        'changed_by_user_id',
        'from_price',
        'to_price',
        'from_currency_code',
        'to_currency_code',
        'from_billing_cycle',
        'to_billing_cycle',
        'from_provider_price_id',
        'to_provider_price_id',
        'reason',
    ];

    public function generateUid(): void
    {
        $this->uid = (string) Str::uuid();
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(AgencySaasPlan::class, 'agency_saas_plan_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}

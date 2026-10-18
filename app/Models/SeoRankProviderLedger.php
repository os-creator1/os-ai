<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SEO Keyword Rank Tracking V1 — internal PLATFORM provider-cost ledger row
 * (not customer-billable usage). Written only by SeoRankTrackingBudget.
 * Amounts are integer micro-USD.
 *
 * @property int $id
 * @property int $business_id
 * @property int|null $workspace_id
 * @property string $provider
 * @property string $operation
 * @property int $seo_rank_check_run_id
 * @property string $usage_month
 * @property int $reserved_micros
 * @property int|null $actual_micros
 * @property string $status
 */
class SeoRankProviderLedger extends Model
{
    public const RESERVED = 'reserved';
    public const COMMITTED = 'committed';
    public const RELEASED = 'released';
    public const HELD = 'held';

    protected $table = 'seo_rank_provider_ledger';

    protected $guarded = ['*'];

    protected $casts = [
        'usage_day' => 'date',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(SeoRankCheckRun::class, 'seo_rank_check_run_id');
    }

    /** What this row counts against every cap: actual once known, else the reservation. */
    public function effectiveMicros(): int
    {
        return $this->status === self::RELEASED ? 0 : (int) ($this->actual_micros ?? $this->reserved_micros);
    }
}

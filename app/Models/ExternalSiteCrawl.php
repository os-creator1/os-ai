<?php

namespace App\Models;

use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * External Website Audit Mode V1 — one crawl of a Business's own external
 * website and the summary of the audit run over it. Written only by
 * ExternalSiteAuditRunner; `start_url` is the address AS CRAWLED (the Business's
 * `website_url` at that moment), so a later URL change never rewrites history.
 *
 * @property int $id
 * @property string $uid
 * @property int $business_id
 * @property string $start_url
 * @property string $host
 * @property string $status queued|running|completed|failed
 * @property string $trigger manual|scheduled|url_change
 * @property ?string $failure_code
 * @property ?string $indexability
 */
class ExternalSiteCrawl extends Model
{
    use HasUid;

    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    protected $guarded = ['id', 'uid'];

    protected $casts = [
        'rule_set_version' => 'integer',
        'pages_discovered' => 'integer',
        'pages_fetched' => 'integer',
        'broken_links' => 'integer',
        'critical_count' => 'integer',
        'warning_count' => 'integer',
        'info_count' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function generateUid(): void
    {
        $this->uid = (string) Str::uuid();
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function pages(): HasMany
    {
        return $this->hasMany(ExternalSitePage::class, 'crawl_id');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(ExternalSiteFinding::class, 'crawl_id');
    }

    public function isCompleted(): bool
    {
        return $this->status === self::COMPLETED;
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::QUEUED, self::RUNNING], true);
    }

    public function totalFindings(): int
    {
        return $this->critical_count + $this->warning_count + $this->info_count;
    }
}

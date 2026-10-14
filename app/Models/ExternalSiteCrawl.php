<?php

namespace App\Models;

use App\Library\ExternalSite\Audit\ExternalAuditCatalog;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * External Website Audit — one crawl of a Business's own external website and the
 * summary of the audit run over it. Written only by ExternalSiteAuditRunner;
 * `start_url` is the address AS CRAWLED (the Business's `website_url` at that
 * moment), so a later URL change never rewrites history.
 *
 * Lifecycle:  queued -> running -> completed | partial | failed   (or cancelled)
 *   completed  everything eligible within the budget was audited
 *   partial    useful results, but coverage is incomplete (budget or time limit,
 *              pages a firewall refused, pages that timed out): the reason is in
 *              `finish_reason` and `coverage`
 *   failed     nothing useful was collected: `failure_code` and `connection_state`
 *              say why (and `failure_code` is NOT necessarily the website's fault)
 *
 * `active_lock` holds the business id only while queued/running, under a UNIQUE
 * index: the database refuses a second concurrent crawl for the same Business.
 *
 * @property int $id
 * @property string $uid
 * @property int $business_id
 * @property string $start_url
 * @property string $host
 * @property ?string $final_url
 * @property string $status queued|running|completed|partial|failed|cancelled
 * @property ?string $stage
 * @property string $trigger manual|scheduled|url_change
 * @property ?string $failure_code
 * @property ?string $finish_reason
 * @property ?string $indexability
 * @property ?string $connection_state
 * @property ?array $coverage
 * @property ?array $summary
 */
class ExternalSiteCrawl extends Model
{
    use HasUid;

    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const COMPLETED = 'completed';

    public const PARTIAL = 'partial';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    protected $guarded = ['id', 'uid'];

    protected $casts = [
        'rule_set_version' => 'integer',
        'page_budget' => 'integer',
        'pages_discovered' => 'integer',
        'pages_fetched' => 'integer',
        'pages_audited' => 'integer',
        'progress_discovered' => 'integer',
        'progress_checked' => 'integer',
        'broken_links' => 'integer',
        'critical_count' => 'integer',
        'warning_count' => 'integer',
        'info_count' => 'integer',
        'connection_http_status' => 'integer',
        'coverage' => 'array',
        'summary' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'heartbeat_at' => 'datetime',
        'details_pruned_at' => 'datetime',
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

    public function links(): HasMany
    {
        return $this->hasMany(ExternalSiteLink::class, 'crawl_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::QUEUED, self::RUNNING], true);
    }

    /** Finished with results of any completeness. */
    public function hasResults(): bool
    {
        return in_array($this->status, [self::COMPLETED, self::PARTIAL], true);
    }

    /** Alias kept for existing callers: a completed crawl. */
    public function isCompleted(): bool
    {
        return $this->status === self::COMPLETED;
    }

    /** True when the full evidence-based report (rule set 2) exists for this crawl. */
    public function isReport(): bool
    {
        return $this->hasResults() && (int) $this->rule_set_version >= ExternalAuditCatalog::VERSION && $this->details_pruned_at === null;
    }

    /** A crawl stored by the first version of the audit: counts exist, the detailed evidence does not. */
    public function isEarlierFormat(): bool
    {
        return $this->hasResults() && (int) $this->rule_set_version < ExternalAuditCatalog::VERSION;
    }

    public function totalFindings(): int
    {
        return $this->critical_count + $this->warning_count + $this->info_count;
    }

    /** @return array<string, int> the distinct coverage counts (all zero for an earlier-format crawl) */
    public function coverageCounts(): array
    {
        return array_merge(
            ['discovered' => 0, 'audited' => 0, 'redirected' => 0, 'errors' => 0, 'blocked' => 0, 'unreachable' => 0, 'robots_disallowed' => 0, 'excluded' => 0, 'skipped' => 0, 'eligible_html' => 0, 'omitted' => 0],
            array_intersect_key((array) $this->coverage, array_flip(['discovered', 'audited', 'redirected', 'errors', 'blocked', 'unreachable', 'robots_disallowed', 'excluded', 'skipped', 'eligible_html', 'omitted'])),
        );
    }
}

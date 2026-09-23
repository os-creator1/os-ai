<?php

namespace App\Models;

use App\Enums\Seo\SeoAuditRunStatus;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Contract 18 §8.7 — an immutable technical-audit run over ONE published
 * Website revision.
 *
 * `UPDATED_AT = null` mirrors `website_revisions`: the row is written once,
 * complete, and never edited. Re-auditing the same revision is prevented by
 * the table's `unique(website_revision_id, rule_set_version)`, not by an
 * update.
 */
class SeoAuditRun extends Model
{
    use HasUid;

    const UPDATED_AT = null;

    protected $fillable = [
        'uid',
        'business_id',
        'website_id',
        'website_revision_id',
        'rule_set_version',
        'status',
        'page_count',
        'critical_count',
        'warning_count',
        'info_count',
    ];

    protected $casts = [
        'status' => SeoAuditRunStatus::class,
        'rule_set_version' => 'integer',
        'page_count' => 'integer',
        'critical_count' => 'integer',
        'warning_count' => 'integer',
        'info_count' => 'integer',
    ];

    public function generateUid()
    {
        $this->uid = (string) Str::uuid();
    }

    public function findings(): HasMany
    {
        return $this->hasMany(SeoAuditFinding::class, 'seo_audit_run_id');
    }

    public function totalFindings(): int
    {
        return $this->critical_count + $this->warning_count + $this->info_count;
    }
}

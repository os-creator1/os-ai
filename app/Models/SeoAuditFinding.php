<?php

namespace App\Models;

use App\Enums\Seo\SeoAuditSeverity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Contract 18 §8.7 — one deterministic finding from one closed registry rule.
 *
 * Carries NO user-visible prose: only a `rule_key`, a `severity` the registry
 * owns, an optional snapshot `page_uid` and validated scalar `facts`. The
 * sentence a customer reads is composed at render time by
 * SeoAuditRuleRegistry from its own template plus those facts, which is what
 * makes free text structurally impossible.
 */
class SeoAuditFinding extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'seo_audit_run_id',
        'page_uid',
        'rule_key',
        'severity',
        'facts',
    ];

    protected $casts = [
        'severity' => SeoAuditSeverity::class,
        'facts' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(SeoAuditRun::class, 'seo_audit_run_id');
    }
}

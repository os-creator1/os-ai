<?php

namespace App\Models;

use App\Enums\Seo\SeoAuditSeverity;
use App\Library\Seo\SeoAuditRuleRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * External Website Audit Mode V1 — one audit finding over a crawled page: a
 * registry rule key, its severity and at most eight scalar facts. Exactly the
 * shape of a hosted `seo_audit_findings` row — the words come from the one
 * SeoAuditRuleRegistry, so there is nowhere to store a sentence.
 */
class ExternalSiteFinding extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'facts' => 'array',
        'created_at' => 'datetime',
    ];

    public function page(): BelongsTo
    {
        return $this->belongsTo(ExternalSitePage::class, 'page_id');
    }

    public function severity(): SeoAuditSeverity
    {
        return SeoAuditSeverity::from((string) $this->severity);
    }

    public function title(): string
    {
        return SeoAuditRuleRegistry::titleFor((string) $this->rule_key);
    }

    public function description(): string
    {
        return SeoAuditRuleRegistry::describe((string) $this->rule_key, is_array($this->facts) ? $this->facts : []);
    }
}

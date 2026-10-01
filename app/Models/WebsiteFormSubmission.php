<?php

namespace App\Models;

use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One real visitor inquiry, owned by WebsiteFormSubmissionService — no
 * other code path writes this table. Tenancy is derived by joining
 * through website_form_id -> website_forms.website_id ->
 * websites.business_id.
 */
class WebsiteFormSubmission extends Model
{
    use HasUid;

    public const STATUS_NEW = 'new';

    public const STATUS_READ = 'read';

    protected $table = 'website_form_submissions';

    protected $fillable = [
        'uid',
        'website_form_id',
        'location_id',
        'contact_id',
        'crm_opportunity_id',
        'contact_resolution',
        'page_slug',
        'page_uid',
        'source_revision_id',
        'data',
        'idempotency_key',
        'ip_hash',
        'is_spam',
        'status',
    ];

    protected $casts = [
        'data' => 'array',
        'is_spam' => 'boolean',
    ];

    public function generateUid(): void
    {
        $this->uid = (string) Str::uuid();
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(WebsiteForm::class, 'website_form_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(BusinessLocation::class, 'location_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contacts::class, 'contact_id');
    }

    public function crmOpportunity(): BelongsTo
    {
        return $this->belongsTo(CrmOpportunity::class, 'crm_opportunity_id');
    }
}

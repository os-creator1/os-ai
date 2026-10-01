<?php

namespace App\Models;

use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Tenancy is derived by joining through website_id -> websites.business_id
 * (business_id is deliberately not duplicated here), matching WebsitePage
 * and WebsiteAsset. `fields` is a plain, code-validated JSON config —
 * WebsiteFormFieldType is the only source of truth for what a field's
 * `type` may be.
 */
class WebsiteForm extends Model
{
    use HasUid;

    public const TYPE_QUOTE_REQUEST = 'quote_request';

    protected $table = 'website_forms';

    protected $fillable = [
        'uid',
        'website_id',
        'type',
        'name',
        'fields',
        'submit_label',
        'location_id',
        'is_active',
        'create_opportunity',
        'crm_pipeline_id',
    ];

    protected $casts = [
        'fields' => 'array',
        'is_active' => 'boolean',
        'create_opportunity' => 'boolean',
    ];

    public function generateUid(): void
    {
        $this->uid = (string) Str::uuid();
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    /** The Location every submission through this form is bound to (null until the owner configures one). */
    public function location(): BelongsTo
    {
        return $this->belongsTo(BusinessLocation::class, 'location_id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(WebsiteFormSubmission::class);
    }
}

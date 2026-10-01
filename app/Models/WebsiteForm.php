<?php

namespace App\Models;

use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Website Builder redesign: `business_id` makes this a real, reusable
 * canonical Business-owned resource (Website Studio's Forms tab, and any
 * future Automations reference), backfilled from the owning Website.
 * `website_id` stays required and unchanged, so every pre-existing
 * form-binding code path (MediaBindingService::bindForms(),
 * WebsiteSectionValidator's Website-scoped $validFormUids,
 * WebsiteStarterDraftService::ensurePhotoBoothQuoteForm()) is unaffected
 * — a `form` section's `form_uid` still resolves exactly as before.
 *
 * `fields` is a plain, code-validated JSON config — WebsiteFormFieldType
 * is the only source of truth for what a field's `type` may be.
 */
class WebsiteForm extends Model
{
    use HasUid;

    public const TYPE_QUOTE_REQUEST = 'quote_request';

    protected $table = 'website_forms';

    protected $fillable = [
        'uid',
        'website_id',
        'business_id',
        'type',
        'name',
        'fields',
        'submit_label',
    ];

    protected $casts = [
        'fields' => 'array',
    ];

    public function generateUid(): void
    {
        $this->uid = (string) Str::uuid();
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(WebsiteFormSubmission::class);
    }
}

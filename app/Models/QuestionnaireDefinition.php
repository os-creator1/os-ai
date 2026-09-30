<?php

namespace App\Models;

use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Website Builder redesign — Niche Builder foundation. The stable parent
 * identity a questionnaire's versions hang off, exactly like
 * `AutomationWorkflow` is to `AutomationWorkflowVersion`. `key` is the
 * durable identity a caller resolves by (e.g.
 * 'photobooth_website_setup'), never the numeric id.
 *
 * `business_id` is null for a platform-seeded niche questionnaire (the
 * only kind this pass ships); a future business-authored questionnaire
 * (out of scope this pass) would set it.
 */
class QuestionnaireDefinition extends Model
{
    use HasUid;

    protected $fillable = [
        'key',
        'scope',
        'niche_key',
        'business_id',
        'name',
        'created_by_user_id',
    ];

    public function generateUid(): void
    {
        $this->uid = (string) Str::uuid();
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(QuestionnaireVersion::class);
    }

    public function responses(): HasMany
    {
        return $this->hasMany(QuestionnaireResponse::class);
    }

    public function draftVersion(): ?QuestionnaireVersion
    {
        return $this->versions()->where('state', 'draft')->first();
    }

    public function publishedVersion(): ?QuestionnaireVersion
    {
        return $this->versions()->where('state', 'published')->first();
    }
}

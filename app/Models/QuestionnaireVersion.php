<?php

namespace App\Models;

use App\Enums\Questionnaire\QuestionnaireVersionState;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Website Builder redesign — Niche Builder foundation. Mirrors
 * AutomationWorkflowVersion: `definition` is the ordered step/question
 * tree a published version freezes forever. THE WIZARD NEVER READS
 * ANYTHING ELSE for a published version — this document IS the
 * questionnaire.
 *
 * Once `state` leaves draft, `definition` must never be written again;
 * nothing in this codebase updates a published/superseded version's
 * definition in place — a platform edit always creates a new draft
 * version instead (QuestionnaireVersionPublisher).
 */
class QuestionnaireVersion extends Model
{
    use HasUid;

    protected $fillable = [
        'questionnaire_definition_id',
        'version_number',
        'state',
        'definition',
        'definition_hash',
        'published_at',
        'published_by_user_id',
    ];

    protected $casts = [
        'state' => QuestionnaireVersionState::class,
        'definition' => 'array',
        'published_at' => 'datetime',
    ];

    public function generateUid(): void
    {
        $this->uid = (string) Str::uuid();
    }

    /** Named to avoid colliding with the `definition` JSON column/cast. */
    public function questionnaireDefinition(): BelongsTo
    {
        return $this->belongsTo(QuestionnaireDefinition::class, 'questionnaire_definition_id');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(QuestionnaireResponse::class);
    }

    public function isDraft(): bool
    {
        return $this->state === QuestionnaireVersionState::Draft;
    }

    public function isImmutable(): bool
    {
        return $this->state->isImmutable();
    }

    /**
     * @return array<int, array<string, mixed>> the ordered step/question tree
     */
    public function steps(): array
    {
        return $this->definition['steps'] ?? [];
    }
}

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
        return array_map(function (array $step) {
            // A step's niche `categories` vocabulary is STORED as an ordered
            // list of {value, label} (a JSON object's key order is not
            // preserved by the database, a list's is) and handed to every
            // consumer as the familiar value => label map, in that order.
            if (isset($step['categories']) && is_array($step['categories']) && array_is_list($step['categories'])) {
                $map = [];
                foreach ($step['categories'] as $category) {
                    if (is_array($category) && isset($category['value'], $category['label'])) {
                        $map[(string) $category['value']] = (string) $category['label'];
                    }
                }
                $step['categories'] = $map;
            }

            return $step;
        }, $this->definition['steps'] ?? []);
    }

    /**
     * The step tree exactly as stored (no read-time shaping) — what the
     * definition validator and "is this the same tree?" checks compare.
     *
     * @return array<int, array<string, mixed>>
     */
    public function storedSteps(): array
    {
        return $this->definition['steps'] ?? [];
    }
}

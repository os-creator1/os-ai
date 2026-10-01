<?php

namespace App\Models;

use App\Enums\Questionnaire\QuestionnaireResponseStatus;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Website Builder redesign — one Business's attempt at answering one
 * questionnaire, through one PINNED version. Mirrors AutomationEnrollment.
 *
 * THE PIN. `questionnaire_version_id` is set once when the session starts
 * (WebsiteSetupSessionManager::start()) and never reassigned. Because a
 * published version is immutable, this response keeps reading/writing
 * against the exact question tree it began with, even after the platform
 * publishes a newer version underneath it — enforced by the composite
 * foreign key against `questionnaire_versions (id,
 * questionnaire_definition_id)`.
 *
 * THE AUTOSAVE STATE. `answers` (a single JSON document, matching
 * `QuestionnaireVersion.definition`'s own document-per-row shape) +
 * `current_step_key` + `answers_revision` (an optimistic-concurrency
 * counter: a stale revision loses the write rather than clobbering
 * another tab).
 *
 * THE CLAIM. `active_definition_guard` forbids the same Business having
 * two `in_progress` responses for the same questionnaire definition at
 * once — resume, never duplicate.
 */
class QuestionnaireResponse extends Model
{
    use HasUid;

    protected $fillable = [
        'business_id',
        'website_id',
        'questionnaire_definition_id',
        'questionnaire_version_id',
        'status',
        'edit_mode',
        'generation_started_at',
        'custom_section_improve_key',
        'custom_section_improve_ledger_key',
        'custom_section_improve_attempt_ordinal',
        'custom_section_improve_status',
        'custom_section_improve_started_revision',
        'custom_section_improve_pending_started_at',
        'custom_section_improve_result',
        'current_step_key',
        'answers',
        'answers_revision',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'status' => QuestionnaireResponseStatus::class,
        'edit_mode' => 'boolean',
        'answers' => 'array',
        'answers_revision' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'generation_started_at' => 'datetime',
        'custom_section_improve_started_revision' => 'integer',
        'custom_section_improve_attempt_ordinal' => 'integer',
        'custom_section_improve_pending_started_at' => 'datetime',
        'custom_section_improve_result' => 'array',
    ];

    public const IMPROVE_STATUS_PENDING = 'pending';

    public const IMPROVE_STATUS_SUCCEEDED = 'succeeded';

    public const IMPROVE_STATUS_FAILED = 'failed';

    /**
     * Independent-review correction round 4 (item 1) — a Website-level
     * concern now (WebsiteGenerationCoordinator's own lease), never this
     * response's own flag. `generation_started_at` remains a written, but
     * purely informational, mirror.
     */
    public function isGenerating(): bool
    {
        if ($this->website_id === null) {
            return false;
        }

        return Website::where('id', $this->website_id)->whereNotNull('generation_lease_token')->exists();
    }

    public function generateUid(): void
    {
        $this->uid = (string) Str::uuid();
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function questionnaireDefinition(): BelongsTo
    {
        return $this->belongsTo(QuestionnaireDefinition::class, 'questionnaire_definition_id');
    }

    /** The pinned version. Never reassigned after creation. */
    public function version(): BelongsTo
    {
        return $this->belongsTo(QuestionnaireVersion::class, 'questionnaire_version_id');
    }

    public function isInProgress(): bool
    {
        return $this->status === QuestionnaireResponseStatus::InProgress;
    }

    public function isCompleted(): bool
    {
        return $this->status === QuestionnaireResponseStatus::Completed;
    }

    public function answer(string $questionKey): mixed
    {
        return ($this->answers ?? [])[$questionKey] ?? null;
    }
}

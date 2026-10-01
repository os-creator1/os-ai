<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Forms V1 — the in-progress state of one multi-page questionnaire.
 *
 * Mutable working state, NOT history: it holds only answers the server already
 * validated against the pinned `FormVersion`, keyed by field key, and it creates
 * no Contact, Opportunity, event or submission. Written only by
 * `FormSessionStore`. Once the final submit commits it is stamped with the
 * submission and accepts nothing more.
 *
 * Abandoned sessions are prunable: `php artisan model:prune --model=App\\Models\\FormSession`
 * removes those a week past expiry (a session is never honoured after
 * `expires_at`; the grace only keeps support lookups possible). Scheduling that
 * command is an operations decision, not part of this slice.
 *
 * @property int $id
 * @property int $form_deployment_id
 * @property int $form_version_id
 * @property string $operation_nonce
 * @property array<string, mixed> $answers
 * @property list<string> $completed_pages
 * @property \Illuminate\Support\Carbon $expires_at
 * @property ?int $form_submission_id
 * @property ?\Illuminate\Support\Carbon $finalized_at
 */
class FormSession extends Model
{
    use MassPrunable;

    /** Never mass-assigned from a request; the store sets every column explicitly. */
    protected $guarded = [];

    protected $casts = [
        'answers' => 'array',
        'completed_pages' => 'array',
        'expires_at' => 'datetime',
        'finalized_at' => 'datetime',
    ];

    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<', now()->subDays(7));
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(FormDeployment::class, 'form_deployment_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(FormVersion::class, 'form_version_id');
    }

    public function isFinalized(): bool
    {
        return $this->finalized_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}

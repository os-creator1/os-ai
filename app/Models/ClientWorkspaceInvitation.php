<?php

namespace App\Models;

use App\Enums\Workspace\ClientInvitationStatus;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Implementation Contract 07 §5 — the durable pre-consent record for
 * Agency-initiated client provisioning. See the migration's own docblock
 * for the full column rationale.
 *
 * Never carries a plaintext token: `token_hash` is the only token-shaped
 * column, and it is opaque at rest (Hash::make()'d), exactly like
 * password_resets.
 */
class ClientWorkspaceInvitation extends Model
{
    use HasUid;

    protected $table = 'client_workspace_invitations';

    protected $fillable = [
        'agency_workspace_id',
        'invited_by_user_id',
        'email',
        'token_hash',
        'intended_business_name',
        'status',
        'expires_at',
        'accepted_at',
        'created_client_workspace_id',
    ];

    protected $casts = [
        'status' => ClientInvitationStatus::class,
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    /**
     * A real UUID, matching every other recent entity's HasUid override
     * (e.g. CrmOpportunity::generateUid()) rather than HasUid's own
     * uniqid()-based default.
     */
    public function generateUid(): void
    {
        $this->uid = (string) Str::uuid();
    }

    public function agencyWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'agency_workspace_id');
    }

    public function createdClientWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'created_client_workspace_id');
    }

    public function isPending(): bool
    {
        return $this->status === ClientInvitationStatus::Pending;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}

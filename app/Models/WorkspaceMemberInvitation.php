<?php

namespace App\Models;

use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMemberInvitationStatus;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A pending, expiring, single-use invitation to join a Workspace as Admin or
 * Staff. Never carries a plaintext token: `token_hash` is opaque at rest.
 */
class WorkspaceMemberInvitation extends Model
{
    use HasUid;

    protected $table = 'workspace_member_invitations';

    protected $fillable = [
        'workspace_id',
        'invited_by_user_id',
        'email',
        'role',
        'business_access_scope',
        'business_ids',
        'token_hash',
        'status',
        'pending_key',
        'expires_at',
        'last_sent_at',
        'accepted_at',
        'accepted_user_id',
        'revoked_at',
    ];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'role' => WorkspaceMembershipRole::class,
        'business_access_scope' => WorkspaceBusinessAccessScope::class,
        'status' => WorkspaceMemberInvitationStatus::class,
        'business_ids' => 'array',
        'expires_at' => 'datetime',
        'last_sent_at' => 'datetime',
        'accepted_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function generateUid(): void
    {
        $this->uid = (string) Str::uuid();
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function isPending(): bool
    {
        return $this->status === WorkspaceMemberInvitationStatus::Pending;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** What the Team page shows: a lapsed pending invitation reads "Expired" without any write on view. */
    public function displayStatus(): WorkspaceMemberInvitationStatus
    {
        return $this->isPending() && $this->isExpired() ? WorkspaceMemberInvitationStatus::Expired : $this->status;
    }
}

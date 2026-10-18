<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only trail of what happened to an assistant proposal. Never updated. */
class AssistantActionAudit extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'assistant_action_audits';

    protected $fillable = ['proposal_id', 'event', 'actor_user_id', 'detail', 'created_at'];

    protected $casts = ['proposal_id' => 'integer', 'actor_user_id' => 'integer', 'detail' => 'array', 'created_at' => 'datetime'];
}

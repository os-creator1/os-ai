<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One prepared action awaiting (or past) the customer's explicit approval. */
class AssistantActionProposal extends Model
{
    public const PENDING = 'pending';

    public const EXECUTING = 'executing';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const DECLINED = 'declined';

    public const EXPIRED = 'expired';

    /** Handed to the module's own endpoint (e.g. Follow-ups), where the customer performs the consequential step. */
    public const HANDED_OFF = 'handed_off';

    /** Prepared, never to be run: the server found the situation had changed. */
    public const STALE = 'stale';

    protected $table = 'assistant_action_proposals';

    protected $fillable = [
        'uid', 'conversation_id', 'workspace_id', 'business_id', 'user_id', 'tool_key', 'params', 'preview',
        'params_hash', 'state_fingerprint', 'status', 'expires_at', 'decided_at', 'executed_at', 'attempts',
        'idempotency_key', 'result', 'failure_code', 'lease_token', 'lease_expires_at', 'fence', 'scrubbed_at',
    ];

    protected $casts = [
        'conversation_id' => 'integer',
        'workspace_id' => 'integer',
        'business_id' => 'integer',
        'user_id' => 'integer',
        'params' => 'array',
        'preview' => 'array',
        'result' => 'array',
        'attempts' => 'integer',
        'expires_at' => 'datetime',
        'decided_at' => 'datetime',
        'executed_at' => 'datetime',
        'lease_expires_at' => 'datetime',
        'scrubbed_at' => 'datetime',
        'fence' => 'integer',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AssistantConversation::class, 'conversation_id');
    }

    public function audits(): HasMany
    {
        return $this->hasMany(AssistantActionAudit::class, 'proposal_id');
    }

    public function isFinal(): bool
    {
        return in_array($this->status, [self::COMPLETED, self::DECLINED, self::EXPIRED, self::STALE, self::HANDED_OFF], true);
    }
}

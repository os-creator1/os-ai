<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Contract §10.2 — the lock-and-counter row `AiGateway` reads with
 * `lockForUpdate()` inside the reservation transaction. Never written to
 * directly outside `App\Library\Ai\AiUsageLedgerManager`.
 */
class AiUsagePeriod extends Model
{
    protected $table = 'ai_usage_periods';

    public const SCOPE_WORKSPACE = 'workspace';

    public const SCOPE_BUSINESS = 'business';

    protected $fillable = [
        'scope_type',
        'scope_id',
        'workspace_id',
        'period_key',
        'policy_key',
        'policy_version',
        'cap_microusd',
        'reserved_microusd',
        'committed_microusd',
        'interactive_reserved_microusd',
        'interactive_committed_microusd',
    ];

    protected $casts = [
        'scope_id' => 'integer',
        'workspace_id' => 'integer',
        'policy_version' => 'integer',
        'cap_microusd' => 'integer',
        'reserved_microusd' => 'integer',
        'committed_microusd' => 'integer',
        'interactive_reserved_microusd' => 'integer',
        'interactive_committed_microusd' => 'integer',
    ];
}

<?php

namespace App\Models;

use App\Enums\Automation\Workflow\WorkflowEdgeKind;
use App\Enums\Automation\Workflow\WorkflowNodeType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Automations V2 §4.3 — one compiled step.
 *
 * Written once by the publisher and never updated, which is why
 * `$timestamps = false` and the table has only `created_at`. An immutable row
 * has no update time, and the missing column makes an accidental update on a
 * live version fail rather than silently rewrite a running journey.
 */
class AutomationWorkflowNode extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'version_id',
        'business_id',
        'node_key',
        'node_type',
        'config',
        'depth',
        'created_at',
    ];

    protected $casts = [
        'node_type' => WorkflowNodeType::class,
        'config' => 'array',
        'depth' => 'integer',
        'created_at' => 'datetime',
    ];

    public function version(): BelongsTo
    {
        return $this->belongsTo(AutomationWorkflowVersion::class, 'version_id');
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** At most one per edge kind, DB-enforced. */
    public function outgoingEdges(): HasMany
    {
        return $this->hasMany(AutomationWorkflowEdge::class, 'from_node_id');
    }

    /** At most one, ever: `UNIQUE(to_node_id)` is what forbids a merge. */
    public function incomingEdge(): HasMany
    {
        return $this->hasMany(AutomationWorkflowEdge::class, 'to_node_id');
    }

    public function stepRuns(): HasMany
    {
        return $this->hasMany(AutomationStepRun::class, 'node_id');
    }

    /**
     * The successor for a given edge kind, or null when this path ends here.
     * The runtime resolves its next step only through this relational lookup —
     * never by re-parsing the definition document.
     */
    public function successor(WorkflowEdgeKind $kind): ?self
    {
        $edge = $this->outgoingEdges()->where('edge_kind', $kind->value)->first();

        return $edge?->toNode;
    }

    public function isRoot(): bool
    {
        return $this->node_type === WorkflowNodeType::Trigger;
    }
}

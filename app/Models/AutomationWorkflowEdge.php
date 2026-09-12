<?php

namespace App\Models;

use App\Enums\Automation\Workflow\WorkflowEdgeKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Automations V2 §4.4 — one compiled connection between two steps.
 *
 * Written once by the publisher and never updated. Two unique indexes on this
 * table are what make "a workflow is a tree" a database fact:
 * `UNIQUE(from_node_id, edge_kind)` gives each step at most one successor per
 * kind, and `UNIQUE(to_node_id)` gives every step at most one parent — so two
 * lanes can never rejoin and no cycle can be written.
 */
class AutomationWorkflowEdge extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'version_id',
        'business_id',
        'from_node_id',
        'to_node_id',
        'edge_kind',
        'created_at',
    ];

    protected $casts = [
        'edge_kind' => WorkflowEdgeKind::class,
        'created_at' => 'datetime',
    ];

    public function version(): BelongsTo
    {
        return $this->belongsTo(AutomationWorkflowVersion::class, 'version_id');
    }

    public function fromNode(): BelongsTo
    {
        return $this->belongsTo(AutomationWorkflowNode::class, 'from_node_id');
    }

    public function toNode(): BelongsTo
    {
        return $this->belongsTo(AutomationWorkflowNode::class, 'to_node_id');
    }
}

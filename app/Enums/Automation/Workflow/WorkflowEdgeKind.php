<?php

namespace App\Enums\Automation\Workflow;

/**
 * Automations V2 §4.4/§5.1 — the kind of an outgoing edge.
 *
 * A node has at most one outgoing edge of each kind
 * (`UNIQUE(from_node_id, edge_kind)`), so a step cannot have two successors and
 * an If/Else cannot have two "yes" lanes. `Yes`/`No` are produced only by an
 * `if_else` node; every other node uses `Next`.
 */
enum WorkflowEdgeKind: string
{
    case Next = 'next';
    case Yes = 'yes';
    case No = 'no';

    /** The two edge kinds an If/Else node emits, in rendering order. */
    public static function branchKinds(): array
    {
        return [self::Yes, self::No];
    }

    public function isBranch(): bool
    {
        return $this !== self::Next;
    }
}

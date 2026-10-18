<?php

namespace App\Library\AgencyOutreach;

use App\Models\AgencyProspectMessage;
use App\Models\Workspace;

/**
 * Re-sends what was held back for lack of funds (contract §12): ledger rows with
 * status `paused`, under the SAME operation key they were first claimed with, so the
 * managed dispatcher's idempotency means a message is never sent twice — not by a
 * second resume, not by a funded wallet racing a retry.
 *
 * Called after the Agency funds the wallet / resumes the campaign. Safe to call at any
 * time: nothing paused means nothing happens. Stops at the first send the wallet still
 * refuses (every later one would be refused the same way).
 */
final class OutreachResumeService
{
    public function __construct(private readonly OutreachSendPipeline $pipeline)
    {
    }

    /** @return int how many paused messages were sent now */
    public function resumePausedSends(Workspace $workspace): int
    {
        $ids = AgencyProspectMessage::query()
            ->where('workspace_id', (int) $workspace->id)
            ->where('direction', AgencyProspectMessage::DIRECTION_OUTBOUND)
            ->where('status', AgencyProspectMessage::STATUS_PAUSED)
            ->orderBy('id')
            ->pluck('id');

        $sent = 0;

        foreach ($ids as $id) {
            $result = $this->pipeline->resume((int) $id);

            if ($result === null) {
                continue;
            }

            if ($result->sent()) {
                $sent++;

                continue;
            }

            if ($result->send?->status === OutreachSendResult::PAUSED) {
                break;
            }
        }

        return $sent;
    }
}

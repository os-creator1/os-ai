<?php

namespace App\Library\AgencyOutreach;

use App\Enums\Business\BusinessStatus;
use App\Models\Business;
use App\Models\Workspace;

/**
 * The Business Agency Outreach sends AS: the Agency Workspace's own single
 * Business (contract §2). It is the payer, the owner of the sending number and
 * of every Conversation Outreach creates, so it is resolved from the Workspace
 * alone — never from a request, never from a client Business, never globally.
 *
 * Static so it can be called as `AgencyOutreachBusinessResolver::forWorkspace()` or on an
 * injected instance alike.
 *
 * Exactly one Business, and Active. Zero, several or an inactive one is
 * "not resolvable" (null), which readiness reports as a blocker and every send
 * treats as a refusal: guessing which of several Businesses to message from
 * would be sending as the wrong company.
 */
final class AgencyOutreachBusinessResolver
{
    public static function forWorkspace(Workspace $workspace): ?Business
    {
        if (! $workspace->exists) {
            return null;
        }

        $businesses = Business::query()
            ->where('workspace_id', (int) $workspace->id)
            ->orderBy('id')
            ->limit(2)
            ->get();

        if ($businesses->count() !== 1) {
            return null;
        }

        $business = $businesses->first();

        return $business->status === BusinessStatus::Active ? $business : null;
    }
}

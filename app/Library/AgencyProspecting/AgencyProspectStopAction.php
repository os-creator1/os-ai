<?php

namespace App\Library\AgencyProspecting;

use App\Enums\AgencyProspecting\AgencyProspectStage;
use App\Enums\AgencyProspecting\AgencyProspectStatus;
use App\Models\AgencyProspect;
use Illuminate\Support\Facades\DB;

/**
 * Runtime pass — the single authority for applying STOP/opt-out from the
 * new runtime call sites (the inbound webhook's deterministic keyword
 * match and an AI hard-negative decision), reproducing the exact
 * terminal-state discipline the foundation pass already established for
 * its own human-initiated AgencyProspectingController::stopProspect()
 * (Correction 1/2/3: lock the prospect row, set status+stopped_at,
 * synchronize every membership to stage 99, never delete anything) —
 * that existing, already-tested foundation method is left untouched
 * rather than refactored onto this shared helper.
 */
final class AgencyProspectStopAction
{
    public function apply(AgencyProspect $prospect): void
    {
        DB::transaction(function () use ($prospect): void {
            $locked = AgencyProspect::where('id', $prospect->id)->lockForUpdate()->first();

            if ($locked === null) {
                return;
            }

            $locked->update([
                'status' => AgencyProspectStatus::Stopped->value,
                'stopped_at' => now(),
            ]);

            $locked->campaignMemberships()->update(['stage' => AgencyProspectStage::StoppedOptOut->value]);
        });
    }
}

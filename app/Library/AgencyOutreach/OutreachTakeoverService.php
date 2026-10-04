<?php

namespace App\Library\AgencyOutreach;

use App\Models\AgencyProspectCampaignMember;
use App\Models\AgencyProspectMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Manual takeover (contract §13): the owner pauses the AI for one prospect, replies
 * from Conversations (canonical, billed like any message), and resumes it later.
 *
 * The ONLY writer of `ai_paused_at` / `ai_paused_by_user_id`. While paused the responder
 * and the follow-up send nothing; the responder records `manual_hold` on the inbound it
 * skipped. Every change is audited on the Outreach ledger as an `event` row carrying the
 * acting user — a ledger entry that is neither inbound nor outbound, so message counts
 * and the "last outbound" duplicate guard are untouched.
 *
 * Pausing a member that is already paused (or resuming one that is not) changes nothing
 * and writes no second audit row, so a double click is harmless.
 */
final class OutreachTakeoverService
{
    public const DIRECTION_EVENT = 'event';

    public const EVENT_PAUSED = 'ai_paused';

    public const EVENT_RESUMED = 'ai_resumed';

    public function pause(AgencyProspectCampaignMember $member, User $actor): void
    {
        DB::transaction(function () use ($member, $actor): void {
            $locked = AgencyProspectCampaignMember::query()->where('id', $member->id)->lockForUpdate()->first();

            if ($locked === null || $locked->isAiPaused()) {
                return;
            }

            $locked->update(['ai_paused_at' => now(), 'ai_paused_by_user_id' => $actor->id]);
            $this->audit($locked, $actor, self::EVENT_PAUSED, 'AI paused. Manual takeover.');
        });

        $member->refresh();
    }

    public function resume(AgencyProspectCampaignMember $member, User $actor): void
    {
        DB::transaction(function () use ($member, $actor): void {
            $locked = AgencyProspectCampaignMember::query()->where('id', $member->id)->lockForUpdate()->first();

            if ($locked === null || ! $locked->isAiPaused()) {
                return;
            }

            $locked->update(['ai_paused_at' => null, 'ai_paused_by_user_id' => null]);
            $this->audit($locked, $actor, self::EVENT_RESUMED, 'AI resumed.');
        });

        $member->refresh();
    }

    private function audit(AgencyProspectCampaignMember $member, User $actor, string $event, string $text): void
    {
        AgencyProspectMessage::create([
            'workspace_id' => $member->workspace_id,
            'campaign_member_id' => $member->id,
            'channel_id' => null,
            'direction' => self::DIRECTION_EVENT,
            'purpose' => null,
            'operation_key' => null,
            'body' => $text,
            'status' => 'recorded',
            'intent' => $event,
            'source' => AgencyProspectMessage::SOURCE_MANUAL,
            'stage_from' => $member->stage->value,
            'stage_to' => $member->stage->value,
            'script_version' => OutreachScript::forWorkspace($member->workspace)->version(),
            'actor_user_id' => $actor->id,
        ]);
    }
}

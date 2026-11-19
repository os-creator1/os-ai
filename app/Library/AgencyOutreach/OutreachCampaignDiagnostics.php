<?php

namespace App\Library\AgencyOutreach;

use App\Models\AgencyProspect;
use App\Models\AgencyProspectCampaign;
use App\Models\AgencyProspectMessage;
use App\Models\Workspace;
use Illuminate\Support\Collection;

/**
 * Read-only explanations for the campaign page: what a prospect gets when they reply,
 * whether the campaign opener duplicates the first scripted step, and why an inbound
 * reply did NOT produce a text. Nothing here writes or sends.
 */
final class OutreachCampaignDiagnostics
{
    /** Replies that read as a positive answer: a "no" answered with one of these opens badly. */
    private const POSITIVE_OPENERS = '/^\s*(great|awesome|perfect|excellent|fantastic|wonderful|amazing|glad|love that|nice|sweet|good to hear|thanks for (getting back|replying|responding|your interest))\b/i';

    public const REASON_LABELS = [
        'duplicate_of_last_outbound' => 'The next message was identical to the last text sent, so it was not repeated.',
        'manual_hold' => 'AI replies are paused for this prospect.',
        'manual_reply_sent' => 'You had already replied by hand.',
        'opted_out' => 'The prospect opted out.',
        'rejected' => 'The prospect said no thanks, so the conversation was ended.',
        'sequence_complete' => 'Every message in your sequence has already been sent.',
        'no_scripted_reply' => 'There is no further scripted message for this stage.',
        'needs_human' => 'A question your approved answers do not cover was left for you.',
        'no_reply_text' => 'There was no message text to send.',
        'terminal_stage' => 'The conversation had already ended (booked or stopped).',
        'stage_not_automated' => 'This stage is not automated.',
        'campaign_not_active' => 'The campaign was not active.',
        'sequence_paused' => 'Your sequence is paused.',
        'prospect_not_active' => 'The prospect was stopped or booked.',
        'step_already_sent' => 'That message had already been delivered.',
        'stage_changed' => 'The conversation moved on while this reply was processed.',
        'send_in_flight' => 'Another text was still being sent.',
        'no_sending_number' => 'No sending number was ready.',
        'verification_incomplete' => 'Messaging verification is not complete.',
        'insufficient_balance' => 'Not enough balance to send.',
    ];

    public static function reasonLabel(?string $reason): string
    {
        return $reason === null || $reason === '' ? '' : (self::REASON_LABELS[$reason] ?? ucfirst(str_replace('_', ' ', $reason)));
    }

    /**
     * @return array{
     *   opener_duplicates_first_step: bool,
     *   first_reply: ?array{id: string, label: string, preview: string},
     *   warnings: list<string>,
     *   silent_replies: Collection<int, AgencyProspectMessage>
     * }
     */
    public function forCampaign(Workspace $workspace, AgencyProspectCampaign $campaign): array
    {
        $sequence = OutreachSequence::forWorkspace($workspace);
        $enabled = $sequence->enabledSteps();
        $sample = new AgencyProspect(['workspace_id' => $workspace->id, 'company_name' => 'Example Company', 'contact_name' => 'Alex Morgan']);
        $render = fn (string $body): string => OutreachScriptRenderer::render(OutreachScriptTokens::canonicalise($body), $workspace, $sample);

        $warnings = [];
        $duplicates = false;
        $first = $enabled[0] ?? null;

        if ($first !== null && $first['advance_on'] === OutreachSequence::REPLY && trim((string) $campaign->opening_message) !== '') {
            $opener = $render((string) $campaign->opening_message);
            $duplicates = OutreachSequence::sameText($opener, $render($first['body']));
        }

        // The first reply step a prospect who has just replied would receive.
        $next = $enabled;
        $position = 1;

        if ($duplicates) {
            array_shift($next);
            $position = 2;
        }

        $firstReply = null;

        foreach ($next as $index => $step) {
            if ($step['advance_on'] === OutreachSequence::REPLY) {
                $number = array_search($step['id'], array_column($enabled, 'id'), true);
                $firstReply = ['id' => $step['id'], 'label' => 'Message ' . (($number === false ? $index : $number) + 1), 'preview' => $render($step['body'])];
                break;
            }
        }

        if ($duplicates) {
            $warnings[] = 'Your first text is identical to Message 1 in Script & Settings. It counts as already sent, so a reply goes straight to '
                . ($firstReply['label'] ?? 'the next message') . '. Change the first text or Message 1 if you want a different flow.';
        }

        if ($firstReply === null) {
            $warnings[] = 'There is no reply message after the first text, so prospects who reply will not get an automatic answer. Add a reply message in Script & Settings.';
        } elseif (preg_match(self::POSITIVE_OPENERS, $firstReply['preview']) === 1) {
            $warnings[] = $firstReply['label'] . ' opens as if the prospect said yes ("' . mb_substr($firstReply['preview'], 0, 40)
                . '…"). A hesitant or negative reply would get it too. Reword it in Script & Settings so it also reads well after "no" or "who is this?".';
        }

        $silent = AgencyProspectMessage::query()
            ->where('workspace_id', $workspace->id)
            ->where('direction', AgencyProspectMessage::DIRECTION_INBOUND)
            ->whereHas('campaignMember', fn ($q) => $q->where('campaign_id', $campaign->id))
            ->whereNotNull('failure_reason')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return [
            'opener_duplicates_first_step' => $duplicates,
            'first_reply' => $firstReply,
            'warnings' => $warnings,
            'silent_replies' => $silent,
        ];
    }
}

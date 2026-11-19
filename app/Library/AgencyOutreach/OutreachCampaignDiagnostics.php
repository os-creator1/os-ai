<?php

namespace App\Library\AgencyOutreach;

use App\Models\AgencyProspect;
use App\Models\AgencyProspectCampaign;
use App\Models\AgencyProspectMessage;
use App\Models\Workspace;
use Illuminate\Support\Collection;

/**
 * Read-only explanations for the campaign page: what a prospect gets when they reply,
 * whether the campaign's first text duplicates the first scripted step, what each prospect
 * will get NEXT, and why an inbound reply did NOT produce a text. Nothing here writes or sends.
 */
final class OutreachCampaignDiagnostics
{
    private const MEMBER_LIMIT = 25;

    public const REASON_LABELS = [
        'duplicate_of_last_outbound' => 'The next message was identical to the last text sent, so it was not repeated.',
        'step_already_delivered' => 'That scripted message had already been sent to this prospect (possibly by you from Conversations), so it was not repeated.',
        'negative_reply_held' => 'The prospect said no and your next message reads like a yes or a booking pitch, so it was held for you. Reword it in Script & Settings, or reply by hand.',
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
     *   silent_replies: Collection<int, AgencyProspectMessage>,
     *   next_by_member: list<array{uid: string, name: string, next: string, preview: string}>
     * }
     */
    public function forCampaign(Workspace $workspace, AgencyProspectCampaign $campaign): array
    {
        $sequence = OutreachSequence::forWorkspace($workspace);
        $enabled = $sequence->enabledSteps();
        $sample = new AgencyProspect(['workspace_id' => $workspace->id, 'company_name' => 'Example Company', 'contact_name' => 'Alex Morgan']);
        $render = fn (string $body, ?AgencyProspect $p = null): string => OutreachScriptRenderer::render(OutreachScriptTokens::canonicalise($body), $workspace, $p ?? $sample);
        $label = fn (string $id): string => 'Message ' . (((int) array_search($id, array_column($enabled, 'id'), true)) + 1);

        $warnings = [];
        $duplicates = false;
        $first = $enabled[0] ?? null;

        if ($first !== null && $first['advance_on'] === OutreachSequence::REPLY && trim((string) $campaign->opening_message) !== '') {
            $duplicates = OutreachSequence::sameText($render((string) $campaign->opening_message), $render($first['body']));
        }

        // The first reply step a prospect who has just replied would receive.
        $firstReply = null;

        foreach ($enabled as $index => $step) {
            if ($duplicates && $index === 0) {
                continue;
            }

            if ($step['advance_on'] === OutreachSequence::REPLY) {
                $firstReply = ['id' => $step['id'], 'label' => $label($step['id']), 'preview' => $render($step['body'])];
                break;
            }
        }

        if ($duplicates) {
            $warnings[] = 'Your first text is identical to Message 1 in Script & Settings. It counts as already sent, so a reply goes straight to '
                . ($firstReply['label'] ?? 'the next message') . '. Change the first text or Message 1 if you want a different flow.';
        }

        if ($firstReply === null) {
            $warnings[] = 'There is no reply message after the first text, so prospects who reply will not get an automatic answer. Add a reply message in Script & Settings.';
        } elseif (OutreachStepTone::assumesYes($firstReply['preview'])) {
            $warnings[] = $firstReply['label'] . ' opens as if the prospect said yes ("' . mb_substr($firstReply['preview'], 0, 40)
                . '…"). A plain "no" will not be answered with it (it is held for you instead). Reword it in Script & Settings so it also reads well after "no".';
        }

        $silent = AgencyProspectMessage::query()
            ->where('workspace_id', $workspace->id)
            ->where('direction', AgencyProspectMessage::DIRECTION_INBOUND)
            ->whereHas('campaignMember', fn ($q) => $q->where('campaign_id', $campaign->id))
            ->whereNotNull('failure_reason')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        $next = [];

        if ($campaign->status->value !== 'draft') {
            $members = $campaign->members()->with('prospect')
                ->whereNotIn('stage', [6, 99])
                ->whereHas('messages', fn ($q) => $q->where('direction', AgencyProspectMessage::DIRECTION_OUTBOUND)->where('status', AgencyProspectMessage::STATUS_SENT))
                ->orderBy('id')->limit(self::MEMBER_LIMIT)->get();

            foreach ($members as $member) {
                $step = $sequence->nextReplyStep($sequence->sentIdsFor($member));
                $next[] = [
                    'uid' => $member->uid,
                    'name' => (string) ($member->prospect?->company_name ?? ''),
                    'next' => $step === null ? 'Nothing further is scripted' : $label($step['id']),
                    'preview' => $step === null ? '' : $render($step['body'], $member->prospect),
                ];
            }
        }

        return [
            'opener_duplicates_first_step' => $duplicates,
            'first_reply' => $firstReply,
            'warnings' => $warnings,
            'silent_replies' => $silent,
            'next_by_member' => $next,
        ];
    }
}

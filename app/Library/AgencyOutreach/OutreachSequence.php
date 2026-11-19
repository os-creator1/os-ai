<?php

namespace App\Library\AgencyOutreach;

use App\Enums\AgencyProspecting\AgencyProspectStage;
use App\Models\AgencyProspect;
use App\Models\AgencyProspectCampaignMember;
use App\Models\AgencyProspectMessage;
use App\Models\ChatBoxMessage;
use App\Models\Reports;
use App\Models\Workspace;

/**
 * One Agency's ordered reply sequence, as the engine reads it (Outreach V2).
 *
 * A step is {id, body, advance_on, delay_hours, enabled}:
 *   - advance_on `reply`  the step is sent when the prospect replies
 *   - advance_on `time`   the step is sent `delay_hours` after the previous send,
 *                         unless the prospect has replied (a reply cancels the timer)
 *
 * Steps have STABLE ids. A prospect's progress is the set of step ids already sent,
 * so editing the sequence while a campaign runs can neither repeat a step nor skip
 * or reorder one for a prospect already part-way through: the next step is the first
 * enabled, unsent step positioned after the last one that prospect received.
 *
 * An Agency that never customised gets the legacy three-message flow derived from
 * message_1..3 / followup_message with the ids legacy-1..3 / legacy-followup, and an
 * existing prospect's progress is derived from their stage -- the explicit
 * compatibility path.
 */
final class OutreachSequence
{
    public const LEGACY_FOLLOWUP = 'legacy-followup';

    public const REPLY = 'reply';

    public const TIME = 'time';

    /** @param array<int, array<string, mixed>> $steps */
    private function __construct(
        private readonly array $steps,
        private readonly bool $paused,
        private readonly bool $custom,
    ) {
    }

    public static function forWorkspace(Workspace $workspace): self
    {
        $script = OutreachScript::forWorkspace($workspace);
        $raw = $script->settings()?->sequence_steps;

        if (is_array($raw)) {
            return new self(self::normalise($raw), $script->sequencePaused(), true);
        }

        return new self(self::legacySteps($script), $script->sequencePaused(), false);
    }

    /** The legacy three-message flow plus its single follow-up, as steps. @return array<int, array<string, mixed>> */
    public static function legacySteps(OutreachScript $script): array
    {
        $steps = [];

        foreach ([1, 2, 3] as $n) {
            $steps[] = ['id' => 'legacy-' . $n, 'body' => $script->get('message_' . $n), 'advance_on' => self::REPLY, 'delay_hours' => null, 'enabled' => true];
        }

        $steps[] = [
            'id' => self::LEGACY_FOLLOWUP,
            'body' => $script->get('followup_message'),
            'advance_on' => self::TIME,
            'delay_hours' => $script->followUpDelayHours(),
            'enabled' => $script->followUpEnabled(),
        ];

        return $steps;
    }

    /** @param array<int, mixed> $raw @return array<int, array<string, mixed>> */
    public static function normalise(array $raw): array
    {
        $steps = [];
        $seen = [];

        foreach ($raw as $step) {
            if (! is_array($step)) {
                continue;
            }

            $id = trim((string) ($step['id'] ?? ''));

            if ($id === '' || isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $time = ($step['advance_on'] ?? self::REPLY) === self::TIME;

            $steps[] = [
                'id' => $id,
                'body' => (string) ($step['body'] ?? ''),
                'advance_on' => $time ? self::TIME : self::REPLY,
                'delay_hours' => $time ? max(1, (int) ($step['delay_hours'] ?? 24)) : null,
                'enabled' => ! array_key_exists('enabled', $step) || filter_var($step['enabled'], FILTER_VALIDATE_BOOLEAN),
            ];
        }

        return $steps;
    }

    /** @return array<int, array<string, mixed>> */
    public function steps(): array
    {
        return $this->steps;
    }

    /** @return array<int, array<string, mixed>> */
    public function enabledSteps(): array
    {
        return array_values(array_filter($this->steps, static fn (array $s): bool => $s['enabled'] && trim($s['body']) !== ''));
    }

    public function isCustom(): bool
    {
        return $this->custom;
    }

    public function paused(): bool
    {
        return $this->paused;
    }

    public function find(string $id): ?array
    {
        foreach ($this->steps as $step) {
            if ($step['id'] === $id) {
                return $step;
            }
        }

        return null;
    }

    /** Whether any enabled message carries the booking-link variable. */
    public function containsCalendarLink(): bool
    {
        // The legacy flow's contract: the third message carries the link.
        if (! $this->custom) {
            return self::stepHasCalendarLink($this->find('legacy-3'));
        }

        foreach ($this->enabledSteps() as $step) {
            if (str_contains($step['body'], '{{agency.calendar_link}}')) {
                return true;
            }
        }

        return false;
    }

    /** The message to repeat when a prospect asks for the link again: the one already sent, else the first that has it. @param array<int, string> $sent */
    public function linkStep(array $sent): ?array
    {
        $found = null;

        foreach ($this->enabledSteps() as $step) {
            if (! self::stepHasCalendarLink($step)) {
                continue;
            }

            if (in_array($step['id'], $sent, true)) {
                $found = $step;
            } elseif ($found === null) {
                $first ??= $step;
            }
        }

        return $found ?? ($first ?? null);
    }

    public static function stepHasCalendarLink(?array $step): bool
    {
        return $step !== null && str_contains((string) $step['body'], '{{agency.calendar_link}}');
    }

    /**
     * The step ids this prospect has already received: the recorded progress, plus every
     * reply step whose text this prospect has demonstrably been SENT in this campaign
     * ({@see deliveredByContent}) -- the campaign opener, or a person sending the scripted
     * text by hand. Derived per member, so it holds for campaigns already running.
     *
     * @return array<int, string>
     */
    public function sentIdsFor(AgencyProspectCampaignMember $member): array
    {
        $ids = $this->recordedSentIds($member);

        foreach ($this->deliveredByContent($member) as $id) {
            if (! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /** @return array<int, string> */
    private function recordedSentIds(AgencyProspectCampaignMember $member): array
    {
        $stored = $member->sequence_sent_ids;

        if (is_array($stored)) {
            return array_values(array_map('strval', $stored));
        }

        // Compatibility: a prospect enrolled before sequences carries only a stage.
        $count = match (true) {
            $member->stage->value >= AgencyProspectStage::BookingLinkSent->value => 3,
            $member->stage === AgencyProspectStage::CallInvitation => 2,
            $member->stage === AgencyProspectStage::Qualification => 1,
            default => 0,
        };

        $ids = [];

        foreach ($this->enabledSteps() as $step) {
            if ($step['advance_on'] === self::REPLY && count($ids) < $count) {
                $ids[] = $step['id'];
            }
        }

        if ($member->followup_sent_at !== null && $this->find(self::LEGACY_FOLLOWUP) !== null) {
            $ids[] = self::LEGACY_FOLLOWUP;
        }

        return $ids;
    }

    /**
     * Which steps this prospect has received, from what was actually sent to them since THIS
     * campaign's opener -- never from the campaign merely being Active. ONE attribution
     * algorithm; every sent text is a unit of evidence that satisfies AT MOST ONE step:
     *
     *  1. Explicit: a delivered ledger row carrying a step id that still exists belongs to
     *     that step (even if the step's wording was edited since). Its evidence is consumed
     *     and can never be reused for another step.
     *  2. Orphaned: a delivered ledger row whose step id the Agency has since edited away is
     *     reconciled to the first still-undelivered step with the same rendered text (exact
     *     match, or the step text as the tail of the row after an approved answer that was
     *     placed in front of it). Each orphaned row is consumed by one step; a row that
     *     matches nothing is simply dropped, never spread over several steps.
     *  3. Unattributed: the campaign opener and confirmed messages a person sent (the
     *     conversation, minus the copies that are the ledger's own sends) satisfy the first
     *     undelivered steps with identical rendered text, one copy per step.
     *
     * Nothing is inferred before the campaign's own opener was delivered, another campaign's
     * members are never read, and a message is never relabelled as a scripted send.
     * Deterministic: steps are walked in sequence order.
     *
     * @return array<int, string>
     */
    public function deliveredByContent(AgencyProspectCampaignMember $member): array
    {
        $ledger = AgencyProspectMessage::query()
            ->where('campaign_member_id', $member->id)
            ->where('direction', AgencyProspectMessage::DIRECTION_OUTBOUND)
            ->where('status', AgencyProspectMessage::STATUS_SENT)
            ->orderBy('id')
            ->get(['body', 'sequence_step_id', 'operation_key', 'created_at']);

        $first = $ledger->first();

        if ($first === null) {
            return [];
        }

        $prospect = AgencyProspect::query()->where('id', $member->prospect_id)->where('workspace_id', $member->workspace_id)->first();
        $workspace = $prospect === null ? null : Workspace::query()->find($member->workspace_id);

        if ($workspace === null) {
            return [];
        }

        $norm = static fn (string $t): string => trim(preg_replace('/\s+/u', ' ', $t) ?? $t);
        $steps = array_values(array_filter($this->enabledSteps(), static fn (array $s): bool => $s['advance_on'] === self::REPLY));
        $text = [];

        foreach ($steps as $step) {
            $text[$step['id']] = $norm(OutreachScriptRenderer::render(OutreachScriptTokens::canonicalise((string) $step['body']), $workspace, $prospect));
        }

        $recorded = array_fill_keys($this->recordedSentIds($member), true);
        $delivered = $recorded;
        $explicit = [];   // steps proven by their own ledger row (or an orphaned one): they own that evidence
        $orphans = [];
        $pool = [];   // unattributed evidence: normalised text => copies

        // 1. Explicit attribution by a still-valid step id; the evidence is consumed here.
        foreach ($ledger as $row) {
            $id = $row->sequence_step_id === null ? null : (string) $row->sequence_step_id;

            if ($id === null) {
                $pool[$norm((string) $row->body)] = ($pool[$norm((string) $row->body)] ?? 0) + 1;
            } elseif ($this->find($id) !== null) {
                $delivered[$id] = true;
                $explicit[$id] = true;
            } else {
                $orphans[] = $norm((string) $row->body);
            }
        }

        // The conversation also holds every Outreach send. Those are the SAME SMS as a ledger row
        // (matched by their messaging operation, never by wording) and are excluded, so one SMS
        // is never counted twice; what remains is what a person confirmed sending.
        $ledgerKeys = $ledger->pluck('operation_key')->filter()->map(fn ($k) => (string) $k)->all();

        foreach ($this->conversationBodies($workspace, $prospect, $first->created_at, $ledgerKeys) as $body) {
            $body = $norm($body);
            $pool[$body] = ($pool[$body] ?? 0) + 1;
        }

        // 2. Orphans: exact text first, then "approved answer + step text"; one step per row.
        foreach (['exact', 'tail'] as $mode) {
            foreach ($orphans as $i => $body) {
                foreach ($steps as $step) {
                    $t = $text[$step['id']];

                    if ($t === '' || isset($explicit[$step['id']])) {
                        continue;
                    }

                    if ($mode === 'exact' ? $body === $t : str_ends_with($body, ' ' . $t)) {
                        $delivered[$step['id']] = true;
                        $explicit[$step['id']] = true;
                        unset($orphans[$i]);

                        break;
                    }
                }
            }
        }

        // 3. Unattributed copies, one per step. A step already recorded as delivered (earlier derived
        // from this same kind of evidence) still CONSUMES its copy, so it cannot be spent again on
        // a later step with identical wording.
        foreach ($steps as $step) {
            $t = $text[$step['id']];

            if ($t === '' || isset($explicit[$step['id']])) {
                continue;
            }

            if (($pool[$t] ?? 0) > 0 && (isset($delivered[$step['id']]) || ! isset($recorded[$step['id']]))) {
                $pool[$t]--;
                $delivered[$step['id']] = true;
            }
        }

        // Stable, deterministic order: the sequence's own order, then any id it no longer has.
        $order = array_flip(array_column($this->steps, 'id'));
        $ids = array_map('strval', array_keys($delivered));
        usort($ids, static fn (string $a, string $b): int => ($order[$a] ?? PHP_INT_MAX) <=> ($order[$b] ?? PHP_INT_MAX));

        return $ids;
    }

    /** Equality after whitespace normalisation (case and wording are never loosened). */
    public static function sameText(string $a, string $b): bool
    {
        $norm = static fn (string $t): string => trim(preg_replace('/\s+/u', ' ', $t) ?? $t);

        return $norm($a) !== '' && $norm($a) === $norm($b);
    }

    /** @return array<int, string> */
    /** @param array<int, string> $ledgerOperationKeys */
    private function conversationBodies(Workspace $workspace, AgencyProspect $prospect, mixed $since, array $ledgerOperationKeys = []): array
    {
        $business = AgencyOutreachBusinessResolver::forWorkspace($workspace);
        $box = $business === null ? null : app(OutreachConversationLinker::class)->conversationFor($business, $prospect);

        if ($box === null) {
            return [];
        }

        // Only a message whose delivery is CONFIRMED proves the prospect received it:
        //  - send_status `sent` / `delivered`;
        //  - a legacy row with no status at all, and no managed-send operation that could still
        //    be pending (rows from before send states existed; nothing is invented for them).
        // `sending`, `ambiguous`, `failed`, `delivery_failed` and any unknown state do not count;
        // when one is later confirmed it starts to count on the next read.
        return ChatBoxMessage::query()
            ->leftJoin('business_messaging_operations as op', 'op.id', '=', 'chat_box_messages.business_messaging_operation_id')
            ->where('chat_box_messages.box_id', $box->id)
            ->where('chat_box_messages.direction', Reports::DIRECTION_OUTGOING)
            ->where('chat_box_messages.created_at', '>=', $since)
            ->where(function ($q): void {
                $q->whereIn('chat_box_messages.send_status', ['sent', 'delivered'])
                    ->orWhere(fn ($legacy) => $legacy->whereNull('chat_box_messages.send_status')->whereNull('chat_box_messages.business_messaging_operation_id'));
            })
            ->when($ledgerOperationKeys !== [], fn ($q) => $q->where(fn ($k) => $k->whereNull('op.operation_key')->orWhereNotIn('op.operation_key', $ledgerOperationKeys)))
            ->pluck('chat_box_messages.message')->map(fn ($m) => (string) $m)->all();
    }

    /**
     * Candidate steps for a prospect: enabled, unsent, positioned after the last
     * step this prospect received (as the sequence is ordered NOW).
     *
     * @param  array<int, string>  $sent
     * @return array<int, array<string, mixed>>
     */
    private function candidates(array $sent): array
    {
        $last = -1;

        foreach ($this->steps as $index => $step) {
            if (in_array($step['id'], $sent, true)) {
                $last = max($last, $index);
            }
        }

        $out = [];

        foreach ($this->steps as $index => $step) {
            if ($index > $last && $step['enabled'] && trim($step['body']) !== '' && ! in_array($step['id'], $sent, true)) {
                $out[] = $step;
            }
        }

        return $out;
    }

    /** The next step to send when the prospect replies (time steps are passed over). @param array<int, string> $sent */
    public function nextReplyStep(array $sent): ?array
    {
        foreach ($this->candidates($sent) as $step) {
            if ($step['advance_on'] === self::REPLY) {
                return $step;
            }
        }

        return null;
    }

    /** The next timed step, only when it is the very next thing due. @param array<int, string> $sent */
    public function nextTimeStep(array $sent): ?array
    {
        $first = $this->candidates($sent)[0] ?? null;

        return $first !== null && $first['advance_on'] === self::TIME ? $first : null;
    }
}

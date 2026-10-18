<?php

namespace App\Library\AgencyOutreach;

use App\Enums\AgencyProspecting\AgencyProspectStage;
use App\Models\AgencyProspectCampaignMember;
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
     * The step ids this prospect has already received.
     *
     * @return array<int, string>
     */
    public function sentIdsFor(AgencyProspectCampaignMember $member): array
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

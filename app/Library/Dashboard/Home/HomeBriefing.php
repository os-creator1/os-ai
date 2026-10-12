<?php

namespace App\Library\Dashboard\Home;

use App\Library\Dashboard\HomeActivityWindow;

/**
 * Home V2 §A — the one-sentence "what happened" briefing.
 *
 * A PURE function of the activity items the Business Home already computed
 * (canonical counts over the visit window), so it costs nothing and cannot
 * disagree with the Business activity figures. It says only what a count
 * proves:
 *
 *  - contacts are "added", never "leads";
 *  - appointments are "booked", never "paid" or "customers";
 *  - automation runs are "automated tasks", never "follow-ups" — a run of an
 *    automation is not necessarily a follow-up, and Home will not say it is.
 *
 * Plain template, no AI, nothing generated per request.
 */
final class HomeBriefing
{
    /**
     * @param  array<string, int>|null  $counts  item key => canonical count; null = no earlier visit to compare with
     * @return array{text: string, quiet: bool, firstVisit: bool, frame: ?string}
     */
    public function compose(?array $counts, ?HomeActivityWindow $window, string $businessName): array
    {
        if ($counts === null || $window === null) {
            return [
                'text' => 'Welcome to ' . $businessName . '. As new contacts and completed work arrive, a short summary of what changed will appear here.',
                'quiet' => true,
                'firstVisit' => true,
                'frame' => null,
            ];
        }

        $clauses = [];

        if (($counts['new_contacts'] ?? 0) > 0) {
            $clauses[] = 'you gained ' . self::n($counts['new_contacts'], 'new contact', 'new contacts');
        }

        if (($counts['new_conversations'] ?? 0) > 0) {
            $c = $counts['new_conversations'];
            $clauses[] = self::n($c, 'customer', 'customers') . ($c === 1 ? ' started' : ' started') . ' a conversation';
        } elseif (($counts['messages_received'] ?? 0) > 0) {
            $m = $counts['messages_received'];
            $clauses[] = self::n($m, 'message', 'messages') . ($m === 1 ? ' came' : ' came') . ' in';
        }

        if (($counts['appointments_booked'] ?? 0) > 0) {
            $a = $counts['appointments_booked'];
            $clauses[] = self::n($a, 'appointment', 'appointments') . ($a === 1 ? ' was' : ' were') . ' booked';
        }

        if (($counts['automations_completed'] ?? 0) > 0) {
            $clauses[] = 'MotionGrove completed ' . self::n($counts['automations_completed'], 'automated task', 'automated tasks');
        }

        if (($counts['automations_failed'] ?? 0) > 0) {
            $f = $counts['automations_failed'];
            $clauses[] = self::n($f, 'automated task', 'automated tasks') . ' did not finish';
        }

        $frame = match ($window->mode) {
            HomeActivityWindow::MODE_TODAY => 'Today so far',
            HomeActivityWindow::MODE_SINCE_LAST_VISIT => 'Since your last visit',
            default => 'In the last ' . $window->catchUpDays . ' days',
        };

        if ($clauses === []) {
            return [
                'text' => $window->emptySentence() . ' New contacts and completed work will show up here as they happen.',
                'quiet' => true,
                'firstVisit' => false,
                'frame' => $frame,
            ];
        }

        $last = array_pop($clauses);
        $body = $clauses === [] ? $last : implode(', ', $clauses) . ' and ' . $last;

        return [
            'text' => $frame . ', ' . $body . '.',
            'quiet' => false,
            'firstVisit' => false,
            'frame' => $frame,
        ];
    }

    private static function n(int $count, string $one, string $many): string
    {
        return number_format($count) . ' ' . ($count === 1 ? $one : $many);
    }
}

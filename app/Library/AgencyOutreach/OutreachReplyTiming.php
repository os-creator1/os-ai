<?php

namespace App\Library\AgencyOutreach;

use App\Models\AgencyProspectMessage;
use App\Models\Workspace;
use Illuminate\Support\Carbon;

/**
 * How long Outreach waits before answering an inbound message, so a conversation has a human
 * pace instead of an instant machine reply. The category is read deterministically from the
 * reply (no model call); the delay is drawn ONCE per inbound message from the Agency's window for
 * that category and stored on the ledger row, so a webhook replay or a job retry can never
 * re-roll it. Nothing here sleeps: the caller schedules a delayed queue job for the due time.
 *
 * STOP / opt-out is not a category: it is applied immediately, before any of this runs.
 */
final class OutreachReplyTiming
{
    public const INTERESTED = 'interested';
    public const QUESTION = 'question';
    public const OBJECTION = 'objection';
    public const BOOKING = 'booking';
    public const OTHER = 'other';

    /** @var array<string, string> category => label shown in Script & Settings */
    public const LABELS = [
        self::INTERESTED => 'Interested or positive reply',
        self::QUESTION => 'Straightforward question',
        self::OBJECTION => 'Objection or hesitation',
        self::BOOKING => 'Booking, scheduling or link request',
        self::OTHER => 'Any other reply',
    ];

    /** @var array<string, array{0: int, 1: int}> the approved default windows, in seconds */
    public const DEFAULTS = [
        self::INTERESTED => [120, 240],
        self::QUESTION => [60, 180],
        self::OBJECTION => [120, 300],
        self::BOOKING => [60, 180],
        self::OTHER => [60, 240],
    ];

    /** The longest wait an Agency may configure, in minutes. */
    public const MAX_MINUTES = 30;

    private const POSITIVE = '/\b(yes|yeah|yep|yup|sure|ok|okay|interested|sounds good|sounds great|tell me more|go ahead|please do|love to|happy to|definitely|absolutely|that works)\b/i';

    private const HESITATION = '/\b(not sure|unsure|maybe|perhaps|hesitant|think about it|too expensive|too much|expensive|busy|not now|not right now|already have|already use|hmm+|i don\'?t know|dont know|skeptical|not looking|no thanks|no thank you|nope|nah)\b/i';

    /**
     * Pacing needs a queue that can DEFER a job. On the `sync` driver a delayed dispatch runs
     * inline and nothing would be left to answer when the moment arrives, so replies are
     * immediate there rather than silently never sent.
     */
    public static function enabled(): bool
    {
        if (! (bool) config('outreach.reply_delay_enabled', true)) {
            return false;
        }

        return self::queueCanDefer();
    }

    /** True when the default queue can hold a delayed job (anything but the inline `sync` driver). */
    public static function queueCanDefer(): bool
    {
        $connection = (string) config('queue.default');

        return config("queue.connections.{$connection}.driver") !== 'sync';
    }

    /** The reply category for one inbound message (deterministic). */
    public static function category(OutreachClassification $reply, string $body): string
    {
        if ($reply->schedulingReply) {
            return self::BOOKING;
        }

        if ($reply->hasFaq() || str_contains($body, '?')) {
            return self::QUESTION;
        }

        if ($reply->stop === OutreachStopClassifier::REJECTED_SOFT
            || OutreachStepTone::isBareNegative($body)
            || preg_match(self::HESITATION, $body) === 1) {
            return self::OBJECTION;
        }

        if (preg_match(self::POSITIVE, $body) === 1) {
            return self::INTERESTED;
        }

        return self::OTHER;
    }

    /**
     * The Agency's windows in seconds: its saved values, else the approved defaults, per category.
     *
     * @return array<string, array{0: int, 1: int}>
     */
    public static function windows(Workspace $workspace): array
    {
        $saved = OutreachScript::forWorkspace($workspace)->settings()?->reply_delay_windows;
        $windows = self::DEFAULTS;

        if (! is_array($saved)) {
            return $windows;
        }

        foreach (self::DEFAULTS as $category => $default) {
            $row = $saved[$category] ?? null;

            if (is_array($row) && isset($row['min'], $row['max']) && is_numeric($row['min']) && is_numeric($row['max'])) {
                $min = max(0, (int) $row['min']);
                $max = max($min, (int) $row['max']);
                $windows[$category] = [$min, min($max, self::MAX_MINUTES * 60)];
            }
        }

        return $windows;
    }

    /**
     * The moment the reply to this inbound message is due, chosen once and stored. Returns null
     * only when pacing is switched off. A second call (webhook replay, job retry) returns the
     * stored moment untouched.
     */
    public function dueAt(AgencyProspectMessage $inbound, Workspace $workspace, OutreachClassification $reply): ?Carbon
    {
        if (! self::enabled()) {
            return null;
        }

        if ($inbound->reply_due_at !== null) {
            return $inbound->reply_due_at;
        }

        $category = self::category($reply, (string) $inbound->body);
        [$min, $max] = self::windows($workspace)[$category];
        $due = now()->addSeconds($min === $max ? $min : random_int($min, $max));

        // First writer wins: concurrent deliveries of the same event cannot disagree.
        AgencyProspectMessage::query()
            ->where('id', $inbound->id)
            ->whereNull('reply_due_at')
            ->update(['reply_due_at' => $due, 'reply_category' => $category]);

        $inbound->refresh();

        return $inbound->reply_due_at;
    }
}

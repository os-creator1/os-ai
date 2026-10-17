<?php

namespace App\Library\Dashboard\Home;

use App\Library\Analytics\AnalyticsDateRange;
use Carbon\CarbonImmutable;

/**
 * Home V2 — the FINAL Home: one pure function from what the existing readers already produced to what the screen shows.
 *
 * It queries nothing and computes no business metric of its own. It decides only HOW a real figure is shown, by the rules of the
 * approved "how each card behaves" sheet:
 *
 *   no data collected          "—" and one line saying why — never a zero
 *   no previous coverage       neutral "First month" / "First period" — never an arrow
 *   real zero, both observed   0 and "Same"
 *   real growth                green ▲ n (the difference, not a percentage)
 *   real decline               amber ▼ n — information, not an error
 *   import still running       "Updating…", the comparison held back
 *   a failed source            "Unavailable" — never an all-clear and never a fabricated zero
 *
 * ZERO AND MISSING ARE DIFFERENT. A previous period is a comparison base only when the system could have recorded it: the
 * Business already existed (contacts, conversations) or already had a booking type (bookings). Where that cannot be established
 * the state is neutral, never "up from nothing".
 *
 * Setup is a suggestion, never a metric or a completed item: nothing here marks a step done because a button was pressed.
 */
final class HomeV2Presenter
{
    public const LAYOUT_HOME = 'home';

    public const LAYOUT_SETUP = 'setup';

    /** Days of observed data before a trend chart says anything the cards do not. */
    public const MIN_CHART_DAYS = 7;

    private const MAX_SUGGESTIONS = 3;

    private const MAX_DONE = 3;

    /**
     * @param  array<string, mixed>  $home  HomeCommandCenter's model so far (state, status, primary, attention, handling, actions, setup)
     * @param  array<string, mixed>|null  $headlines  the headlines band (null when absent or failed)
     * @param  array<int, string>  $failed  the presenter's failed band keys
     * @param  array<string, mixed>  $facts  HomeV2Facts::gather()
     * @param  array<string, mixed>|null  $booking  HomeBookingReader::summary() for this actor (null = no calendar for them)
     * @param  array{text: string, url: ?string, label: string}|null  $billing  a billing exception, when there is one
     * @return array<string, mixed>
     */
    public function compose(array $home, ?array $headlines, array $failed, array $facts, ?array $booking, ?CarbonImmutable $earliestBookingType, ?array $billing): array
    {
        $headlinesFailed = in_array('headlines', $failed, true);
        $kpis = $headlines === null ? [] : $this->kpis($headlines, $facts, $booking, $earliestBookingType, $home['actions']);
        $welcome = $this->welcome($home, $facts, $booking);
        $active = $this->hasMeaningfulActivity($headlines);
        $needsYou = $this->needsYou($home, $billing) + ['recommendations' => $this->recommendations($home)];

        // A genuinely new business gets the setup Home: the existing HomeSetupJourney says its essentials are still unfinished, nothing
        // has happened yet and nothing needs it. A Business with real activity is never held there by optional setup; one whose essentials
        // are done just sees what is suggested next; one with something that needs it sees that first.
        $setupLayout = $home['state'] === HomeCommandCenter::STATE_SETUP && ! $active && ! $headlinesFailed && $welcome['remaining'] > 0 && $needsYou['kind'] === 'caught_up';

        return [
            'layout' => $setupLayout ? self::LAYOUT_SETUP : self::LAYOUT_HOME,
            'needs' => $needsYou,
            'growth' => [
                'available' => $headlines !== null,
                'failed' => $headlinesFailed,
                'kpis' => $kpis,
                'revenue' => $this->revenuePrompt($home['actions'], $facts),
                'chart' => $headlines === null ? null : $this->chart($headlines, $facts),
            ],
            'done' => $this->done($home, $headlines),
            'suggested' => $this->suggested($home, $facts, $booking),
            'welcome' => $welcome,
            'chips' => $this->chips($home, $needsYou),
        ];
    }

    /**
     * One or two short suggestions under Ask, each a question the assistant can really answer for this actor (the card's own
     * availability rules already removed the rest). While customers are waiting, the first one names them.
     *
     * @return array<int, array{key: string, label: string, prompt: string}>
     */
    private function chips(array $home, array $needsYou): array
    {
        $available = array_values(array_filter($home['ask']['suggestions'], fn (array $s): bool => $s['available']));
        $chips = [];

        if ($needsYou['kind'] === 'waiting' && (int) $needsYou['tile'] > 0 && in_array('unreplied', array_column($available, 'key'), true)) {
            $label = 'Help me reply to my ' . (int) $needsYou['tile'] . ' waiting ' . ((int) $needsYou['tile'] === 1 ? 'customer' : 'customers');
            $chips[] = ['key' => 'reply_waiting', 'label' => $label, 'prompt' => $label];
        }

        foreach ($available as $suggestion) {
            if (count($chips) >= 2) {
                break;
            }

            if ($suggestion['key'] === 'unreplied' && $chips !== []) {
                continue;
            }

            $chips[] = ['key' => $suggestion['key'], 'label' => $suggestion['label'], 'prompt' => $suggestion['prompt']];
        }

        return array_slice($chips, 0, $needsYou['kind'] === 'waiting' ? 1 : 2);
    }

    // ── Needs you ───────────────────────────────────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function needsYou(array $home, ?array $billing): array
    {
        $attention = $home['attention'];
        $others = array_merge($attention['visible'], $attention['overflow']);

        $primary = $home['primary'];

        if ($primary !== null) {
            $metric = $primary['metric'] ?? null;
            $waiting = $primary['key'] === 'conversations_awaiting_reply' && $metric !== null;
            $count = $waiting ? (int) $metric['value'] : null;
            $action = $primary['action'];
            $grace = max(0, (int) config('conversations.awaiting_reply_grace_minutes', 5));

            if ($waiting) {
                // Straight to the filtered list of exactly these customers (the inbox's own "waiting" filter), not a drawer.
                $url = $home['actions']['follow-ups']['fullUrl'] ?? ($action['url'] ?? null);
                $url = $url === null ? null : $url . (str_contains($url, '?') ? '&' : '?') . 'filter=waiting';
            } else {
                $url = $action['url'] ?? null;
            }

            return [
                'severityWord' => $primary['severity']?->word(),
                'moveKey' => $primary['key'],
                'moveKind' => $primary['kind'],
                'moveStatus' => $primary['status'],
                'severity' => $primary['severity']?->value,
                'kind' => $waiting ? 'waiting' : 'move',
                'headline' => $waiting ? ($count === 1 ? '1 customer needs a reply' : $count . ' customers need a reply') : $primary['headline'],
                'sub' => $waiting ? ($count === 1 ? 'They have been waiting over ' . $grace . ' minutes' : 'Some have been waiting over ' . $grace . ' minutes') : ($primary['summary'] ?: ($primary['why'][0] ?? null)),
                'tile' => $waiting ? (string) $count : '!',
                'primary' => $url === null ? null : ['label' => $waiting ? 'Reply now' : ($action['label'] ?? 'Open'), 'url' => $url],
                'ai' => $primary['aiAction'] ?? null,
                'why' => $primary['why'],
                'others' => $others,
            ];
        }

        if ($others !== []) {
            $first = $others[0];

            return ['kind' => 'move', 'headline' => $first['text'], 'sub' => null, 'tile' => '!', 'primary' => ($first['action']['url'] ?? null) === null ? null : ['label' => $first['action']['label'], 'url' => $first['action']['url']], 'ai' => null, 'why' => [], 'others' => array_slice($others, 1)];
        }

        // The move itself could not be worked out: say so, and never fall through to an all-clear.
        if ($home['primaryFailed']) {
            return ['kind' => 'unavailable', 'failedMove' => true, 'headline' => "We couldn't work out what needs you", 'sub' => 'This section could not be loaded just now. Everything else on this page is unaffected; refresh in a moment.', 'tile' => null, 'primary' => null, 'ai' => null, 'why' => [], 'others' => []];
        }

        // Billing is the strip above, and it IS what needs the customer: no second, empty card beside it.
        if ($billing !== null) {
            return ['kind' => 'billing_strip', 'headline' => '', 'sub' => null, 'tile' => null, 'primary' => null, 'ai' => null, 'why' => [], 'others' => []];
        }

        // Honest all-clear: only when every read that could contradict it succeeded. (Unfinished setup is not a problem to act on today:
        // it is the suggestions' job, so it neither blocks nor hides behind "all caught up".)
        if (in_array($home['status']['key'], ['clear', 'setup'], true)) {
            return ['kind' => 'caught_up', 'headline' => $home['status']['key'] === 'clear' ? "You're all caught up" : 'Nothing needs you right now', 'sub' => null, 'tile' => null, 'primary' => null, 'ai' => null, 'why' => [], 'others' => []];
        }

        return ['kind' => 'unavailable', 'headline' => "We couldn't check everything just now", 'sub' => "So we can't tell you everything is fine. Refresh in a moment.", 'tile' => null, 'primary' => null, 'ai' => null, 'why' => [], 'others' => []];
    }

    /** @return array{label: string, url: string}|null the discreet "all recommendations" link, only when there is a queue to open */
    private function recommendations(array $home): ?array
    {
        if (($home['queueUrl'] ?? null) === null || in_array((string) ($home['queueLabel'] ?? '0'), ['', '0'], true)) {
            return null;
        }

        return ['label' => (string) $home['queueLabel'], 'url' => (string) $home['queueUrl']];
    }

    // ── Is my business growing? ─────────────────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, array<string, mixed>|null>  $actions
     * @return array<int, array<string, mixed>>
     */
    private function kpis(array $headlines, array $facts, ?array $booking, ?CarbonImmutable $earliestBookingType, array $actions): array
    {
        /** @var AnalyticsDateRange $range */
        $range = $headlines['range'];
        /** @var AnalyticsDateRange $previous */
        $previous = $headlines['previousRange'];
        $items = [];

        foreach ($headlines['items'] as $headline) {
            $items[$headline->key] = $headline;
        }

        $createdAt = $facts['createdAt'];
        $updating = ($facts['contactImportRunning'] ?? false) === true;
        $from = $range->startLocal->format('Y-m-d');
        $to = $range->endLocal->format('Y-m-d');
        $cards = [];

        // 1 · Bookings
        if (isset($items['appointments_booked'])) {
            // Bookings that exist stay visible after every booking type is switched off: history needs a type to have EXISTED, not to be
            // active. A period that ended before the first type was created was not observed at all.
            $set = $booking !== null && (int) $booking['typeCount'] > 0 && $earliestBookingType !== null && $earliestBookingType < $range->endUtc;
            $previousObserved = $set && $earliestBookingType <= $previous->startUtc;
            $cards[] = $this->card('bookings', 'Bookings', $items['appointments_booked'], $set, $previousObserved, false, $range, 'No bookings yet', $this->calendarUrl($actions['upcoming']['fullUrl'] ?? null, $range));
        }

        // 2 · New contacts (the metric counts contacts added, so that is its truthful name — not "leads")
        if (isset($items['new_contacts'])) {
            $observed = $createdAt !== null && $createdAt <= $previous->startUtc;
            // A period that ended before the Business existed was not observed: a dash, not a fabricated zero.
            $currentObserved = $createdAt === null || $createdAt < $range->endUtc;
            $cards[] = $this->card('contacts', 'New contacts', $items['new_contacts'], $currentObserved, $observed, $updating, $range, '', $this->rangeUrl($actions['contacts-list']['fullUrl'] ?? null, $from, $to));
        }

        // 3 · New conversations
        if (isset($items['new_conversations'])) {
            $observed = $createdAt !== null && $createdAt <= $previous->startUtc;
            $currentObserved = $createdAt === null || $createdAt < $range->endUtc;
            $cards[] = $this->card('conversations', 'New conversations', $items['new_conversations'], $currentObserved, $observed, false, $range, '', $this->rangeUrl($actions['follow-ups']['fullUrl'] ?? null, $from, $to));
        }

        return $cards;
    }

    /** @return array<string, mixed> */
    private function card(string $key, string $label, object $headline, bool $currentObserved, bool $previousObserved, bool $updating, AnalyticsDateRange $range, string $emptyNote, ?string $url): array
    {
        $current = (int) $headline->comparison->current;
        $before = (int) $headline->comparison->previous;
        $monthly = $range->preset === AnalyticsDateRange::PRESET_THIS_MONTH || $range->preset === AnalyticsDateRange::PRESET_LAST_MONTH;

        // Nothing was ever collected for this metric: a dash and a short reason, no badge.
        if (! $currentObserved) {
            return ['key' => $key, 'label' => $label, 'value' => '—', 'note' => $emptyNote, 'badge' => null, 'url' => $url];
        }

        if ($updating) {
            return ['key' => $key, 'label' => $label, 'value' => (string) $headline->figure, 'note' => null, 'badge' => ['tone' => 'updating', 'text' => 'Updating…', 'sr' => 'Still updating; comparison held back'], 'url' => $url];
        }

        if (! $previousObserved) {
            $text = $monthly ? 'First month' : 'First period';

            return ['key' => $key, 'label' => $label, 'value' => (string) $headline->figure, 'note' => null, 'badge' => ['tone' => 'neutral', 'text' => $text, 'sr' => 'No earlier period to compare with'], 'url' => $url];
        }

        $delta = $current - $before;
        $badge = match (true) {
            $delta > 0 => ['tone' => 'up', 'text' => '▲ ' . number_format($delta), 'sr' => 'Up ' . number_format($delta) . ' from the previous period'],
            $delta < 0 => ['tone' => 'down', 'text' => '▼ ' . number_format(abs($delta)), 'sr' => 'Down ' . number_format(abs($delta)) . ' from the previous period'],
            default => ['tone' => 'same', 'text' => 'Same', 'sr' => 'Same as the previous period'],
        };

        return ['key' => $key, 'label' => $label, 'value' => (string) $headline->figure, 'note' => null, 'badge' => $badge, 'url' => $url];
    }

    private function rangeUrl(?string $base, string $from, string $to): ?string
    {
        return $base === null ? null : $base . (str_contains($base, '?') ? '&' : '?') . http_build_query(['created_from' => $from, 'created_to' => $to]);
    }

    /** The calendar shows a day/week window (7, 10 or 14 days) anchored at a date: anchored at the period's first day. */
    private function calendarUrl(?string $base, AnalyticsDateRange $range): ?string
    {
        if ($base === null) {
            return null;
        }

        $days = $range->days() <= 7 ? 7 : 14;

        return $base . (str_contains($base, '?') ? '&' : '?') . http_build_query(['view' => 'week', 'days' => $days, 'date' => $range->startLocal->format('Y-m-d')]);
    }

    /** @return array{url: string, label: string}|null */
    private function revenuePrompt(array $actions, array $facts): ?array
    {
        // No reliable revenue reader exists, so revenue is never a metric here: only a way to start tracking it, and only
        // where payments are not connected and the actor may connect them.
        if (($facts['stripeActive'] ?? null) !== false || ($actions['payments']['fullUrl'] ?? null) === null) {
            return null;
        }

        return ['url' => $actions['payments']['fullUrl'], 'label' => 'Connect'];
    }

    // ── Chart ───────────────────────────────────────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function chart(array $headlines, array $facts): array
    {
        /** @var AnalyticsDateRange $range */
        $range = $headlines['range'];
        $createdAt = $facts['createdAt'];
        $first = $createdAt !== null && $createdAt > $range->startUtc ? $createdAt->setTimezone($range->startLocal->getTimezone()) : $range->startLocal;
        $observedDays = max(0, (int) $first->startOfDay()->diffInDays($range->endLocal->startOfDay()) + 1);

        return [
            'seriesUrl' => $headlines['seriesUrl'] ?? null,
            'resultsUrl' => $headlines['resultsUrl'] ?? null,
            'days' => $range->days(),
            'observedDays' => $observedDays,
            // Fewer than a week of data: the trend adds nothing the cards do not already say.
            'tooEarly' => $observedDays < self::MIN_CHART_DAYS,
        ];
    }

    private function hasMeaningfulActivity(?array $headlines): bool
    {
        foreach ($headlines['items'] ?? [] as $headline) {
            if (in_array($headline->key, ['new_contacts', 'new_conversations', 'appointments_booked'], true)
                && ($headline->comparison->current > 0 || $headline->comparison->previous > 0)) {
                return true;
            }
        }

        return false;
    }

    // ── Done for you / Suggested next ───────────────────────────────────────────────────────────────────────────────

    /** @return array{rows: array<int, array<string, mixed>>, url: ?string} */
    private function done(array $home, ?array $headlines): array
    {
        $rows = [];
        $seen = [];

        foreach ($home['handling'] as $row) {
            // Only outcomes that are PROVEN complete; each appears once.
            if ($row['status'] !== 'completed') {
                continue;
            }

            $signature = $row['key'] . '|' . $row['text'];

            if (isset($seen[$signature])) {
                continue;
            }

            $seen[$signature] = true;
            $rows[] = ['text' => $row['text'], 'at' => $row['at'], 'url' => $row['url']];

            if (count($rows) === self::MAX_DONE) {
                break;
            }
        }

        return ['rows' => $rows, 'url' => $headlines['resultsUrl'] ?? null];
    }

    /**
     * Up to three real recommendations from real state. A thing already done, or one this actor cannot reach, is never listed.
     *
     * @return array<int, array{key: string, title: string, label: string, url: string}>
     */
    private function suggested(array $home, array $facts, ?array $booking): array
    {
        $actions = $home['actions'];
        $out = [];
        // Only a status that was actually read can say a website is needed: an unknown status suggests nothing.
        $websiteMissing = array_key_exists('websitePublished', $home) && $home['websitePublished'] === false;
        $add = function (string $key, string $title, string $label, ?string $url) use (&$out): void {
            if ($url !== null && count($out) < self::MAX_SUGGESTIONS) {
                $out[] = ['key' => $key, 'title' => $title, 'label' => $label, 'url' => $url];
            }
        };

        if ($websiteMissing) {
            $add('website', 'Build your website', 'Start', $actions['website']['fullUrl'] ?? null);
        }

        if (($facts['hasActiveAutomation'] ?? null) === false) {
            $add('follow_ups', 'Follow up with new leads automatically', 'Set up', $actions['automation']['fullUrl'] ?? null);
        }

        if (($facts['stripeActive'] ?? null) === false) {
            $add('payments', 'Track revenue by connecting payments', 'Connect', $actions['payments']['fullUrl'] ?? null);
        }

        if (($facts['emailConnected'] ?? null) === false) {
            $add('email', 'Connect your email', 'Connect', $actions['email']['fullUrl'] ?? null);
        }

        if ($booking !== null && (int) $booking['activeTypeCount'] === 0) {
            $add('booking', 'Create a booking type', 'Create', $actions['booking-type']['fullUrl'] ?? null);
        }

        return $out;
    }

    // ── New business: the welcome checklist ─────────────────────────────────────────────────────────────────────────

    /**
     * Every step is done ONLY because a saved fact says so (a published website, a connected mailbox, a priced catalog item, a
     * booking type that can take a booking, an active automation). A step the Business cannot do (no such feature or permission
     * here) is left out rather than shown as mandatory, and an unreadable fact is never treated as done.
     *
     * @return array<string, mixed>
     */
    private function welcome(array $home, array $facts, ?array $booking): array
    {
        $actions = $home['actions'];
        $steps = [];
        $journey = $home['setup'] ?? null;

        // HomeSetupJourney's own steps: each is done only because a persisted fact says so, and a step this actor cannot act on is
        // listed without a button (never a dead link).
        foreach ($journey['steps'] ?? [] as $step) {
            $steps[] = ['key' => $step['key'], 'title' => $step['title'], 'sub' => $step['note'] ?? null, 'done' => (bool) $step['done'], 'label' => $step['action']['label'] ?? null, 'url' => $step['action']['url'] ?? null];
        }


        if (($actions['email']['fullUrl'] ?? null) !== null && ($facts['emailConnected'] ?? null) !== null) {
            $steps[] = ['key' => 'email', 'title' => 'Connect your email', 'sub' => 'So you can reply to leads from here', 'done' => $facts['emailConnected'], 'label' => 'Connect', 'url' => $actions['email']['fullUrl']];
        }

        if (($actions['catalog']['fullUrl'] ?? null) !== null && ($facts['hasPricedCatalog'] ?? null) !== null) {
            $steps[] = ['key' => 'packages', 'title' => 'Add your packages and prices', 'sub' => null, 'done' => $facts['hasPricedCatalog'], 'label' => 'Add', 'url' => $actions['catalog']['fullUrl']];
        }

        if (($actions['automation']['fullUrl'] ?? null) !== null && ($facts['hasActiveAutomation'] ?? null) !== null) {
            $steps[] = ['key' => 'follow_ups', 'title' => 'Turn on automatic lead follow-ups', 'sub' => null, 'done' => $facts['hasActiveAutomation'], 'label' => 'Turn on', 'url' => $actions['automation']['fullUrl']];
        }

        $remaining = count(array_filter($steps, fn (array $s): bool => ! $s['done']));
        $nextKey = null;

        foreach ($steps as $step) {
            if (! $step['done']) {
                $nextKey = $step['key'];

                break;
            }
        }

        return ['steps' => $steps, 'total' => count($steps), 'done' => count($steps) - $remaining, 'remaining' => $remaining, 'nextKey' => $nextKey];
    }
}

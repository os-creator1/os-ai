<?php

namespace App\Library\Dashboard\Home;

use App\Library\Growth\GrowthAdvisor;

/**
 * Home V2 §F — "Ask MotionGrove": a CLOSED registry of the requests Home can
 * actually carry out, and a deterministic matcher over it.
 *
 * WHAT THIS IS NOT. It is not a chatbot and not an agent: nothing here calls
 * an AI provider, nothing is generated per request, and nothing is executed.
 * A request resolves to one of four things, and only one of them can change
 * data — and only after the customer reviews and confirms it in the same
 * drawer the module's own endpoint backs:
 *
 *   drawer      open an in-Home drawer (read, or a form posting to the module)
 *   link        open the exact module page
 *   post        the Advisor's closed-question form (the only AI-capable path,
 *               and only because the customer submits it — never on Home GET)
 *   unsupported say so, plainly, and offer the nearest real module
 *
 * The matcher is word-overlap against fixed phrase lists; ties and misses are
 * decided by registry order and an explicit "unsupported", never a guess at
 * what the customer "probably" meant to execute.
 */
final class HomeAskRegistry
{
    /**
     * key => [label shown as a suggestion, phrase groups (every word of one group must appear)]
     *
     * @var array<string, array{label: string, groups: array<int, array<int, string>>}>
     */
    private const INTENTS = [
        'follow_up' => [
            'label' => 'Follow up with leads',
            'groups' => [['follow', 'up'], ['followup'], ['follow-up'], ['waiting', 'reply'], ['unanswered'], ['reply', 'customers'], ['reply', 'leads'], ['respond']],
        ],
        'booking_type' => [
            'label' => 'Create a booking type',
            'groups' => [['booking', 'type'], ['new', 'service'], ['add', 'service'], ['create', 'service'], ['appointment', 'type']],
        ],
        'appointment' => [
            'label' => 'Book an appointment',
            'groups' => [['book', 'appointment'], ['new', 'appointment'], ['schedule', 'appointment'], ['book', 'customer'], ['make', 'booking']],
        ],
        'upcoming' => [
            'label' => 'Show upcoming appointments',
            'groups' => [['upcoming', 'appointments'], ['upcoming'], ['appointments'], ['my', 'schedule'], ['who', 'booked'], ['calendar']],
        ],
        'ads' => [
            'label' => 'Improve my ads',
            'groups' => [['improve', 'ads'], ['ads'], ['advertising'], ['campaign'], ['ad', 'spend'], ['google', 'ads'], ['meta', 'ads']],
        ],
        'website' => [
            'label' => 'Update my website',
            'groups' => [['update', 'website'], ['website'], ['web', 'site'], ['my', 'site'], ['edit', 'page']],
        ],
        'contact' => [
            'label' => 'Add a contact',
            'groups' => [['add', 'contact'], ['new', 'contact'], ['add', 'customer'], ['new', 'customer']],
        ],
        'explain' => [
            'label' => "Explain this month's results",
            'groups' => [['explain', 'results'], ['explain'], ['results'], ['how', 'doing'], ['what', 'changed'], ['performance'], ['this', 'month']],
        ],
    ];

    /** The suggestion chips, in display order. */
    public function suggestions(): array
    {
        return array_map(fn (string $key): array => ['key' => $key, 'label' => self::INTENTS[$key]['label']], array_keys(self::INTENTS));
    }

    public function has(string $key): bool
    {
        return isset(self::INTENTS[$key]);
    }

    public function label(string $key): string
    {
        return self::INTENTS[$key]['label'] ?? '';
    }

    /**
     * The intent a free-text request names, or null. Registry order breaks ties,
     * so "book an appointment" (appointment) wins over the single word
     * "appointments" (upcoming) because it is listed first and scores higher.
     */
    public function match(string $query): ?string
    {
        $query = mb_strtolower(trim($query));

        if ($query === '' || mb_strlen($query) > 200) {
            return null;
        }

        $words = preg_split('/[^a-z0-9\-]+/', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_values(array_unique(array_merge($words, array_map(fn (string $w): string => str_replace('-', '', $w), $words))));
        $best = null;
        $bestScore = 0;

        foreach (self::INTENTS as $key => $intent) {
            foreach ($intent['groups'] as $group) {
                if (array_diff($group, $words) === []) {
                    $score = count($group);

                    if ($score > $bestScore) {
                        $best = $key;
                        $bestScore = $score;
                    }
                }
            }
        }

        return $best;
    }

    /**
     * Resolve an intent into what the customer is actually offered.
     *
     * @param  array<string, array{drawer: ?string, drawerUrl: ?string, fullUrl: ?string, label: string}|null>  $actions  HomeActionRouter::forActor()
     * @param  ?string  $advisorAskUrl  the Advisor POST route, already gated by the caller (null = not offered)
     * @return array{status: string, intent: ?string, title: string, message: string, drawer: ?string, drawerUrl: ?string, url: ?string, post: ?array<string, mixed>}
     */
    public function resolve(?string $intent, array $actions, ?string $advisorAskUrl): array
    {
        $base = ['status' => 'unsupported', 'intent' => $intent, 'title' => '', 'message' => '', 'drawer' => null, 'drawerUrl' => null, 'url' => null, 'post' => null];

        if ($intent === null || ! $this->has($intent)) {
            return array_merge($base, [
                'title' => "I can't do that from here yet",
                'message' => 'Ask MotionGrove can follow up with waiting customers, create a booking type, book or show appointments, review an ads recommendation, open your website, add a contact, or explain your results. Pick one of those, or use the menu for everything else.',
            ]);
        }

        $map = [
            'follow_up' => 'follow-ups', 'booking_type' => 'booking-type', 'appointment' => 'appointment',
            'upcoming' => 'upcoming', 'ads' => 'ads', 'website' => 'website', 'contact' => 'contact',
        ];

        if ($intent === 'explain') {
            if ($advisorAskUrl === null) {
                return array_merge($base, [
                    'title' => 'Results explanations are not available on this plan or for your role',
                    'message' => 'You can still see your numbers in Business performance on this page.',
                ]);
            }

            return array_merge($base, [
                'status' => 'post',
                'title' => "Explain this month's results",
                'message' => "This asks the Advisor what changed. It uses your existing figures and may use your AI allowance — it only runs when you confirm.",
                'url' => $advisorAskUrl,
                'post' => ['question' => array_key_exists('what_changed', GrowthAdvisor::QUESTIONS) ? 'what_changed' : 'what_today'],
            ]);
        }

        $action = $actions[$map[$intent]] ?? null;

        if ($action === null) {
            return array_merge($base, [
                'title' => $this->label($intent) . ' is not available to you right now',
                'message' => 'Either it is not part of your plan, your role does not include it, or it is not set up yet. Nothing was changed.',
            ]);
        }

        if ($action['drawerUrl'] !== null) {
            return array_merge($base, [
                'status' => 'drawer',
                'title' => $action['label'],
                'message' => 'Opening it here. Nothing happens until you confirm.',
                'drawer' => $action['drawer'],
                'drawerUrl' => $action['drawerUrl'],
                'url' => $action['fullUrl'],
            ]);
        }

        return array_merge($base, [
            'status' => 'link',
            'title' => $action['label'],
            'message' => 'This one is done on its own page.',
            'url' => $action['fullUrl'],
        ]);
    }
}

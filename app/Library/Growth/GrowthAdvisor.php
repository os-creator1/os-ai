<?php

declare(strict_types=1);

namespace App\Library\Growth;

use App\Library\Ai\AiGateway;
use App\Library\Ai\AiRequest;
use App\Library\Ai\Enums\AiLane;
use App\Library\Ai\Enums\AiModelRoute;
use App\Library\Ai\Enums\AiUsageCategory;
use App\Models\Business;
use App\Models\Workspace;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The Growth Advisor (Growth Center §34-37): a short, honest answer to one of
 * a CLOSED set of owner questions, built from the Growth Center's own
 * canonical truth.
 *
 * TWO LAYERS, and the first is complete on its own:
 *
 *  1. DETERMINISTIC. `compose()` builds the entire answer — lead sentence,
 *     "Today / This week / Later" plan, and every item — from the open
 *     Opportunities in their engine priority order. Each plan item IS an
 *     Opportunity (headline, evidence figures, action link); there is no
 *     free-floating to-do list. With AI off, refused, over budget or wrong,
 *     this is what the owner gets, and it is the whole product.
 *
 *  2. AI EXPLANATION (optional). The AI is handed GrowthAdvisorContext — a
 *     bounded digest, never a database — and may only (a) rewrite the lead
 *     sentence in plainer words and (b) add one short note per item, referring
 *     to items by handle. Its reply is parsed as strict JSON and rejected
 *     whole if it names an unknown item, contains markup or a link, is too
 *     long, or states any number that was not in the digest. It can never add,
 *     remove, re-rank or invent an Opportunity: detection and priority are the
 *     engine's.
 *
 * Questions are a closed list (not free text), so there is no prompt-injection
 * surface and each question maps to a deterministic composer.
 */
final class GrowthAdvisor
{
    public const QUESTIONS = [
        'what_today' => 'What should I do today?',
        'fix_first' => 'Which issue should I fix first?',
        'what_changed' => 'What changed this month?',
        'why_leads_down' => 'Why are leads down?',
        'wasting_money' => 'Where am I wasting money?',
        'five_bookings' => 'How can I get 5 more bookings?',
    ];

    private const LEAD_MAX = 280;

    private const NOTE_MAX = 180;

    public function __construct(
        private readonly AiGateway $gateway,
    ) {
    }

    /**
     * @param  array<int, array<string, mixed>>  $cards  presented open Opportunities in engine priority order
     * @param  array<int, array{kind: string, text: string}>  $changes
     * @param  array<string, mixed>|null  $movement
     * @param  array<int, string>  $positives
     * @param  array<int, string>  $unavailable
     * @return array{question: string, lead: string, sections: array<int, array{title: string, items: array<int, array<string, mixed>>}>, notes: array<int, string>, ai: string}
     */
    public function answer(
        string $questionKey,
        array $cards,
        ?array $movement,
        array $changes,
        array $positives,
        array $unavailable,
        ?array $scoreSummary,
        ?Workspace $workspace = null,
        ?Business $business = null,
        ?int $actorUserId = null,
        bool $allowAi = true,
    ): array {
        $questionKey = array_key_exists($questionKey, self::QUESTIONS) ? $questionKey : 'what_today';

        $answer = $this->compose($questionKey, $cards, $movement, $changes, $unavailable);
        $answer['ai'] = 'not_used';

        if (! $allowAi || $workspace === null || $business === null || $answer['sections'] === []) {
            return $answer;
        }

        $context = GrowthAdvisorContext::fromParts(
            $cards,
            $scoreSummary ?? ['overall' => null],
            array_map(fn (array $c) => $c['text'], $changes),
            $positives,
            $unavailable,
        );

        return $this->explain($answer, $context, $questionKey, $workspace, $business, $actorUserId);
    }

    /** @return array<int, string> */
    public function questions(): array
    {
        return self::QUESTIONS;
    }

    // ── Layer 1: deterministic ───────────────────────────────────────────

    /**
     * @param  array<int, array<string, mixed>>  $cards
     * @param  array<int, array{kind: string, text: string}>  $changes
     * @param  array<string, mixed>|null  $movement
     * @param  array<int, string>  $unavailable
     */
    public function compose(string $key, array $cards, ?array $movement, array $changes, array $unavailable): array
    {
        $q = self::QUESTIONS[$key];
        $notes = [];

        if ($unavailable !== []) {
            $notes[] = 'Not included in this answer (not available for this business yet): ' . implode(', ', $unavailable) . '.';
        }

        $base = ['question' => $q, 'notes' => $notes, 'ai' => 'not_used'];

        switch ($key) {
            case 'what_changed':
                $lines = [];

                if ($movement !== null) {
                    $lines[] = $movement['sentence'];
                }

                foreach ($changes as $c) {
                    $lines[] = $c['text'];
                }

                return $base + [
                    'lead' => $lines !== [] ? implode(' ', array_slice($lines, 0, 2)) : 'There is not enough history yet to say what changed. Check back after a couple of weeks of use.',
                    'sections' => $lines === [] ? [] : [['title' => 'What changed', 'items' => array_map(fn (string $l) => ['text' => $l], array_slice($lines, 0, 6))]],
                ];

            case 'fix_first':
                $first = $cards[0] ?? null;

                return $base + [
                    'lead' => $first === null ? "You're in good shape — nothing needs fixing first." : 'Start here: ' . $first['headline'],
                    'sections' => $first === null ? [] : [['title' => 'Fix this first', 'items' => [$this->item($first, true)]]],
                ];

            case 'why_leads_down':
                $related = $this->only($cards, ['lead_response', 'website', 'seo', 'bookings', 'forms', 'lead_generation']);
                $drop = collect($changes)->first(fn (array $c) => $c['kind'] === 'down' && str_starts_with($c['text'], 'New leads'));

                return $base + [
                    'lead' => $drop['text'] ?? ($related === [] ? 'Nothing in your account points to a problem with lead flow right now.' : 'I cannot see a measured drop in new leads, but these things are in the way of getting and keeping them:'),
                    'sections' => $related === [] ? [] : [['title' => 'What affects lead flow', 'items' => array_map(fn ($c) => $this->item($c), array_slice($related, 0, 4))]],
                ];

            case 'wasting_money':
                $money = $this->only($cards, ['sales_pipeline', 'proposals_sales', 'payments']);
                $ads = array_values(array_filter($this->only($cards, ['ads']), fn ($c) => ! in_array($c['rule_key'] ?? '', ['ads.connection_needed:v1', 'ads.sync_stale:v1'], true)));
                $adsNotConnected = array_filter($cards, fn ($c) => ($c['rule_key'] ?? '') === 'ads.connection_needed:v1') !== [];
                $sections = [];

                if ($ads !== []) {
                    $sections[] = ['title' => 'Ad budget to review', 'items' => array_map(fn ($c) => $this->item($c), array_slice($ads, 0, 5))];
                }

                if ($money !== []) {
                    $sections[] = ['title' => 'Money waiting on you', 'items' => array_map(fn ($c) => $this->item($c), array_slice($money, 0, 5))];
                }

                $adsUnavailable = $adsNotConnected || in_array('Ads', $unavailable, true);

                return $base + [
                    'lead' => match (true) {
                        $ads !== [] => 'Here is where ad budget may be going to waste, and the money I can see sitting idle:',
                        $adsUnavailable => 'Ad spend is not connected to Business OS yet, so I cannot say whether any of it is wasted. The money I can see sitting idle is:',
                        default => 'I do not see any ad budget problems right now. The money I can see sitting idle is:',
                    },
                    'sections' => $sections,
                ];

            case 'five_bookings':
                $related = $this->only($cards, ['bookings', 'lead_response', 'website']);

                return $base + [
                    'lead' => 'I cannot promise a number. Bookings come from leads who get a fast answer and a booking path that works. Here is what is currently in the way:',
                    'sections' => $related === [] ? [] : [['title' => 'In the way of more bookings', 'items' => array_map(fn ($c) => $this->item($c), array_slice($related, 0, 5))]],
                ];

            default:
                if ($cards === []) {
                    return $base + ['lead' => "You're in good shape — nothing needs your attention today.", 'sections' => []];
                }

                $today = array_slice($cards, 0, 3);
                $week = array_slice($cards, 3, 4);
                $later = array_slice($cards, 7, 4);
                $sections = [['title' => 'Today', 'items' => array_map(fn ($c) => $this->item($c), $today)]];

                if ($week !== []) {
                    $sections[] = ['title' => 'This week', 'items' => array_map(fn ($c) => $this->item($c), $week)];
                }

                if ($later !== []) {
                    $sections[] = ['title' => 'Later', 'items' => array_map(fn ($c) => $this->item($c), $later)];
                }

                return $base + [
                    'lead' => 'The most important thing today: ' . $cards[0]['headline'],
                    'sections' => $sections,
                ];
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $cards
     * @param  array<int, string>  $categories
     * @return array<int, array<string, mixed>>
     */
    private function only(array $cards, array $categories): array
    {
        return array_values(array_filter($cards, fn (array $c) => in_array($c['category'] ?? '', $categories, true)));
    }

    /** @return array<string, mixed> */
    private function item(array $card, bool $withWhy = false): array
    {
        return [
            'uid' => $card['uid'],
            'headline' => $card['headline'],
            'category' => $card['category_label'],
            'impact' => $card['impact'],
            'action_label' => $card['action_label'],
            'detail_url' => $card['detail_url'] ?? null,
            'why' => $withWhy ? ($card['why'] ?? null) : null,
            'note' => null,
        ];
    }

    // ── Layer 2: AI explanation ──────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $answer
     * @return array<string, mixed>
     */
    private function explain(array $answer, GrowthAdvisorContext $context, string $questionKey, Workspace $workspace, Business $business, ?int $actorUserId): array
    {
        if (! config('services.openai.active')) {
            $answer['ai'] = 'disabled';

            return $answer;
        }

        $handles = [];

        foreach ($context->opportunities as $handle => $card) {
            $handles[$card['uid']] = $handle;
        }

        $planned = [];

        foreach ($answer['sections'] as $section) {
            foreach ($section['items'] as $item) {
                if (isset($item['uid'], $handles[$item['uid']])) {
                    $planned[] = $handles[$item['uid']];
                }
            }
        }

        if ($planned === []) {
            return $answer;
        }

        try {
            $result = $this->gateway->complete(new AiRequest(
                workspace: $workspace,
                business: $business,
                category: AiUsageCategory::CooInteractive,
                lane: AiLane::Interactive,
                route: AiModelRoute::Routine,
                messages: $this->messages($questionKey, $context, $planned),
                maxOutputTokens: 400,
                idempotencyKey: 'growth_advisor:' . $business->id . ':' . Str::uuid()->toString(),
                actorUserId: $actorUserId,
                jsonMode: true,
            ));
        } catch (\Throwable $e) {
            Log::warning('Growth advisor AI call failed', ['business_id' => $business->id, 'exception' => $e::class]);
            $answer['ai'] = 'unavailable';

            return $answer;
        }

        if (! $result->ok) {
            $answer['ai'] = $result->refusalReason !== null ? 'refused' : 'unavailable';

            return $answer;
        }

        $parsed = $this->validate((string) $result->content, $context, $planned);

        if ($parsed === null) {
            Log::warning('Growth advisor AI output rejected', ['business_id' => $business->id]);
            $answer['ai'] = 'rejected';

            return $answer;
        }

        if ($parsed['lead'] !== null) {
            $answer['lead'] = $parsed['lead'];
        }

        foreach ($answer['sections'] as $s => $section) {
            foreach ($section['items'] as $i => $item) {
                $handle = $handles[$item['uid'] ?? ''] ?? null;

                if ($handle !== null && isset($parsed['notes'][$handle])) {
                    $answer['sections'][$s]['items'][$i]['note'] = $parsed['notes'][$handle];
                }
            }
        }

        $answer['ai'] = 'used';

        return $answer;
    }

    /**
     * @param  array<int, string>  $planned
     * @return array<int, array{role: string, content: string}>
     */
    private function messages(string $questionKey, GrowthAdvisorContext $context, array $planned): array
    {
        $system = <<<'TXT'
You explain a local business owner's Growth Center results in plain, calm language.
You are given a JSON digest of facts. Use ONLY facts in the digest. Never invent a customer, a figure, a cause or a promise.
Do not say anything is failing, urgent, or guaranteed. Do not mention any number that is not in the digest.
Reply with a single JSON object and nothing else:
{"lead": "<one or two sentences answering the question, max 280 characters>", "notes": {"<item id>": "<one short sentence on why this item matters now, max 180 characters>"}}
"notes" may only use item ids from the list provided. No markup, no links.
TXT;

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => json_encode([
                'question' => self::QUESTIONS[$questionKey],
                'item_ids_in_plan' => $planned,
                'digest' => $context->data,
            ], JSON_UNESCAPED_UNICODE)],
        ];
    }

    /**
     * Strict acceptance of the AI's reply, or null (use the deterministic answer).
     *
     * @param  array<int, string>  $planned
     * @return array{lead: string|null, notes: array<string, string>}|null
     */
    public function validate(string $content, GrowthAdvisorContext $context, array $planned): ?array
    {
        $decoded = json_decode($content, true);

        if (! is_array($decoded) || array_diff(array_keys($decoded), ['lead', 'notes']) !== []) {
            return null;
        }

        $lead = $decoded['lead'] ?? null;
        $notes = $decoded['notes'] ?? [];

        if ($lead !== null && ! $this->safeText($lead, self::LEAD_MAX, $context)) {
            return null;
        }

        if (! is_array($notes)) {
            return null;
        }

        $clean = [];

        foreach ($notes as $handle => $note) {
            if (! is_string($handle) || ! in_array($handle, $planned, true) || ! $context->hasOpportunity($handle)) {
                return null;   // an item that is not in the plan is an invented one
            }

            if (! $this->safeText($note, self::NOTE_MAX, $context)) {
                return null;
            }

            $clean[$handle] = trim($note);
        }

        return ['lead' => $lead === null ? null : trim($lead), 'notes' => $clean];
    }

    private function safeText(mixed $text, int $max, GrowthAdvisorContext $context): bool
    {
        if (! is_string($text) || trim($text) === '' || mb_strlen($text) > $max) {
            return false;
        }

        if ($text !== strip_tags($text) || preg_match('/https?:|www\.|`|\[.*\]\(/i', $text) === 1) {
            return false;
        }

        return $context->numbersAreGrounded($text);
    }
}

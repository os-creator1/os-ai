<?php

namespace App\Library\AgencyOutreach;

use App\Library\Ai\AiGateway;
use App\Library\Ai\AiModelRouter;
use App\Library\Ai\AiRequest;
use App\Library\Ai\Enums\AiLane;
use App\Library\Ai\Enums\AiUsageCategory;
use App\Models\Workspace;
use Throwable;

/**
 * Matches EVERY question in one prospect reply to the Agency's approved answers.
 *
 *   1. split    the reply into requests: line breaks, sentence ends, and an "and"/"also"
 *               that joins two questions ("...fee and do you guarantee leads?"). An "and"
 *               inside one question ("your info and website") does not split it, and a
 *               plain statement ("ok sounds good") is not a request.
 *   2. match    each request deterministically, in the prospect's order:
 *                 - an exact taught alias (OutreachFaq::aliasKey) -> that entry
 *                 - every TOPIC the request touches (the classifier's FAQ intents plus the
 *                   agency-sales topics below) -> the entry whose question/keywords/intent
 *                   is ABOUT that topic. Coverage is by topic: a monthly-fee answer never
 *                   answers ad spend, testimonials never answer "can I speak to customers".
 *                 - an owner keyword, unless the request is about topics the entry is not.
 *               A topic left over is still covered when an answer already chosen for this
 *               reply explicitly addresses it ("12-month contract, cancel with 30 days'
 *               notice" covers contract AND cancellation) -- rendered once.
 *   3. resolve  whatever is still unmatched, with AT MOST ONE bounded gateway call for the
 *               whole inbound (same idempotency key as before). The model only names an
 *               approved FAQ id per question or null; it never writes customer text. Ids are
 *               validated against the entries applicable to this campaign/niche. On AI-off,
 *               failure, malformed output or an unknown id the deterministic matches stand
 *               and the rest stays unresolved -- never guessed.
 *
 * Unresolved requests are what the owner sees in Outreach -> Unanswered questions.
 */
final class OutreachQuestionMatcher
{
    public const MAX_REQUESTS = 12;

    public const MAX_MATCH_OUTPUT_TOKENS = 200;

    /** Pseudo entry id for the field-less "who is this" answer. */
    public const NAME_ANSWER = 'builtin-name';

    /** Agency-sales topics the classifier's single-winner intents do not separate. */
    private const TOPICS = [
        'ad_spend' => '/\b((ad|ads|advertising|marketing|media) (spend|budget|budgets|costs?)|budget (for|on) (the )?(ads|advertising|marketing)|spend on (the )?(ads|advertising|marketing)|how much (should|do|would|will) (i|we) (need to )?(spend|budget|put))\b/u',
        'guarantee' => '/\b(guarantee|guarantees|guaranteed|guaranty|promise (any )?(leads|results)|what if (i|we) (dont|do not) get (any )?(leads|results))\b/u',
        'references' => '/\b((speak|talk|chat) (with|to) (some|a few|a couple|one|two|three|\d+)? ?(of )?(your )?(customers|clients|references)|references|reference (customers|clients)|contact (some|a few|\d+)? ?(of )?your (customers|clients))\b/u',
        'clients' => '/\b((existing|current|other|past) (clients|customers)|who (do you|have you|else do you) (work|worked) with|clients like me|how many (clients|customers)|examples of (your )?(clients|work))\b/u',
        'lead_ownership' => '/\b(own the leads|own my leads|own (the )?leads|leads (are|be|stay) mine|who owns (the |my )?leads|ownership of (the |my )?leads|lead ownership|keep (the |my )?leads|leads belong)\b/u',
        'contract' => '/\b(contract|contracts|lock in|locked in|commitment|minimum term|term length|how long (do i|do we|is the|am i) (have to|need to|sign|stay|locked|committed|commit))\b/u',
        'cancel' => '/\b(cancel|cancellation|cancelling|canceling|exit clause)\b/u',
        'human_ai' => '/\b(human|real person|bot|robot|automated|an ai|ai or|or ai|is this ai|are you ai|chatbot)\b/u',
    ];

    /** When a request is about ad spend, only these words still make it a question about OUR price. */
    private const OWN_PRICE = '/\b(fee|fees|monthly|retainer|you charge|your (price|pricing|rate|rates|cost|costs)|pay you)\b/u';

    private const REQUEST_OPENER = '/^(what|whats|how|hows|who|whos|where|when|why|which|do|does|did|is|are|can|could|will|would|should|id like|i would like|i want to (know|see|understand|hear)|id love|i would love|i need to know|need to know|tell me|send me|let me know|please (send|share|tell|explain|let)|wondering|curious|whats the)\b/u';

    private const LEADING_FILLER = '/^((ok|okay|sure|yes|yeah|so|also|and|but|plus|hey|hi|hello|thanks|thank you|great|cool|before we do that|before that|first)\s+)+/u';

    /** A question joined to the next one by "and"/"also"/"plus" — split only when a new question starts. */
    private const JOINER = '/\s*,?\s+(?:and|also|plus|&)\s+(?=(?:what|what\'s|whats|how|who|where|when|why|which|do|does|did|is|are|can|could|will|would|should|i\'d like|id like|i would like|i want|tell me|send me|please)\b)/iu';

    private const DEFERRAL = '/\b(before (we|i) (do that|do this|book|schedule|hop on|jump on|get on|set (that|this|anything) up|talk|chat|go any further|commit)|before (that|then|anything else|booking|scheduling|a call)|not ready (to|yet|for)|hold off)\b/u';

    public function __construct(
        private readonly AiGateway $gateway,
        private readonly AiModelRouter $router,
    ) {
    }

    /**
     * The requests in a reply, in order. A request is a question or an explicit ask
     * ("I'd like a breakdown of ..."); anything else is dropped.
     *
     * @return list<string>
     */
    public static function requests(string $body): array
    {
        $pieces = preg_split('/(?<=[?!])\s+|(?<=\.)\s+(?=[A-Z])|\R+|;\s*/u', trim($body)) ?: [];
        $out = [];

        foreach ($pieces as $piece) {
            foreach (preg_split(self::JOINER, $piece) ?: [] as $part) {
                $part = trim($part, " \t,");

                if ($part !== '' && self::isRequest($part)) {
                    $out[] = $part;
                }

                if (count($out) >= self::MAX_REQUESTS) {
                    return $out;
                }
            }
        }

        return $out;
    }

    public static function isRequest(string $text): bool
    {
        $norm = preg_replace(self::LEADING_FILLER, '', OutreachIntentClassifier::normalise($text)) ?? '';

        if ($norm === '') {
            return false;
        }

        // "ok?" or "really?" is a reaction, not a question to answer; "price?" names a topic.
        if (str_contains($text, '?')) {
            return substr_count($norm, ' ') >= 2 || self::topicsIn($norm, $text) !== [];
        }

        return preg_match(self::REQUEST_OPENER, $norm) === 1;
    }

    /** "Before we do that, ..." — the prospect wants answers before the booking pitch. */
    public static function defersBooking(string $body): bool
    {
        return preg_match(self::DEFERRAL, OutreachIntentClassifier::normalise($body)) === 1;
    }

    /** Deterministic matching only: never a model call. */
    public function match(OutreachFaq $faq, string $body, ?int $campaignId): OutreachAnswerPlan
    {
        $entries = $faq->applicable($campaignId);
        $answers = [];   // entry id => order
        $unresolved = [];
        $requests = self::requests($body);

        foreach ($requests as $index => $request) {
            $missing = false;
            $found = $this->matchRequest($request, $entries, $answers, $missing);

            foreach ($found as $id => $offset) {
                $answers[$id] ??= $index * 10_000 + $offset;
            }

            if ($missing) {
                $unresolved[$index] = $request;
            }
        }

        return new OutreachAnswerPlan($requests, $answers, $unresolved, false, false);
    }

    /**
     * Resolve what deterministic matching left, with ONE bounded gateway call for the whole
     * inbound. Anything the model cannot place against an approved, in-scope id stays unresolved.
     */
    public function resolveWithAi(OutreachAnswerPlan $plan, OutreachFaq $faq, Workspace $workspace, string $body, ?int $campaignId, int $inboundMessageId): OutreachAnswerPlan
    {
        $entries = array_values(array_filter($faq->applicable($campaignId), static fn (array $e): bool => $e['id'] !== self::NAME_ANSWER));

        if ($plan->unresolved === [] || $entries === []) {
            return $plan;
        }

        $numbered = array_values($plan->unresolved);
        $keys = array_keys($plan->unresolved);
        $choices = $this->askModel($workspace, $body, $numbered, $entries, $inboundMessageId);

        if ($choices === null) {
            return new OutreachAnswerPlan($plan->requests, $plan->answers, $plan->unresolved, false, true);
        }

        $answers = $plan->answers;
        $unresolved = $plan->unresolved;
        $used = false;

        foreach ($choices as $n => $id) {
            $entry = isset($numbered[$n]) ? $faq->applicableById($id, $campaignId) : null;

            // Only an approved, in-scope id -- and never one about a different topic than the
            // question names (testimonials for "can I speak with your customers").
            if ($entry === null || ! $this->covers($entry, $numbered[$n])) {
                continue;
            }

            $answers[$id] ??= $keys[$n] * 10_000 + 9_999;
            unset($unresolved[$keys[$n]]);
            $used = true;
        }

        return new OutreachAnswerPlan($plan->requests, $answers, $unresolved, $used, true);
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @param  array<string, int>  $chosen  entries already chosen for earlier requests
     * @return array<string, int> entry id => offset of what it answers in this request
     */
    private function matchRequest(string $request, array $entries, array $chosen, bool &$missing): array
    {
        $norm = OutreachIntentClassifier::normalise($request);
        $key = OutreachFaq::aliasKey($request);

        foreach ($entries as $entry) {
            if (in_array($key, $entry['aliases'] ?? [], true)) {
                return [$entry['id'] => 0];
            }
        }

        $topics = self::topicsIn($norm, $request);
        $found = [];

        foreach ($topics as $topic => $offset) {
            $entry = $this->entryAbout($topic, $entries);

            if ($entry !== null) {
                $found[$entry['id']] ??= $offset;
                continue;
            }

            if ($topic === OutreachIntentClassifier::NAME) {
                $found[self::NAME_ANSWER] ??= $offset;
                continue;
            }

            // Covered after all if an answer already chosen for this reply addresses it explicitly.
            if ($this->addressedBy($topic, array_keys($found + $chosen), $entries)) {
                continue;
            }

            $missing = true;
        }

        foreach ($entries as $entry) {
            $about = $this->topicsOf($entry);

            // An owner keyword matches unless the request is about topics this entry is not about.
            if (isset($found[$entry['id']]) || ($topics !== [] && array_intersect_key($topics, $about) === [])) {
                continue;
            }

            foreach ($entry['keywords'] as $keyword) {
                $at = $keyword === '' ? false : mb_strpos($norm, OutreachIntentClassifier::normalise($keyword));

                if ($at !== false) {
                    $found[$entry['id']] = $at;
                    break;
                }
            }
        }

        if ($topics === [] && $found === []) {
            $missing = true;
        }

        asort($found);

        return $found;
    }

    /** @return array<string, int> topic => offset, in the order written */
    private static function topicsIn(string $norm, string $raw): array
    {
        $topics = OutreachIntentClassifier::matchingIntents($raw);

        foreach (self::TOPICS as $topic => $pattern) {
            if (preg_match($pattern, $norm, $m, PREG_OFFSET_CAPTURE) === 1) {
                $topics[$topic] = (int) $m[0][1];
            }
        }

        // "How much should I budget for ads" is about ad spend, not our price.
        if (isset($topics['ad_spend'], $topics[OutreachIntentClassifier::PRICING]) && preg_match(self::OWN_PRICE, $norm) !== 1) {
            unset($topics[OutreachIntentClassifier::PRICING]);
        }

        // "Can I speak with three of your customers" is a reference request, not a client list.
        if (isset($topics['references'])) {
            unset($topics['clients']);
        }

        // The broad intents are last resorts, as in classify(): "is the fee per event?" is a pricing
        // question, not the event clarification, and "can you explain the contract" is not "what do you do".
        if (count($topics) > 1) {
            unset($topics[OutreachIntentClassifier::CLARIFY]);
        }

        if (isset($topics[OutreachIntentClassifier::WHAT_WE_DO]) && array_diff_key($topics, array_flip([
            OutreachIntentClassifier::WHAT_WE_DO, OutreachIntentClassifier::WEBSITE, OutreachIntentClassifier::LOCATION, OutreachIntentClassifier::NAME,
        ])) !== []) {
            unset($topics[OutreachIntentClassifier::WHAT_WE_DO]);
        }

        asort($topics);

        return $topics;
    }

    /** The first entry whose own question, keywords or intent are ABOUT this topic. */
    private function entryAbout(string $topic, array $entries): ?array
    {
        foreach ($entries as $entry) {
            if (isset($this->topicsOf($entry)[$topic])) {
                return $entry;
            }
        }

        return null;
    }

    /** @return array<string, true> the topics an entry is primarily about (question, keywords, intent -- not the answer) */
    private function topicsOf(array $entry): array
    {
        $about = [];

        if ($entry['intent'] !== null) {
            $about[$entry['intent']] = true;
        }

        $text = trim($entry['question'] . ' ' . implode(' , ', $entry['keywords']));

        if ($text === '') {
            return $about;
        }

        foreach (array_keys(self::topicsIn(OutreachIntentClassifier::normalise($text), $text . '?')) as $topic) {
            $about[$topic] = true;
        }

        return $about;
    }

    /** Whether an entry is about (or its answer explicitly addresses) a topic the request names; any entry for a topic-less request. */
    private function covers(array $entry, string $request): bool
    {
        $topics = self::topicsIn(OutreachIntentClassifier::normalise($request), $request);

        if ($topics === [] || array_intersect_key($topics, $this->topicsOf($entry)) !== []) {
            return true;
        }

        foreach (array_keys($topics) as $topic) {
            if ($this->addressedBy($topic, [$entry['id']], [$entry])) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $ids */
    private function addressedBy(string $topic, array $ids, array $entries): bool
    {
        $pattern = self::TOPICS[$topic] ?? OutreachIntentClassifier::pattern($topic);

        if ($pattern === null) {
            return false;
        }

        foreach ($entries as $entry) {
            if (in_array($entry['id'], $ids, true) && preg_match($pattern, OutreachIntentClassifier::normalise($entry['answer'])) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The one bounded call. Returns question number => approved id, or null when the model
     * could not be asked or answered with anything but the exact JSON shape.
     *
     * @param  list<string>  $questions
     * @param  list<array<string, mixed>>  $entries
     * @return array<int, string>|null
     */
    private function askModel(Workspace $workspace, string $body, array $questions, array $entries, int $inboundMessageId): ?array
    {
        $catalog = array_map(static fn (array $e): array => [
            'id' => $e['id'],
            'question' => $e['question'],
            'answer' => mb_substr($e['answer'], 0, 400),
        ], $entries);

        $system = 'You match questions from a business owner to an agency\'s APPROVED answers. You never write answers yourself. '
            . 'For each numbered question pick the id of the ONE approved answer whose wording directly answers that exact question, or null. '
            . 'Choose null unless the answer really covers what is asked: a monthly fee answer does not answer ad spend, testimonials do not answer a request to speak with customers. '
            . 'Reply with JSON only, exactly: {"matches":[{"n":1,"id":"<approved id or null>"}]}';

        $user = "Message:\n" . mb_substr($body, 0, 1500)
            . "\n\nQuestions:\n" . implode("\n", array_map(static fn (string $q, int $i): string => ($i + 1) . '. ' . mb_substr($q, 0, 300), $questions, array_keys($questions)))
            . "\n\nApproved answers:\n" . json_encode($catalog, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        try {
            $category = AiUsageCategory::AgencyProspectReply;
            $route = $this->router->defaultRouteFor($category);
            $routeConfig = $this->router->config($route);

            $result = $this->gateway->complete(AiRequest::forWorkspace(
                workspace: $workspace,
                business: null,
                category: $category,
                lane: AiLane::Product,
                route: $route,
                messages: [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
                maxOutputTokens: min(self::MAX_MATCH_OUTPUT_TOKENS, (int) $routeConfig['max_output_tokens']),
                idempotencyKey: 'agency_prospect_reply:' . $inboundMessageId,
                actorUserId: null,
            ));
        } catch (Throwable) {
            return null;
        }

        return $result->ok ? self::parseChoices((string) $result->content, count($questions)) : null;
    }

    /**
     * Strictly parse {"matches":[{"n":1,"id":"x"|null}]}; anything else is null. Only the
     * shape is checked here -- whether an id is approved and in scope is the caller's check.
     *
     * @return array<int, string>|null zero-based question index => id
     */
    public static function parseChoices(string $raw, int $questionCount): ?array
    {
        $raw = trim($raw);
        $raw = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $raw) ?? $raw;
        $data = json_decode($raw, true);

        if (! is_array($data) || ! isset($data['matches']) || ! is_array($data['matches'])) {
            return null;
        }

        $out = [];

        foreach ($data['matches'] as $row) {
            if (! is_array($row) || ! isset($row['n']) || ! is_int($row['n'])) {
                return null;
            }

            $n = $row['n'] - 1;
            $id = $row['id'] ?? null;

            if ($n < 0 || $n >= $questionCount) {
                continue;
            }

            if (is_string($id) && $id !== '' && $id !== 'null') {
                $out[$n] = $id;
            }
        }

        return $out;
    }
}

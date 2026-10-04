<?php

namespace App\Library\AgencyOutreach;

use App\Library\AgencyProspecting\AgencyProspectUrlPolicy;
use App\Library\Ai\AiGateway;
use App\Library\Ai\AiModelRouter;
use App\Library\Ai\AiRequest;
use App\Library\Ai\Enums\AiLane;
use App\Library\Ai\Enums\AiUsageCategory;
use App\Models\AgencyProspect;
use App\Models\AgencyProspectMessage;
use App\Models\Workspace;
use Throwable;

/**
 * Builds the text of one reply (contract §5): the answer to the prospect's question
 * first, then the EXACT current-stage message from the Agency's script.
 *
 *   known FAQ        the Agency's own field, rendered. No model call, ever.
 *   other question   ONE bounded gateway call (when the Agency has AI on): the last <= 8
 *                    messages, <= 80 output tokens, must return one short sentence using
 *                    only the supplied Agency facts. URLs in the model's text are stripped.
 *   no question      the stage message alone.
 *
 * THE MODEL CAN NEVER REPLACE THE SCRIPT. The final text must contain the exact stage
 * message; a refusal, failure, empty / over-long / multi-line answer or any doubt falls
 * back to the stage message alone. For a stage with no scripted message (booking) a
 * failed answer means there is nothing to send, never an invented reply.
 *
 * The duplicate guard is here too: a reply identical to the last outbound message is
 * not produced (the link repeat is the one deliberate exception — it repeats text by design).
 */
final class OutreachReplyComposer
{
    /** Hard bounds of the one model call (contract §5.3). */
    public const MAX_OUTPUT_TOKENS = 80;

    public const MAX_HISTORY_MESSAGES = 8;

    /** An answer sentence longer than this is a rambling model, not a sentence. */
    public const MAX_ANSWER_CHARS = 220;

    private const FAQ_FIELDS = [
        OutreachIntentClassifier::PRICING => 'pricing_answer',
        OutreachIntentClassifier::LOCATION => 'location_answer',
        OutreachIntentClassifier::FOUND_YOU => 'found_you_answer',
        OutreachIntentClassifier::WEBSITE => 'website_answer',
        OutreachIntentClassifier::WHAT_WE_DO => 'what_we_do_answer',
        OutreachIntentClassifier::CLARIFY => 'clarify_answer',
    ];

    public function __construct(
        private readonly AiGateway $gateway,
        private readonly AiModelRouter $router,
    ) {
    }

    /**
     * @param  string|null  $lastOutboundBody  the last text sent to this prospect, for the duplicate guard
     */
    public function compose(
        Workspace $workspace,
        AgencyProspect $prospect,
        OutreachDecision $decision,
        string $intent,
        int $inboundMessageId,
        ?string $lastOutboundBody = null,
    ): OutreachComposedReply {
        $script = OutreachScript::forWorkspace($workspace);

        $stageText = $decision->messageNumber === null
            ? null
            : OutreachScriptRenderer::render($script->get('message_' . $decision->messageNumber), $workspace, $prospect);

        if ($stageText === '') {
            $stageText = null;
        }

        $answer = null;
        $source = AgencyProspectMessage::SOURCE_DETERMINISTIC;

        if ($decision->answerFirst) {
            [$answer, $usedAi] = $this->answer($workspace, $prospect, $script, $intent, $inboundMessageId);

            if ($usedAi && $answer !== null) {
                $source = AgencyProspectMessage::SOURCE_AI;
            }
        }

        $text = $this->join($answer, $stageText);

        // The model may only ADD a sentence in front of the exact stage message; if the
        // result somehow lost it, the stage message alone is what goes out.
        if ($stageText !== null && ! str_contains((string) $text, $stageText)) {
            $text = $stageText;
            $source = AgencyProspectMessage::SOURCE_DETERMINISTIC;
        }

        if ($text === null || trim($text) === '') {
            return OutreachComposedReply::nothing($intent, 'no_reply_text');
        }

        if ($decision->action !== OutreachDecision::RESEND_LINK
            && $lastOutboundBody !== null
            && self::same($text, $lastOutboundBody)) {
            return OutreachComposedReply::nothing($intent, 'duplicate_of_last_outbound');
        }

        return new OutreachComposedReply($text, $source, $intent);
    }

    /**
     * @return array{0: ?string, 1: bool} the answer sentence and whether a model produced it
     */
    private function answer(Workspace $workspace, AgencyProspect $prospect, OutreachScript $script, string $intent, int $inboundMessageId): array
    {
        if ($intent === OutreachIntentClassifier::NAME) {
            return [OutreachScriptRenderer::render("We're {{agency.name}}.", $workspace, $prospect) ?: null, false];
        }

        if (isset(self::FAQ_FIELDS[$intent])) {
            $rendered = OutreachScriptRenderer::render($script->get(self::FAQ_FIELDS[$intent]), $workspace, $prospect);

            return [$rendered === '' ? null : $rendered, false];
        }

        if ($intent === OutreachIntentClassifier::QUESTION_OTHER && $script->aiEnabled()) {
            $sentence = $this->askModel($workspace, $prospect, $script, $inboundMessageId);

            return [$sentence, $sentence !== null];
        }

        return [null, false];
    }

    /**
     * The one bounded model call. Any failure — refusal, provider error, an exception, an
     * unusable answer — is null, and the caller falls back to the script.
     */
    private function askModel(Workspace $workspace, AgencyProspect $prospect, OutreachScript $script, int $inboundMessageId): ?string
    {
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
                messages: $this->messages($workspace, $prospect, $script, $inboundMessageId),
                maxOutputTokens: min(self::MAX_OUTPUT_TOKENS, (int) $routeConfig['max_output_tokens']),
                idempotencyKey: 'agency_prospect_reply:' . $inboundMessageId,
                actorUserId: null,
            ));
        } catch (Throwable) {
            return null;
        }

        if (! $result->ok) {
            return null;
        }

        return self::usableSentence((string) $result->content);
    }

    /**
     * One short, single-line, URL-free sentence — or null.
     */
    public static function usableSentence(string $raw): ?string
    {
        $text = trim(preg_replace('/\s+/', ' ', $raw) ?? '');
        $text = trim($text, "\"' ");

        // Empty, multi-line, token-bearing, or structured output (JSON / code fences) is a model
        // misbehaving, not an answer.
        if ($text === ''
            || str_contains($text, '{{')
            || str_contains(trim($raw), "\n")
            || str_contains($text, '```')
            || in_array($text[0], ['{', '[', '<'], true)) {
            return null;
        }

        $text = AgencyProspectUrlPolicy::sanitizeAiText($text);

        if ($text === '' || mb_strlen($text) > self::MAX_ANSWER_CHARS) {
            return null;
        }

        return $text;
    }

    /**
     * System facts + at most the last MAX_HISTORY_MESSAGES ledger messages.
     *
     * @return list<array{role: string, content: string}>
     */
    private function messages(Workspace $workspace, AgencyProspect $prospect, OutreachScript $script, int $inboundMessageId): array
    {
        $settings = $script->settings();
        $render = static fn (string $t): string => OutreachScriptRenderer::render($t, $workspace, $prospect);

        $facts = [
            'Agency name: ' . ($script->agencyName() ?: 'not given'),
            'What the agency offers: ' . (trim((string) $settings?->offer) ?: 'not given'),
            'Who it is for: ' . (trim((string) $settings?->niche) ?: 'not given'),
            'Pricing answer: ' . ($render($script->get('pricing_answer')) ?: 'not given'),
            'Location answer: ' . ($render($script->get('location_answer')) ?: 'not given'),
            'How we found them: ' . ($render($script->get('found_you_answer')) ?: 'not given'),
            'What we do: ' . ($render($script->get('what_we_do_answer')) ?: 'not given'),
            'Website: ' . ($script->settings()?->website_url ?: 'not given'),
            'Booking link: ' . ($script->calendarUrl() ?: 'not given'),
        ];

        $system = 'You answer ONE question from a business owner who replied to a text from an agency. '
            . 'Reply with exactly ONE short sentence, plain text, no links, no markdown, under 200 characters. '
            . 'Use ONLY the facts below. If the answer is not in the facts, reply with one sentence saying it is best covered on a quick call. '
            . "Never invent prices, places, names or promises.\n\nFacts:\n" . implode("\n", $facts);

        $history = AgencyProspectMessage::query()
            ->where('campaign_member_id', function ($q) use ($inboundMessageId): void {
                $q->select('campaign_member_id')->from('agency_prospect_messages')->where('id', $inboundMessageId);
            })
            ->whereIn('direction', [AgencyProspectMessage::DIRECTION_INBOUND, AgencyProspectMessage::DIRECTION_OUTBOUND])
            ->where('id', '<=', $inboundMessageId)
            ->whereIn('status', [AgencyProspectMessage::STATUS_SENT, AgencyProspectMessage::STATUS_RECEIVED, 'handled'])
            ->orderByDesc('id')
            ->limit(self::MAX_HISTORY_MESSAGES)
            ->get()
            ->reverse()
            ->map(fn (AgencyProspectMessage $m): array => [
                'role' => $m->direction === AgencyProspectMessage::DIRECTION_INBOUND ? 'user' : 'assistant',
                'content' => mb_substr((string) $m->body, 0, 400),
            ])
            ->values()
            ->all();

        return array_merge([['role' => 'system', 'content' => $system]], $history);
    }

    private function join(?string $answer, ?string $stageText): ?string
    {
        $answer = $answer === null ? null : trim($answer);

        if ($answer === null || $answer === '') {
            return $stageText;
        }

        return $stageText === null ? $answer : $answer . ' ' . $stageText;
    }

    private static function same(string $a, string $b): bool
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $a) ?? $a)) === mb_strtolower(trim(preg_replace('/\s+/', ' ', $b) ?? $b));
    }
}

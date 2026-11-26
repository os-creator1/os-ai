<?php

namespace App\Library\AgencyOutreach;

use App\Models\AgencyProspect;
use App\Models\AgencyProspectMessage;
use App\Models\Workspace;

/**
 * Builds the text of one reply (contract §5): the Agency's approved answers to EVERY
 * question the prospect asked, in the prospect's order, then the EXACT current-stage
 * message from the Agency's script.
 *
 *   answers     OutreachQuestionMatcher's plan: approved entries only, each rendered through
 *               the token renderer, each at most once. A model may have SELECTED an entry;
 *               no model ever writes customer-facing text.
 *   stacked     two or more answers go on their own lines, the stage message after a blank line.
 *   duplicates  an identical answer, or a sentence repeating a URL already in the reply
 *               (including the stage message's own calendar link), is not repeated.
 *   deferral    "Before we do that, can I get ..." answers the questions and holds the booking
 *               pitch: the step is NOT sent, so it stays pending for the next reply.
 *   unanswered  a reply whose questions none of the approved answers covers never gets a
 *               booking pitch as if it were the answer: a pitch step waits (the questions go to
 *               the Unanswered queue); a non-pitch step continues; "handoff" sends nothing.
 *
 * The duplicate guard is here too: a reply identical to the last outbound message is
 * not produced (the link repeat is the one deliberate exception — it repeats text by design).
 */
final class OutreachReplyComposer
{
    /** A step that asks for the call / carries the calendar link. */
    private const PITCH = '/\b(quick call|a call|on a call|hop on|jump on|book a|book your|schedule a|grab a time|set up a (call|time|meeting))\b/iu';

    public function __construct(private readonly OutreachQuestionMatcher $matcher)
    {
    }

    /**
     * @param  string|null  $lastOutboundBody  the last text sent to this prospect, for the duplicate guard
     * @param  array<int, string>  $deliveredStepIds  steps this prospect already has (OutreachSequence::deliveredByContent), for the step guard
     * @param  OutreachAnswerPlan|null  $plan  the matcher's plan for this inbound (deterministic matching when omitted)
     */
    public function compose(
        Workspace $workspace,
        AgencyProspect $prospect,
        OutreachDecision $decision,
        string $intent,
        int $inboundMessageId,
        ?string $lastOutboundBody = null,
        array $deliveredStepIds = [],
        ?OutreachAnswerPlan $plan = null,
    ): OutreachComposedReply {
        $script = OutreachScript::forWorkspace($workspace);
        $faq = OutreachFaq::forWorkspace($workspace);

        $inbound = AgencyProspectMessage::query()->with('campaignMember')->find($inboundMessageId);
        $campaignId = $inbound?->campaignMember?->campaign_id === null ? null : (int) $inbound->campaignMember->campaign_id;
        $inboundBody = (string) $inbound?->body;
        $plan ??= $this->matcher->match($faq, $inboundBody, $campaignId);

        $stageTemplate = null;

        if ($decision->stepId !== null) {
            $stageTemplate = OutreachScriptTokens::canonicalise((string) (OutreachSequence::forWorkspace($workspace)->find($decision->stepId)['body'] ?? ''));
        } elseif ($decision->messageNumber !== null) {
            $stageTemplate = OutreachScriptTokens::canonicalise($script->get('message_' . $decision->messageNumber));
        }

        $stageText = $stageTemplate === null ? null : OutreachScriptRenderer::render($stageTemplate, $workspace, $prospect);

        if ($stageText === '') {
            $stageText = null;
        }

        $answers = $decision->answerFirst ? $this->answers($workspace, $prospect, $faq, $plan, $campaignId) : [];
        $unanswered = $plan->unansweredQuestions();
        $pitch = $stageTemplate !== null
            && $decision->action !== OutreachDecision::RESEND_LINK
            && (OutreachSequence::stepHasCalendarLink(['body' => $stageTemplate]) || preg_match(self::PITCH, $stageTemplate) === 1);
        $deferred = false;

        if ($answers === [] && $unanswered !== [] && $decision->answerFirst) {
            // Nothing approved covers what was asked: never guess, never pitch as if that answered it.
            if ($faq->fallback() === OutreachFaq::FALLBACK_HANDOFF) {
                return OutreachComposedReply::nothing($intent, 'needs_human', $unanswered);
            }

            if ($pitch) {
                return OutreachComposedReply::nothing($intent, 'question_unanswered', $unanswered, stepDeferred: true);
            }
        }

        if ($answers !== [] && $pitch && OutreachQuestionMatcher::defersBooking($inboundBody)) {
            $stageText = null;
            $deferred = true;
        }

        $answers = self::withoutRepeatedUrls($answers, $stageText);
        $text = $this->join($answers, $stageText);

        if ($text === null || trim($text) === '') {
            return OutreachComposedReply::nothing($intent, $unanswered !== [] ? 'question_unanswered' : 'no_reply_text', $unanswered, $deferred);
        }

        if ($decision->action !== OutreachDecision::RESEND_LINK
            && $lastOutboundBody !== null
            && self::same($text, $lastOutboundBody)) {
            return OutreachComposedReply::nothing($intent, 'duplicate_of_last_outbound', $unanswered);
        }

        // Backstop: THIS step must not go out twice, however the answer in front of it makes the
        // text differ (a person may have sent it by hand). It asks the same one-delivery-per-step
        // question selection does, so a repeated wording later in the sequence (A, B, A) is a
        // different step and still sends, while a copy already delivered never satisfies two steps.
        if (! $deferred
            && $decision->action !== OutreachDecision::RESEND_LINK
            && $decision->stepId !== null
            && in_array($decision->stepId, $deliveredStepIds, true)) {
            return OutreachComposedReply::nothing($intent, 'step_already_delivered', $unanswered);
        }

        $source = $plan->usedAi && $answers !== [] ? AgencyProspectMessage::SOURCE_AI : AgencyProspectMessage::SOURCE_DETERMINISTIC;

        return new OutreachComposedReply($text, $source, $intent, '', $unanswered, $deferred);
    }

    /**
     * The approved answers, rendered, in the prospect's order, each distinct text once.
     *
     * @return list<string>
     */
    private function answers(Workspace $workspace, AgencyProspect $prospect, OutreachFaq $faq, OutreachAnswerPlan $plan, ?int $campaignId): array
    {
        $out = [];

        foreach ($plan->orderedAnswerIds() as $id) {
            if ($id === OutreachQuestionMatcher::NAME_ANSWER) {
                $text = OutreachScriptRenderer::render("We're {{agency.name}}.", $workspace, $prospect);
            } else {
                // Only an approved entry that applies to this campaign/niche is ever rendered.
                $entry = $faq->applicableById($id, $campaignId);
                $text = $entry === null ? '' : OutreachScriptRenderer::render(OutreachScriptTokens::canonicalise((string) $entry['answer']), $workspace, $prospect);
            }

            $text = trim($text);

            if ($text !== '' && ! in_array(self::key($text), array_map([self::class, 'key'], $out), true)) {
                $out[] = $text;
            }
        }

        return $out;
    }

    /**
     * A sentence that repeats a URL already in the reply is dropped (the stage message keeps its
     * own link; an earlier answer keeps a URL a later one repeats). Different URLs all stay.
     *
     * @param  list<string>  $answers
     * @return list<string>
     */
    public static function withoutRepeatedUrls(array $answers, ?string $stageText): array
    {
        $seen = $stageText === null ? [] : self::urls($stageText);
        $out = [];

        foreach ($answers as $answer) {
            $kept = [];
            foreach (preg_split('/(?<=[.!?])\s+/u', $answer) ?: [] as $sentence) {
                $removed = false;
                $clean = preg_replace_callback('~(?:https?://|www\\.)[^\\s<>"\']+~iu', static function (array $match) use (&$seen, &$removed): string {
                    $url = rtrim($match[0], '.,;:!?)');
                    if (in_array($url, $seen, true)) {
                        $removed = true;
                        return substr($match[0], strlen($url));
                    }
                    $seen[] = $url;
                    return $match[0];
                }, $sentence) ?? $sentence;
                $clean = trim($clean);
                // A link-only instruction adds nothing once its link is already in the reply.
                if ($removed && preg_match('/^(?:[^:]{0,20}:\s*)?(?:(?:our |the )?(?:web ?site|link|details)(?: is| here)?|(?:you can )?book (?:a )?(?:quick )?call here)?[\s:.,!]*$/iu', $clean)) {
                    continue;
                }
                if ($clean !== '') {
                    $kept[] = $clean;
                }
            }
            $text = implode(' ', $kept);

            if ($text !== '') {
                $out[] = $text;
            }
        }

        return $out;
    }

    /** @return list<string> */
    private static function urls(string $text): array
    {
        preg_match_all('~(?:https?://|www\.)[^\s<>"\']+~iu', $text, $m);

        return array_map(static fn (string $u): string => rtrim($u, '.,;:!?)'), $m[0]);
    }

    /** @param list<string> $answers */
    private function join(array $answers, ?string $stageText): ?string
    {
        if ($answers === []) {
            return $stageText;
        }

        if (count($answers) === 1) {
            return $stageText === null ? $answers[0] : $answers[0] . ' ' . $stageText;
        }

        $block = implode("\n", $answers);

        return $stageText === null ? $block : $block . "\n\n" . $stageText;
    }

    private static function key(string $text): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $text) ?? $text));
    }

    private static function same(string $a, string $b): bool
    {
        return self::key($a) === self::key($b);
    }
}

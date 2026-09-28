<?php

namespace App\Library\Coo\Insight;

/**
 * Contract §8.3/§8.4 — the one prompt a COO insight is written from.
 *
 * The user message is nothing but CooInsightFacts::forPrompt(): aggregate
 * counts, classifications, Attention and Opportunity types, registry evidence
 * wording and status words for ONE Business (§15.2). No Business name, no
 * contact, no message text — the facts object has nowhere to put one.
 *
 * The rules the model is given are the same rules CooInsightOutputValidator
 * then enforces, so a model that follows them is accepted and one that does
 * not is discarded, never repaired.
 */
final class CooInsightPromptBuilder
{
    /**
     * @param  string|null  $explains  Contract 19 §12 19.C — the fact_ref of
     *   the deterministic move NextBestMoveSelector already picked. Null for
     *   a PerformanceDiagnosis insight. Naming it steers the model toward
     *   explaining THAT move; it does not relax grounding — fact_refs is
     *   still validated against $facts->factRefs() exactly as always, and
     *   the deterministic pick itself never comes from this prompt or its
     *   answer (R-1).
     * @return array<int, array{role: string, content: string}>
     */
    public function messages(CooInsightFacts $facts, ?string $explains = null): array
    {
        $payload = $facts->forPrompt();

        if ($explains !== null) {
            $payload['explains'] = $explains;
        }

        return [
            ['role' => 'system', 'content' => $this->instructions($explains)],
            ['role' => 'user', 'content' => CooInsightFacts::canonicalJson($payload)],
        ];
    }

    private function instructions(?string $explains): string
    {
        $task = $explains === null
            ? 'You summarise the performance facts of one local business for its owner.'
            : 'A local business\'s software has already chosen its one recommended next action, named by the user message\'s "explains" field. You explain, to the business owner, why that action makes sense right now. You do not recommend, choose or suggest a different action — only explain the one already chosen.';

        return implode("\n", [
            $task,
            'Use only the facts in the user message. Do not add numbers, events, names or advice that are not in them.',
            'Reply with one JSON object and nothing else, exactly this shape:',
            '{"statements":[{"class":"known","text":"...","fact_refs":["metric.new_contacts"]}]}',
            'Rules:',
            '- 1 to ' . CooInsightOutputValidator::MAX_STATEMENTS . ' statements, each at most ' . CooInsightOutputValidator::MAX_TEXT_LENGTH . ' characters.',
            '- "class" is "known", "likely" or "unknown".',
            '- "fact_refs" lists only "ref" values from the facts.',
            '- A "known" statement cites at least one metric fact and states its number.',
            '- A "likely" statement uses hedged wording: may, likely or could.',
            '- Never claim a cause. Do not write: caused, because of, led to, resulted in, drove, thanks to.',
            '- For a change that followed an event, write exactly: "{Metric} rose after {event}. We can\'t tell whether {event} caused it."',
            '- A rise in a count is a fact, not a success or a failure.',
        ]);
    }
}

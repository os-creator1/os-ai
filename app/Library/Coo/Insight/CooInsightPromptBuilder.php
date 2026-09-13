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
     * @return array<int, array{role: string, content: string}>
     */
    public function messages(CooInsightFacts $facts): array
    {
        return [
            ['role' => 'system', 'content' => $this->instructions()],
            ['role' => 'user', 'content' => CooInsightFacts::canonicalJson($facts->forPrompt())],
        ];
    }

    private function instructions(): string
    {
        return implode("\n", [
            'You summarise the performance facts of one local business for its owner.',
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

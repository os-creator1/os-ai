<?php

namespace App\Library\Seo\Content\Autopilot;

use App\Library\Seo\Content\ArticleDraftPromptBuilder;

/**
 * Content Autopilot - the writer prompt, built from the structured brief and nothing else. The grounding rules, the link
 * rules and the per-intent structure are the Content Engine's own (ArticleDraftPromptBuilder's system prompt - one copy,
 * not two); the brief adds the topic's questions, length, niche constraints and the things it must not say.
 *
 * The model never receives the whole Business, only `brief.facts`: a fact that is not in the brief cannot be written.
 */
final class BriefPromptBuilder
{
    public function __construct(private readonly ArticleDraftPromptBuilder $engine)
    {
    }

    /**
     * @param  array<string, mixed>  $brief  ArticleBriefBuilder::build()
     * @param  list<string>  $rejectedFor  what the previous attempt was refused for (the single repair attempt)
     * @return list<array{role: string, content: string}>
     */
    public function messages(array $brief, array $rejectedFor = []): array
    {
        $intent = (string) $brief['intent'];

        // The Content Engine's system prompt does not depend on the facts, so one empty call yields exactly its rules.
        $system = $this->engine->messages([], ['title' => $brief['topic'], 'search_intent' => $intent])[0]['content'];

        $addendum = [
            '',
            'BRIEF: the user message is a brief, not a description of the whole business. Write ONLY from its "facts"; "business" in the rules above means "facts" here.',
            '- Answer the brief\'s "questions", in an order that helps the reader. Do not answer questions it does not list.',
            '- Aim for about ' . (int) ($brief['length']['target_words'] ?? 800) . ' words, and never fewer than ' . (int) ($brief['length']['min_words'] ?? 350) . '.',
            '- Never state or imply anything listed under "must_not". If a topic or phrase there would be natural to mention, leave it out.',
            '- Use the niche\'s "preferred_terms" when naming things, and the "brand_voice" if one is given.',
        ];

        if (! empty($brief['cta']['uid'])) {
            $addendum[] = '- End by pointing the reader to the next step with the allowed link "' . $brief['cta']['title'] . '" if it appears under "allowed_links".';
        }

        if (! empty($brief['rewrite'])) {
            $addendum[] = '';
            $addendum[] = 'UPDATE: you are updating the existing article given as "current_article", not writing a new one. Keep what is still true and the structure readers know; correct what has changed using ONLY the facts; keep its useful links. Reasons it needs an update: ' . implode(' | ', array_slice((array) ($brief['rewrite']['reasons'] ?? []), 0, 3)) . '.';
        }

        if ($rejectedFor !== []) {
            $addendum[] = '';
            $addendum[] = 'YOUR PREVIOUS DRAFT WAS REJECTED because it contained: ' . implode(' | ', array_slice($rejectedFor, 0, 5)) . '. Write the article again without any of these.';
        }

        $user = json_encode([
            'topic' => $brief['topic'],
            'target_topic' => $brief['primary_topic'],
            'search_intent' => $intent,
            'journey_stage' => $brief['stage'] ?? null,
            'audience' => $brief['audience'] ?? null,
            'facts' => $brief['facts'],
            'questions' => $brief['questions'],
            'must_not' => $brief['must_not'],
            'niche' => $brief['niche'],
            'current_article' => $brief['rewrite']['current'] ?? null,
            'allowed_links' => array_map(fn (array $l) => [
                'anchor' => $l['anchor'], 'reference' => $l['type'] . ':' . $l['uid'], 'what_it_is' => $l['title'],
            ], (array) ($brief['links'] ?? [])),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return [
            ['role' => 'system', 'content' => $system . "\n" . implode("\n", $addendum)],
            ['role' => 'user', 'content' => (string) $user],
        ];
    }
}

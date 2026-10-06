<?php

namespace App\Library\Seo\Content;

/**
 * SEO Content Engine V1 — the prompt for one grounded article draft. The system message states the rules; the user
 * message is the Business's facts (ArticleGroundingFacts) plus the topic and the ONLY internal links the article
 * may use. The rules are explicit about what must never be invented, because a draft is read by a customer as the
 * Business's own words.
 */
final class ArticleDraftPromptBuilder
{
    /** Structure a draft should follow, by search intent — different articles, different shapes. */
    private const STRUCTURE = [
        'cost' => 'Structure: what drives the price; what the Business\'s real packages are and what each includes (use ONLY the packages and prices provided; if a package has no price say it is quoted on request); what is normally included; when an upgrade is worth it; a short, honest closing that points the reader to the next step.',
        'comparison' => 'Structure: the real differences between the options; pros and cons of each; who each option suits; a short, honest closing that helps the reader choose.',
        'how_to' => 'Structure: practical numbered steps; useful advice at each step; common pitfalls and how to avoid them; a short closing.',
        'ideas' => 'Structure: a handful of genuinely distinct ideas, each explained in a sentence or two with a practical tip; no filler list items; a short closing.',
        'planning' => 'Structure: what to confirm and when; the practical details that are easy to miss (space, power, timing, access); a simple checklist; a short closing.',
        'guide' => 'Structure: answer the question directly first; then the considerations that matter; who it suits; a short closing.',
    ];

    /**
     * @param  array<string, mixed>  $facts  ArticleGroundingFacts::forBusiness()
     * @param  array<string, mixed>  $opportunity
     * @return array<int, array{role: string, content: string}>
     */
    public function messages(array $facts, array $opportunity): array
    {
        $intent = (string) ($opportunity['search_intent'] ?? 'guide');
        $structure = self::STRUCTURE[$intent] ?? self::STRUCTURE['guide'];

        $system = implode("\n", [
            'You write one helpful blog article for a local business, in its own voice, using ONLY the facts you are given.',
            'Respond with ONLY a valid JSON object, no prose, with exactly these keys: "title" (string), "excerpt" (one or two sentences), "meta_description" (about 150 characters), "body_markdown" (string).',
            '',
            'GROUNDING — these rules are absolute:',
            '- Use ONLY the business facts provided. If a fact is not provided, do not state it.',
            '- NEVER invent or mention: prices that are not in the provided packages; years in business or founding dates; awards or press; statistics or percentages; customer, event or review counts; star ratings or review scores; celebrity or notable clients; offices, studios or locations that are not in the provided service areas; guarantees or money-back promises; delivery times or capacities that are not provided.',
            '- NEVER write quotations, testimonials or "customers say" statements.',
            '- Only mention a price if it is in a provided package, exactly as given. Otherwise discuss cost in general terms without numbers.',
            '- Do not use superlatives such as "best", "leading" or "number one".',
            '',
            'LINKS: you may link ONLY with the exact references provided under "allowed_links", written as [anchor text](page:UID) or [anchor text](article:UID). Use each at most once, only where it genuinely helps the reader. Never write any other link, URL or image.',
            '',
            'QUALITY: be genuinely useful and specific to the topic. No generic introduction ("In today\'s world..."), no keyword stuffing, no repeated paragraphs, no padding to reach a length, no obvious template cadence. Plain, warm, confident language. Aim for roughly 450-700 words.',
            $structure,
            '',
            'FORMAT for body_markdown: Markdown. Do NOT include the title or any "# " heading. Use "## " section headings sparingly (3 to 5) and short paragraphs. Do not include HTML.',
        ]);

        $user = json_encode([
            'topic' => $opportunity['title'],
            'target_topic' => $opportunity['primary_topic'] ?? $opportunity['title'],
            'search_intent' => $intent,
            'business' => $facts,
            'allowed_links' => array_map(fn (array $l) => [
                'anchor' => $l['anchor'], 'reference' => $l['type'] . ':' . $l['uid'], 'what_it_is' => $l['title'],
            ], (array) ($opportunity['internal_links'] ?? [])),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => (string) $user],
        ];
    }
}

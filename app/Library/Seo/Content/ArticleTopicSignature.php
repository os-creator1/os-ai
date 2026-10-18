<?php

namespace App\Library\Seo\Content;

use App\Library\Seo\SeoPhraseNormalizer;

/**
 * SEO Content Engine V1 — the deterministic topic signature every overlap and cannibalization
 * decision is made with. No AI, no stemming library, no fuzzy score: the same two phrases always
 * give the same answer.
 *
 * A phrase is split into
 *   - CORE tokens: the subject ("photo booth rental chicago"), stop-words removed and plurals folded;
 *   - MODIFIER tokens: the words that change the INTENT of a search about that subject
 *     ("how much", "cost", "vs", "ideas", "worth", "checklist" ...).
 *
 * A page or article about "photo booth rental chicago" and one about "how much does photo booth rental
 * cost in chicago" share a core but differ in modifiers — they answer different searches, so the second
 * SUPPORTS the first. Two phrases with the same core and NO informational modifier on the newer one are
 * the same search, and that is cannibalization.
 */
final class ArticleTopicSignature
{
    private const STOP_WORDS = [
        'a', 'an', 'and', 'are', 'as', 'at', 'be', 'by', 'do', 'does', 'for', 'from', 'get', 'in', 'is', 'it',
        'of', 'on', 'or', 'our', 'the', 'to', 'we', 'with', 'you', 'your', 'my', 'me', 'i', 'can', 'should',
        'near', 'best', 'top', 'services', 'service',
    ];

    /**
     * Words that make a search informational / comparative instead of "hire this service", each mapped to
     * the ONE canonical modifier it stands for. Synonyms share a canonical form ("room", "size" and "space"
     * all ask the same question), so "How much room does a booth need?" and "How much space does a booth
     * need?" are recognised as the same topic. Both the raw and the plural-folded token are looked up, so a
     * plural that is itself listed ("logistics", "questions", "mistakes") is never mistaken for a core word.
     */
    private const MODIFIERS = [
        'how' => 'how', 'what' => 'what', 'why' => 'why', 'when' => 'when', 'which' => 'which', 'who' => 'who',
        'much' => 'much', 'many' => 'many', 'long' => 'long',
        'cost' => 'cost', 'costs' => 'cost', 'price' => 'cost', 'prices' => 'cost', 'pricing' => 'cost',
        'worth' => 'worth',
        'vs' => 'vs', 'versus' => 'vs', 'compare' => 'vs', 'comparison' => 'vs', 'difference' => 'vs', 'differences' => 'vs',
        'ideas' => 'ideas', 'idea' => 'ideas', 'inspiration' => 'ideas', 'theme' => 'ideas', 'themes' => 'ideas',
        'examples' => 'ideas', 'example' => 'ideas',
        'tips' => 'tips', 'tip' => 'tips',
        'guide' => 'guide', 'checklist' => 'checklist',
        'plan' => 'plan', 'planning' => 'plan',
        'steps' => 'steps', 'step' => 'steps',
        'mistakes' => 'mistakes', 'mistake' => 'mistakes',
        'questions' => 'questions', 'question' => 'questions', 'faq' => 'questions',
        'include' => 'include', 'includes' => 'include', 'included' => 'include',
        'need' => 'need', 'needs' => 'need',
        'space' => 'space', 'room' => 'space', 'size' => 'space',
        'backdrop' => 'backdrop', 'backdrops' => 'backdrop',
        'guestbook' => 'guestbook', 'guestbooks' => 'guestbook',
        'benefits' => 'benefits', 'benefit' => 'benefits', 'pros' => 'pros', 'cons' => 'cons',
        'choose' => 'choose', 'choosing' => 'choose', 'pick' => 'choose',
        'start' => 'start', 'prepare' => 'prepare', 'work' => 'work', 'works' => 'work',
        'setup' => 'setup', 'logistics' => 'logistics',
        'trends' => 'trends', 'trend' => 'trends', 'reasons' => 'reasons', 'reason' => 'reasons',
    ];

    /**
     * @return array{core: array<int, string>, modifiers: array<int, string>, key: string}
     */
    public static function of(string $phrase): array
    {
        $normalized = SeoPhraseNormalizer::normalize($phrase);
        $normalized = (string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $normalized);

        $core = [];
        $modifiers = [];

        foreach (preg_split('/\s+/u', trim($normalized)) ?: [] as $token) {
            if ($token === '') {
                continue;
            }

            if (in_array($token, self::STOP_WORDS, true)) {
                continue;
            }

            $raw = $token;
            $token = self::fold($token);

            if (in_array($token, self::STOP_WORDS, true)) {
                continue;
            }

            $modifier = self::MODIFIERS[$raw] ?? self::MODIFIERS[$token] ?? null;

            if ($modifier !== null) {
                $modifiers[$modifier] = true;

                continue;
            }

            $core[$token] = true;
        }

        $coreTokens = array_keys($core);
        sort($coreTokens);
        $modifierTokens = array_keys($modifiers);
        sort($modifierTokens);

        return [
            'core' => $coreTokens,
            'modifiers' => $modifierTokens,
            'key' => implode(' ', $coreTokens) . ($modifierTokens !== [] ? ' | ' . implode(' ', $modifierTokens) : ''),
        ];
    }

    /** What is stored in website_articles.topic_signature. */
    public static function key(string $phrase): string
    {
        return mb_substr(self::of($phrase)['key'], 0, 190);
    }

    /** Plural folding only ("booths" → "booth"); deliberately not a stemmer. */
    private static function fold(string $token): string
    {
        if (mb_strlen($token) > 3 && str_ends_with($token, 'ies')) {
            return mb_substr($token, 0, -3) . 'y';
        }

        if (mb_strlen($token) > 3 && str_ends_with($token, 's') && ! str_ends_with($token, 'ss')) {
            return mb_substr($token, 0, -1);
        }

        return $token;
    }
}

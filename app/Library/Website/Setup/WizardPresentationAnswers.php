<?php

namespace App\Library\Website\Setup;

use App\Models\QuestionnaireResponse;

/**
 * Independent-review correction round 3 — extracted from
 * WebsiteWizardController's own private helpers so WebsiteController's
 * "Regenerate with AI"/"Rebuild" actions can resolve the SAME custom
 * section/FAQ facts the wizard itself would pass to guided generation
 * (item 11): a deliberate rebuild is the one path that is supposed to
 * pick up every current fact, including presentation-only ones set
 * through "Edit setup answers" — a rebuild that silently kept dropping
 * them would defeat the very point of requiring one.
 */
final class WizardPresentationAnswers
{
    /**
     * @return ?array{title: string, layout: string, body: ?string, images: array<int, string>}
     */
    public static function customSection(?QuestionnaireResponse $response): ?array
    {
        if ($response === null) {
            return null;
        }

        $entries = self::answerForModule($response, 'custom_section');

        if (! is_array($entries) || $entries === []) {
            return null;
        }

        $entry = $entries[0];

        if (! is_array($entry) || trim((string) ($entry['name'] ?? '')) === '') {
            return null;
        }

        return [
            'title' => (string) $entry['name'],
            'layout' => $entry['layout'] ?? 'stacked',
            'body' => $entry['body'] ?? null,
            'images' => $entry['images'] ?? [],
        ];
    }

    /**
     * @return ?array<int, array{question: string, answer: string}>
     */
    public static function customerFaq(?QuestionnaireResponse $response): ?array
    {
        if ($response === null) {
            return null;
        }

        $entries = self::answerForModule($response, 'faq');

        if (! is_array($entries) || $entries === []) {
            return null;
        }

        $pairs = array_values(array_filter(array_map(function ($entry) {
            if (! is_array($entry)) {
                return null;
            }

            $question = trim((string) ($entry['question'] ?? ''));
            $answer = trim((string) ($entry['answer'] ?? ''));

            return $question !== '' && $answer !== '' ? ['question' => $question, 'answer' => $answer] : null;
        }, $entries)));

        return $pairs === [] ? null : $pairs;
    }

    /**
     * Independent-review correction round 4 (item 4) — resolves the
     * REAL step key for the given target_module from the response's own
     * pinned version, rather than assuming any niche's custom-section/
     * FAQ step is literally keyed 'custom_section'/'faq_items'. A niche
     * with no such step at all (or one never answered) simply has
     * nothing to resolve — null is a normal result, not an error.
     */
    private static function answerForModule(QuestionnaireResponse $response, string $targetModule): mixed
    {
        $step = collect($response->version->steps())->firstWhere('target_module', $targetModule);

        if ($step === null) {
            return null;
        }

        return $response->answer($step['key']);
    }
}

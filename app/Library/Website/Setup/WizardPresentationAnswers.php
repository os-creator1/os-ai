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
     * The canonical packages the owner chose to show on the website, as
     * catalog uids in the owner's order. Null means "no explicit choice":
     * the questionnaire has no package-selection step (v1 and other niches)
     * or it was never answered, and every active package is used as before.
     *
     * @return ?array<int, string>
     */
    public static function catalogSelection(?QuestionnaireResponse $response): ?array
    {
        if ($response === null) {
            return null;
        }

        $step = collect($response->version->steps())->first(fn (array $s) => ($s['input_type'] ?? null) === 'catalog_selection');

        if ($step === null) {
            return null;
        }

        $answer = $response->answer($step['key']);

        if (! is_array($answer)) {
            return null;
        }

        return array_values(array_filter(array_map(fn ($e) => is_array($e) && is_string($e['uid'] ?? null) ? $e['uid'] : null, $answer)));
    }

    /**
     * Every service the owner entered across ALL `business_service` steps,
     * in questionnaire order then entry order — the order the applier will
     * give them (continuing positions), so the Review screen's page plan
     * previews exactly what Generate will build.
     *
     * @return array<int, array{name: string, description: ?string}>
     */
    public static function serviceRows(?QuestionnaireResponse $response): array
    {
        if ($response === null) {
            return [];
        }

        $rows = [];

        foreach ($response->version->steps() as $step) {
            if (($step['target_module'] ?? null) !== 'business_service') {
                continue;
            }

            $answer = $response->answer($step['key']);

            if (! is_array($answer)) {
                continue;
            }

            foreach ($answer as $item) {
                $name = trim((string) (is_array($item) ? ($item['name'] ?? '') : ''));

                if ($name !== '') {
                    $rows[] = ['name' => $name, 'description' => isset($item['description']) ? trim((string) $item['description']) : null];
                }
            }
        }

        return $rows;
    }

    /** Whether the owner entered at least one backdrop (so a Backdrops page will be planned). */
    public static function hasBackdrops(?QuestionnaireResponse $response): bool
    {
        if ($response === null) {
            return false;
        }

        foreach ($response->version->steps() as $step) {
            if (($step['target_module'] ?? null) !== 'backdrop') {
                continue;
            }

            $answer = $response->answer($step['key']);

            if (is_array($answer) && collect($answer)->contains(fn ($item) => is_array($item) && trim((string) ($item['name'] ?? '')) !== '')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The owner's service areas, in the order they entered them (that order
     * is their priority), when the questionnaire collects them as a list.
     * Null for a questionnaire that does not (v1's free-text answer): no
     * service-area pages are planned from it.
     *
     * @return ?array<int, string>
     */
    public static function serviceAreas(?QuestionnaireResponse $response): ?array
    {
        if ($response === null) {
            return null;
        }

        $step = collect($response->version->steps())->first(
            fn (array $s) => ($s['input_type'] ?? null) === 'string_list' && ($s['target_module'] ?? null) === 'business_location'
        );

        if ($step === null) {
            return null;
        }

        $answer = $response->answer($step['key']);

        return is_array($answer) && $answer !== [] ? ServiceAreaList::normalize($answer) : null;
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

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

        // A package step that the owner's answers hid (quote-only pricing) means "no predefined packages" —
        // an explicit empty choice, never "show every catalog item".
        if ($step['conditional_visibility'] ?? null) {
            $visible = collect((new QuestionnaireStepResolver())->visibleSteps($response->version->steps(), $response->answers ?? []))->contains('key', $step['key']);

            if (! $visible) {
                return [];
            }
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
            if (($step['target_module'] ?? null) !== 'business_service' || ($step['item_kind'] ?? null) === 'event') {
                continue;
            }

            $answer = $response->answer($step['key']);

            if (! is_array($answer)) {
                continue;
            }

            foreach ($answer as $item) {
                $name = trim((string) (is_array($item) ? ($item['name'] ?? '') : ''));

                if ($name !== '' && (! is_array($item) || ($item['visible'] ?? true))) {
                    $rows[] = ['name' => $name, 'description' => isset($item['description']) ? trim((string) $item['description']) : null];
                }
            }
        }

        return $rows;
    }

    /**
     * The event types the owner entered (item_kind "event"), shown ones only, with their own descriptions — what the Review
     * screen previews event pages from before the answers are saved as services.
     *
     * @return array<int, array{name: string, description: ?string}>
     */
    public static function eventRows(?QuestionnaireResponse $response): array
    {
        if ($response === null) {
            return [];
        }

        $rows = [];

        foreach ($response->version->steps() as $step) {
            if (($step['target_module'] ?? null) !== 'business_service' || ($step['item_kind'] ?? null) !== 'event') {
                continue;
            }

            $answer = $response->answer($step['key']);

            foreach (is_array($answer) ? $answer : [] as $item) {
                $name = trim((string) (is_array($item) ? ($item['name'] ?? '') : ''));

                if ($name !== '' && (! is_array($item) || ($item['visible'] ?? true))) {
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
     * @return ?array<int, string|array{name: string, details: array<string, mixed>}>
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

        // A guided questionnaire that collects local details per area: every area is passed on (a bare name, or the name
        // with what the owner confirmed), and LocalAreaUsefulness decides which of them earn a page of their own.
        if (($step['area_details'] ?? false) === true) {
            return is_array($answer) && $answer !== []
                ? ServiceAreaDetails::entries(ServiceAreaList::normalize($answer), $response->answer(ServiceAreaDetails::ANSWER_KEY))
                : null;
        }

        // A guided questionnaire that collects areas for the business's own records only (never for
        // separate city SEO pages) says so; a v1/v2 list keeps planning area pages as before.
        if (($step['area_pages'] ?? true) === false) {
            return null;
        }

        return is_array($answer) && $answer !== [] ? ServiceAreaList::normalize($answer) : null;
    }

    /**
     * Everything the deterministic Photo Booth blueprint needs from a GUIDED setup (v3): the brand tone, the booking
     * action, the pricing style, the owner's About text and FAQ, the packages they chose and which booth each one is
     * paired with. Null for an older questionnaire — those keep the AI-written path.
     *
     * @return ?array{tone: ?string, cta: ?string, pricing: ?string, about: ?string, customer_faq: array<int, array{question: string, answer: string}>, catalog_uids: ?array<int, string>, package_links: array<string, string>, answers: array<string, mixed>}
     */
    public static function blueprintContext(?QuestionnaireResponse $response): ?array
    {
        if ($response === null) {
            return null;
        }

        $guided = collect($response->version->steps())->contains(
            fn (array $s) => ($s['target_module'] ?? null) === 'business_service' && in_array('cover', (array) ($s['row_fields'] ?? []), true)
        );

        if (! $guided) {
            return null;
        }

        $links = [];
        $step = collect($response->version->steps())->first(fn (array $s) => ($s['input_type'] ?? null) === 'catalog_selection');
        foreach ((array) ($step !== null ? $response->answer($step['key']) : []) as $entry) {
            if (is_array($entry) && is_string($entry['uid'] ?? null) && is_string($entry['service_key'] ?? null) && $entry['service_key'] !== '') {
                $links[$entry['uid']] = $entry['service_key'];
            }
        }

        $tone = $response->answer('brand_personality');
        $cta = $response->answer('primary_cta');

        return [
            'tone' => is_string($tone) ? $tone : null,
            'cta' => is_string($cta) ? $cta : null,
            'pricing' => self::pricingStrategy($response),
            'about' => self::aboutText($response),
            'customer_faq' => self::customerFaq($response) ?? [],
            'catalog_uids' => self::catalogSelection($response),
            'package_links' => $links,
            'answers' => (array) ($response->answers ?? []),
        ];
    }

    /** One of 'fixed_packages' | 'starting_from' | 'custom_quote'; null when the questionnaire never asked. */
    public static function pricingStrategy(?QuestionnaireResponse $response): ?string
    {
        $value = $response === null ? null : $response->answer('pricing_strategy');

        return in_array($value, ['fixed_packages', 'starting_from', 'custom_quote'], true) ? $value : null;
    }

    /** The owner's About copy (kept, edited or replaced) from a guided questionnaire; null when not collected. */
    public static function aboutText(?QuestionnaireResponse $response): ?string
    {
        $value = $response === null ? null : $response->answer('about_story');
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
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

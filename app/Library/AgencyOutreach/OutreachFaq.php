<?php

namespace App\Library\AgencyOutreach;

use App\Models\Workspace;

/**
 * One Agency's approved questions and answers (Outreach V2).
 *
 * An entry is {id, question, answer, keywords[], intent?, campaign_ids?, niche?}.
 * Entries are the ONLY facts the engine (and the one bounded AI call) may answer
 * from. An Agency that never customised gets its six legacy answer fields as entries
 * mapped to their classifier intent, so the old behaviour is unchanged.
 *
 * Scope: an entry with `campaign_ids` applies to those campaigns only; one with
 * `niche` applies when the Agency's niche setting equals it. An entry with neither
 * applies everywhere.
 */
final class OutreachFaq
{
    public const FALLBACK_AI = 'ai';

    public const FALLBACK_CONTINUE = 'continue';

    public const FALLBACK_HANDOFF = 'handoff';

    private const LEGACY = [
        'pricing_answer' => [OutreachIntentClassifier::PRICING, 'What does it cost?', ['price', 'cost', 'how much', 'fee']],
        'location_answer' => [OutreachIntentClassifier::LOCATION, 'Where are you based?', ['where are you', 'location', 'based']],
        'found_you_answer' => [OutreachIntentClassifier::FOUND_YOU, 'How did you find me?', ['find me', 'found me', 'got my']],
        'what_we_do_answer' => [OutreachIntentClassifier::WHAT_WE_DO, 'What do you do?', ['what do you do', 'what is this']],
        'website_answer' => [OutreachIntentClassifier::WEBSITE, 'Website', ['website', 'web site', 'link']],
        'clarify_answer' => [OutreachIntentClassifier::CLARIFY, 'Specific questions', []],
    ];

    /** @param array<int, array<string, mixed>> $entries */
    private function __construct(private readonly array $entries, private readonly string $fallback, private readonly ?string $niche)
    {
    }

    public static function forWorkspace(Workspace $workspace): self
    {
        $script = OutreachScript::forWorkspace($workspace);
        $settings = $script->settings();
        $niche = trim((string) $settings?->niche) ?: null;
        $fallback = in_array($settings?->faq_fallback, [self::FALLBACK_AI, self::FALLBACK_CONTINUE, self::FALLBACK_HANDOFF], true)
            ? $settings->faq_fallback
            : self::FALLBACK_AI;
        $raw = $settings?->faq_entries;

        if (is_array($raw)) {
            return new self(self::normalise($raw), $fallback, $niche);
        }

        $entries = [];

        foreach (self::LEGACY as $field => [$intent, $question, $keywords]) {
            $entries[] = [
                'id' => 'legacy-' . $field,
                'question' => $question,
                'answer' => $script->get($field),
                'keywords' => [],
                'intent' => $intent,
                'campaign_ids' => null,
                'niche' => null,
            ];
        }

        return new self($entries, $fallback, $niche);
    }

    /** @param array<int, mixed> $raw @return array<int, array<string, mixed>> */
    public static function normalise(array $raw): array
    {
        $out = [];
        $seen = [];

        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $id = trim((string) ($entry['id'] ?? ''));

            if ($id === '' || isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $keywords = $entry['keywords'] ?? [];

            if (is_string($keywords)) {
                $keywords = preg_split('/[,\n]+/', $keywords) ?: [];
            }

            $campaigns = $entry['campaign_ids'] ?? null;

            $out[] = [
                'id' => $id,
                'question' => trim((string) ($entry['question'] ?? '')),
                'answer' => trim((string) ($entry['answer'] ?? '')),
                'keywords' => array_values(array_filter(array_map(
                    static fn ($k): string => mb_strtolower(trim((string) $k)),
                    (array) $keywords,
                ), static fn (string $k): bool => $k !== '')),
                'intent' => isset($entry['intent']) && $entry['intent'] !== '' ? (string) $entry['intent'] : null,
                'campaign_ids' => is_array($campaigns) && $campaigns !== [] ? array_values(array_map('intval', $campaigns)) : null,
                'niche' => trim((string) ($entry['niche'] ?? '')) !== '' ? trim((string) $entry['niche']) : null,
            ];
        }

        return $out;
    }

    public function fallback(): string
    {
        return $this->fallback;
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->entries;
    }

    /** Entries that apply to this campaign and niche, with a usable answer. @return array<int, array<string, mixed>> */
    public function applicable(?int $campaignId = null): array
    {
        return array_values(array_filter($this->entries, function (array $e) use ($campaignId): bool {
            if ($e['answer'] === '') {
                return false;
            }

            if ($e['campaign_ids'] !== null && ($campaignId === null || ! in_array($campaignId, $e['campaign_ids'], true))) {
                return false;
            }

            return $e['niche'] === null
                || ($this->niche !== null && mb_strtolower($e['niche']) === mb_strtolower($this->niche));
        }));
    }

    /**
     * The approved entry that answers this reply, if any: a classifier-intent match
     * first, then the entry whose keywords appear in the message.
     *
     * @return array<string, mixed>|null
     */
    public function match(string $inboundBody, string $intent, ?int $campaignId = null): ?array
    {
        $candidates = $this->applicable($campaignId);

        foreach ($candidates as $entry) {
            if ($entry['intent'] !== null && $entry['intent'] === $intent) {
                return $entry;
            }
        }

        $haystack = mb_strtolower($inboundBody);

        foreach ($candidates as $entry) {
            foreach ($entry['keywords'] as $keyword) {
                if ($keyword !== '' && str_contains($haystack, $keyword)) {
                    return $entry;
                }
            }
        }

        return null;
    }
}

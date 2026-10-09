<?php

namespace App\Library\Seo\Content\Autopilot;

use App\Library\Business\BusinessKnowledgeProfileManager;
use App\Models\Business;
use App\Models\BusinessKnowledgeProfile;
use App\Models\ContentAutopilotSetting;
use Illuminate\Validation\ValidationException;

/**
 * Content Autopilot — the short first-enable "Content Profile".
 *
 * MotionGrove already knows a great deal about the Business (Knowledge Profile, packages, locations, the Website), and
 * the owner is never asked for any of it again. This class owns only the few things nobody can know:
 *
 *   - common_questions — what customers ask most;
 *   - emphasis        — what the owner wants emphasised;
 *   - avoid_topics    — topics Autopilot must never write about.
 *
 * The fourth first-enable question — what makes the Business different — is NOT stored here: it is the Knowledge
 * Profile's `differentiators`, written through BusinessKnowledgeProfileManager (the one authority for it) and only
 * asked while that field is empty. Claims the owner never wants made are the Knowledge Profile's `prohibited_claims`.
 *
 * Every field is optional: skipping the flow is a valid answer, and saving (even empty) completes it.
 */
class ContentProfile
{
    public const MAX_QUESTIONS = 8;
    public const MAX_EMPHASIS = 6;
    public const MAX_AVOID = 10;
    public const MAX_DIFFERENTIATORS = 6;

    private const QUESTION_CHARS = 160;
    private const SHORT_CHARS = 120;

    public function __construct(private readonly BusinessKnowledgeProfileManager $knowledge)
    {
    }

    public function setting(Business $business): ?ContentAutopilotSetting
    {
        return ContentAutopilotSetting::query()->where('business_id', $business->id)->first();
    }

    /** @return array{common_questions: string[], emphasis: string[], avoid_topics: string[]} */
    public function get(Business $business): array
    {
        $profile = (array) ($this->setting($business)?->profile ?? []);

        return [
            'common_questions' => $this->stringList($profile['common_questions'] ?? []),
            'emphasis' => $this->stringList($profile['emphasis'] ?? []),
            'avoid_topics' => $this->stringList($profile['avoid_topics'] ?? []),
        ];
    }

    public function isCompleted(Business $business): bool
    {
        return $this->setting($business)?->profile_completed_at !== null;
    }

    /**
     * What the first-enable flow still has to ask: only fields that are empty. `differentiators` is empty when the
     * Knowledge Profile has none; the three Content fields are empty until the owner has answered them.
     *
     * @return string[]
     */
    public function gaps(Business $business): array
    {
        $gaps = [];
        $knowledge = BusinessKnowledgeProfile::query()->where('business_id', $business->id)->first();

        if (empty($knowledge?->differentiators)) {
            $gaps[] = 'differentiators';
        }

        foreach ($this->get($business) as $key => $values) {
            if ($values === []) {
                $gaps[] = $key;
            }
        }

        return $gaps;
    }

    /**
     * @param  array<string, mixed>  $input  arrays or newline-separated text; unknown keys are ignored
     *
     * @throws ValidationException
     */
    public function save(Business $business, int $actorUserId, array $input): ContentAutopilotSetting
    {
        $errors = [];
        $clean = [];

        foreach ([
            'common_questions' => [self::MAX_QUESTIONS, self::QUESTION_CHARS],
            'emphasis' => [self::MAX_EMPHASIS, self::SHORT_CHARS],
            'avoid_topics' => [self::MAX_AVOID, self::SHORT_CHARS],
        ] as $key => [$max, $chars]) {
            try {
                $clean[$key] = $this->bounded($input[$key] ?? [], $max, $chars, $key);
            } catch (ValidationException $e) {
                $errors += $e->errors();
            }
        }

        $differentiators = null;

        if (array_key_exists('differentiators', $input)) {
            try {
                $differentiators = $this->bounded($input['differentiators'], self::MAX_DIFFERENTIATORS, self::SHORT_CHARS, 'differentiators');
            } catch (ValidationException $e) {
                $errors += $e->errors();
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        // The Knowledge Profile stays the single authority for what makes the Business different.
        if ($differentiators !== null && $differentiators !== []) {
            $this->knowledge->updateFields($business, ['differentiators' => $differentiators], 'manual_edit', $actorUserId, true);
        }

        $setting = ContentAutopilotSetting::query()->firstOrNew(['business_id' => $business->id]);
        $setting->profile = $clean;
        $setting->profile_completed_at ??= now();
        $setting->save();

        return $setting;
    }

    /**
     * @return string[]
     *
     * @throws ValidationException
     */
    private function bounded(mixed $value, int $max, int $chars, string $key): array
    {
        $list = $this->stringList($value);

        if (count($list) > $max) {
            throw ValidationException::withMessages([$key => ["Please keep this to at most {$max} entries."]]);
        }

        foreach ($list as $item) {
            if (mb_strlen($item) > $chars) {
                throw ValidationException::withMessages([$key => ["Each entry can be at most {$chars} characters."]]);
            }
        }

        return $list;
    }

    /** @return string[] trimmed, de-duplicated case-insensitively, empties dropped, order kept */
    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/\R/u', $value) ?: [];
        }

        if (! is_array($value)) {
            return [];
        }

        $out = [];
        $seen = [];

        foreach ($value as $item) {
            if (! is_string($item)) {
                continue;
            }

            $item = trim((string) preg_replace('/\s+/u', ' ', $item));
            $key = mb_strtolower($item);

            if ($item === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $out[] = $item;
        }

        return $out;
    }
}

<?php

namespace App\Models;

use App\Enums\Business\BusinessIndustry;
use App\Enums\Business\BusinessKnowledgeProfileFieldKey;
use App\Enums\Business\QuestionInputType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Website Guided Generation contract §6.2/§6.3. Packs are immutable
 * once referenced by any completed generation -- a new question or
 * changed wording ships as a new `version` row under the same `key`,
 * never an in-place edit (this model does not forbid update() itself,
 * mirroring the codebase's convention of documenting immutability as a
 * process discipline rather than a database trigger; no code path in
 * this slice ever calls update() on an existing pack).
 *
 * Every write through this model (create/update/save) is validated
 * here, at the model-event boundary -- this protects `QuestionPack::
 * create()`/`update()`/`save()` calls only, including this contract's
 * own fixtures and any future seeder or admin surface built on top of
 * this model. It does NOT and cannot protect a hypothetical raw
 * `DB::table('question_packs')->insert(...)` call, which bypasses
 * Eloquent (and therefore this hook) entirely; no such call exists
 * anywhere in this contract's implementation, and none is authorized.
 */
class QuestionPack extends Model
{
    private const KEY_PATTERN = '/^[a-z0-9]+(?:[_-][a-z0-9]+)*$/';

    private const MAX_KEY_LENGTH = 40;

    private const GENERAL_KEY = 'general';

    private const MAX_QUESTIONS = 30;

    private const MAX_PROMPT_LENGTH = 300;

    private const MAX_OPTIONS = 20;

    private const MAX_OPTION_LENGTH = 80;

    protected $fillable = [
        'key',
        'applies_to_industry',
        'applies_to_vertical_key',
        'version',
        'questions',
        'is_active',
    ];

    protected $casts = [
        'version' => 'integer',
        'questions' => 'array',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (QuestionPack $pack) {
            static::assertValid($pack);
        });
    }

    private static function assertValid(QuestionPack $pack): void
    {
        if (! is_string($pack->key) || $pack->key === '' || mb_strlen($pack->key) > self::MAX_KEY_LENGTH || ! preg_match(self::KEY_PATTERN, $pack->key)) {
            throw ValidationException::withMessages([
                'key' => ['key must be a lowercase, hyphen/underscore-separated string of at most ' . self::MAX_KEY_LENGTH . ' characters, with no leading, trailing, or repeated separators.'],
            ]);
        }

        if (! is_int($pack->version) || $pack->version < 1) {
            throw ValidationException::withMessages([
                'version' => ['version must be an integer of at least 1.'],
            ]);
        }

        if ($pack->applies_to_industry !== null && $pack->applies_to_vertical_key !== null) {
            throw ValidationException::withMessages([
                'question_pack' => ['A question pack may target at most one of applies_to_industry or applies_to_vertical_key, never both.'],
            ]);
        }

        if ($pack->applies_to_industry !== null && BusinessIndustry::tryFrom($pack->applies_to_industry) === null) {
            throw ValidationException::withMessages([
                'applies_to_industry' => ['Invalid applies_to_industry value.'],
            ]);
        }

        if ($pack->applies_to_vertical_key !== null && ! BusinessVertical::where('key', $pack->applies_to_vertical_key)->exists()) {
            throw ValidationException::withMessages([
                'applies_to_vertical_key' => ['applies_to_vertical_key must reference an existing business_verticals entry.'],
            ]);
        }

        $isGeneralShape = $pack->applies_to_industry === null && $pack->applies_to_vertical_key === null;

        // §6.2/§6.3: the general-fallback scope is identified by BOTH
        // key === 'general' AND the both-columns-null shape -- the two
        // must always agree, so a pack can never accidentally occupy
        // (or fail to occupy) the fallback scope by shape alone.
        if ($isGeneralShape && $pack->key !== self::GENERAL_KEY) {
            throw ValidationException::withMessages([
                'key' => ["A question pack with no applies_to_industry and no applies_to_vertical_key must use key = '" . self::GENERAL_KEY . "'."],
            ]);
        }

        if (! $isGeneralShape && $pack->key === self::GENERAL_KEY) {
            throw ValidationException::withMessages([
                'key' => ["The '" . self::GENERAL_KEY . "' key is reserved for the general-fallback shape (both applies_to_industry and applies_to_vertical_key null)."],
            ]);
        }

        self::assertNoCompetingActiveFamily($pack, $isGeneralShape);

        $questions = $pack->questions;

        if (! is_array($questions) || $questions === []) {
            throw ValidationException::withMessages([
                'questions' => ['questions must be a non-empty array.'],
            ]);
        }

        if (count($questions) > self::MAX_QUESTIONS) {
            throw ValidationException::withMessages([
                'questions' => ['questions must contain at most ' . self::MAX_QUESTIONS . ' entries.'],
            ]);
        }

        $allowedFieldKeys = array_column(BusinessKnowledgeProfileFieldKey::cases(), 'value');
        $seenFieldKeys = [];

        foreach ($questions as $index => $question) {
            if (! is_array($question)) {
                throw ValidationException::withMessages([
                    'questions' => ["questions[{$index}] must be an object."],
                ]);
            }

            $fieldKey = $question['field_key'] ?? null;

            if (! is_string($fieldKey) || ! in_array($fieldKey, $allowedFieldKeys, true)) {
                throw ValidationException::withMessages([
                    'questions' => ["questions[{$index}].field_key must be a known BusinessKnowledgeProfileFieldKey value."],
                ]);
            }

            if (isset($seenFieldKeys[$fieldKey])) {
                throw ValidationException::withMessages([
                    'questions' => ["questions[{$index}].field_key '{$fieldKey}' is already used by another question in this pack; each field_key may appear at most once."],
                ]);
            }

            $seenFieldKeys[$fieldKey] = true;

            $prompt = $question['prompt'] ?? null;

            if (! is_string($prompt) || trim($prompt) === '' || mb_strlen($prompt) > self::MAX_PROMPT_LENGTH) {
                throw ValidationException::withMessages([
                    'questions' => ["questions[{$index}].prompt is required and must be a non-blank string of at most " . self::MAX_PROMPT_LENGTH . ' characters.'],
                ]);
            }

            $inputType = $question['input_type'] ?? null;

            if (! is_string($inputType) || QuestionInputType::tryFrom($inputType) === null) {
                throw ValidationException::withMessages([
                    'questions' => ["questions[{$index}].input_type must be one of: text, textarea, select, multi_select, boolean."],
                ]);
            }

            $options = $question['options'] ?? null;
            $requiresOptions = in_array($inputType, [QuestionInputType::Select->value, QuestionInputType::MultiSelect->value], true);

            if ($requiresOptions) {
                if (! is_array($options) || $options === []) {
                    throw ValidationException::withMessages([
                        'questions' => ["questions[{$index}].options is required and must be a non-empty array for input_type '{$inputType}'."],
                    ]);
                }
            } elseif ($options !== null) {
                throw ValidationException::withMessages([
                    'questions' => ["questions[{$index}].options must be null for input_type '{$inputType}'."],
                ]);
            }

            if ($options !== null) {
                if (! is_array($options) || count($options) > self::MAX_OPTIONS) {
                    throw ValidationException::withMessages([
                        'questions' => ["questions[{$index}].options must be an array of at most " . self::MAX_OPTIONS . ' strings.'],
                    ]);
                }

                $seenOptions = [];

                foreach ($options as $option) {
                    if (! is_string($option) || trim($option) === '' || mb_strlen($option) > self::MAX_OPTION_LENGTH) {
                        throw ValidationException::withMessages([
                            'questions' => ["questions[{$index}].options must be an array of non-blank strings, each at most " . self::MAX_OPTION_LENGTH . ' characters.'],
                        ]);
                    }

                    if (isset($seenOptions[$option])) {
                        throw ValidationException::withMessages([
                            'questions' => ["questions[{$index}].options must not contain duplicate values."],
                        ]);
                    }

                    $seenOptions[$option] = true;
                }
            }

            if (! array_key_exists('required', $question) || ! is_bool($question['required'])) {
                throw ValidationException::withMessages([
                    'questions' => ["questions[{$index}].required must be a boolean."],
                ]);
            }
        }
    }

    /**
     * §6.3's determinism correction: because applies_to_industry/
     * applies_to_vertical_key are nullable columns, no portable
     * database constraint can by itself guarantee "at most one active
     * pack family per target scope." Multiple versions of the SAME key
     * family remain fully supported (that is the immutable-versioning
     * model itself) -- what is rejected here is a DIFFERENT key family
     * being active for the same scope at the same time, which would
     * make resolveQuestionPack()'s result ambiguous/order-dependent.
     */
    private static function assertNoCompetingActiveFamily(QuestionPack $pack, bool $isGeneralShape): void
    {
        if ($pack->is_active !== true) {
            return;
        }

        $query = QuestionPack::where('is_active', true)->where('key', '!=', $pack->key);

        if ($pack->exists) {
            $query->where('id', '!=', $pack->id);
        }

        if ($isGeneralShape) {
            $query->whereNull('applies_to_industry')->whereNull('applies_to_vertical_key');
        } elseif ($pack->applies_to_vertical_key !== null) {
            $query->where('applies_to_vertical_key', $pack->applies_to_vertical_key);
        } else {
            $query->where('applies_to_industry', $pack->applies_to_industry)->whereNull('applies_to_vertical_key');
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'key' => ['Another active question pack family already targets this scope; deactivate it first or reuse its key as a new version.'],
            ]);
        }
    }
}

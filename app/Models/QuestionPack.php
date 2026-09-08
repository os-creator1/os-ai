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
 * this slice ever calls update() on an existing pack). Every write
 * (create or update) is validated here, at the model boundary, so no
 * caller -- test, future seeder, or future admin surface -- can bypass
 * the shape contracted by §6.2.
 */
class QuestionPack extends Model
{
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

        $questions = $pack->questions;

        if (! is_array($questions)) {
            throw ValidationException::withMessages([
                'questions' => ['questions must be an array.'],
            ]);
        }

        $allowedFieldKeys = array_column(BusinessKnowledgeProfileFieldKey::cases(), 'value');

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

            $prompt = $question['prompt'] ?? null;

            if (! is_string($prompt) || $prompt === '') {
                throw ValidationException::withMessages([
                    'questions' => ["questions[{$index}].prompt is required."],
                ]);
            }

            $inputType = $question['input_type'] ?? null;

            if (! is_string($inputType) || QuestionInputType::tryFrom($inputType) === null) {
                throw ValidationException::withMessages([
                    'questions' => ["questions[{$index}].input_type must be one of: text, textarea, select, multi_select, boolean."],
                ]);
            }

            $options = $question['options'] ?? null;

            if ($options !== null) {
                if (! is_array($options)) {
                    throw ValidationException::withMessages([
                        'questions' => ["questions[{$index}].options must be an array of strings or null."],
                    ]);
                }

                foreach ($options as $option) {
                    if (! is_string($option)) {
                        throw ValidationException::withMessages([
                            'questions' => ["questions[{$index}].options must be an array of strings or null."],
                        ]);
                    }
                }
            }

            if (! array_key_exists('required', $question) || ! is_bool($question['required'])) {
                throw ValidationException::withMessages([
                    'questions' => ["questions[{$index}].required must be a boolean."],
                ]);
            }
        }
    }
}

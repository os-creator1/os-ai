<?php

namespace App\Library\Crm\Templates;

use App\Enums\Crm\CrmStageSemanticKey;
use InvalidArgumentException;

/**
 * One pipeline of a Business Template.
 *
 * A blueprint is validated when it is built, so a malformed template fails where
 * it is defined rather than half-way through copying into a Business: at least
 * one stage, every name present and short enough to store, semantic keys well
 * formed and unique, and the first stage always the canonical `new_inquiry`.
 */
final readonly class PipelineBlueprint
{
    /** @var list<StageBlueprint> */
    public array $stages;

    /**
     * @param  list<StageBlueprint>  $stages
     */
    public function __construct(
        public string $key,
        public string $name,
        array $stages,
    ) {
        if (preg_match(CrmStageSemanticKey::PATTERN, $key) !== 1) {
            throw new InvalidArgumentException("Pipeline blueprint key [{$key}] is not a valid key.");
        }

        self::assertName($name, "pipeline [{$key}]");

        if ($stages === []) {
            throw new InvalidArgumentException("Pipeline blueprint [{$key}] has no stages.");
        }

        if ($stages[0]->semanticKey !== CrmStageSemanticKey::NewInquiry->value) {
            throw new InvalidArgumentException("Pipeline blueprint [{$key}] must start with the new_inquiry stage.");
        }

        $seen = [];

        foreach ($stages as $stage) {
            self::assertName($stage->name, "a stage of pipeline [{$key}]");

            if ($stage->semanticKey === null) {
                continue;
            }

            if (preg_match(CrmStageSemanticKey::PATTERN, $stage->semanticKey) !== 1 || isset($seen[$stage->semanticKey])) {
                throw new InvalidArgumentException("Pipeline blueprint [{$key}] has an invalid or repeated stage key [{$stage->semanticKey}].");
            }

            $seen[$stage->semanticKey] = true;
        }

        $this->stages = array_values($stages);
    }

    private static function assertName(string $name, string $what): void
    {
        if (trim($name) === '' || mb_strlen($name) > 100) {
            throw new InvalidArgumentException("The name of {$what} must be 1–100 characters.");
        }
    }
}

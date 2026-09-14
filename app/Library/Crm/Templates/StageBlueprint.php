<?php

namespace App\Library\Crm\Templates;

/** One stage of a template pipeline: a display name and an optional semantic key. */
final readonly class StageBlueprint
{
    public function __construct(
        public string $name,
        public ?string $semanticKey = null,
    ) {
    }
}

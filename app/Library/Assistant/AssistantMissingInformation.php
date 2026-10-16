<?php

namespace App\Library\Assistant;

use RuntimeException;

/** The request is understood but incomplete: ask the customer, in their words, for what is missing. */
final class AssistantMissingInformation extends RuntimeException
{
    /** @param array<int, string> $questions plain-language questions, at most a few */
    public function __construct(public readonly array $questions, public readonly array $options = [])
    {
        parent::__construct(implode(' ', $questions));
    }
}

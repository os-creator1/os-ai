<?php

namespace App\Library\Assistant;

use App\Models\AssistantActionProposal;

/** What the assistant said in reply to one customer message: a kind, plain text, and structured extras for the UI. */
final readonly class AssistantTurn
{
    public const ANSWER = 'answer';

    public const CLARIFY = 'clarify';

    public const PROPOSAL = 'proposal';

    public const UNSUPPORTED = 'unsupported';

    public const LIMIT = 'limit';

    public const ERROR = 'error';

    public const NAVIGATE = 'navigate';

    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $kind,
        public string $body,
        public array $payload = [],
        public ?AssistantActionProposal $proposal = null,
    ) {
    }
}

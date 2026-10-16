<?php

namespace App\Library\Assistant;

/**
 * Exactly what an action will do, as the server computed it from real records — shown to the customer BEFORE
 * they approve. `resolved` holds the validated, id-resolved arguments the executor will use; the proposal stores
 * them, so what runs is what was previewed and never a re-interpretation of the model's text.
 */
final readonly class ActionPreview
{
    /**
     * @param  array<int, array{label: string, value: string}>  $lines  the details (who/what/when/where)
     * @param  array<int, string>  $effects  plain sentences: what will happen, and what will not
     * @param  array<int, string>  $recipients  people who will be contacted by this action (empty when none)
     * @param  array<string, mixed>  $resolved
     */
    public function __construct(
        public string $title,
        public array $lines,
        public array $effects,
        public array $recipients,
        public ?string $cost,
        public array $resolved,
        public string $confirmLabel = 'Confirm',
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'lines' => $this->lines,
            'effects' => $this->effects,
            'recipients' => $this->recipients,
            'cost' => $this->cost,
            'confirmLabel' => $this->confirmLabel,
        ];
    }
}

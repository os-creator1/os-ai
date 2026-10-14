<?php

namespace App\Library\Assistant;

use App\Library\Assistant\Tools\ActionTool;
use App\Library\Assistant\Tools\ReadTool;
use Illuminate\Support\Facades\Validator;

/**
 * The CLOSED set of things the assistant can read or prepare. A capability exists only if a class here implements it
 * against an existing domain seam: the model cannot add a tool, rename one or widen its arguments. Anything it names
 * that is not registered AND available to this actor is refused, and arguments are validated against the tool's own
 * rules with unknown keys dropped.
 */
final class AssistantToolRegistry
{
    /** What the assistant honestly cannot do in V1 — told to the model so it says so instead of improvising. */
    public const UNSUPPORTED = [
        'publishing or editing website pages from chat (website changes are made in the website editor)',
        'sending a message directly from chat (a reply is prepared and sent by the customer in Follow-ups)',
        'switching an automation on, or building its steps from chat',
        'adding a contact from chat (contacts are added on the Add contact page)',
        'payments, refunds, invoices, billing or plan changes',
        'changing ads, budgets or campaigns',
        'anything about another business or workspace',
    ];

    /** @var array<string, ReadTool> */
    private array $reads = [];

    /** @var array<string, ActionTool> */
    private array $actions = [];

    /**
     * @param  iterable<ReadTool>  $reads
     * @param  iterable<ActionTool>  $actions
     */
    public function __construct(iterable $reads, iterable $actions)
    {
        foreach ($reads as $tool) {
            $this->reads[$tool->key()] = $tool;
        }

        foreach ($actions as $tool) {
            $this->actions[$tool->key()] = $tool;
        }
    }

    public function read(string $key): ?ReadTool
    {
        return $this->reads[$key] ?? null;
    }

    public function action(string $key): ?ActionTool
    {
        return $this->actions[$key] ?? null;
    }

    /** @return array<string, ReadTool> only the readers this actor may use */
    public function availableReads(AssistantContext $context): array
    {
        return array_filter($this->reads, fn (ReadTool $t): bool => $t->available($context));
    }

    /** @return array<string, ActionTool> */
    public function availableActions(AssistantContext $context): array
    {
        // Writes are never prepared on a client's behalf while an agency is viewing as them.
        if ($context->viewingAsClient()) {
            return [];
        }

        return array_filter($this->actions, fn (ActionTool $t): bool => $t->available($context));
    }

    /** The catalogue the model sees: authorised tools only, one line each. */
    public function catalogue(AssistantContext $context): string
    {
        $lines = ['READ tools (call to look things up):'];

        foreach ($this->availableReads($context) as $tool) {
            $lines[] = '- ' . $tool->key() . ': ' . $tool->description();
        }

        $lines[] = 'ACTION tools (propose; the customer approves before anything happens):';

        foreach ($this->availableActions($context) as $tool) {
            $lines[] = '- ' . $tool->key() . ': ' . $tool->description();
        }

        return implode("\n", $lines);
    }

    /**
     * Validate a model-supplied argument set against a tool's own rules. Returns only keys the rules name.
     *
     * @param  array<string, mixed>  $rules
     * @param  mixed  $args
     * @return array<string, mixed>
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function validate(array $rules, mixed $args): array
    {
        $args = is_array($args) ? array_intersect_key($args, $rules) : [];

        return Validator::make($args, $rules)->validate();
    }
}

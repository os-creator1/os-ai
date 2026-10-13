<?php

namespace App\Library\Assistant;

use Illuminate\Support\Str;
use Throwable;

/**
 * Answers the handful of requests that need NO model: an exact, well-known phrase that is a plain lookup or a plain
 * navigation. These are what the suggestion chips send, so using one costs nothing — no allowance is touched, and it
 * keeps working when the included AI is used up. Everything else, however it is worded, goes to the model.
 *
 * The answer is rendered from the same ReadTool result the model would have been given, so it can say nothing the
 * module did not report.
 */
final class AssistantFastPath
{
    /** normalised phrase => [tool, args] */
    private const PHRASES = [
        "show customers who haven't received a reply" => ['unanswered_conversations', []],
        'show customers who havent received a reply' => ['unanswered_conversations', []],
        'who is waiting for a reply' => ['unanswered_conversations', []],
        'show upcoming appointments' => ['schedule', []],
        'show my upcoming appointments' => ['schedule', []],
        'open my website' => ['open_page', ['page' => 'website']],
        'add a contact' => ['open_page', ['page' => 'add_contact']],
        'show my contacts' => ['open_page', ['page' => 'contacts']],
        'open my calendar' => ['open_page', ['page' => 'calendar']],
    ];

    public function __construct(private readonly AssistantToolRegistry $tools)
    {
    }

    /** Is this exact phrase answered without a model (so it needs no AI entitlement and no allowance)? */
    public function handles(string $question): bool
    {
        return isset(self::PHRASES[$this->normalise($question)]);
    }

    public function match(AssistantContext $context, string $question): ?AssistantTurn
    {
        $key = $this->normalise($question);
        $hit = self::PHRASES[$key] ?? null;

        if ($hit === null) {
            return null;
        }

        [$toolKey, $args] = $hit;
        $tool = $this->tools->read($toolKey);

        if ($tool === null || ! $tool->available($context)) {
            return new AssistantTurn(AssistantTurn::UNSUPPORTED, "That isn't available to you (your plan or role does not include it).", ['links' => []]);
        }

        try {
            $result = $tool->read($context, $this->tools->validate($tool->rules(), $args));
        } catch (Throwable $e) {
            report($e);

            return new AssistantTurn(AssistantTurn::ERROR, "I couldn't read that just now. Nothing was changed.", ['title' => "I couldn't load that", 'retryable' => true]);
        }

        return $this->render($toolKey, $result);
    }

    private function render(string $toolKey, ToolResult $r): AssistantTurn
    {
        $payload = ['facts' => $r->facts, 'links' => $r->links, 'limitations' => $r->limitations, 'suggestions' => [], 'deterministic' => true];

        if (! $r->available) {
            return new AssistantTurn(AssistantTurn::UNSUPPORTED, (string) ($r->limitations[0] ?? 'That is not available.'), ['links' => []]);
        }

        if ($toolKey === 'open_page') {
            return new AssistantTurn(AssistantTurn::NAVIGATE, 'Here you go.', ['links' => $r->links]);
        }

        if ($toolKey === 'unanswered_conversations') {
            $shown = $r->display;
            $total = (int) ($r->data['waiting_total'] ?? 0);
            $payload['suggestions'] = $shown === [] ? [] : ['Help me follow up with those leads'];

            if ($total === 0) {
                return new AssistantTurn(AssistantTurn::ANSWER, 'Nobody is waiting for a reply right now.', $payload);
            }

            $lines = array_map(fn (array $c): string => '• ' . $c['name'] . ' — "' . Str::limit($c['last_message'], 90) . '"', $shown);

            return new AssistantTurn(AssistantTurn::ANSWER, ($total === 1 ? '1 customer is' : $total . ' customers are') . " waiting for a reply:\n" . implode("\n", $lines), $payload);
        }

        if ($toolKey === 'schedule') {
            $rows = $r->display;

            if ($rows === []) {
                return new AssistantTurn(AssistantTurn::ANSWER, 'You have no appointments booked in the next 14 days.', $payload);
            }

            $lines = array_map(fn (array $a): string => '• ' . $a['when'] . ' — ' . $a['type'] . ' with ' . $a['with'], $rows);

            return new AssistantTurn(AssistantTurn::ANSWER, "Your upcoming appointments:\n" . implode("\n", $lines), $payload);
        }

        return new AssistantTurn(AssistantTurn::ANSWER, 'Here is what I found.', $payload);
    }

    private function normalise(string $q): string
    {
        $q = mb_strtolower(trim($q));
        $q = str_replace(['’', '‘'], "'", $q);

        return rtrim(preg_replace('/\s+/', ' ', $q) ?? $q, " .?!");
    }
}

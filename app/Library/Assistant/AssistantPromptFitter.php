<?php

namespace App\Library\Assistant;

use App\Library\Ai\AiModelRouter;
use App\Library\Ai\Enums\AiModelRoute;

/**
 * Makes the WHOLE serialized request fit the route's input ceiling BEFORE the gateway is called — system, tool catalogue,
 * server context, summary, history, the customer's message and every tool result message — measured with the gateway's
 * own estimator (AiModelRouter::estimateInputTokens), minus a configured headroom.
 *
 * Order of trimming, least valuable first, and the instructions are never touched:
 *   1. drop the oldest history turns, one at a time;
 *   2. shorten tool-result messages (halving, never below a floor);
 *   3. drop the compacted summary;
 * and if it STILL does not fit, report that rather than send it: an identical oversized request must never be retried.
 */
final class AssistantPromptFitter
{
    private const TOOL_RESULT_FLOOR_CHARS = 300;

    public function __construct(private readonly AiModelRouter $router)
    {
    }

    /** The token budget for the input: the route's ceiling less the headroom. */
    public function budget(AiModelRoute $route): int
    {
        $ceiling = (int) $this->router->config($route)['max_input_tokens'];
        $headroom = max(0, min(5000, (int) config('assistant.input_headroom_bps')));

        return (int) floor($ceiling * (10_000 - $headroom) / 10_000);
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $loop
     * @return array{messages: array<int, array{role: string, content: string}>, loop: array<int, array{role: string, content: string}>, tokens: int, budget: int, trimmed: array<int, string>}|null  null = cannot fit
     */
    public function fit(AssistantPrompt $prompt, array $loop, AiModelRoute $route): ?array
    {
        $budget = $this->budget($route);
        $keep = count($prompt->history);
        $withSummary = true;
        $trimmed = [];

        for ($guard = 0; $guard < 200; $guard++) {
            $messages = $prompt->messages($loop, $withSummary, $keep);
            $tokens = $this->router->estimateInputTokens($messages);

            if ($tokens <= $budget) {
                return ['messages' => $messages, 'loop' => $loop, 'tokens' => $tokens, 'budget' => $budget, 'trimmed' => array_values(array_unique($trimmed))];
            }

            if ($keep > 0) {
                $keep--;
                $trimmed[] = 'history';

                continue;
            }

            [$loop, $shrunk] = $this->shrinkToolResults($loop);

            if ($shrunk) {
                $trimmed[] = 'tool_results';

                continue;
            }

            if ($withSummary) {
                $withSummary = false;
                $trimmed[] = 'summary';

                continue;
            }

            return null;
        }

        return null;
    }

    /** @param array<int, array{role: string, content: string}> $loop @return array{0: array<int, array{role: string, content: string}>, 1: bool} */
    private function shrinkToolResults(array $loop): array
    {
        $changed = false;

        foreach ($loop as $i => $m) {
            if (! str_starts_with($m['content'], 'TOOL RESULTS')) {
                continue;
            }

            $len = mb_strlen($m['content']);

            if ($len <= self::TOOL_RESULT_FLOOR_CHARS + 30) {
                continue;
            }

            $loop[$i]['content'] = mb_substr($m['content'], 0, max(self::TOOL_RESULT_FLOOR_CHARS, intdiv($len, 2))) . '…[shortened to fit]';
            $changed = true;
        }

        return [$loop, $changed];
    }
}

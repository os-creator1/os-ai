<?php

namespace App\Library\Website\GuidedGeneration;

/**
 * Operational budgets and deterministic batching for guided website generation. Pure: no I/O, no AI.
 *
 * The complete page plan (WebsitePageStrategy) is written to the provider in small sequential batches so the size of a
 * website is no longer bounded by what fits in ONE response's output-token envelope. Batching is a cost/safety mechanism:
 * every limit here is explicit, deterministic, and enforced BEFORE the next provider request is made, so a failure or a
 * retry can never turn into unbounded spend.
 *
 * Worst case, per generation: MAX_AI_REQUESTS_PER_GENERATION provider requests (batches + corrective retries), each
 * limited to GuidedWebsiteGenerationClient::MAX_OUTPUT_TOKENS output tokens.
 */
final class GenerationBatchPlanner
{
    /**
     * Pages written per provider request. ~5 realistic pages fit comfortably inside one request's 8,000-output-token
     * envelope (the previous single request was proven to overflow at 20 pages).
     */
    public const MAX_PAGES_PER_BATCH = 5;

    /** Corrective retries allowed for any ONE batch (the existing single bounded retry, now per batch). */
    public const MAX_RETRIES_PER_BATCH = 1;

    /**
     * Hard ceiling on provider requests (first tries + retries) for one generation attempt. With
     * WebsitePageStrategy::MAX_PAGES_PER_GENERATION = 40 and 5 pages per batch there are at most 8 batches, so
     * this leaves room for 12 retries across the whole site while staying a fixed, small multiple of the batch count.
     */
    public const MAX_AI_REQUESTS_PER_GENERATION = 20;

    /** Required safety margin under the route's input-token ceiling: a batch is sized to at most 95% of it. */
    public const INPUT_HEADROOM = 0.05;

    /**
     * The estimated-input-token limit one batch request must stay under: the website_generation route's configured
     * ceiling minus INPUT_HEADROOM (12,000 -> 11,400). Read from the same config the AI gateway enforces.
     */
    public static function inputTokenLimit(): int
    {
        $ceiling = (int) config('ai.routes.website_generation.max_input_tokens', 12_000);

        return (int) floor($ceiling * (1 - self::INPUT_HEADROOM));
    }

    /**
     * The deterministic, size-aware batching of the complete plan, or a refusal. Pure given (plan, confirmed facts, config): no
     * AI call, no randomness, so batch numbers (and therefore request idempotency keys) are stable across identical attempts.
     *
     * Pages stay in plan order. A batch grows page by page (up to MAX_PAGES_PER_BATCH) while the ACTUAL prompt the client would
     * send — built by the same code, estimated by the gateway's own estimator, including the whole-site link context, the confirmed
     * facts and the worst-case list of SEO titles already used — stays within inputTokenLimit(). Nothing is truncated or removed
     * to make a batch fit. When even one page cannot fit, or the batches would need more requests than
     * MAX_AI_REQUESTS_PER_GENERATION, the plan is refused before any request is made.
     *
     * @param  array<int, array<string, mixed>>  $plan
     * @param  callable(array<int, array<string, mixed>>, int): int  $estimate  (batch pages, pages already written before it) => estimated input tokens
     * @return array{batches: ?array<int, array<int, array<string, mixed>>>, refusal: ?string}
     */
    public static function fit(array $plan, callable $estimate, int $limit, int $maxRequests = self::MAX_AI_REQUESTS_PER_GENERATION, int $perBatch = self::MAX_PAGES_PER_BATCH): array
    {
        $plan = array_values($plan);
        $total = count($plan);
        $batches = [];
        $start = 0;

        while ($start < $total) {
            $batch = [];

            for ($i = $start; $i < $total && count($batch) < max(1, $perBatch); $i++) {
                $candidate = [...$batch, $plan[$i]];

                if ($estimate($candidate, $start) > $limit) {
                    break;
                }

                $batch = $candidate;
            }

            if ($batch === []) {
                return ['batches' => null, 'refusal' => 'The page "' . (string) ($plan[$start]['page_key'] ?? $start) . '" cannot be written within the AI input limit (' . $limit . ' estimated tokens) even on its own, because the confirmed business facts and the site plan are too large. Nothing was changed and nothing was sent to the AI.'];
            }

            $batches[] = $batch;
            $start += count($batch);
        }

        if (count($batches) > $maxRequests) {
            return ['batches' => null, 'refusal' => 'This website would need ' . count($batches) . ' AI batches, more than the ' . $maxRequests . '-request limit for one generation. Nothing was changed and nothing was sent to the AI.'];
        }

        return ['batches' => $batches, 'refusal' => null];
    }

    /**
     * fit() for a real Business, using the guided client's own prompt construction and the gateway's estimator.
     *
     * @param  array<int, array<string, mixed>>  $plan  the complete AI plan
     * @return array{batches: ?array<int, array<int, array<string, mixed>>>, refusal: ?string}
     */
    public static function plan(\App\Models\Business $business, array $plan, GuidedWebsiteGenerationClient $client, ?int $limit = null, int $maxPages = \App\Library\Website\WebsitePageStrategy::MAX_PAGES_PER_GENERATION): array
    {
        $refusal = self::refusal($plan, $maxPages);
        if ($refusal !== null) {
            return ['batches' => null, 'refusal' => $refusal];
        }

        $facts = $client->canonicalFacts($business);

        return self::fit($plan, fn (array $batch, int $prior) => $client->estimateInputTokens($business, $batch, $plan, $prior, $facts), $limit ?? self::inputTokenLimit());
    }

    /**
     * Splits the complete plan, preserving its order, into batches of at most MAX_PAGES_PER_BATCH pages.
     *
     * @param  array<int, array<string, mixed>>  $plan
     * @return array<int, array<int, array<string, mixed>>>
     */
    public static function batches(array $plan, int $perBatch = self::MAX_PAGES_PER_BATCH): array
    {
        return array_values(array_chunk(array_values($plan), max(1, $perBatch)));
    }

    /**
     * Why the plan cannot be generated within the operational budgets, or null when it can. Checked before any
     * provider request: a plan that does not fit is refused outright, never silently trimmed.
     *
     * @param  array<int, array<string, mixed>>  $plan
     */
    public static function refusal(array $plan, int $maxPages, int $maxRequests = self::MAX_AI_REQUESTS_PER_GENERATION): ?string
    {
        if (count($plan) > $maxPages) {
            return 'This website plans ' . count($plan) . ' pages, more than the ' . $maxPages . '-page limit for one generation. Nothing was changed.';
        }

        if (count(self::batches($plan)) > $maxRequests) {
            return 'This website needs more AI requests than the ' . $maxRequests . '-request limit for one generation. Nothing was changed.';
        }

        return null;
    }
}

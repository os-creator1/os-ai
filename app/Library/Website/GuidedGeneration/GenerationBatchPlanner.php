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

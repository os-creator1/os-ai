<?php

namespace App\Library\Seo\Rank\Provider;

/**
 * The ONLY boundary between rank tracking and a paid SERP vendor.
 *
 * Contract: SEO KEYWORD RANK TRACKING V1 §4. Controllers, jobs and the budget
 * authority depend on this interface, never on a vendor class. Every method
 * is asynchronous-queue shaped (submit now, collect later) because scheduled
 * checks never use a live/real-time mode.
 *
 * The caller (SeoRankTrackingBudget) has ALREADY reserved the spend before
 * submit() is reached; an implementation must not retry a submit on its own.
 */
interface SeoRankProvider
{
    /** Stable provider key stored on targets, runs, observations and the ledger. */
    public function key(): string;

    /**
     * Whether credentials/endpoint are present so a call could even be attempted.
     * Local config check only — never a network call, never reveals a secret.
     */
    public function isConfigured(): bool;

    /**
     * Queue one task. Returns only after the provider has ACCEPTED it.
     *
     * @throws SeoRankProviderException  outcome Rejected = provider definitively
     *         did not create a task (safe to retry); Ambiguous = unknown whether
     *         a task (and a charge) exists (never auto-resubmit).
     */
    public function submit(SeoRankTaskRequest $request): SeoRankTaskSubmission;

    /**
     * Collect one task. Free at the provider. Never submits anything.
     *
     * @throws SeoRankProviderException
     */
    public function fetch(string $checkType, string $taskId): SeoRankTaskResult;

    /**
     * Completed-but-uncollected task ids keyed by the `tag` we sent (our run
     * uid). Used only to recover a run whose submit outcome was ambiguous.
     *
     * @return array<string, string> tag => provider task id
     *
     * @throws SeoRankProviderException
     */
    public function readyTasksByTag(string $checkType): array;

    /**
     * The provider's FREE location catalogue for one country (ISO alpha-2).
     *
     * @return list<array{code: int, name: string, parent_code: int|null, country_iso: string, type: string}>
     *
     * @throws SeoRankProviderException
     */
    public function locations(string $countryIso): array;
}

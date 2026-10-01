<?php

namespace App\Library\Website\GuidedGeneration;

use App\Library\Website\Setup\Exceptions\GenerationInProgressException;
use App\Models\Website;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Independent-review correction round 4 (item 1) — the single durable
 * Website-level generation coordinator shared by wizard generation,
 * Studio's "Regenerate with AI", and rebuild. Replaces round 3's
 * response-scoped `generation_started_at` freeze, which could not
 * coordinate Studio-originated generation at all (`WebsiteController::
 * generate()`/`rebuild()` never touched a QuestionnaireResponse) and
 * whose stale-lease recovery cleared every pending attempt for the
 * Website rather than only the one an expired lease actually owned.
 *
 * A Website, not a QuestionnaireResponse, is the one row every
 * generation-adjacent mutation (wizard answers, Studio asset edits,
 * template changes, a rebuild) already shares — so the lease lives there.
 *
 * `beginLease()` is non-blocking: a lease already active and not yet
 * expired throws GenerationInProgressException immediately (a friendly,
 * deterministic duplicate response), never blocks and never risks a
 * LockTimeoutException. The fencing token it returns must be threaded
 * through to GuidedGenerationCommitService::generateFull()/rebuild() and
 * then to release() — every clear is compare-and-swap, so an obsolete
 * worker (one whose lease already expired and was reclaimed) can never
 * clear a lease that is not its own.
 */
final class WebsiteGenerationCoordinator
{
    /**
     * Comfortably longer than one bounded AI call (OpenAiCompletionClient
     * now enforces its own PROVIDER_TIMEOUT_SECONDS, well under this)
     * plus its one corrective retry, validation, media binding, and the
     * atomic commit — so a lease is only ever reclaimed once it is
     * genuinely, unambiguously stale, never merely because a slow-but-
     * still-running provider call happened to take a while.
     */
    public const LEASE_SECONDS = 300;

    public function __construct(private readonly GuidedGenerationCommitService $guidedGeneration)
    {
    }

    /**
     * @throws GenerationInProgressException
     */
    public function beginLease(Website $website): string
    {
        return DB::transaction(function () use ($website) {
            $locked = Website::whereKey($website->id)->lockForUpdate()->firstOrFail();

            if ($locked->generation_lease_token !== null) {
                if (! $this->leaseExpired($locked)) {
                    throw new GenerationInProgressException('Your website is already being generated. Please wait a moment and check back.');
                }

                // Independent-review correction round 4 — recovers ONLY
                // the attempt THIS expired lease owns, never every
                // pending attempt for the Website.
                if ($locked->generation_lease_attempt_uid !== null) {
                    $this->guidedGeneration->recoverAttemptByUid($locked->generation_lease_attempt_uid);
                }
            }

            $token = (string) Str::uuid();

            $locked->forceFill([
                'generation_lease_token' => $token,
                'generation_lease_started_at' => now(),
                'generation_lease_attempt_uid' => null,
            ])->save();

            return $token;
        });
    }

    /**
     * Compare-and-swap release: clears the lease ONLY if it still carries
     * this exact token. A caller whose lease was already reclaimed as
     * stale (it ran past LEASE_SECONDS and a newer worker took over) has
     * nothing to release — its call here is a safe no-op, never a clear
     * of the newer worker's own, still-active lease. Used identically on
     * success and on failure; the attempt's own status is recorded
     * separately by GuidedGenerationCommitService.
     */
    public function release(Website $website, string $token): void
    {
        Website::where('id', $website->id)
            ->where('generation_lease_token', $token)
            ->update([
                'generation_lease_token' => null,
                'generation_lease_started_at' => null,
                'generation_lease_attempt_uid' => null,
            ]);
    }

    /**
     * A non-locking precondition guard for mutation endpoints that are
     * not themselves part of the generate/rebuild flow (e.g. Studio's
     * general asset upload/delete) — refuses while a lease is active and
     * not yet expired. Short-lived by design: the lock is released
     * before this returns, so callers needing a stronger guarantee
     * should hold their OWN lock across both this check and their
     * mutation (see WebsiteSetupSessionManager::runIfNotGenerating()).
     *
     * @throws GenerationInProgressException
     */
    public function assertNotLeased(Website $website): void
    {
        DB::transaction(function () use ($website) {
            $locked = Website::whereKey($website->id)->lockForUpdate()->firstOrFail();

            if ($locked->generation_lease_token !== null && ! $this->leaseExpired($locked)) {
                throw new GenerationInProgressException('Your website is currently being generated — changes cannot be made until it finishes.');
            }
        });
    }

    private function leaseExpired(Website $locked): bool
    {
        return $locked->generation_lease_started_at !== null
            && $locked->generation_lease_started_at->lt(now()->subSeconds(self::LEASE_SECONDS));
    }
}

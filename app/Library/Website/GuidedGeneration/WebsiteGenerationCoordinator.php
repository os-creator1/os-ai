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
 *
 * Independent-review correction round 5 — round 4's `assertNotLeased()`
 * (a non-locking precondition check, released before the caller's own
 * mutation ran) is REMOVED: it left a genuine check-then-mutate race
 * where a generation could acquire the lease in the gap between the
 * check and the write. Every caller that mutates generation inputs or
 * draft page/media state now goes through `runExclusive()` instead,
 * which holds the Website row lock across the ENTIRE mutation, not
 * merely the check.
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
        return $this->runExclusive($website, function (Website $locked) {
            $token = (string) Str::uuid();

            $locked->forceFill([
                'generation_lease_token' => $token,
                'generation_lease_started_at' => now(),
                'generation_lease_attempt_uid' => null,
            ])->save();

            // The lease is exclusive, so a pending attempt older than a whole lease can only be an orphan of a
            // crashed/timed-out request or worker. Fail it so it can never be mistaken for work in flight.
            $this->guidedGeneration->recoverStalePendingAttempts((int) $locked->id, self::LEASE_SECONDS);

            return $token;
        });
    }

    /**
     * Independent-review correction round 5 (item 1) — THE canonical
     * mutation boundary for anything that must never interleave with a
     * generation/rebuild on this Website. Round 4's `assertNotLeased()`
     * took and released the Website row lock BEFORE the caller performed
     * its own mutation, leaving a genuine gap: a generation could acquire
     * the lease in between the check and the write. This replaces that
     * pattern entirely — the check and the mutation now share ONE lock,
     * held across ONE transaction:
     *
     *   - locks the Website row;
     *   - resolves lease state (refuses for a genuinely active lease;
     *     recovers and clears an EXPIRED one before proceeding — see
     *     resolveLeaseState(), the one place this decision is made);
     *   - runs $callback with that SAME lock still held, inside the SAME
     *     transaction.
     *
     * A concurrent beginLease() call for this Website genuinely BLOCKS on
     * MySQL's own row lock until this transaction commits or rolls back
     * — it can never interleave with $callback's own writes. Conversely,
     * a mutation that arrives after generation has already committed its
     * lease genuinely refuses here, before $callback ever runs, never
     * after.
     *
     * $callback receives the LOCKED Website row and must perform its
     * ENTIRE mutation inside the closure for this guarantee to hold —
     * any database write belongs inside it. A filesystem write (e.g. an
     * asset upload) may still happen inside the closure too; if the
     * closure or the outer transaction later throws, the caller is
     * responsible for its own compensation (deleting an orphaned file)
     * exactly as the existing upload endpoints already do for their own
     * inner transactions.
     *
     * @template TReturn
     *
     * @param  \Closure(Website): TReturn  $callback
     * @return TReturn
     *
     * @throws GenerationInProgressException
     */
    public function runExclusive(Website $website, \Closure $callback): mixed
    {
        return DB::transaction(function () use ($website, $callback) {
            $locked = Website::whereKey($website->id)->lockForUpdate()->firstOrFail();

            $this->resolveLeaseState($locked);

            return $callback($locked);
        });
    }

    /**
     * Independent-review correction round 5 (item 2) — the ONE place
     * "is this lease still active" is decided, so no caller (previously
     * WebsiteSetupSessionManager::runIfNotGenerating()/beginEdit(), which
     * each separately and incorrectly checked only
     * `generation_lease_token !== null`, never expiry) can invent its own
     * definition of stale and permanently block on a crashed generation.
     *
     * Must be called with $locked already SELECT...FOR UPDATE'd by the
     * caller, inside the same transaction that will go on to mutate.
     *
     * @throws GenerationInProgressException
     */
    private function resolveLeaseState(Website $locked): void
    {
        if ($locked->generation_lease_token === null) {
            return;
        }

        if (! $this->leaseExpired($locked)) {
            throw new GenerationInProgressException('Your website is currently being generated — changes cannot be made until it finishes.');
        }

        // Independent-review correction round 4 — recovers ONLY the
        // attempt THIS expired lease owns, never every pending attempt
        // for the Website.
        if ($locked->generation_lease_attempt_uid !== null) {
            $this->guidedGeneration->recoverAttemptByUid($locked->generation_lease_attempt_uid);
        }

        // Independent-review correction round 5 (item 2) — the expired
        // lease is explicitly cleared here, under this SAME row lock, so
        // a crashed generation can never permanently freeze setup/Studio
        // mutations behind a lease nothing will ever come back to
        // release. Clearing it (rather than merely treating it as
        // "expired, so ignore it") also means the ORIGINAL worker's own
        // later release()/commit still only ever matches by exact token
        // — this row no longer carries that token, so both remain safe,
        // fenced no-ops.
        $locked->forceFill([
            'generation_lease_token' => null,
            'generation_lease_started_at' => null,
            'generation_lease_attempt_uid' => null,
        ])->save();
    }

    /**
     * Independent-review correction round 6 — the canonical fenced commit
     * for the LEGACY (non-template) AI draft generator
     * (WebsiteAiDraftGenerator), mirroring GuidedGenerationCommitService's
     * own final in-transaction fence exactly, rather than inventing a
     * second lease/fencing mechanism for this one remaining path: locks
     * the Website row, verifies the caller's lease token is STILL the one
     * on the row, and ONLY THEN runs the supplied page-creation callback
     * inside that SAME transaction. A Throwable from the callback rolls
     * back the whole transaction — $createPages must create the ENTIRE
     * batch or none of it ever persists.
     *
     * An obsolete worker (one whose lease already expired and was
     * reclaimed by a newer caller before this runs) finds its token no
     * longer matches and this returns false WITHOUT EVER invoking
     * $createPages — it writes zero pages, exactly like an obsolete
     * guided-generation worker's own final fence check.
     *
     * The AI provider call itself must already be finished before this is
     * called (§ the generator's own documented discipline of keeping
     * provider calls outside any database transaction) — this method only
     * ever wraps the already-validated page batch's persistence.
     *
     * @param  \Closure(Website): void  $createPages  receives the LOCKED Website row; must create the entire page batch
     * @return bool true if the batch was committed, false if this worker's lease had already been reclaimed (a safe, fenced no-op)
     */
    public function commitFencedLegacyDraft(Website $website, string $token, \Closure $createPages): bool
    {
        return DB::transaction(function () use ($website, $token, $createPages) {
            $locked = Website::whereKey($website->id)->lockForUpdate()->firstOrFail();

            if ($locked->generation_lease_token !== $token) {
                return false;
            }

            $createPages($locked);

            return true;
        });
    }

    /**
     * A queued job restarts the lease clock when it actually begins running, so time spent waiting in the queue never
     * eats into the lease the job needs for the provider call and the commit. Compare-and-swap on the token: false
     * means this job no longer owns the lease (it was reclaimed as stale) and must do nothing at all.
     */
    public function heartbeat(Website $website, string $token): bool
    {
        Website::where('id', $website->id)
            ->where('generation_lease_token', $token)
            ->update(['generation_lease_started_at' => now()]);

        // Not the affected-row count: MySQL reports 0 when the timestamp did not change within the same second.
        return Website::where('id', $website->id)->where('generation_lease_token', $token)->exists();
    }

    /**
     * A queued run died (exception, queue timeout, worker crash handled by the queue's failed callback). Fails the
     * attempt THIS lease owns and releases the lease, both only while the token still matches.
     */
    public function abandon(Website $website, string $token, string $reason): void
    {
        $owned = Website::where('id', $website->id)->where('generation_lease_token', $token)->value('generation_lease_attempt_uid');

        if ($owned !== null) {
            $this->guidedGeneration->recoverAttemptByUid($owned, $reason);
        }

        $this->release($website, $token);
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

    private function leaseExpired(Website $locked): bool
    {
        return $locked->generation_lease_started_at !== null
            && $locked->generation_lease_started_at->lt(now()->subSeconds(self::LEASE_SECONDS));
    }
}

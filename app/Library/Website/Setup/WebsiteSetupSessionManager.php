<?php

namespace App\Library\Website\Setup;

use App\Enums\Questionnaire\QuestionnaireResponseStatus;
use App\Library\Website\GuidedGeneration\GuidedGenerationCommitService;
use App\Library\Website\Setup\Exceptions\AnswerRevisionConflictException;
use App\Library\Website\Setup\Exceptions\GenerationInProgressException;
use App\Models\Business;
use App\Models\QuestionnaireDefinition;
use App\Models\QuestionnaireResponse;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Website Builder redesign — owns the setup wizard's state machine (start/
 * resume/autosave/back-navigation/complete), mirroring
 * `App\Library\Business\OnboardingManager`'s "thin controller, Manager
 * owns the state" shape, extended with real backward navigation (nothing
 * in this codebase's existing onboarding wizard needs that, since it is
 * forward-only).
 *
 * THE PIN happens here, once, in start(): a brand-new session reads the
 * definition's CURRENTLY published version and never changes
 * `questionnaire_version_id` again for the life of this response — later
 * platform edits publishing a new version have zero effect on an
 * in-progress session (QuestionnaireResponse's own class docblock).
 *
 * THE CLAIM: start() is idempotent per (business, definition) — a second
 * call finds and returns the existing `in_progress` response rather than
 * creating a duplicate, the same guarantee the `active_definition_guard`
 * DB constraint backstops.
 */
final class WebsiteSetupSessionManager
{
    /**
     * A conservative ceiling on the persisted `answers` JSON document —
     * bounds an otherwise-unbounded autosave payload (e.g. a forged
     * repeatable-group submission with an enormous number of items or an
     * oversized text field slipping past per-field limits) rather than
     * ever storing an unbounded blob.
     */
    private const MAX_ANSWERS_JSON_BYTES = 200_000;

    /**
     * Independent-review correction round 3 — `generation_started_at` is
     * a LEASE, not a permanent flag: a process that set it and then died
     * (crashed, timed out, or was killed) before ever clearing it must
     * not lock the customer out of retrying forever. A generous ceiling
     * — comfortably longer than one bounded AI call plus its one
     * corrective retry, validation, media binding, and the atomic commit
     * could ever legitimately take — so a lease is only ever reclaimed
     * once it is genuinely, unambiguously stale.
     */
    private const GENERATION_LEASE_SECONDS = 300;

    public function __construct(
        private readonly QuestionnaireStepResolver $stepResolver,
        private readonly GuidedGenerationCommitService $guidedGeneration,
    ) {
    }

    /**
     * The one seam every setup-mutation entry point (gallery/custom-
     * section uploads, template swap, back-navigation, answer edits)
     * shares: locks the response row, refuses while a generation is
     * genuinely in flight, and otherwise runs `$callback` with that SAME
     * lock still held — so a concurrent beginGeneration() call (which
     * locks this identical row) can never interleave with this
     * mutation's own writes. Never stores an uploaded file or writes
     * anything before this check passes (independent-review correction
     * round 3, item 1).
     *
     * @throws GenerationInProgressException
     */
    public function runIfNotGenerating(QuestionnaireResponse $response, \Closure $callback): mixed
    {
        return DB::transaction(function () use ($response, $callback) {
            $locked = QuestionnaireResponse::whereKey($response->id)->lockForUpdate()->firstOrFail();

            if ($locked->isGenerating()) {
                throw new GenerationInProgressException('Your website is currently being generated — changes cannot be made until it finishes.');
            }

            return $callback($locked);
        });
    }

    public function start(Business $business, string $questionnaireKey, ?int $websiteId = null): QuestionnaireResponse
    {
        $definition = QuestionnaireDefinition::where('key', $questionnaireKey)->firstOrFail();

        return DB::transaction(function () use ($business, $definition, $websiteId) {
            $existing = QuestionnaireResponse::where('business_id', $business->id)
                ->where('questionnaire_definition_id', $definition->id)
                ->where('status', QuestionnaireResponseStatus::InProgress->value)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $publishedVersion = $definition->publishedVersion();

            if ($publishedVersion === null) {
                throw new DomainException('This questionnaire has no published version yet.');
            }

            $firstStepKey = $this->stepResolver->firstStepKey($publishedVersion->steps(), []);

            return QuestionnaireResponse::create([
                'business_id' => $business->id,
                'website_id' => $websiteId,
                'questionnaire_definition_id' => $definition->id,
                'questionnaire_version_id' => $publishedVersion->id,
                'status' => QuestionnaireResponseStatus::InProgress,
                'current_step_key' => $firstStepKey,
                'answers' => [],
                'answers_revision' => 1,
                'started_at' => now(),
            ]);
        });
    }

    /**
     * Never trusts the caller's copy: re-loads by uid and verifies the
     * persisted `business_id` matches, mirroring CatalogItemManager's own
     * tenancy-recheck discipline.
     */
    public function resume(Business $business, string $uid): QuestionnaireResponse
    {
        $response = QuestionnaireResponse::where('uid', $uid)->first();

        if ($response === null || (int) $response->business_id !== (int) $business->id) {
            throw new DomainException('That setup session does not belong to this Business.');
        }

        return $response;
    }

    /**
     * @return ?array<string, mixed> null if current_step_key no longer resolves to a visible step
     */
    public function currentStep(QuestionnaireResponse $response): ?array
    {
        $steps = $response->version->steps();

        return $this->stepResolver->stepAt($steps, $response->answers ?? [], (string) $response->current_step_key);
    }

    /**
     * Optimistic-concurrency guarded: a caller must supply the revision it
     * last observed. A stale revision throws rather than silently
     * clobbering a write another tab already made.
     *
     * @throws AnswerRevisionConflictException
     */
    public function saveAnswer(QuestionnaireResponse $response, string $stepKey, mixed $value, int $expectedRevision): QuestionnaireResponse
    {
        return DB::transaction(function () use ($response, $stepKey, $value, $expectedRevision) {
            $locked = QuestionnaireResponse::whereKey($response->id)->lockForUpdate()->firstOrFail();

            if ($locked->isGenerating()) {
                throw new GenerationInProgressException('Your website is currently being generated — answers cannot change until it finishes.');
            }

            if ((int) $locked->answers_revision !== $expectedRevision) {
                throw new AnswerRevisionConflictException((int) $locked->answers_revision);
            }

            $answers = $locked->answers ?? [];
            $answers[$stepKey] = $value;

            if (strlen((string) json_encode($answers)) > self::MAX_ANSWERS_JSON_BYTES) {
                throw new DomainException('This answer is too large to save.');
            }

            $steps = $locked->version->steps();
            $nextStepKey = $this->stepResolver->nextStepKey($steps, $answers, $stepKey);

            $locked->forceFill([
                'answers' => $answers,
                'answers_revision' => $locked->answers_revision + 1,
                'current_step_key' => $nextStepKey ?? $stepKey,
            ])->save();

            return $locked->refresh();
        });
    }

    /**
     * Independent-review correction round — updates one step's stored
     * answer WITHOUT advancing `current_step_key`, unlike saveAnswer().
     * The custom-section step's inline image upload/remove/improve
     * actions call this instead: they happen WHILE the owner is still on
     * that step, not when they click Continue, so they must never
     * silently move the resume position past it the way saveAnswer()'s
     * own next-step advancement would.
     */
    public function updateAnswerInPlace(QuestionnaireResponse $response, string $stepKey, mixed $value): QuestionnaireResponse
    {
        return DB::transaction(function () use ($response, $stepKey, $value) {
            $locked = QuestionnaireResponse::whereKey($response->id)->lockForUpdate()->firstOrFail();

            if ($locked->isGenerating()) {
                throw new GenerationInProgressException('Your website is currently being generated — answers cannot change until it finishes.');
            }

            $answers = $locked->answers ?? [];
            $answers[$stepKey] = $value;

            if (strlen((string) json_encode($answers)) > self::MAX_ANSWERS_JSON_BYTES) {
                throw new DomainException('This answer is too large to save.');
            }

            $locked->forceFill([
                'answers' => $answers,
                'answers_revision' => $locked->answers_revision + 1,
            ])->save();

            return $locked->refresh();
        });
    }

    /**
     * The back arrow (and any direct jump to a previously-visited step):
     * refuses a step key that is not part of this questionnaire, or that
     * the CURRENT answers no longer make visible.
     */
    public function goToStep(QuestionnaireResponse $response, string $stepKey): QuestionnaireResponse
    {
        return $this->runIfNotGenerating($response, function (QuestionnaireResponse $locked) use ($stepKey) {
            $target = $this->stepResolver->stepAt($locked->version->steps(), $locked->answers ?? [], $stepKey);

            if ($target === null) {
                throw new DomainException('That step is not part of this questionnaire, or is not currently visible.');
            }

            $locked->forceFill(['current_step_key' => $stepKey])->save();

            return $locked->refresh();
        });
    }

    /**
     * Independent-review correction round 2 — the durable "generating"
     * freeze: re-verifies ownership, status, completeness, and the
     * caller's last-observed answers_revision ALL AT ONCE, under lock,
     * immediately after the per-response generation lock is acquired —
     * never trusting whatever the caller resolved before that lock. A
     * response that is not genuinely `in_progress`/complete/at the
     * expected revision right now refuses to enter generation at all
     * (DomainException), rather than silently reconciling/spending AI
     * against a response a concurrent request already completed or
     * changed. Success sets `generation_started_at`, which
     * saveAnswer()/updateAnswerInPlace() both refuse to write through
     * while set.
     *
     * @throws DomainException
     */
    public function beginGeneration(Business $business, QuestionnaireResponse $response, int $expectedRevision): QuestionnaireResponse
    {
        return DB::transaction(function () use ($business, $response, $expectedRevision) {
            $locked = QuestionnaireResponse::whereKey($response->id)->lockForUpdate()->first();

            if ($locked === null || (int) $locked->business_id !== (int) $business->id) {
                throw new DomainException('That setup session does not belong to this Business.');
            }

            if ($locked->status !== QuestionnaireResponseStatus::InProgress) {
                throw new DomainException('This setup session is no longer in progress.');
            }

            if ($locked->isGenerating()) {
                if (! $this->generationLeaseExpired($locked)) {
                    throw new DomainException('This website is already being generated.');
                }

                // Independent-review correction round 3 — the lease has
                // genuinely expired: whatever process set this freeze
                // never cleared it (crash, timeout, a killed request).
                // Recover the underlying stalled attempt too (never
                // merely the response's own flag alone) so it never
                // permanently blocks GuidedGenerationCommitService's own
                // idempotency match on a `pending` row that will never
                // move again, then fall through to re-verify everything
                // else fresh and re-freeze below — a genuinely active
                // attempt (lease not yet expired) is never touched.
                $this->guidedGeneration->recoverStaleAttempt((int) $locked->website_id, now()->subSeconds(self::GENERATION_LEASE_SECONDS));
            }

            if ((int) $locked->answers_revision !== $expectedRevision) {
                throw new AnswerRevisionConflictException((int) $locked->answers_revision);
            }

            if (! $this->stepResolver->isComplete($locked->version->steps(), $locked->answers ?? [])) {
                throw new DomainException('Answer every required question before generating your website.');
            }

            $locked->forceFill(['generation_started_at' => now()])->save();

            return $locked->refresh();
        });
    }

    /**
     * A technical failure returns the response to a genuinely retryable
     * in_progress state — never left frozen in "generating" forever.
     */
    public function recordGenerationFailure(QuestionnaireResponse $response): QuestionnaireResponse
    {
        $response->forceFill(['generation_started_at' => null])->save();

        return $response->refresh();
    }

    /**
     * Marks the response completed. Callers decide WHEN this may run —
     * WebsiteWizardController::generate() calls this only after the
     * website has actually been generated successfully, never before, so
     * a failed generation leaves the response `in_progress` and
     * genuinely retryable rather than stranded with no way back in.
     */
    public function complete(QuestionnaireResponse $response): QuestionnaireResponse
    {
        $steps = $response->version->steps();

        if (! $this->stepResolver->isComplete($steps, $response->answers ?? [])) {
            throw new DomainException('Answer every required question before finishing setup.');
        }

        $response->forceFill([
            'status' => QuestionnaireResponseStatus::Completed,
            'edit_mode' => false,
            'generation_started_at' => null,
            'completed_at' => now(),
        ])->save();

        return $response->refresh();
    }

    /**
     * "Edit setup answers" reopens the SAME pinned response/version
     * (never a new one) by flipping it back to `in_progress` so every
     * existing wizard route's resume check keeps working unmodified;
     * `edit_mode` records that finishing this session must only
     * reconcile canonical facts (WebsiteSetupAnswerApplier), never call
     * guided generation again. Jumps back to the first visible step so
     * the owner reviews the whole questionnaire, since the back arrow
     * already lets them reach every earlier step from there.
     */
    public function beginEdit(Business $business, QuestionnaireResponse $completed): QuestionnaireResponse
    {
        if ((int) $completed->business_id !== (int) $business->id) {
            throw new DomainException('That setup session does not belong to this Business.');
        }

        if ($completed->status !== QuestionnaireResponseStatus::Completed) {
            throw new DomainException('Only a completed setup session can be reopened for editing.');
        }

        return DB::transaction(function () use ($completed) {
            $locked = QuestionnaireResponse::whereKey($completed->id)->lockForUpdate()->firstOrFail();

            $firstStepKey = $this->stepResolver->firstStepKey($locked->version->steps(), $locked->answers ?? []);

            $locked->forceFill([
                'status' => QuestionnaireResponseStatus::InProgress,
                'edit_mode' => true,
                'current_step_key' => $firstStepKey,
                'completed_at' => null,
            ])->save();

            return $locked->refresh();
        });
    }

    /**
     * The edit-existing counterpart to complete(): reconciliation already
     * ran (WebsiteSetupAnswerApplier::apply()) before this is called, and
     * this never triggers guided generation — manually edited page
     * content is never touched by an answer edit alone.
     */
    public function completeEdit(QuestionnaireResponse $response): QuestionnaireResponse
    {
        $response->forceFill([
            'status' => QuestionnaireResponseStatus::Completed,
            'edit_mode' => false,
            'generation_started_at' => null,
            'completed_at' => now(),
        ])->save();

        return $response->refresh();
    }

    private function generationLeaseExpired(QuestionnaireResponse $locked): bool
    {
        return $locked->generation_started_at !== null
            && $locked->generation_started_at->lt(now()->subSeconds(self::GENERATION_LEASE_SECONDS));
    }

    /**
     * Independent-review correction round 3 — durable Improve
     * idempotency (item 2), replacing a Cache lock plus a 60-second
     * Cache "done" marker with real, response-row-locked state that
     * survives a cache eviction and is identical across every
     * application server. Persists the submitted (pre-improvement) text
     * exactly like an ordinary autosave — but only when this is
     * genuinely a NEW logical submission — and opens exactly one
     * `pending` attempt for it, all inside the same lock that also
     * refuses while a generation is in flight.
     *
     * `$idempotencyKey` is the caller's durable identity for this EXACT
     * logical submission (response + the answers_revision the owner's
     * form last observed + title/body/layout) — a changed title or body,
     * or a submission against a since-changed revision, always produces
     * a different key and is always treated as new.
     *
     * @param  array{key: string, name: string, description: ?string, body: ?string, layout: string, images: array<int, string>}  $entry
     * @return array{outcome: 'start'|'duplicate_pending'|'duplicate_succeeded', response: QuestionnaireResponse, result: ?array}
     * @throws GenerationInProgressException
     */
    public function beginCustomSectionImprove(QuestionnaireResponse $response, string $idempotencyKey, array $entry): array
    {
        return DB::transaction(function () use ($response, $idempotencyKey, $entry) {
            $locked = QuestionnaireResponse::whereKey($response->id)->lockForUpdate()->firstOrFail();

            if ($locked->isGenerating()) {
                throw new GenerationInProgressException('Your website is currently being generated — changes cannot be made until it finishes.');
            }

            // A pending or already-succeeded attempt for this EXACT
            // logical submission already exists — converge to it rather
            // than spending AI (or re-persisting the pre-improvement
            // text) a second time. A previously FAILED attempt for the
            // same key is never short-circuited: a genuine retry of
            // identical content must be able to try again.
            if ($locked->custom_section_improve_key === $idempotencyKey
                && $locked->custom_section_improve_status !== QuestionnaireResponse::IMPROVE_STATUS_FAILED) {
                return [
                    'outcome' => $locked->custom_section_improve_status === QuestionnaireResponse::IMPROVE_STATUS_PENDING
                        ? 'duplicate_pending' : 'duplicate_succeeded',
                    'response' => $locked,
                    'result' => $locked->custom_section_improve_result,
                ];
            }

            $answers = $locked->answers ?? [];
            $answers['custom_section'] = [$entry];

            if (strlen((string) json_encode($answers)) > self::MAX_ANSWERS_JSON_BYTES) {
                throw new DomainException('This answer is too large to save.');
            }

            $startedRevision = $locked->answers_revision + 1;

            $locked->forceFill([
                'answers' => $answers,
                'answers_revision' => $startedRevision,
                'custom_section_improve_key' => $idempotencyKey,
                'custom_section_improve_status' => QuestionnaireResponse::IMPROVE_STATUS_PENDING,
                'custom_section_improve_started_revision' => $startedRevision,
                'custom_section_improve_result' => null,
            ])->save();

            return ['outcome' => 'start', 'response' => $locked->refresh(), 'result' => null];
        });
    }

    /**
     * Settles the attempt `beginCustomSectionImprove()` opened. Runs
     * AFTER the AI call, which stays outside any database transaction
     * (matching GuidedGenerationCommitService's own documented
     * discipline). Compare-and-swap: if the owner saved a further edit
     * (bumping `answers_revision`) while this call was in flight, or a
     * newer logical submission already opened its OWN attempt (a
     * different `custom_section_improve_key`), the AI result here is
     * discarded rather than silently overwriting whatever is current now
     * — never a stale response beating a newer edit.
     *
     * @return array{outcome: 'succeeded'|'failed'|'stale'|'stale_edit', response: ?QuestionnaireResponse}
     */
    public function completeCustomSectionImprove(QuestionnaireResponse $response, string $idempotencyKey, ?string $improvedBody): array
    {
        return DB::transaction(function () use ($response, $idempotencyKey, $improvedBody) {
            $locked = QuestionnaireResponse::whereKey($response->id)->lockForUpdate()->firstOrFail();

            if ($locked->custom_section_improve_key !== $idempotencyKey) {
                return ['outcome' => 'stale', 'response' => null];
            }

            if ((int) $locked->answers_revision !== (int) $locked->custom_section_improve_started_revision) {
                $locked->forceFill(['custom_section_improve_status' => QuestionnaireResponse::IMPROVE_STATUS_FAILED])->save();

                return ['outcome' => 'stale_edit', 'response' => null];
            }

            if ($improvedBody === null) {
                $locked->forceFill(['custom_section_improve_status' => QuestionnaireResponse::IMPROVE_STATUS_FAILED])->save();

                return ['outcome' => 'failed', 'response' => $locked->refresh()];
            }

            $entries = $locked->answer('custom_section');
            $entry = is_array($entries) && isset($entries[0]) && is_array($entries[0]) ? $entries[0] : [];
            $entry['body'] = $improvedBody;

            $answers = $locked->answers ?? [];
            $answers['custom_section'] = [$entry];

            $locked->forceFill([
                'answers' => $answers,
                'answers_revision' => $locked->answers_revision + 1,
                'custom_section_improve_status' => QuestionnaireResponse::IMPROVE_STATUS_SUCCEEDED,
                'custom_section_improve_result' => ['body' => $improvedBody],
            ])->save();

            return ['outcome' => 'succeeded', 'response' => $locked->refresh()];
        });
    }
}

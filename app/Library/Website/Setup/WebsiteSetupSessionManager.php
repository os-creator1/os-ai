<?php

namespace App\Library\Website\Setup;

use App\Enums\Questionnaire\QuestionnaireResponseStatus;
use App\Library\Website\GuidedGeneration\WebsiteGenerationCoordinator;
use App\Library\Website\Setup\Exceptions\AnswerRevisionConflictException;
use App\Library\Website\Setup\Exceptions\GenerationInProgressException;
use App\Models\Business;
use App\Models\QuestionnaireDefinition;
use App\Models\QuestionnaireResponse;
use App\Models\Website;
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

    public function __construct(
        private readonly QuestionnaireStepResolver $stepResolver,
        private readonly WebsiteGenerationCoordinator $generationCoordinator,
    ) {
    }

    /**
     * The one seam every setup-mutation entry point (gallery/custom-
     * section uploads, template swap, back-navigation, answer edits)
     * shares. Independent-review correction round 4 (item 1) — locks the
     * WEBSITE row FIRST (same row WebsiteGenerationCoordinator leases),
     * THEN locks the response row and runs `$callback` with BOTH locks
     * still held — so a concurrent generate() call, whether wizard- or
     * Studio-originated (both acquire the identical Website lease), can
     * never interleave with this mutation's own writes, and this
     * mutation can never silently race a lease that is acquired a moment
     * later (the website row lock is held for the whole transaction, not
     * merely checked once up front). Never stores an uploaded file or
     * writes anything before this check passes. Lock order (Website,
     * then QuestionnaireResponse) is fixed everywhere this is called, so
     * two callers can never deadlock against each other.
     *
     * Independent-review correction round 5 (item 2) — the Website-lock-
     * and-refuse-while-leased half of this is now delegated entirely to
     * WebsiteGenerationCoordinator::runExclusive(), the one place lease
     * expiry is decided. This method used to check only
     * `generation_lease_token !== null` itself, with no expiry check at
     * all — a crashed generation's lease (its token left set forever,
     * since nothing was left running to call release()) permanently
     * blocked every setup mutation until some LATER generation attempt
     * happened to reclaim and then release it. runExclusive() instead
     * recovers and clears a genuinely EXPIRED lease before proceeding,
     * while still refusing immediately for a genuinely active one.
     *
     * @throws GenerationInProgressException
     */
    public function runIfNotGenerating(QuestionnaireResponse $response, \Closure $callback): mixed
    {
        if ($response->website_id === null) {
            return DB::transaction(function () use ($response, $callback) {
                $locked = QuestionnaireResponse::whereKey($response->id)->lockForUpdate()->firstOrFail();

                return $callback($locked);
            });
        }

        $website = Website::findOrFail($response->website_id);

        return $this->generationCoordinator->runExclusive($website, function () use ($response, $callback) {
            $locked = QuestionnaireResponse::whereKey($response->id)->lockForUpdate()->firstOrFail();

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
        return $this->runIfNotGenerating($response, function (QuestionnaireResponse $locked) use ($stepKey, $value, $expectedRevision) {
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
        return $this->runIfNotGenerating($response, function (QuestionnaireResponse $locked) use ($stepKey, $value) {
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
     * Re-verifies ownership, status, completeness, and the caller's
     * last-observed answers_revision, under lock. Independent-review
     * correction round 4 (item 1) — the generation LEASE itself (and its
     * own stale-lease recovery) is now entirely
     * WebsiteGenerationCoordinator's responsibility, acquired on the
     * Website BEFORE this is ever called; this method no longer checks
     * or sets any freeze of its own — it purely validates the RESPONSE's
     * own preconditions now that the caller already holds the Website
     * lease (so nothing else can be mutating this response concurrently).
     * `generation_started_at` is still set, as an audit/UI-facing mirror
     * of "a generation is in flight for this response" — it is never the
     * authority for concurrency control.
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

        // Independent-review correction round 4 (item 1) / round 5 (item
        // 2) — edit-session OPENING coordinates through the same Website
        // lock, via the same runExclusive()/resolveLeaseState() path
        // every other setup mutation now uses: a generation already in
        // flight must never have its own facts yanked out from under it,
        // but a merely CRASHED one (expired lease, nothing left to
        // release it) must never permanently block reopening either.
        $open = function () use ($completed) {
            $locked = QuestionnaireResponse::whereKey($completed->id)->lockForUpdate()->firstOrFail();

            $firstStepKey = $this->stepResolver->firstStepKey($locked->version->steps(), $locked->answers ?? []);

            $locked->forceFill([
                'status' => QuestionnaireResponseStatus::InProgress,
                'edit_mode' => true,
                'current_step_key' => $firstStepKey,
                'completed_at' => null,
            ])->save();

            return $locked->refresh();
        };

        if ($completed->website_id === null) {
            return DB::transaction($open);
        }

        $website = Website::findOrFail($completed->website_id);

        return $this->generationCoordinator->runExclusive($website, $open);
    }

    /**
     * Website creation flow fix — a `completed` response whose website
     * never actually has pages (generation never succeeded on its behalf,
     * or every generated page was later deleted) must not strand the
     * owner: this flips it back to a plain first-time `in_progress`
     * session (NOT edit_mode — finishing it MUST generate) parked on its
     * final question, which is exactly where the wizard sends an owner to
     * the review/generate screen. Never creates a second response or
     * Website.
     */
    public function reopenForGeneration(QuestionnaireResponse $completed): QuestionnaireResponse
    {
        if ($completed->status !== QuestionnaireResponseStatus::Completed) {
            return $completed;
        }

        return $this->runIfNotGenerating($completed, function (QuestionnaireResponse $locked) {
            if ($locked->status !== QuestionnaireResponseStatus::Completed) {
                return $locked;
            }

            $visible = $this->stepResolver->visibleSteps($locked->version->steps(), $locked->answers ?? []);
            $lastKey = $visible !== [] ? (string) end($visible)['key'] : (string) $locked->current_step_key;

            $locked->forceFill([
                'status' => QuestionnaireResponseStatus::InProgress,
                'edit_mode' => false,
                'current_step_key' => $lastKey,
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

    /**
     * Independent-review correction round 4 (item 2) — an abandoned
     * pending attempt (the process calling the provider crashed or was
     * killed before completeCustomSectionImprove() ever ran) must not
     * block a genuine retry forever. Deliberately short: an Improve call
     * is far smaller than a full-site generation (OpenAiCompletionClient
     * ::PROVIDER_TIMEOUT_SECONDS bounds the provider call itself well
     * under this).
     */
    private const IMPROVE_PENDING_LEASE_SECONDS = 90;

    /**
     * Independent-review correction round 4 (item 2) — durable Improve
     * idempotency, corrected: `$expectedRevision` is now REQUIRED and
     * compared against the locked row BEFORE anything is persisted — a
     * stale form (one that observed an older revision than what is
     * actually current) is refused outright with
     * AnswerRevisionConflictException, never allowed to acquire the lock
     * and overwrite a newer edit with its own stale text.
     *
     * `$logicalKey` identifies the LOGICAL submission (response +
     * observed revision + exact title/body/layout) and stays stable
     * across a legitimate retry of that same submission. The PROVIDER-
     * FACING ledger key returned here is a SEPARATE, always-fresh-per-
     * attempt value (`$logicalKey` plus a durable attempt ordinal) — a
     * failed attempt's retry therefore never reuses the same AiGateway
     * ledger idempotency key, which AiGateway's own documentation warns
     * can otherwise throw UniqueConstraintViolationException.
     *
     * A PENDING attempt for the identical logical key converges to
     * 'duplicate_pending' UNLESS its own pending lease has expired
     * (IMPROVE_PENDING_LEASE_SECONDS) — an abandoned attempt is instead
     * recovered here and a fresh attempt (next ordinal) begins.
     *
     * @param  array{key: string, name: string, description: ?string, body: ?string, layout: string, images: array<int, string>}  $entry
     * @return array{outcome: 'start'|'duplicate_pending'|'duplicate_succeeded', response: QuestionnaireResponse, result: ?array, ledgerKey: ?string}
     * @throws GenerationInProgressException
     * @throws AnswerRevisionConflictException
     */
    public function beginCustomSectionImprove(QuestionnaireResponse $response, string $answerKey, string $logicalKey, array $entry, int $expectedRevision): array
    {
        return $this->runIfNotGenerating($response, function (QuestionnaireResponse $locked) use ($answerKey, $logicalKey, $entry, $expectedRevision) {
            // Independent-review correction round 4 (item 2) — whether
            // this is a recognized resubmission of the EXACT SAME logical
            // content is decided before the raw revision match is ever
            // enforced. A literal retry of the identical {observed
            // revision, title, body, layout} tuple already matches
            // something this response has already accepted and validated
            // once — it is never "stale" merely because this response's
            // revision counter has since moved on to reflect that very
            // submission (begin() below persists the submitted text and
            // bumps the revision immediately, before AI ever runs). Only
            // a submission that is NOT a recognized duplicate — genuinely
            // different content, or a different originally-observed
            // revision — must match the CURRENT revision exactly.
            $sameLogicalKey = $locked->custom_section_improve_key === $logicalKey;

            if ($sameLogicalKey && $locked->custom_section_improve_status === QuestionnaireResponse::IMPROVE_STATUS_SUCCEEDED) {
                return [
                    'outcome' => 'duplicate_succeeded',
                    'response' => $locked,
                    'result' => $locked->custom_section_improve_result,
                    'ledgerKey' => null,
                ];
            }

            if ($sameLogicalKey && $locked->custom_section_improve_status === QuestionnaireResponse::IMPROVE_STATUS_PENDING) {
                $pendingExpired = $locked->custom_section_improve_pending_started_at === null
                    || $locked->custom_section_improve_pending_started_at->lt(now()->subSeconds(self::IMPROVE_PENDING_LEASE_SECONDS));

                if (! $pendingExpired) {
                    return [
                        'outcome' => 'duplicate_pending',
                        'response' => $locked,
                        'result' => null,
                        'ledgerKey' => null,
                    ];
                }
                // Independent-review correction round 4 (item 2) —
                // abandoned pending attempt: fall through to start a
                // fresh, incremented-ordinal attempt for the SAME
                // logical key. The submitted text is identical (it is
                // the same logical key), so there is nothing new to
                // persist, only a new attempt to open.
            }

            // A genuinely new/different submission (sameLogicalKey is
            // false — different text, title, or originally-observed
            // revision) must still match this response's current
            // revision exactly, same as any other mutation.
            if (! $sameLogicalKey && (int) $locked->answers_revision !== $expectedRevision) {
                throw new AnswerRevisionConflictException((int) $locked->answers_revision);
            }

            $ordinal = $sameLogicalKey ? (int) ($locked->custom_section_improve_attempt_ordinal ?? 0) + 1 : 1;
            $ledgerKey = hash('sha256', $logicalKey . ':attempt:' . $ordinal);

            $answers = $locked->answers ?? [];
            $answers[$answerKey] = [$entry];

            if (strlen((string) json_encode($answers)) > self::MAX_ANSWERS_JSON_BYTES) {
                throw new DomainException('This answer is too large to save.');
            }

            $startedRevision = $sameLogicalKey ? (int) $locked->answers_revision : $locked->answers_revision + 1;

            $locked->forceFill(array_merge([
                'custom_section_improve_key' => $logicalKey,
                'custom_section_improve_ledger_key' => $ledgerKey,
                'custom_section_improve_attempt_ordinal' => $ordinal,
                'custom_section_improve_status' => QuestionnaireResponse::IMPROVE_STATUS_PENDING,
                'custom_section_improve_started_revision' => $startedRevision,
                'custom_section_improve_pending_started_at' => now(),
                'custom_section_improve_result' => null,
            ], $sameLogicalKey ? [] : [
                'answers' => $answers,
                'answers_revision' => $startedRevision,
            ]))->save();

            return ['outcome' => 'start', 'response' => $locked->refresh(), 'result' => null, 'ledgerKey' => $ledgerKey];
        });
    }

    /**
     * Settles the attempt `beginCustomSectionImprove()` opened. Runs
     * AFTER the AI call, which stays outside any database transaction
     * (matching GuidedGenerationCommitService's own documented
     * discipline). Compare-and-swap on BOTH the logical key and the
     * specific attempt's ledger key (independent-review correction round
     * 4, item 2 — an abandoned attempt that is recovered and retried
     * gets a NEW ledger key; a late completion from the original,
     * abandoned attempt must never be mistaken for the new one even
     * though they share the same logical key), and on `answers_revision`
     * (a further edit saved while this call was in flight discards the
     * result rather than overwriting it).
     *
     * @return array{outcome: 'succeeded'|'failed'|'stale'|'stale_edit', response: ?QuestionnaireResponse}
     */
    public function completeCustomSectionImprove(QuestionnaireResponse $response, string $answerKey, string $logicalKey, string $ledgerKey, ?string $improvedBody): array
    {
        return DB::transaction(function () use ($answerKey, $response, $logicalKey, $ledgerKey, $improvedBody) {
            $locked = QuestionnaireResponse::whereKey($response->id)->lockForUpdate()->firstOrFail();

            if ($locked->custom_section_improve_key !== $logicalKey || $locked->custom_section_improve_ledger_key !== $ledgerKey) {
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

            $entries = $locked->answer($answerKey);
            $entry = is_array($entries) && isset($entries[0]) && is_array($entries[0]) ? $entries[0] : [];
            $entry['body'] = $improvedBody;

            $answers = $locked->answers ?? [];
            $answers[$answerKey] = [$entry];

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

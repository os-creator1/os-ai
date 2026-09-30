<?php

namespace App\Library\Website\Setup;

use App\Enums\Questionnaire\QuestionnaireResponseStatus;
use App\Library\Website\Setup\Exceptions\AnswerRevisionConflictException;
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

    public function __construct(private readonly QuestionnaireStepResolver $stepResolver)
    {
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
        $steps = $response->version->steps();
        $target = $this->stepResolver->stepAt($steps, $response->answers ?? [], $stepKey);

        if ($target === null) {
            throw new DomainException('That step is not part of this questionnaire, or is not currently visible.');
        }

        $response->forceFill(['current_step_key' => $stepKey])->save();

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
            'completed_at' => now(),
        ])->save();

        return $response->refresh();
    }
}

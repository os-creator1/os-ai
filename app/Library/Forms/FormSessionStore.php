<?php

namespace App\Library\Forms;

use App\Models\FormSession;
use App\Models\FormSubmission;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Forms V1 — the ONE writer of `form_sessions`: the server-side, in-progress
 * state of a multi-page questionnaire.
 *
 * IN PROGRESS IS NOT HISTORY. Nothing here creates a Contact, an Opportunity, an
 * event or a `form_submissions` row; that is FormSubmissionService's final
 * submit alone. A session only remembers answers the server has ALREADY
 * validated against the pinned version, so the browser never carries an answer
 * blob that could be altered between pages.
 *
 * PINNED, ORDERED, BOUNDED.
 *  - A session belongs to one (deployment, nonce) — the HMAC-bound identity issued
 *    when the questionnaire started — and to ONE immutable version. A request
 *    pinned to a different version than the session's is refused.
 *  - Pages are completed in order: page N can be saved (or opened) only once every
 *    earlier page has been completed. A stale or invented page key fails closed.
 *    There is no branching: the order is the version's stored order, always.
 *  - A session is honoured for SESSION_TTL_HOURS from its last answer, then
 *    refused. It can only ever hold keys of the pinned (bounded) definition.
 *  - After the final submit commits, the session is stamped with the submission
 *    and takes no further answers.
 *
 * Every method is handed the already-authorised FormDeploymentContext
 * (FormDeploymentResolver has just re-proven the current authority); this class
 * adds no second authority check.
 */
final class FormSessionStore
{
    public const SESSION_TTL_HOURS = 24;

    /** Attempts for the creating transaction; a deadlock between two first-page saves is retried. */
    private const DEADLOCK_ATTEMPTS = 3;

    public function find(FormDeploymentContext $context, string $nonce): ?FormSession
    {
        return FormSession::query()
            ->where('form_deployment_id', $context->deployment->id)
            ->where('operation_nonce', $nonce)
            ->first();
    }

    /**
     * May the visitor OPEN this page now? Used by the page GET, which answers a
     * "no" with a 404 (a stale or forged page is indistinguishable from none).
     */
    public function canOpen(FormDeploymentContext $context, ?FormSession $session, string $pageKey): bool
    {
        $index = $context->version->pageIndex($pageKey);

        if ($index === null) {
            return false;
        }

        if ($session === null) {
            return $index === 0;
        }

        return (int) $session->form_version_id === (int) $context->version->id
            && ! $session->isExpired()
            && ! $session->isFinalized()
            && $this->earlierPagesCompleted($context, $session, $index);
    }

    /**
     * Records the validated answers of ONE NON-FINAL page and marks it completed,
     * in its own short transaction. The LAST page never comes through here: the
     * final step is finished at one atomic boundary by FormSubmissionService
     * (see lockForFinal()/finalize()).
     *
     * A finalized session is returned untouched — a late save, including an
     * earlier-page edit that raced the final submit and lost, cannot change it.
     *
     * @param  array<string, mixed>  $pageValues  normalized values for the fields of $pageKey only
     *
     * @throws ValidationException when the page or flow is not acceptable
     */
    public function savePage(FormDeploymentContext $context, string $nonce, string $pageKey, array $pageValues): FormSession
    {
        $index = $context->version->pageIndex($pageKey) ?? throw $this->refused('page', 'That page is not part of this form.');

        try {
            return $this->save($context, $nonce, $pageKey, $index, $pageValues);
        } catch (UniqueConstraintViolationException) {
            // A twin created the session between our read and our insert.
            return $this->save($context, $nonce, $pageKey, $index, $pageValues);
        }
    }

    /**
     * The FIRST step of the final submit's transaction: re-reads the session
     * AUTHORITATIVELY by (deployment, nonce) under `FOR UPDATE` and re-proves its
     * identity. Must be the first statement of the caller's transaction — before
     * any plain SELECT — so everything the caller then reads is read AFTER the lock
     * is held and therefore sees whatever a competing page save committed.
     *
     * A session that does not exist cannot be finished (a questionnaire starts on
     * its first page). A FINALIZED session is returned as-is for the caller to
     * treat as a replay; any other must be pinned to this version and unexpired.
     *
     * @throws ValidationException
     */
    public function lockForFinal(FormDeploymentContext $context, string $nonce): FormSession
    {
        $session = FormSession::query()
            ->where('form_deployment_id', $context->deployment->id)
            ->where('operation_nonce', $nonce)
            ->lockForUpdate()
            ->first() ?? throw $this->refused('page', 'Start the form from its first page.');

        if ((int) $session->form_version_id !== (int) $context->version->id) {
            throw $this->refused('form', 'This form has expired. Reload the page and send it again.');
        }

        if (! $session->isFinalized() && $session->isExpired()) {
            throw $this->refused('form', 'This form has expired. Reload the page and send it again.');
        }

        return $session;
    }

    /**
     * Every page BEFORE $index must be completed. Called under the lock taken by
     * lockForFinal(), so it judges the authoritative session, not a stale copy.
     *
     * @throws ValidationException
     */
    public function assertEarlierPagesCompleted(FormDeploymentContext $context, FormSession $session, int $index): void
    {
        if (! $this->earlierPagesCompleted($context, $session, $index)) {
            throw $this->refused('page', 'Please complete the earlier pages first.');
        }
    }

    /**
     * Stamps the session as final. Called INSIDE the transaction that commits the
     * submission, on the row that transaction LOCKED with lockForFinal(), so an
     * in-progress session can never read as finalized without its submission and
     * the two can never disagree.
     *
     * It also writes the session's answers as EXACTLY the values the submission
     * was built from — the one authoritative answer set the lock selected — so a
     * finalized session is a faithful copy of its submission.
     *
     * @param  array<string, mixed>  $values  the complete validated answers the submission was created from
     * @param  list<string>  $pageKeys  every page of the pinned version (a finished questionnaire has completed them all)
     */
    public function finalize(FormSession $session, FormSubmission $submission, array $values, array $pageKeys): void
    {
        DB::table('form_sessions')->where('id', $session->id)->update([
            'answers' => json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'completed_pages' => json_encode(array_values($pageKeys)),
            'form_submission_id' => $submission->id,
            'finalized_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $pageValues
     */
    private function save(FormDeploymentContext $context, string $nonce, string $pageKey, int $index, array $pageValues): FormSession
    {
        return DB::transaction(function () use ($context, $nonce, $pageKey, $index, $pageValues): FormSession {
            $session = FormSession::query()
                ->where('form_deployment_id', $context->deployment->id)
                ->where('operation_nonce', $nonce)
                ->lockForUpdate()
                ->first();

            if ($session === null) {
                // A questionnaire can only START on its first page.
                if ($index !== 0) {
                    throw $this->refused('page', 'Start the form from its first page.');
                }

                $session = FormSession::create([
                    'form_deployment_id' => $context->deployment->id,
                    'form_version_id' => $context->version->id,
                    'operation_nonce' => $nonce,
                    'answers' => [],
                    'completed_pages' => [],
                    'expires_at' => now()->addHours(self::SESSION_TTL_HOURS),
                ]);
            }

            if ($session->isFinalized()) {
                return $session;
            }

            if ((int) $session->form_version_id !== (int) $context->version->id) {
                throw $this->refused('form', 'This form has expired. Reload the page and send it again.');
            }

            if ($session->isExpired()) {
                throw $this->refused('form', 'This form has expired. Reload the page and send it again.');
            }

            if (! $this->earlierPagesCompleted($context, $session, $index)) {
                throw $this->refused('page', 'Please complete the earlier pages first.');
            }

            $completed = $session->completed_pages;
            if (! in_array($pageKey, $completed, true)) {
                $completed[] = $pageKey;
            }

            $session->forceFill([
                'answers' => array_merge($session->answers, $pageValues),
                'completed_pages' => array_values($completed),
                'expires_at' => now()->addHours(self::SESSION_TTL_HOURS),
            ])->save();

            return $session;
        }, self::DEADLOCK_ATTEMPTS);
    }

    private function earlierPagesCompleted(FormDeploymentContext $context, FormSession $session, int $index): bool
    {
        foreach (array_slice($context->version->pageKeys(), 0, $index) as $earlier) {
            if (! in_array($earlier, $session->completed_pages, true)) {
                return false;
            }
        }

        return true;
    }

    private function refused(string $field, string $message): ValidationException
    {
        return ValidationException::withMessages([$field => [$message]]);
    }
}

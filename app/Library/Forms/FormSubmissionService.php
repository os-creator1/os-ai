<?php

namespace App\Library\Forms;

use App\Enums\Forms\FormContactResolution;
use App\Enums\Forms\FormFieldType;
use App\Events\Forms\FormSubmissionRecorded;
use App\Library\Crm\CrmOpportunityService;
use App\Library\Crm\Exceptions\CrmRuleException;
use App\Library\CustomFields\CustomFieldRuleException;
use App\Library\CustomFields\CustomFieldValueService;
use App\Library\CustomFields\FormFieldMapping;
use App\Library\Forms\Exceptions\FormUnavailableException;
use App\Models\Contacts;
use App\Models\CrmOpportunity;
use App\Models\CrmPipeline;
use App\Models\FormSession;
use App\Models\FormSubmission;
use App\Repositories\Eloquent\EloquentContactsRepository;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Forms V1 — the ONE writer of `form_submissions`, and the one place a visitor's
 * answers become a historical fact, a Contact and (where configured) a CRM
 * Opportunity.
 *
 * THE FLOW, in the order it matters:
 *
 *  1. AUTHORITY. FormDeploymentResolver re-reads and mutually proves every row
 *     from persistence; the Location is the DEPLOYMENT's. A posted
 *     `location_uid` may only restate it.
 *  2. OPERATION TOKEN + PINNED VERSION. The token must be genuine for this
 *     deployment (FormOperationToken) and names the immutable version the visitor
 *     SAW; that version is re-read and proven to belong to THIS deployment's Form
 *     (FormDeploymentResolver::pin()). Everything below uses that pinned version,
 *     never the form's current one — an owner publishing a newer version cannot
 *     reinterpret a flow already rendered.
 *  3. VALIDATION against the pinned version, producing normalized values keyed by
 *     field key. An ordinary (one-page) form validates everything now. A
 *     questionnaire validates ONE page per request, keeps it in the server-side
 *     session (FormSessionStore) and answers with the next page — creating no
 *     Contact, Opportunity, event or submission — until the LAST page, which
 *     validates the complete definition from the server-held answers. A refusal
 *     writes nothing and does not consume the token, so the visitor can correct
 *     and resend.
 *  4. REPLAY. A row already claiming (deployment, nonce) is returned as-is — no
 *     second row, Contact, Opportunity or event. The same token with a DIFFERENT
 *     body is refused rather than silently answered with the first body.
 *  5. ONE TRANSACTION: INSERT the submission first, as the idempotency claim; the
 *     unique (deployment, nonce) index is the real concurrency backstop — a racing
 *     twin blocks on it, then loses with a duplicate-key error and converges on
 *     the winner's row. Then resolve the Contact (Location-local, under the
 *     existing identity lock), create the Opportunity if configured, link both
 *     onto the submission, stamp the questionnaire session as final, and
 *     dispatch FormSubmissionRecorded AFTER COMMIT.
 *
 * ANYTHING THAT THROWS INSIDE THE TRANSACTION (a blacklisted phone, a database
 * error) rolls the claim back with it: no submission, no Contact, no
 * Opportunity, no event, and the token stays usable.
 *
 * AN INQUIRY IS NEVER MESSAGING CONSENT. A Contact created here is not
 * subscribed and triggers no contact-created automation (see
 * EloquentContactsRepository::findOrCreateForForm()).
 *
 * LEAD OVER CRM. If the configured Opportunity pipeline has since been archived
 * or removed, the submission is still recorded with no Opportunity — an inquiry
 * is never lost because a CRM setting went stale.
 */
final class FormSubmissionService
{
    public const TOKEN_FIELD = 'operation_token';

    public const LOCATION_FIELD = 'location_uid';

    public const HONEYPOT_FIELD = 'form_hp';

    /** The key of the questionnaire page a step is submitting. */
    public const PAGE_FIELD = 'page';

    private const TEXT_MAX = 200;

    private const TEXTAREA_MAX = 2000;

    private const EMAIL_MAX = 160;

    /**
     * Attempts for the claiming transaction. Two requests queued on one
     * duplicate-key INSERT while its holder rolls back is a textbook InnoDB
     * deadlock: the server aborts one of them with SQLSTATE 40001 even though
     * nothing is wrong. Laravel retries a deadlocked OUTERMOST transaction
     * natively; on the retry the loser meets the winner's committed claim and
     * converges as a replay, so a visitor never sees the server's arbitration.
     */
    private const DEADLOCK_ATTEMPTS = 3;

    public function __construct(
        private readonly FormDeploymentResolver $resolver,
        private readonly EloquentContactsRepository $contacts,
        private readonly CrmOpportunityService $opportunities,
        private readonly FormSessionStore $sessions,
        private readonly FormFieldMapping $mappings,
        private readonly CustomFieldValueService $customValues,
    ) {
    }

    private function expired(): ValidationException
    {
        return ValidationException::withMessages(['form' => ['This form has expired. Reload the page and send it again.']]);
    }

    /**
     * @param  array<string, mixed>  $input  the visitor's request input: answers keyed by field key, plus TOKEN_FIELD and optionally LOCATION_FIELD
     *
     * @throws Exceptions\FormUnavailableException when the form cannot accept a submission at all
     * @throws ValidationException when the token, the answers or the posted Location are not acceptable
     */
    public function submit(string $deploymentUid, array $input): FormSubmissionResult
    {
        // CURRENT authority first: deployment, Form, Location, Business, account
        // and entitlement, re-proven on EVERY request, whatever the token says.
        $context = $this->resolver->resolve($deploymentUid);

        $this->assertLocationRestated($context, $input[self::LOCATION_FIELD] ?? null);

        $claims = FormOperationToken::claims($context->deployment, $input[self::TOKEN_FIELD] ?? null)
            ?? throw $this->expired();

        // The version the visitor SAW, authenticated by the token and then
        // re-read from persistence and proven to be a version of THIS Form. A
        // version of another Form (or one that does not exist) is refused exactly
        // like a forged token.
        try {
            $context = $this->resolver->pin($context, $claims['version_id']);
        } catch (FormUnavailableException) {
            throw $this->expired();
        }

        $nonce = $claims['nonce'];

        // An ordinary form is one page: validated and finished in one request.
        if (! $context->version->isMultiPage()) {
            return $this->finish($context, $nonce, $this->validated($context->version->fields, $input));
        }

        return $this->submitStep($context, $nonce, $input);
    }

    /**
     * One page of a questionnaire. Validates THIS page against the pinned
     * version. A page that is not the last is recorded in the server-side session
     * in its own short transaction and answered with the next page to show,
     * having created no Contact, Opportunity, event or submission.
     *
     * The LAST page does not go through the session store's page save at all: it
     * is finished at ONE atomic boundary (finishQuestionnaire) that owns the
     * session lock from choosing the final answers to stamping the session.
     *
     * @param  array<string, mixed>  $input
     */
    private function submitStep(FormDeploymentContext $context, string $nonce, array $input): FormSubmissionResult
    {
        $version = $context->version;
        $pageKey = trim((string) ($input[self::PAGE_FIELD] ?? ''));
        $index = $version->pageIndex($pageKey)
            ?? throw ValidationException::withMessages([self::PAGE_FIELD => ['That page is not part of this form.']]);

        $pageValues = $this->validated($version->fieldsOnPage($pageKey), $input);

        if ($index < count($version->pages()) - 1) {
            $this->sessions->savePage($context, $nonce, $pageKey, $pageValues);

            return FormSubmissionResult::progress($version->pageKeys()[$index + 1]);
        }

        return $this->finishQuestionnaire($context, $nonce, $index, $pageValues);
    }

    /**
     * The FINAL step of a questionnaire, at ONE atomic boundary.
     *
     * The previous shape split this: the session was locked, written and released
     * by a page save, and only afterwards did a separate transaction claim the
     * submission from a detached copy of it. A concurrent edit could land in that
     * gap, leaving a submission built from answers X and a finalized session that
     * holds Y. Here the transaction BEGINS by locking the session row, and every
     * decision that must agree happens while that lock is held:
     *
     *   1. re-read the session authoritatively by (deployment, nonce) and re-prove
     *      its version, expiry and — for a first finish — that every earlier page
     *      was completed;
     *   2. choose the final answer set: the locked session's answers with this
     *      last page laid over them, validated as the COMPLETE pinned definition;
     *   3. if the session is ALREADY finalized this is a replay: identical answers
     *      converge on the winning submission, different answers are the existing
     *      idempotency conflict — either way nothing is written;
     *   4. otherwise claim the submission, resolve the Contact, create the
     *      Opportunity, link them, and stamp THE SAME locked session with the
     *      submission AND with exactly the values the submission was built from;
     *   5. dispatch the after-commit event.
     *
     * A concurrent page save queues on the same lock: it either committed first
     * (and is therefore part of the answers chosen in step 2) or arrives after
     * finalization and finds a session it may not change. No page can land between
     * "final answers chosen" and "session finalized".
     *
     * Nothing external happens inside this transaction.
     *
     * @param  array<string, mixed>  $pageValues  the validated answers of the last page
     */
    private function finishQuestionnaire(FormDeploymentContext $context, string $nonce, int $index, array $pageValues): FormSubmissionResult
    {
        $version = $context->version;
        $payloadHash = null;

        try {
            return DB::transaction(function () use ($context, $nonce, $index, $pageValues, $version, &$payloadHash): FormSubmissionResult {
                // FIRST statement: the locking read. No plain SELECT may precede it,
                // or the transaction's snapshot would predate a committed twin.
                $session = $this->sessions->lockForFinal($context, $nonce);

                if (! $session->isFinalized()) {
                    $this->sessions->assertEarlierPagesCompleted($context, $session, $index);
                }

                $values = $this->validated($version->fields, array_merge($session->answers, $pageValues));
                $payloadHash = hash('sha256', json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                if ($session->isFinalized()) {
                    $winner = $session->form_submission_id === null ? null : FormSubmission::query()->find($session->form_submission_id);

                    if ($winner === null) {
                        throw $this->expired();
                    }

                    return $this->replay($winner, $payloadHash);
                }

                return new FormSubmissionResult($this->record($context, $nonce, $values, $payloadHash, $session), false);
            }, self::DEADLOCK_ATTEMPTS);
        } catch (UniqueConstraintViolationException $exception) {
            // Only reachable if a claim for this (deployment, nonce) exists that the
            // session does not know about. Read it with a CURRENT read; anything
            // else is not ours to swallow.
            $existing = $this->claimedBy($context, $nonce, true);

            if ($existing === null || $payloadHash === null) {
                throw $exception;
            }

            return $this->replay($existing, $payloadHash);
        }
    }

    /**
     * Replay check, then the claiming transaction — an ordinary ONE-PAGE form
     * (a questionnaire's final step is finishQuestionnaire()).
     *
     * @param  array<string, mixed>  $values  normalized answers for the COMPLETE pinned definition
     */
    private function finish(FormDeploymentContext $context, string $nonce, array $values): FormSubmissionResult
    {
        $payloadHash = hash('sha256', json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $existing = $this->claimedBy($context, $nonce, false);
        if ($existing !== null) {
            return $this->replay($existing, $payloadHash);
        }

        try {
            $submission = DB::transaction(
                fn (): FormSubmission => $this->record($context, $nonce, $values, $payloadHash, null),
                self::DEADLOCK_ATTEMPTS
            );
        } catch (UniqueConstraintViolationException $exception) {
            // A concurrent twin won the claim. Read the winner with a CURRENT
            // read (not a possibly stale snapshot); if there is none, the
            // violation was something else and must not be swallowed.
            $existing = $this->claimedBy($context, $nonce, true);

            if ($existing === null) {
                throw $exception;
            }

            return $this->replay($existing, $payloadHash);
        }

        return new FormSubmissionResult($submission, false);
    }

    private function assertLocationRestated(FormDeploymentContext $context, mixed $posted): void
    {
        $posted = trim((string) $posted);

        if ($posted !== '' && $posted !== (string) $context->location->uid) {
            throw ValidationException::withMessages([
                self::LOCATION_FIELD => ['That location is not valid for this form.'],
            ]);
        }
    }

    private function claimedBy(FormDeploymentContext $context, string $nonce, bool $currentRead): ?FormSubmission
    {
        $query = FormSubmission::query()
            ->where('form_deployment_id', $context->deployment->id)
            ->where('operation_nonce', $nonce);

        return ($currentRead ? $query->lockForUpdate() : $query)->first();
    }

    private function replay(FormSubmission $existing, string $payloadHash): FormSubmissionResult
    {
        if (! hash_equals($existing->payload_hash, $payloadHash)) {
            throw ValidationException::withMessages([
                'form' => ['This form was already sent with different answers. Reload the page to send a new response.'],
            ]);
        }

        return new FormSubmissionResult($existing, true);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function record(FormDeploymentContext $context, string $nonce, array $values, string $payloadHash, ?FormSession $locked): FormSubmission
    {
        $uid = (string) Str::uuid();

        // The CLAIM: this INSERT is what the unique (deployment, nonce) index
        // arbitrates between concurrent requests.
        $submission = FormSubmission::create([
            'uid' => $uid,
            'business_id' => $context->business->id,
            'business_location_id' => $context->location->id,
            'form_id' => $context->form->id,
            'form_version_id' => $context->version->id,
            'form_deployment_id' => $context->deployment->id,
            'source' => $context->deployment->source,
            'operation_nonce' => $nonce,
            'payload_hash' => $payloadHash,
            'values' => $values,
            'contact_resolution' => FormContactResolution::None->value,
            'occurrence_key' => FormSubmissionRecorded::occurrenceKeyFor($uid),
        ]);

        [$contact, $resolution] = $this->resolveContact($context, $values);

        $opportunity = $contact !== null && $context->version->create_opportunity
            ? $this->createOpportunity($context, $contact, $values)
            : null;

        $this->applyMappedAnswers($context, $contact, $resolution, $values);

        $this->link($submission, $contact, $resolution, $opportunity);

        // The questionnaire session (if any) — locked by this very transaction —
        // becomes history in the SAME commit, holding exactly the answers this
        // submission was built from.
        if ($locked !== null) {
            $this->sessions->finalize($locked, $submission, $values, $context->version->pageKeys());
        }

        FormSubmissionRecorded::dispatch(
            (int) $context->business->id,
            (int) $context->location->id,
            (int) $context->form->id,
            (int) $context->version->id,
            (int) $submission->id,
            $contact?->id === null ? null : (int) $contact->id,
            $opportunity?->id === null ? null : (int) $opportunity->id,
            $resolution->value,
            $submission->occurrence_key,
        );

        return $submission->fresh();
    }

    /**
     * Save each EXPLICITLY mapped answer to its Contact custom field.
     *
     * Only questions carrying a `custom_field_uid` are touched, and only that one
     * field per question: never identity, tags, or an unmapped field. A Contact
     * that was matched (not just created) is updated too — the mapping is the
     * author's explicit instruction. An Ambiguous match or no phone number means
     * there is no Contact, so nothing is written. A blank or unusable answer is
     * skipped and never clears an existing value; the answer itself always
     * remains on the (write-once) submission.
     *
     * @param  array<string, mixed>  $values
     */
    private function applyMappedAnswers(FormDeploymentContext $context, ?Contacts $contact, FormContactResolution $resolution, array $values): void
    {
        if ($contact === null || ! in_array($resolution, [FormContactResolution::Created, FormContactResolution::Matched], true)) {
            return;
        }

        foreach ($this->mappings->answersFor($context->business, $context->version->fields ?? [], $values) as [$definition, $answer]) {
            try {
                $this->customValues->applyAnswer($context->business, $contact, $definition, $answer);
            } catch (CustomFieldRuleException) {
                // A scope mismatch is refused, not fatal: the submission stands.
            }
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array{0: ?Contacts, 1: FormContactResolution}
     */
    private function resolveContact(FormDeploymentContext $context, array $values): array
    {
        $fields = collect($context->version->fields);

        $phoneKey = $fields->firstWhere('type', FormFieldType::Phone->value)['key'] ?? null;
        $phone = $phoneKey === null ? null : ($values[$phoneKey] ?? null);

        if ($phone === null || $phone === '') {
            return [null, FormContactResolution::None];
        }

        $emailKey = $fields->firstWhere('type', FormFieldType::Email->value)['key'] ?? null;

        [$first, $last] = $this->personName($context->version->fields ?? [], $values);

        $details = array_filter([
            'FIRST_NAME' => $first,
            'LAST_NAME' => $last,
            'EMAIL' => $emailKey === null ? null : ($values[$emailKey] ?? null),
        ], fn ($value) => $value !== null && $value !== '');

        // The Location is the authoritative row the resolver re-read — the
        // Contacts seam takes its Business from it.
        return $this->contacts->findOrCreateForForm($context->location, (string) $phone, $details);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function createOpportunity(FormDeploymentContext $context, Contacts $contact, array $values): ?CrmOpportunity
    {
        $pipelineId = $context->version->opportunity_pipeline_id
            ?? CrmPipeline::query()
                ->where('business_id', $context->business->id)
                ->whereNull('archived_at')
                ->orderBy('position')
                ->orderBy('id')
                ->value('id');

        if ($pipelineId === null) {
            return null;
        }

        $name = trim(implode(' ', array_filter($this->personName($context->version->fields ?? [], $values))));
        $title = Str::limit(trim(($name !== '' ? $name.' — ' : '').$context->form->name), CrmOpportunityService::TITLE_MAX, '');

        try {
            return $this->opportunities->createAtLocation(
                (int) $context->business->id,
                (int) $context->location->id,
                (int) $pipelineId,
                (int) $contact->id,
                $title,
                null,
                null,
                null,
                CrmOpportunity::SOURCE_FORM,
            );
        } catch (CrmRuleException) {
            // Stale CRM setting (archived pipeline, no active stage): keep the
            // lead, skip the deal.
            return null;
        }
    }

    /**
     * The one post-insert write, inside the claiming transaction. A
     * query-builder update on purpose: the model refuses every Eloquent update,
     * so nothing but this method can touch a submission, and it can touch only
     * these three columns.
     */
    private function link(FormSubmission $submission, ?Contacts $contact, FormContactResolution $resolution, ?CrmOpportunity $opportunity): void
    {
        DB::table('form_submissions')->where('id', $submission->id)->update([
            'contact_id' => $contact?->id,
            'contact_resolution' => $resolution->value,
            'crm_opportunity_id' => $opportunity?->id,
        ]);
    }

    /**
     * Normalized answers keyed by field key, in the form's own field order (so
     * the payload hash is stable). Unknown posted keys are ignored, never stored.
     *
     * @param  list<array<string, mixed>>  $fields  the fields to validate — a page's, or the whole pinned definition's
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function validated(array $fields, array $input): array
    {
        $rules = [];
        $labels = [];
        $data = [];

        foreach ($fields as $field) {
            $key = $field['key'];
            $type = FormFieldType::from($field['type']);

            // Headings, paragraphs, dividers and spacers collect nothing.
            if (! $type->isInput()) {
                continue;
            }

            $required = (bool) $field['required'];
            $raw = $input[$key] ?? null;
            $data[$key] = is_string($raw) ? (trim($raw) === '' ? null : trim($raw)) : $raw;
            $labels[$key] = $field['label'];
            $presence = $required ? 'required' : 'nullable';

            if ($type === FormFieldType::MultiSelect) {
                // An array of the owner's own options, nothing else.
                $data[$key] = is_array($raw) ? array_values(array_unique(array_filter($raw, 'is_string'))) : null;
                $rules[$key] = $required ? ['required', 'array', 'min:1'] : ['nullable', 'array'];
                $rules[$key.'.*'] = ['string', Rule::in($field['options'])];
                $labels[$key.'.*'] = $field['label'];

                continue;
            }

            $rules[$key] = match ($type) {
                FormFieldType::Number => [$presence, 'numeric', 'between:-1000000000,1000000000'],
                FormFieldType::Currency => [$presence, 'numeric', 'min:0', 'max:1000000000', 'decimal:0,2'],
                FormFieldType::DateTime => [$presence, 'date_format:Y-m-d\TH:i'],
                FormFieldType::Radio => [$presence, 'string', Rule::in($field['options'])],
                FormFieldType::YesNo => [$presence, 'string', Rule::in(['Yes', 'No'])],
                FormFieldType::ConsentTransactional, FormFieldType::ConsentMarketing => $required ? ['accepted'] : ['nullable', 'boolean'],
                FormFieldType::Text => [$presence, 'string', 'max:'.self::TEXT_MAX],
                FormFieldType::Textarea => [$presence, 'string', 'max:'.self::TEXTAREA_MAX],
                FormFieldType::Email => [$presence, 'string', 'email', 'max:'.self::EMAIL_MAX],
                FormFieldType::Phone => [$presence, 'string', 'regex:/^\+?[0-9 ().\-]{7,32}$/', function (string $attribute, mixed $value, \Closure $fail): void {
                    $digits = strlen(preg_replace('/\D+/', '', (string) $value));
                    if ($digits < 7 || $digits > 15) {
                        $fail('Enter a valid phone number.');
                    }
                }],
                FormFieldType::Select => [$presence, 'string', Rule::in($field['options'])],
                FormFieldType::Checkbox => $required ? ['accepted'] : ['nullable', 'boolean'],
                FormFieldType::Date => [$presence, 'date_format:Y-m-d'],
                FormFieldType::MultiSelect, FormFieldType::Heading, FormFieldType::Paragraph, FormFieldType::Divider, FormFieldType::Spacer => [],
            };
        }

        $validated = Validator::make($data, $rules, [], $labels)->validate();

        $values = [];
        foreach ($fields as $field) {
            $key = $field['key'];
            $type = FormFieldType::from($field['type']);

            if (! $type->isInput()) {
                continue;
            }

            $value = $validated[$key] ?? null;

            $values[$key] = match (true) {
                $type->isBoolean() => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                $type === FormFieldType::Phone => $value === null ? null : (preg_replace('/\D+/', '', (string) $value) ?: null),
                $type === FormFieldType::Email => $value === null ? null : mb_strtolower((string) $value),
                $type === FormFieldType::Number => $value === null ? null : (str_contains((string) $value, '.') ? (float) $value : (int) $value),
                $type === FormFieldType::Currency => $value === null ? null : number_format((float) $value, 2, '.', ''),
                // The owner's own option order, whatever order the browser posted.
                $type === FormFieldType::MultiSelect => $value === null || $value === [] ? null : array_values(array_intersect($field['options'], $value)),
                default => $value,
            };
        }

        return $values;
    }

    /**
     * The person's first and last name from the version's EXPLICIT markers: one
     * full-name question (split on the first space), or separate first-name and
     * last-name questions. Never guessed from a label.
     *
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $values
     * @return array{0: string, 1: string}
     */
    private function personName(array $fields, array $values): array
    {
        $collection = collect($fields);
        $fullKey = $collection->firstWhere('contact_name', true)['key'] ?? null;

        if ($fullKey !== null) {
            return $this->splitName((string) ($values[$fullKey] ?? ''));
        }

        $firstKey = $collection->firstWhere('contact_part', 'first_name')['key'] ?? null;
        $lastKey = $collection->firstWhere('contact_part', 'last_name')['key'] ?? null;

        return [
            $firstKey === null ? '' : trim((string) ($values[$firstKey] ?? '')),
            $lastKey === null ? '' : trim((string) ($values[$lastKey] ?? '')),
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitName(string $name): array
    {
        $name = trim($name);

        if ($name === '') {
            return ['', ''];
        }

        $parts = explode(' ', $name, 2);

        return [$parts[0], trim($parts[1] ?? '')];
    }
}

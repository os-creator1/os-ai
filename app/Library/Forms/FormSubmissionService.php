<?php

namespace App\Library\Forms;

use App\Enums\Forms\FormContactResolution;
use App\Enums\Forms\FormFieldType;
use App\Events\Forms\FormSubmissionRecorded;
use App\Library\Crm\CrmOpportunityService;
use App\Library\Crm\Exceptions\CrmRuleException;
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
            return $this->finish($context, $nonce, $this->validated($context->version->fields, $input), null);
        }

        return $this->submitStep($context, $nonce, $input);
    }

    /**
     * One page of a questionnaire. Validates THIS page against the pinned
     * version, records it in the server-side session and — unless it is the last
     * page — answers with the next page to show, having created no Contact,
     * Opportunity, event or submission. The last page validates the COMPLETE
     * pinned definition from the server-held answers and finishes through the
     * same idempotent claim as a one-page form.
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

        $session = $this->sessions->savePage($context, $nonce, $pageKey, $pageValues);

        if ($index < count($version->pages()) - 1) {
            return FormSubmissionResult::progress($version->pageKeys()[$index + 1]);
        }

        // Final page. A finalized session is never mutated, so a replay of this
        // step is judged on what it posted laid over what was recorded: an
        // identical replay reproduces the stored hash, a tampered one does not.
        $answers = $session->isFinalized() ? array_merge($session->answers, $pageValues) : $session->answers;

        return $this->finish($context, $nonce, $this->validated($version->fields, $answers), $session);
    }

    /**
     * Replay check, then the claiming transaction.
     *
     * @param  array<string, mixed>  $values  normalized answers for the COMPLETE pinned definition
     */
    private function finish(FormDeploymentContext $context, string $nonce, array $values, ?FormSession $session): FormSubmissionResult
    {
        $payloadHash = hash('sha256', json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $existing = $this->claimedBy($context, $nonce, false);
        if ($existing !== null) {
            return $this->replay($existing, $payloadHash);
        }

        try {
            $submission = DB::transaction(
                fn (): FormSubmission => $this->record($context, $nonce, $values, $payloadHash, $session),
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
    private function record(FormDeploymentContext $context, string $nonce, array $values, string $payloadHash, ?FormSession $session): FormSubmission
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

        $this->link($submission, $contact, $resolution, $opportunity);

        // The questionnaire session (if any) becomes history in the SAME commit.
        if ($session !== null) {
            $this->sessions->finalize($session, $submission);
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

        $nameKey = $fields->firstWhere('contact_name', true)['key'] ?? null;
        $emailKey = $fields->firstWhere('type', FormFieldType::Email->value)['key'] ?? null;

        [$first, $last] = $this->splitName((string) ($nameKey === null ? '' : ($values[$nameKey] ?? '')));

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

        $nameKey = collect($context->version->fields)->firstWhere('contact_name', true)['key'] ?? null;
        $name = $nameKey === null ? '' : trim((string) ($values[$nameKey] ?? ''));
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
            $required = (bool) $field['required'];
            $raw = $input[$key] ?? null;
            $data[$key] = is_string($raw) ? (trim($raw) === '' ? null : trim($raw)) : $raw;
            $labels[$key] = $field['label'];
            $presence = $required ? 'required' : 'nullable';

            $rules[$key] = match ($type) {
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
            };
        }

        $validated = Validator::make($data, $rules, [], $labels)->validate();

        $values = [];
        foreach ($fields as $field) {
            $key = $field['key'];
            $value = $validated[$key] ?? null;

            $values[$key] = match (FormFieldType::from($field['type'])) {
                FormFieldType::Checkbox => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                FormFieldType::Phone => $value === null ? null : (preg_replace('/\D+/', '', (string) $value) ?: null),
                FormFieldType::Email => $value === null ? null : mb_strtolower((string) $value),
                default => $value,
            };
        }

        return $values;
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

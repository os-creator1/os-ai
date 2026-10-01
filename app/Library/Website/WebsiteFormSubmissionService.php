<?php

namespace App\Library\Website;

use App\Enums\Website\WebsiteFormFieldType;
use App\Events\Forms\FormSubmissionRecorded;
use App\Library\Crm\CrmBoard;
use App\Library\Crm\CrmOpportunityService;
use App\Library\Crm\Exceptions\CrmRuleException;
use App\Library\Forms\FormLocationResolver;
use App\Models\CrmPipeline;
use App\Models\WebsiteForm;
use App\Models\WebsiteFormSubmission;
use App\Repositories\Eloquent\EloquentContactsRepository;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * THE canonical form-submission seam (Blueprint §16): the one writer of
 * website_form_submissions, and the one place a submitted inquiry becomes
 * Location-bound CRM state.
 *
 *   form definition (Business-wide, via its Website)
 *     -> Location the form carries (FormLocationResolver — fail closed)
 *     -> Contact found/created at THAT Location (Location-local identity)
 *     -> CrmOpportunity at that Location, where the definition asks for one
 *     -> website_form_submissions row (the durable record)
 *     -> FormSubmissionRecorded (after commit)
 *
 * all in ONE transaction: a failure anywhere leaves no submission, no
 * Contact, no Opportunity and no event.
 *
 * IDEMPOTENCY. A submission is one LOGICAL submission, identified by the
 * token the rendered form carries (`submission_token`, one per page render).
 * The same token posted again — a double-click, a browser or network retry,
 * two concurrent requests — returns the original row and writes nothing.
 * `(website_form_id, idempotency_key)` is UNIQUE, so the database refuses a
 * second row even if this code were bypassed. A post with no usable token is
 * its own submission: content is never compared, so two genuinely separate
 * inquiries that happen to read alike are both kept.
 *
 * A Business with no CRM pipeline yet never loses the inquiry: the
 * submission row is the durable record either way.
 *
 * An anonymous inquiry is never treated as messaging consent: the Contact
 * this creates is never subscribed and never fires a contact-created
 * automation (EloquentContactsRepository::findOrCreateForWebsiteForm()
 * carries that rule).
 *
 * Never called for a preview render.
 */
final class WebsiteFormSubmissionService
{
    public const HONEYPOT_FIELD = 'website_hp';

    /** The hidden per-render token that makes a retry the same submission. */
    public const TOKEN_FIELD = 'submission_token';

    /** Bounds the whole posted payload, whatever the form's own fields are. */
    private const MAX_INPUT_BYTES = 32768;

    public function __construct(
        private readonly EloquentContactsRepository $contacts,
        private readonly CrmBoard $crmBoard,
        private readonly CrmOpportunityService $opportunities,
        private readonly FormLocationResolver $locations,
    ) {}

    /**
     * @param  array<int, array{key: string, label: string, type: string, required: bool}>  $fields  the form's field config AS PUBLISHED — the caller reads this from the immutable snapshot, never live from $form, so an edit made after publishing can never change what a submission is validated against
     * @param  array<string, mixed>  $input  raw request input, keyed by the form's own field keys, plus the honeypot / token / posted-location control fields. Keys that are not the form's fields are discarded, never stored and never mapped onto a Contact or Business.
     * @return WebsiteFormSubmission the new row, or — for a replayed token — the original one (`wasRecentlyCreated` is false)
     *
     * @throws ValidationException a field is invalid, the payload is too large, the form is inactive or has no valid Location, or a posted Location is not the form's
     */
    public function submit(
        WebsiteForm $form,
        array $fields,
        string $formName,
        array $input,
        ?string $pageSlug,
        ?string $ip,
        ?int $sourceRevisionId = null,
        ?string $pageUid = null,
    ): WebsiteFormSubmission {
        $this->assertBounded($input);

        $isSpam = trim((string) ($input[self::HONEYPOT_FIELD] ?? '')) !== '';
        $data = $this->validated($fields, $input);
        $idempotencyKey = $this->idempotencyKey($input);
        $postedLocation = is_string($input[FormLocationResolver::POSTED_LOCATION_FIELD] ?? null)
            ? $input[FormLocationResolver::POSTED_LOCATION_FIELD]
            : null;

        try {
            return DB::transaction(function () use ($form, $formName, $data, $pageSlug, $ip, $idempotencyKey, $isSpam, $postedLocation, $sourceRevisionId, $pageUid) {
                // Locks the WebsiteForm row itself so concurrent submissions
                // for the SAME form are serialized: the replay check below
                // only ever runs after the first has committed or rolled
                // back. The lock also gives a fresh read of is_active and
                // location_id, so a deactivation or Location change takes
                // effect for any submission that has not yet started.
                $locked = WebsiteForm::query()->whereKey($form->id)->lockForUpdate()->firstOrFail();

                if ($idempotencyKey !== null) {
                    $existing = $this->existing($locked, $idempotencyKey);
                    if ($existing !== null) {
                        return $existing;
                    }
                }

                if (! $locked->is_active) {
                    throw ValidationException::withMessages(['form' => ['This form is not accepting submissions right now.']]);
                }

                $location = $this->locations->resolve($locked, $postedLocation);

                $submission = WebsiteFormSubmission::create([
                    'website_form_id' => $locked->id,
                    'location_id' => $location->id,
                    'page_slug' => $pageSlug,
                    'page_uid' => $pageUid,
                    'source_revision_id' => $sourceRevisionId,
                    'data' => $data,
                    'idempotency_key' => $idempotencyKey,
                    'ip_hash' => $ip !== null && $ip !== '' ? hash('sha256', $ip) : null,
                    'is_spam' => $isSpam,
                    'status' => WebsiteFormSubmission::STATUS_NEW,
                ]);

                // Spam is recorded (so a business can see what is being
                // filtered) but never creates a Contact, Opportunity or event.
                if ($isSpam) {
                    return $submission;
                }

                $contact = null;
                $resolution = 'none';

                if (! empty($data['phone'])) {
                    [$firstName, $lastName] = $this->splitName((string) ($data['name'] ?? ''));

                    [$contact, $resolution] = $this->contacts->findOrCreateForWebsiteForm($location, (string) $data['phone'], [
                        'FIRST_NAME' => $firstName,
                        'LAST_NAME' => $lastName,
                    ]);
                }

                $submission->contact_id = $contact?->id;
                $submission->contact_resolution = $resolution;

                if ($contact !== null && $locked->create_opportunity) {
                    $business = $locked->website->business;
                    $pipeline = $this->pipelineFor($locked, $business);

                    if ($pipeline !== null) {
                        $name = (string) ($data['name'] ?? '');
                        $title = Str::limit(trim(($name !== '' ? $name.' — ' : '').$formName), CrmOpportunityService::TITLE_MAX, '');

                        try {
                            $opportunity = $this->opportunities->create(
                                $business,
                                $pipeline,
                                $contact,
                                $title,
                                null,
                                null,
                                null,
                                'website_form',
                                $location,
                            );
                            $submission->crm_opportunity_id = $opportunity->id;
                        } catch (CrmRuleException) {
                            // A pipeline that cannot take a deal right now (no
                            // active stage, an archived pipeline) must not cost
                            // the Business the inquiry: the submission and its
                            // Contact are still recorded.
                        }
                    }
                }

                $submission->save();

                FormSubmissionRecorded::dispatch(
                    $location->business_id,
                    $location->id,
                    $locked->id,
                    $locked->uid,
                    $submission->id,
                    $submission->uid,
                    $submission->contact_id,
                    $submission->crm_opportunity_id,
                    $resolution,
                    FormSubmissionRecorded::occurrenceKeyFor($submission->id),
                );

                return $submission;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Defence in depth: the row lock above already serializes
            // same-form submissions, so reaching this means the UNIQUE
            // (form, token) index refused a second row. That is the replay
            // case, not an error.
            $existing = $idempotencyKey !== null ? $this->existing($form, $idempotencyKey) : null;

            if ($existing === null) {
                throw $e;
            }

            return $existing;
        }
    }

    private function existing(WebsiteForm $form, string $idempotencyKey): ?WebsiteFormSubmission
    {
        return WebsiteFormSubmission::query()
            ->where('website_form_id', $form->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    /**
     * The form's configured pipeline when it is still one of this
     * Business's active pipelines, else the Business's first active
     * pipeline (the behaviour before a pipeline could be chosen). Null when
     * the Business has no pipeline at all.
     */
    private function pipelineFor(WebsiteForm $form, \App\Models\Business $business): ?CrmPipeline
    {
        $pipelines = $this->crmBoard->pipelines($business);

        return ($form->crm_pipeline_id !== null ? $pipelines->firstWhere('id', $form->crm_pipeline_id) : null)
            ?? $pipelines->first();
    }

    /**
     * The token is client-supplied, so it is trusted only as an opaque
     * identity: a UUID, normalised, or nothing. Anything else means "no
     * token" and the post becomes its own submission.
     */
    private function idempotencyKey(array $input): ?string
    {
        $token = $input[self::TOKEN_FIELD] ?? null;

        if (! is_string($token) || ! Str::isUuid($token)) {
            return null;
        }

        return strtolower($token);
    }

    private function assertBounded(array $input): void
    {
        $encoded = json_encode($input, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        if ($encoded === false || strlen($encoded) > self::MAX_INPUT_BYTES) {
            throw ValidationException::withMessages(['form' => ['That submission is too large.']]);
        }
    }

    /**
     * Only the form's own, published fields are accepted. Anything else in
     * the request is discarded (never stored, never mapped onto a Contact),
     * and a field value that is not a plain string — an array, an uploaded
     * file — fails the field's own rule: V1 forms carry no file fields.
     *
     * @param  array<int, array{key: string, label: string, type: string, required: bool}>  $fields
     * @return array<string, mixed>
     */
    private function validated(array $fields, array $input): array
    {
        $rules = [];

        foreach ($fields as $field) {
            $type = WebsiteFormFieldType::tryFrom((string) ($field['type'] ?? ''));
            $prefix = ($field['required'] ?? false) ? 'required' : 'nullable';

            $rules[$field['key']] = match ($type) {
                WebsiteFormFieldType::Email => [$prefix, 'string', 'email', 'max:160'],
                WebsiteFormFieldType::Date => [$prefix, 'string', 'date', 'max:40'],
                WebsiteFormFieldType::Textarea => [$prefix, 'string', 'max:2000'],
                WebsiteFormFieldType::Tel => [$prefix, 'string', 'max:40', $this->phoneRule()],
                default => [$prefix, 'string', 'max:160'],
            };
        }

        $validated = ValidatorFacade::make($input, $rules)->validate();

        $data = [];
        foreach ($fields as $field) {
            $data[$field['key']] = $validated[$field['key']] ?? null;
        }

        return $data;
    }

    /** 7–15 digits, with only the usual separators around them. */
    private function phoneRule(): \Closure
    {
        return static function (string $attribute, mixed $value, \Closure $fail): void {
            $value = (string) $value;
            $digits = preg_replace('/\D/', '', $value);

            if ($value === '' || preg_match('/^\+?[0-9\s().-]+$/', $value) !== 1 || strlen($digits) < 7 || strlen($digits) > 15) {
                $fail('Enter a valid phone number.');
            }
        };
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

        return [$parts[0], $parts[1] ?? ''];
    }
}

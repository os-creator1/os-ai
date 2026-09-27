<?php

namespace App\Library\Website;

use App\Enums\Website\WebsiteFormFieldType;
use App\Library\Crm\CrmBoard;
use App\Library\Crm\CrmOpportunityService;
use App\Models\WebsiteForm;
use App\Models\WebsiteFormSubmission;
use App\Repositories\Eloquent\EloquentContactsRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Support\Str;

/**
 * The one writer of website_form_submissions, and the one place a
 * submitted inquiry turns into a Contact and, where the Business already
 * has a CRM pipeline, a CrmOpportunity (source `website_form` — the exact
 * seam crm_opportunities' own migration docblock names as a future step).
 *
 * A Business with no CRM pipeline yet never loses the inquiry: the
 * WebsiteFormSubmission row is the durable record either way.
 *
 * An anonymous inquiry is never treated as messaging consent: the Contact
 * this creates is never subscribed and never fires a contact-created
 * automation (EloquentContactsRepository::findOrCreateForWebsiteForm()
 * carries that rule) — only the CRM link is created, so the business can
 * find and reply to the inquiry the same way it always contacts a lead.
 *
 * Never called for a preview render (WebsiteFormSubmissionService has no
 * concept of preview — the public controller simply never calls submit()
 * from a preview request in the first place).
 */
final class WebsiteFormSubmissionService
{
    private const DUPLICATE_WINDOW_MINUTES = 10;

    public const HONEYPOT_FIELD = 'website_hp';

    public function __construct(
        private readonly EloquentContactsRepository $contacts,
        private readonly CrmBoard $crmBoard,
        private readonly CrmOpportunityService $opportunities,
    ) {}

    /**
     * @param  array<int, array{key: string, label: string, type: string, required: bool}>  $fields  the form's field config AS PUBLISHED — the caller reads this from the immutable snapshot, never live from $form, so an edit made after publishing can never change what a submission is validated against
     * @param  array<string, mixed>  $input  raw request input, keyed by the form's own field keys, plus the honeypot field
     * @return ?WebsiteFormSubmission null when this exact request (same
     *                                form, same field values) was already submitted within the duplicate
     *                                window — a different value in even one field is a distinct request and is
     *                                always retained
     */
    public function submit(WebsiteForm $form, array $fields, string $formName, array $input, ?string $pageSlug, ?string $ip): ?WebsiteFormSubmission
    {
        $isSpam = trim((string) ($input[self::HONEYPOT_FIELD] ?? '')) !== '';
        $data = $this->validated($fields, $input);
        $dedupeKey = $this->dedupeKey($form, $data);

        return DB::transaction(function () use ($form, $formName, $data, $pageSlug, $ip, $dedupeKey, $isSpam) {
            // Locks the WebsiteForm row itself so two submissions racing
            // for the SAME form are serialized — the second one's
            // duplicate check below only ever runs after the first has
            // either committed or rolled back, never concurrently with it.
            WebsiteForm::where('id', $form->id)->lockForUpdate()->first();

            if ($dedupeKey !== null && $this->isDuplicate($form, $dedupeKey)) {
                return null;
            }

            $submission = WebsiteFormSubmission::create([
                'website_form_id' => $form->id,
                'page_slug' => $pageSlug,
                'data' => $data,
                'dedupe_key' => $dedupeKey ?? Str::random(64),
                'ip_hash' => $ip !== null && $ip !== '' ? hash('sha256', $ip) : null,
                'is_spam' => $isSpam,
                'status' => WebsiteFormSubmission::STATUS_NEW,
            ]);

            // Spam is recorded (so a business can see what is being
            // filtered) but never creates a Contact or CrmOpportunity.
            if ($isSpam || empty($data['phone'])) {
                return $submission;
            }

            $business = $form->website->business;
            [$firstName, $lastName] = $this->splitName((string) ($data['name'] ?? ''));

            $contact = $this->contacts->findOrCreateForWebsiteForm($business, (string) $data['phone'], [
                'FIRST_NAME' => $firstName,
                'LAST_NAME' => $lastName,
            ]);
            $submission->contact_id = $contact->id;

            $pipeline = $this->crmBoard->pipelines($business)->first();
            if ($pipeline !== null) {
                $title = Str::limit(trim(($data['name'] !== '' ? $data['name'].' — ' : '').$formName), CrmOpportunityService::TITLE_MAX, '');
                $opportunity = $this->opportunities->create(
                    $business,
                    $pipeline,
                    $contact,
                    $title,
                    null,
                    null,
                    null,
                    'website_form',
                );
                $submission->crm_opportunity_id = $opportunity->id;
            }

            $submission->save();

            return $submission;
        });
    }

    /**
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
                WebsiteFormFieldType::Email => [$prefix, 'email', 'max:160'],
                WebsiteFormFieldType::Date => [$prefix, 'date'],
                WebsiteFormFieldType::Textarea => [$prefix, 'string', 'max:2000'],
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

    /**
     * Null when the submission carries neither a phone nor an email — a
     * shape only a future, non-preset form could produce, since the
     * shipped preset always requires phone. Deduplication needs a real
     * identity to compare; without one, every submission is unique.
     *
     * Hashes every submitted field, not just phone/email: two requests
     * from the same person are duplicates only when EVERY value matches
     * (an honest double-click or a page refresh). A different event date,
     * event type or message is a different request and must never be
     * silently dropped just because the contact details repeat.
     */
    private function dedupeKey(WebsiteForm $form, array $data): ?string
    {
        $hasIdentity = trim((string) ($data['phone'] ?? '')) !== '' || trim((string) ($data['email'] ?? '')) !== '';

        if (! $hasIdentity) {
            return null;
        }

        $normalized = [];
        foreach ($data as $key => $value) {
            $normalized[$key] = is_string($value) ? strtolower(trim($value)) : $value;
        }
        ksort($normalized);

        return hash('sha256', $form->id.'|'.json_encode($normalized));
    }

    private function isDuplicate(WebsiteForm $form, string $dedupeKey): bool
    {
        return WebsiteFormSubmission::query()
            ->where('website_form_id', $form->id)
            ->where('dedupe_key', $dedupeKey)
            ->where('created_at', '>=', now()->subMinutes(self::DUPLICATE_WINDOW_MINUTES))
            ->exists();
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

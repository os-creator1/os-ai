<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Entitlement\PlatformFeature;
use App\Exceptions\Workspace\BusinessWorkspaceMismatchException;
use App\Exceptions\Workspace\WorkspaceBusinessNotFoundException;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\Calendar\CalendarScheduleService;
use App\Enums\Automation\Workflow\ContactCreationSource;
use App\Library\Contacts\ContactDirectory;
use App\Library\Contacts\ContactEmailIdentity;
use App\Library\Contacts\CountryDirectory;
use App\Library\Crm\CrmLocationScope;
use App\Library\CustomFields\CustomFieldRuleException;
use App\Library\CustomFields\CustomFieldValueService;
use App\Library\Workspace\LocationAccessGuard;
use App\Models\Contacts;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Workspace\BusinessRouteAccess;
use App\Models\Business;
use App\Models\ContactGroups;
use App\Models\Workspace;
use App\Repositories\Contracts\ContactsRepository;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/**
 * Contacts, person first: Contacts opens "All contacts" — every contact of
 * the selected Business, whatever group it is in — and each contact opens a
 * profile of what is actually stored about them. Groups stay fully
 * available as the secondary tab (ContactsController, unchanged).
 *
 * Tenancy is the Contacts boundary: an unknown account or Business, a
 * Business the actor cannot access, and a contact outside the Business all
 * answer the same 404. Adding and importing reuse the existing per-group
 * flows; a Business with no group yet gets its first list ("Contacts") on
 * request instead of being sent through the Groups screen.
 */
class ContactDirectoryController extends CustomerBaseController
{
    private const FIRST_LIST_NAME = 'Contacts';

    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly EntitlementManager $entitlementManager,
        private readonly ContactsRepository $contactGroups,
        private readonly ContactDirectory $directory,
        private readonly CustomFieldValueService $customFields,
    ) {
    }

    public function listing(Request $request, string $workspaceUid, string $businessUid): View
    {
        [, $business] = $this->resolveBusiness($workspaceUid, $businessUid);
        $this->authorize('view_contact');

        $search = mb_substr(trim((string) $request->query('q', '')), 0, 100);

        // The group filter is offered only to an actor who may view groups
        // (the same view_contact_group the Groups tab requires), and only
        // lists groups holding at least one contact this actor may already
        // see under the CRM Location scope — so it never discloses an empty
        // group, or one whose contacts are all at unreachable Locations. A
        // uid outside that list (unknown, foreign, hidden) is ignored rather
        // than widening or erroring.
        $groups = collect();

        if (Gate::allows('view_contact_group')) {
            $visibleContacts = Contacts::query()
                ->where('business_id', $business->id)
                ->whereNotNull('group_id');
            app(CrmLocationScope::class)->restrict($visibleContacts, $business, (int) Auth::id(), 'contacts.location_id');

            $groups = ContactGroups::query()
                ->where('business_id', $business->id)
                ->whereIn('id', $visibleContacts->select('group_id'))
                ->orderBy('name')
                ->get(['id', 'uid', 'name']);
        }

        $group = $groups->firstWhere('uid', (string) $request->query('group', ''));

        $added = \App\Library\Dashboard\Home\HomeDestinationRange::fromRequest($request, $business);

        $modal = Gate::allows('create_contact') ? $this->newContactModalShell($business) : null;

        return view('customer.people.index', [
            'browserCalling' => $this->browserCalling($workspaceUid, $businessUid, $business),
            'modal' => $modal,
            'added' => $added,
            'contacts' => $this->directory->page($business, $search, (int) Auth::id(), $group?->id, $added),
            'search' => $search,
            'groups' => $groups,
            'selectedGroup' => $group,
        ]);
    }

    public function show(string $workspaceUid, string $businessUid, string $contactUid): View
    {
        [$workspace, $business] = $this->resolveBusiness($workspaceUid, $businessUid);
        $this->authorize('view_contact');

        // A Contact at a Location the actor cannot reach answers exactly like an
        // unknown uid: knowing the uid never bypasses the Location ACL (Addendum §4).
        $contact = $this->directory->findForBusiness($business, $contactUid, (int) Auth::id());
        abort_if($contact === null, 404);

        $canConverse = $this->canSeeConversations($workspace, $business);
        $profile = $this->directory->profile($business, $contact, $canConverse);

        return view('customer.people.show', [
            'contactUid' => (string) $contact->uid,
            'profile' => $profile,
            'browserCalling' => $this->browserCalling($workspaceUid, $businessUid, $business),
            'callHistory' => $this->callHistory($business, $contact),
            'actions' => $this->profileActions($workspace, $business, $contact, $profile, $canConverse),
            'emailActions' => $this->emailActions($workspace, $business, $contact, $canConverse),
            // Business-defined Custom Fields: only shown for a Contact whose
            // Location the actor may access (the existing Contact Location ACL).
            'customFields' => $this->locationAccessible($contact)
                ? $this->customFields->sectionFor($business, $contact)
                : null,
        ]);
    }

    /**
     * The Email block of the profile: the canonical address and what can honestly be done with it.
     *
     *  - state: none | valid | ambiguous (several different EMAIL values: never guess one).
     *  - sendUrl: Conversations → Email composer for this Contact, only with a valid address, a connected
     *    mailbox and the chat/contact permissions. With a valid address but no mailbox, connectUrl instead.
     *
     * @return array{state: string, email: ?string, sendUrl: ?string, connectUrl: ?string, canEdit: bool, updateUrl: string}
     */
    private function emailActions(Workspace $workspace, Business $business, Contacts $contact, bool $canConverse): array
    {
        $status = app(ContactEmailIdentity::class)->status($contact);
        $scope = [$workspace->uid, $business->uid];
        $connected = app(\App\Library\BusinessEmail\BusinessEmailAccountManager::class)->findForBusiness($business)?->isActive() === true;
        $mayCompose = $canConverse && Gate::allows('chat_box') && Gate::allows('view_contact');

        return [
            'state' => $status['state'],
            'email' => $status['email'],
            'sendUrl' => $status['state'] === ContactEmailIdentity::STATE_VALID && $mayCompose && $connected
                ? route('customer.workspaces.businesses.conversations.index', [...$scope, 'channel' => 'email', 'compose' => (string) $contact->uid])
                : null,
            'connectUrl' => $status['state'] === ContactEmailIdentity::STATE_VALID && $mayCompose && ! $connected
                ? route('customer.workspaces.businesses.email.show', $scope)
                : null,
            'canEdit' => Gate::allows('update_contact') && $this->locationAccessible($contact) && $contact->contactGroup !== null,
            'updateUrl' => route('customer.workspaces.businesses.people.email.update', [...$scope, (string) $contact->uid]),
        ];
    }

    /** Add / change / clear the Contact's canonical EMAIL identity (the built-in field, created for the group on demand). */
    public function updateEmail(Request $request, string $workspaceUid, string $businessUid, string $contactUid): RedirectResponse
    {
        [, $business] = $this->resolveBusiness($workspaceUid, $businessUid);
        $this->authorize('update_contact');

        $contact = $this->directory->findForBusiness($business, $contactUid, (int) Auth::id());
        abort_if($contact === null || ! $this->locationAccessible($contact), 404);

        $input = $request->validate(['email' => ['nullable', 'string', 'max:191']]);
        $back = redirect()->route('customer.workspaces.businesses.people.show', [$workspaceUid, $businessUid, $contactUid]);

        $recorder = app(\App\Library\Contacts\ContactEditRecorder::class);

        try {
            // The canonical email update and its audit event must commit or roll back together.
            // Lock the contact before taking the old snapshot to serialize concurrent manual saves.
            $saved = DB::transaction(function () use ($contact, $business, $input, $recorder): ?string {
                $locked = Contacts::query()
                    ->where('business_id', $business->id)
                    ->lockForUpdate()
                    ->findOrFail($contact->id);
                $before = $recorder->snapshot($locked);
                $saved = app(ContactEmailIdentity::class)->save($locked, $input['email'] ?? null);
                $recorder->record(
                    $business,
                    $locked,
                    ['email' => $before['email']],
                    ['email' => $recorder->snapshot($locked)['email']],
                    Auth::id() !== null ? (int) Auth::id() : null,
                );

                return $saved;
            });
        } catch (\InvalidArgumentException $exception) {
            return $back->withInput()->withErrors(['email' => $exception->getMessage()]);
        }

        return $back->with('flash_success', $saved === null ? 'Email removed.' : 'Email saved.');
    }

    /**
     * Save the Contact-details Custom Fields section: typed validation per
     * field, all-or-nothing, only this Business's active fields.
     */
    public function updateCustomFields(Request $request, string $workspaceUid, string $businessUid, string $contactUid): RedirectResponse
    {
        [, $business] = $this->resolveBusiness($workspaceUid, $businessUid);
        $this->authorize('update_contact');

        $contact = $this->directory->findForBusiness($business, $contactUid);
        abort_if($contact === null || ! $this->locationAccessible($contact), 404);

        $input = $request->validate(['custom_fields' => ['nullable', 'array', 'max:200']])['custom_fields'] ?? [];

        try {
            $this->customFields->saveForContact($business, $contact, $input);
        } catch (CustomFieldRuleException $exception) {
            return back()->withInput()->withErrors(['custom_fields' => $exception->getMessage()]);
        }

        return redirect()
            ->route('customer.workspaces.businesses.people.show', [$workspaceUid, $businessUid, $contactUid])
            ->with('flash_success', 'Custom fields saved.');
    }

    /**
     * Browser calling is offered only to a user who may place calls, on a deployment where it is switched on and live.
     * The page then gets the endpoints the softphone needs; every one re-checks tenancy, entitlement and permission.
     *
     * @return array{sessionUrl: string, placeUrl: string, statusUrl: string, hangupUrl: string, wrapupUrl: string}|null
     */
    private function browserCalling(string $workspaceUid, string $businessUid, Business $business): ?array
    {
        if (! (bool) config('messaging.voice.customer_calls.enabled')
            || ! (bool) config('messaging.voice.browser.enabled')
            || config('services.telnyx.mode') !== 'live'
            || ! app(\App\Library\Voice\CallingAuthority::class)->mayCall(auth()->user(), $business)) {
            return null;
        }

        $scope = [$workspaceUid, $businessUid];

        $inbound = app(\App\Library\Voice\Inbound\InboundCallingSurface::class)->inboundUrls($workspaceUid, $business, auth()->user());

        return [
            'inbound' => $inbound,
            'sessionUrl' => route('customer.workspaces.businesses.calls.browser-session', $scope),
            'placeUrl' => route('customer.workspaces.businesses.calls.store', $scope),
            'statusUrl' => route('customer.workspaces.businesses.calls.show', [...$scope, '__CALL__']),
            'hangupUrl' => route('customer.workspaces.businesses.calls.hangup', [...$scope, '__CALL__']),
            'wrapupUrl' => route('customer.workspaces.businesses.calls.wrapup', [...$scope, '__CALL__']),
            'numbersUrl' => route('customer.workspaces.businesses.calls.numbers', $scope),
        ];
    }

    /**
     * The contact's recent calls for the profile: outcome, duration and time only. No phone number, Telnyx id or
     * credential is ever included. The Contact's Location access was already enforced by findForBusiness().
     *
     * @return list<array{outcome: string, duration: ?string, when: string}>
     */
    private function callHistory(Business $business, Contacts $contact): array
    {
        return \App\Models\BusinessVoiceCall::query()
            ->where('business_id', $business->id)->whereIn('kind', ['customer', 'inbound'])->where('contact_id', $contact->id)
            ->whereIn('status', [\App\Models\BusinessVoiceCall::STATUS_ENDED, \App\Models\BusinessVoiceCall::STATUS_FAILED])
            ->latest('id')->limit(10)->get()
            ->map(function ($call): array {
                $seconds = $call->duration_seconds;

                return [
                    'outcome' => $call->kind === 'inbound'
                        ? match ($call->outcome) {
                            'answered' => 'Incoming · Answered',
                            'forwarded' => 'Incoming · Forwarded to phone',
                            'declined' => 'Incoming · Declined',
                            'failed' => 'Incoming · Failed',
                            default => 'Incoming · Missed',
                        }
                        : ($call->status === \App\Models\BusinessVoiceCall::STATUS_FAILED
                            ? 'Failed'
                            : ($call->answered_at !== null ? 'Connected' : 'Not answered')),
                    'duration' => $seconds !== null ? intdiv($seconds, 60) . ':' . str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT) : null,
                    'when' => $call->created_at?->diffForHumans() ?? '',
                ];
            })->all();
    }

    /**
     * The profile's header actions, each only when it has a real, authorized
     * destination (otherwise null and the view shows nothing):
     *  - messageUrl: the existing Inbox / new-conversation screens. They cannot be
     *    pre-filled with this Contact's number today, so this opens the screen only.
     *  - bookUrl: the "Book appointment" screen at the Contact's Location (or the actor's
     *    only Location) with this Contact preselected by its immutable uid. That screen
     *    re-resolves the uid server-side against the Business and Location, so the link
     *    carries no authority of its own.
     *
     * @return array{messageUrl: ?string, messageIsInbox: bool, bookUrl: ?string}
     */
    private function profileActions(Workspace $workspace, Business $business, Contacts $contact, array $profile, bool $canConverse): array
    {
        $scope = [$workspace->uid, $business->uid];

        $messageUrl = null;

        if ($canConverse) {
            $messageUrl = route('customer.workspaces.businesses.conversations.' . ($profile['conversation'] !== null ? 'index' : 'new'), $scope);
        }

        $bookUrl = null;

        try {
            $calendar = $this->entitlementManager->decide($workspace, $business, PlatformFeature::Calendar->value, (int) Auth::id())->allowed;
        } catch (WorkspaceBusinessNotFoundException|BusinessWorkspaceMismatchException) {
            $calendar = false;
        }

        if ($calendar) {
            $locations = app(CalendarScheduleService::class)->accessibleLocations($business, (int) Auth::id());
            $location = $contact->location_id !== null
                ? $locations->firstWhere('id', (int) $contact->location_id)
                : ($locations->count() === 1 ? $locations->first() : null);

            if ($location !== null) {
                $bookUrl = route('customer.workspaces.businesses.calendar.appointments.create', [...$scope, $location->uid]) . '?contact=' . urlencode((string) $contact->uid);
            }
        }

        return ['messageUrl' => $messageUrl, 'messageIsInbox' => $profile['conversation'] !== null, 'bookUrl' => $bookUrl];
    }

    /** A Contact with no Location is governed by Business access alone; otherwise the Location must be the actor's. */
    private function locationAccessible(Contacts $contact): bool
    {
        if ($contact->location_id === null) {
            return true;
        }

        $location = $contact->location;

        return $location !== null && app(LocationAccessGuard::class)->userCanAccessLocation((int) Auth::id(), $location);
    }

    /** Static shell data for the modal: the country list and default region only (no group or Location names are rendered into the page). */
    private function newContactModalShell(Business $business): array
    {
        $region = strtoupper((string) $business->country_code);

        return [
            'countries' => CountryDirectory::all(),
            'defaultRegion' => CountryDirectory::isRegion($region) ? $region : CountryDirectory::DEFAULT_REGION,
        ];
    }

    /** The modal's group / Location choices, fetched when it opens (same authorization as the add chooser). */
    public function newContactOptions(string $workspaceUid, string $businessUid): JsonResponse
    {
        [, $business] = $this->resolveBusiness($workspaceUid, $businessUid);
        $this->authorize('create_contact');
        $data = $this->newContactModalData($business);

        return response()->json(['groups' => $data['groups'], 'locations' => $data['locations'], 'canCreateFirstList' => $data['canCreateFirstList']]);
    }

    /**
     * Data the New-contact modal needs: the Business's groups (not only those already holding
     * contacts), the Locations the actor may create at, and the country list.
     *
     * @return array{groups: list<array{uid: string, name: string}>, locations: list<array{uid: string, name: string}>, countries: list<array<string, mixed>>, defaultRegion: string, canCreateFirstList: bool}
     */
    private function newContactModalData(Business $business): array
    {
        $groups = ContactGroups::query()->where('business_id', $business->id)->orderBy('name')->get(['id', 'uid', 'name']);
        $locations = app(CrmLocationScope::class)->selectableLocations($business, (int) Auth::id());
        $region = strtoupper((string) $business->country_code);

        return [
            'groups' => $groups->map(fn (ContactGroups $g) => ['uid' => (string) $g->uid, 'name' => (string) $g->name])->all(),
            'locations' => $locations->count() > 1 ? $locations->map(fn ($l) => ['uid' => (string) $l->uid, 'name' => (string) $l->name])->values()->all() : [],
            'countries' => CountryDirectory::all(),
            'defaultRegion' => CountryDirectory::isRegion($region) ? $region : CountryDirectory::DEFAULT_REGION,
            'canCreateFirstList' => $groups->isEmpty() && Gate::allows('create_contact_group'),
        ];
    }

    /**
     * The New-contact modal's save. The phone is validated against the CHOSEN country (libphonenumber)
     * and stored in the one canonical digits form; the email is written to the built-in EMAIL identity
     * field of the group (created on demand), never to an invented custom field. JSON in, JSON out;
     * every tenancy failure is a 404 and nothing is created on a validation error.
     */
    public function store(Request $request, string $workspaceUid, string $businessUid): JsonResponse
    {
        [, $business] = $this->resolveBusiness($workspaceUid, $businessUid);
        $this->authorize('create_contact');

        $input = $request->validate([
            'country' => ['required', 'string', 'size:2'],
            'phone' => ['required', 'string', 'max:40'],
            'first_name' => ['nullable', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'string', 'max:191'],
            'group' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'location' => ['nullable', 'string', 'max:64'],
        ]);

        $errors = [];
        $phone = CountryDirectory::validate($input['phone'], $input['country']);

        if (! $phone['ok']) {
            $errors['phone'] = [$phone['error']];
        }

        $email = trim((string) ($input['email'] ?? ''));

        if ($email !== '' && \App\Library\BusinessEmail\BusinessEmailContactResolver::normalize($email) === null) {
            $errors['email'] = ['Enter a valid email address.'];
        }

        // The group: only one of THIS Business's; the first list is offered, never forced on, when there is none.
        $groupQuery = ContactGroups::query()->where('business_id', $business->id);
        $group = filled($input['group'] ?? null)
            ? (clone $groupQuery)->where('uid', (string) $input['group'])->first()
            : (clone $groupQuery)->orderBy('id')->first();

        if ($group === null && ! filled($input['group'] ?? null) && Gate::allows('create_contact_group') && ! $groupQuery->exists()) {
            $group = $this->contactGroups->store(['name' => self::FIRST_LIST_NAME, 'business_id' => $business->id, 'user_id' => $business->customer_id]);
        }

        if ($group === null) {
            $errors['group'] = ['Choose one of your groups.'];
        }

        // The Location: one is used automatically, several require the actor to choose one of THEIR reachable ones.
        $locationId = null;
        $options = app(CrmLocationScope::class)->selectableLocations($business, (int) Auth::id());

        if ($options->isEmpty()) {
            $errors['location'] = [__('locale.contacts.no_location_available')];
        } elseif ($options->count() === 1 && ! filled($input['location'] ?? null)) {
            $locationId = (int) $options->first()->id;
        } else {
            $chosen = $options->firstWhere('uid', (string) ($input['location'] ?? ''));
            $chosen === null ? $errors['location'] = [__('locale.contacts.choose_a_location')] : $locationId = (int) $chosen->id;
        }

        if ($errors !== []) {
            return response()->json(['status' => 'error', 'message' => 'Please fix the highlighted fields.', 'errors' => $errors], 422);
        }

        /** @var ContactGroups $group */
        $identity = app(ContactEmailIdentity::class);
        $identity->ensureField($group);
        $notes = trim((string) ($input['notes'] ?? ''));

        if ($notes !== '') {
            $identity->ensureField($group, 'NOTES');
        }

        [$validator, $subscriber] = $this->contactGroups->createContactFromRequest($group->refresh(), array_filter([
            'PHONE' => $phone['digits'],
            'FIRST_NAME' => trim((string) ($input['first_name'] ?? '')),
            'LAST_NAME' => trim((string) ($input['last_name'] ?? '')),
            'EMAIL' => $email !== '' ? \App\Library\BusinessEmail\BusinessEmailContactResolver::normalize($email) : null,
            'NOTES' => $notes !== '' ? $notes : null,
        ], static fn ($value) => $value !== null) + ['FIRST_NAME' => '', 'LAST_NAME' => ''], ContactCreationSource::Manual, $locationId);

        if ($subscriber === null) {
            $messages = collect($validator->errors()->toArray())
                ->mapWithKeys(fn ($list, $key) => [strtolower((string) $key) === 'phone' ? 'phone' : strtolower((string) $key) => $list])
                ->map(fn ($list, $key) => $key === 'phone' ? ['A contact with this phone number already exists in this group.'] : $list)
                ->all();

            return response()->json(['status' => 'error', 'message' => 'The contact was not saved.', 'errors' => $messages], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Contact saved.',
            'contact' => [
                'uid' => (string) $subscriber->uid,
                'url' => route('customer.workspaces.businesses.people.show', [$workspaceUid, $businessUid, $subscriber->uid]),
            ],
        ]);
    }

    /**
     * The contact as the Edit-contact modal needs it (JSON, fetched when the modal opens). The Contact must belong to the
     * Business and sit at a Location the actor may reach; anything else answers 404 exactly like an unknown uid. Only what
     * the schema really stores is returned: the phone split into its country and national number, the identity fields
     * (first / last name, email, company), the group and the notes.
     */
    public function editDetails(string $workspaceUid, string $businessUid, string $contactUid): JsonResponse
    {
        [, $business] = $this->resolveBusiness($workspaceUid, $businessUid);
        $this->authorize('update_contact');
        $contact = $this->editableContact($business, $contactUid);

        $email = app(ContactEmailIdentity::class)->status($contact);
        [$region, $national] = $this->splitPhone((string) $contact->phone, $this->defaultRegionFor($business));

        return response()->json([
            'uid' => (string) $contact->uid,
            'country' => $region,
            'phone' => $national,
            'first_name' => (string) $contact->getValueByTag('FIRST_NAME'),
            'last_name' => (string) $contact->getValueByTag('LAST_NAME'),
            'email' => (string) ($email['email'] ?? ''),
            'email_ambiguous' => $email['state'] === ContactEmailIdentity::STATE_AMBIGUOUS,
            'company' => (string) $contact->getValueByTag('COMPANY'),
            'notes' => (string) $contact->getValueByTag('NOTES'),
            'group' => $contact->contactGroup !== null ? (string) $contact->contactGroup->uid : '',
            'groups' => ContactGroups::query()->where('business_id', $business->id)->orderBy('name')->get(['uid', 'name'])
                ->map(fn (ContactGroups $g) => ['uid' => (string) $g->uid, 'name' => (string) $g->name])->all(),
            'can_delete' => Gate::allows('delete_contact'),
            'updateUrl' => route('customer.workspaces.businesses.people.update', [$workspaceUid, $businessUid, $contact->uid]),
            'deleteUrl' => route('customer.workspaces.businesses.people.destroy', [$workspaceUid, $businessUid, $contact->uid]),
        ]);
    }

    /**
     * The Edit-contact modal's save: the same canonical writers the profile and the New-contact modal use
     * (ContactEmailIdentity for the email, Contacts::updateFields for the identity fields and the stored phone). The phone is
     * validated against the CHOSEN country and stored in the one canonical digits form; the contact keeps its uid, Business and
     * Location. A group change carries every stored value across by field tag and is refused, changing nothing, when it would drop a
     * value the other group has no field for. JSON in, JSON out; nothing is written on a validation error.
     */
    public function update(Request $request, string $workspaceUid, string $businessUid, string $contactUid): JsonResponse
    {
        [, $business] = $this->resolveBusiness($workspaceUid, $businessUid);
        $this->authorize('update_contact');

        if (config('app.stage') === 'demo') {
            return response()->json(['status' => 'error', 'message' => 'Sorry! This option is not available in demo mode'], 403);
        }

        $contact = $this->editableContact($business, $contactUid);

        $input = $request->validate([
            'country' => ['required', 'string', 'size:2'],
            'phone' => ['required', 'string', 'max:40'],
            'first_name' => ['nullable', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'string', 'max:191'],
            'company' => ['nullable', 'string', 'max:191'],
            'group' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $errors = [];
        $phone = CountryDirectory::validate($input['phone'], $input['country']);

        if (! $phone['ok']) {
            $errors['phone'] = [$phone['error']];
        }

        $email = trim((string) ($input['email'] ?? ''));
        $normalizedEmail = $email === '' ? null : \App\Library\BusinessEmail\BusinessEmailContactResolver::normalize($email);

        if ($email !== '' && $normalizedEmail === null) {
            $errors['email'] = ['Enter a valid email address.'];
        }

        // The group: only one of THIS Business's own; empty keeps the current one.
        $current = ContactGroups::query()->where('business_id', $business->id)->whereKey($contact->group_id)->first();
        $target = $current;

        if (filled($input['group'] ?? null) && (string) $input['group'] !== (string) $current?->uid) {
            $target = ContactGroups::query()->where('business_id', $business->id)->where('uid', (string) $input['group'])->first();

            if ($target === null) {
                $errors['group'] = ['Choose one of your groups.'];
            }
        }

        if ($target === null && ! isset($errors['group'])) {
            $errors['group'] = ['Choose one of your groups.'];
        }

        $moving = $target !== null && $current !== null && (int) $target->id !== (int) $current->id;
        $lost = $moving ? $this->valuesWithoutAHome($contact, $target) : [];

        if ($lost !== []) {
            $errors['group'] = ['“' . $target->name . '” has no ' . implode(', ', $lost) . ' field, so that information would be lost. Add the field to the group first.'];
        }

        // The number is the contact's identity inside its Business and Location: another contact there may not hold it.
        if ($phone['ok']) {
            $digits = (string) $phone['digits'];
            $duplicate = Contacts::query()
                ->where('business_id', $business->id)
                ->where('id', '!=', $contact->id)
                ->where('phone', $digits)
                ->when(
                    $contact->location_id === null,
                    fn ($query) => $query->whereNull('location_id'),
                    fn ($query) => $query->where('location_id', $contact->location_id),
                )
                ->exists();

            if ($duplicate) {
                $errors['phone'] = ['A contact with this phone number already exists.'];
            } elseif ($digits !== (string) $contact->phone && \App\Models\Blacklists::where('number', $digits)->exists()) {
                $errors['phone'] = [__('locale.blacklist.phone_was_blacklisted')];
            }
        }

        if ($errors !== []) {
            return response()->json(['status' => 'error', 'message' => 'Please fix the highlighted fields.', 'errors' => $errors], 422);
        }

        $identity = app(ContactEmailIdentity::class);
        $notes = trim((string) ($input['notes'] ?? ''));
        $ambiguous = $identity->status($contact)['state'] === ContactEmailIdentity::STATE_AMBIGUOUS;
        $recorder = app(\App\Library\Contacts\ContactEditRecorder::class);
        $before = $recorder->snapshot($contact);

        DB::transaction(function () use ($contact, $current, $target, $moving, $phone, $input, $notes, $normalizedEmail, $ambiguous, $identity, $recorder, $before, $business): void {
            // The number is about to change: keep this lead's existing conversations attached to it.
            if ($phone['ok'] && (string) $phone['digits'] !== (string) $contact->phone) {
                \App\Models\ChatBox::linkConversationsToContact($contact);
            }

            if ($moving) {
                $this->moveToGroup($contact, $target);
            }

            foreach (['FIRST_NAME', 'LAST_NAME', 'COMPANY', 'NOTES'] as $tag) {
                $identity->ensureField($target, $tag);
            }

            $contact->setRelation('contactGroup', ContactGroups::query()->find($target->id));
            $contact->updateFields([
                'PHONE' => (string) $phone['digits'],
                'FIRST_NAME' => trim((string) ($input['first_name'] ?? '')),
                'LAST_NAME' => trim((string) ($input['last_name'] ?? '')),
                'COMPANY' => trim((string) ($input['company'] ?? '')),
                'NOTES' => $notes,
            ]);

            // Several different stored addresses are never overwritten by a blank box.
            if (! ($ambiguous && $normalizedEmail === null)) {
                $identity->save($contact, $normalizedEmail);
            }

            if ($moving) {
                $current->updateCache();
                $target->updateCache();
            }

            // Same transaction as the write: an edit and its record stand or fall together.
            $recorder->record($business, $contact, $before, $recorder->snapshot($contact), Auth::id() !== null ? (int) Auth::id() : null);
        });

        session()->flash('flash_success', 'Contact saved.');

        return response()->json(['status' => 'success', 'message' => 'Contact saved.']);
    }

    /** Delete from the Edit-contact modal: the canonical group delete, behind the delete permission and the same Location ACL. */
    public function destroy(string $workspaceUid, string $businessUid, string $contactUid): JsonResponse
    {
        [, $business] = $this->resolveBusiness($workspaceUid, $businessUid);
        $this->authorize('delete_contact');

        if (config('app.stage') === 'demo') {
            return response()->json(['status' => 'error', 'message' => 'Sorry! This option is not available in demo mode'], 403);
        }

        $contact = $this->editableContact($business, $contactUid);
        $group = ContactGroups::query()->where('business_id', $business->id)->whereKey($contact->group_id)->first();
        abort_if($group === null, 404);

        if (! $this->contactGroups->contactDestroy($group, (string) $contact->uid)) {
            return response()->json(['status' => 'error', 'message' => 'The contact could not be deleted.'], 422);
        }

        session()->flash('flash_success', 'Contact deleted.');

        return response()->json([
            'status' => 'success',
            'message' => 'Contact deleted.',
            'redirect' => route('customer.workspaces.businesses.people.index', [$workspaceUid, $businessUid]),
        ]);
    }

    /** A contact of this Business at a Location the actor may reach; every other case is the same 404. */
    private function editableContact(Business $business, string $contactUid): Contacts
    {
        $contact = $this->directory->findForBusiness($business, $contactUid, (int) Auth::id());
        abort_if($contact === null || ! $this->locationAccessible($contact), 404);

        return $contact;
    }

    private function defaultRegionFor(Business $business): string
    {
        $region = strtoupper((string) $business->country_code);

        return CountryDirectory::isRegion($region) ? $region : CountryDirectory::DEFAULT_REGION;
    }

    /**
     * The stored digits as the picker shows them: the number's own country and its national form.
     *
     * @return array{0: string, 1: string}
     */
    private function splitPhone(string $digits, string $fallbackRegion): array
    {
        $digits = trim($digits);
        $util = PhoneNumberUtil::getInstance();

        try {
            $number = $util->parse('+' . ltrim($digits, '+'), null);
            $region = $util->getRegionCodeForNumber($number);

            if ($region !== null && CountryDirectory::isRegion($region)) {
                return [$region, $util->format($number, PhoneNumberFormat::NATIONAL)];
            }
        } catch (NumberParseException) {
            // fall through: show the digits exactly as stored
        }

        return [$fallbackRegion, $digits];
    }

    /**
     * Labels of the contact's non-empty stored values that the target group has no field for (so a move would lose them).
     * Built-in identity fields do not count: they are created on the target group on demand.
     *
     * @return list<string>
     */
    private function valuesWithoutAHome(Contacts $contact, ContactGroups $target): array
    {
        $builtIn = ['PHONE', 'FIRST_NAME', 'LAST_NAME', 'EMAIL', 'COMPANY', 'NOTES'];
        $targetTags = DB::table('contact_group_fields')->where('contact_group_id', $target->id)->pluck('tag')->map(fn ($t) => strtolower((string) $t))->all();

        return DB::table('contacts_custom_field as v')
            ->join('contact_group_fields as f', 'f.id', '=', 'v.field_id')
            ->where('v.contact_id', $contact->id)
            ->get(['f.tag', 'f.label', 'v.value'])
            ->filter(fn ($row) => trim((string) $row->value) !== ''
                && ! in_array(strtoupper((string) $row->tag), $builtIn, true)
                && ! in_array(strtolower((string) $row->tag), $targetTags, true))
            ->map(fn ($row) => (string) $row->label)
            ->unique()
            ->values()
            ->all();
    }

    /** Re-home every stored value on the target group's field with the same tag, then point the contact at the group. */
    private function moveToGroup(Contacts $contact, ContactGroups $target): void
    {
        $identity = app(ContactEmailIdentity::class);
        $targetFields = DB::table('contact_group_fields')->where('contact_group_id', $target->id)->orderBy('id')->get(['id', 'tag'])
            ->groupBy(fn ($f) => strtolower((string) $f->tag))->map(fn ($g) => (int) $g->first()->id);

        $rows = DB::table('contacts_custom_field as v')
            ->join('contact_group_fields as f', 'f.id', '=', 'v.field_id')
            ->where('v.contact_id', $contact->id)
            ->get(['v.id as value_id', 'f.tag', 'v.value']);

        foreach ($rows as $row) {
            $tag = strtolower((string) $row->tag);

            if (! $targetFields->has($tag) && in_array(strtoupper($tag), ['FIRST_NAME', 'LAST_NAME', 'EMAIL', 'COMPANY', 'NOTES'], true)) {
                $targetFields->put($tag, $identity->ensureField($target, strtoupper($tag)));
            }

            if ($targetFields->has($tag)) {
                $clash = DB::table('contacts_custom_field')->where('contact_id', $contact->id)->where('field_id', $targetFields[$tag])->where('id', '!=', $row->value_id)->exists();
                $clash
                    ? DB::table('contacts_custom_field')->where('id', $row->value_id)->delete()
                    : DB::table('contacts_custom_field')->where('id', $row->value_id)->update(['field_id' => $targetFields[$tag]]);
            } else {
                DB::table('contacts_custom_field')->where('id', $row->value_id)->delete();
            }
        }

        $contact->group_id = $target->id;
        $contact->save();
    }

    /**
     * + Add contact: straight into the one group's existing add form, a
     * choice when there are several, the first-list offer when there is none.
     */
    public function add(string $workspaceUid, string $businessUid): View|RedirectResponse
    {
        return $this->chooseGroupFor('add', $workspaceUid, $businessUid, 'create_contact');
    }

    /**
     * Import: the existing per-group import, reached the same way as Add.
     */
    public function import(string $workspaceUid, string $businessUid): View|RedirectResponse
    {
        return $this->chooseGroupFor('import', $workspaceUid, $businessUid, 'create_contact');
    }

    /**
     * Creates this Business's first group ("Contacts") so a customer who
     * never uses groups can add or import contacts without the Groups screen.
     * Only while the Business has no group at all; otherwise it is the
     * normal choice again.
     */
    public function createFirstList(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        [, $business] = $this->resolveBusiness($workspaceUid, $businessUid);
        $this->authorize('create_contact_group');
        $next = $request->input('next') === 'import' ? 'import' : 'add';

        if (! ContactGroups::query()->where('business_id', $business->id)->exists()) {
            // Exactly what a business-scoped "New group" stores
            // (ContactsController::store()): the Business, owned by its customer.
            $this->contactGroups->store([
                'name' => self::FIRST_LIST_NAME,
                'business_id' => $business->id,
                'user_id' => $business->customer_id,
            ]);
        }

        return redirect()->route('customer.workspaces.businesses.people.' . $next, [$workspaceUid, $businessUid]);
    }

    private function chooseGroupFor(string $purpose, string $workspaceUid, string $businessUid, string $permission): View|RedirectResponse
    {
        [, $business] = $this->resolveBusiness($workspaceUid, $businessUid);
        $this->authorize($permission);

        $groups = ContactGroups::query()
            ->where('business_id', $business->id)
            ->orderBy('name')
            ->get(['id', 'uid', 'name']);

        $target = $purpose === 'import' ? 'customer.workspaces.businesses.contact.import' : 'customer.workspaces.businesses.contact.create';

        if ($groups->count() === 1) {
            return redirect()->route($target, [$workspaceUid, $businessUid, $groups->first()->uid]);
        }

        return view('customer.people.choose-group', [
            'purpose' => $purpose,
            'groups' => $groups->map(fn (ContactGroups $group) => [
                'name' => (string) $group->name,
                'url' => route($target, [$workspaceUid, $businessUid, $group->uid]),
            ])->all(),
            'canCreateFirstList' => $groups->isEmpty() && Gate::allows('create_contact_group'),
        ]);
    }

    /**
     * The same fail-closed boundary as ContactsController::currentBusinessContext().
     *
     * @return array{0: Workspace, 1: Business}
     */
    private function resolveBusiness(string $workspaceUid, string $businessUid): array
    {
        $workspace = $this->workspaceRepository->findByUid($workspaceUid);

        if ($workspace === null) {
            abort(404);
        }

        $business = $this->workspaceRepository->businessesForWorkspace($workspace)->firstWhere('uid', $businessUid);

        if ($business === null || ! app(BusinessRouteAccess::class)->actorMayUseBusinessRoute(Auth::user(), $workspace, $business)) {
            abort(404);
        }

        return [$workspace, $business];
    }

    /**
     * The profile shows the conversation only to someone the Inbox itself
     * would show it to: the chat permission, and a Business entitled to
     * Conversations (the same decide() the Inbox asks).
     */
    private function canSeeConversations(Workspace $workspace, Business $business): bool
    {
        if (! Gate::allows('chat_box')) {
            return false;
        }

        try {
            return $this->entitlementManager->decide($workspace, $business, PlatformFeature::Conversations->value, (int) Auth::id())->allowed;
        } catch (WorkspaceBusinessNotFoundException|BusinessWorkspaceMismatchException) {
            return false;
        }
    }
}

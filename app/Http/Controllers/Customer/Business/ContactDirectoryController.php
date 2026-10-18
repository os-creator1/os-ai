<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Entitlement\PlatformFeature;
use App\Exceptions\Workspace\BusinessWorkspaceMismatchException;
use App\Exceptions\Workspace\WorkspaceBusinessNotFoundException;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\Calendar\CalendarScheduleService;
use App\Library\Contacts\ContactDirectory;
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
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

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

        return view('customer.people.index', [
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
            'actions' => $this->profileActions($workspace, $business, $contact, $profile, $canConverse),
            // Business-defined Custom Fields: only shown for a Contact whose
            // Location the actor may access (the existing Contact Location ACL).
            'customFields' => $this->locationAccessible($contact)
                ? $this->customFields->sectionFor($business, $contact)
                : null,
        ]);
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
     * The profile's header actions, each only when it has a real, authorized
     * destination (otherwise null and the view shows nothing):
     *  - messageUrl: the existing Inbox / new-conversation screens. They cannot be
     *    pre-filled with this Contact's number today, so this opens the screen only.
     *  - bookUrl: the existing "New appointment" screen at the Contact's Location (or
     *    the actor's only Location) with the Contact pre-searched via `q`; the Contact
     *    still has to be chosen there because that screen has no contact preselection.
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
                $bookUrl = route('customer.workspaces.businesses.calendar.appointments.create', [...$scope, $location->uid]) . '?q=' . urlencode((string) $profile['phone']);
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

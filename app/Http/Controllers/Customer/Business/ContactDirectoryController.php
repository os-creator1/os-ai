<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Entitlement\PlatformFeature;
use App\Exceptions\Workspace\BusinessWorkspaceMismatchException;
use App\Exceptions\Workspace\WorkspaceBusinessNotFoundException;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\Contacts\ContactDirectory;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Workspace\WorkspaceManager;
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
        private readonly WorkspaceManager $workspaceManager,
        private readonly EntitlementManager $entitlementManager,
        private readonly ContactsRepository $contactGroups,
        private readonly ContactDirectory $directory,
    ) {
    }

    public function listing(Request $request, string $workspaceUid, string $businessUid): View
    {
        [, $business] = $this->resolveBusiness($workspaceUid, $businessUid);
        $this->authorize('view_contact');

        $search = mb_substr(trim((string) $request->query('q', '')), 0, 100);

        return view('customer.people.index', [
            'contacts' => $this->directory->page($business, $search),
            'search' => $search,
        ]);
    }

    public function show(string $workspaceUid, string $businessUid, string $contactUid): View
    {
        [$workspace, $business] = $this->resolveBusiness($workspaceUid, $businessUid);
        $this->authorize('view_contact');

        $contact = $this->directory->findForBusiness($business, $contactUid);
        abort_if($contact === null, 404);

        return view('customer.people.show', [
            'contactUid' => (string) $contact->uid,
            'profile' => $this->directory->profile($business, $contact, $this->canSeeConversations($workspace, $business)),
        ]);
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

        if ($business === null || ! $this->workspaceManager->userCanAccessBusiness((int) Auth::id(), $business)) {
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

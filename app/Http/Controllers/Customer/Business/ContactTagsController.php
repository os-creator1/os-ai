<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Entitlement\PlatformFeature;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\Crm\Exceptions\CrmRuleException;
use App\Library\Crm\TagManager;
use App\Library\Workspace\LocationAccessGuard;
use App\Models\Business;
use App\Models\Contacts;
use App\Models\Tag;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Contact Tags foundation §6 — the minimum customer CRM surface: manage a
 * Business's available tags, and attach/detach them on a Contact. Mirrors
 * `CrmOpportunitiesController`'s exact conventions (business tenancy
 * resolution first, reused Contacts permissions, manual uid resolution,
 * Location ACL re-derived from the persisted row) rather than inventing a
 * new controller shape.
 */
class ContactTagsController extends CustomerBaseController
{
    use ResolvesBusinessTenancy;

    private const VIEW_PERMISSION = 'view_contact';

    private const MANAGE_PERMISSION = 'update_contact';

    public function __construct(private readonly TagManager $tags)
    {
    }

    public function list(string $workspaceUid, string $businessUid): View
    {
        $business = $this->business($workspaceUid, $businessUid);
        $this->authorize(self::VIEW_PERMISSION);

        return view('customer.crm.tags.index', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'business' => $business,
            'tags' => $this->tags->tagsForBusiness($business, true),
        ]);
    }

    public function store(string $workspaceUid, string $businessUid, Request $request): RedirectResponse
    {
        $business = $this->business($workspaceUid, $businessUid);
        $this->authorize(self::MANAGE_PERMISSION);

        $data = $request->validate(['name' => ['required', 'string', 'max:191']]);

        try {
            $this->tags->createTag($business, $data['name']);
        } catch (CrmRuleException $exception) {
            return back()->withInput()->withErrors(['name' => $exception->getMessage()]);
        }

        return back()->with('status', 'Tag created.');
    }

    public function rename(string $workspaceUid, string $businessUid, string $tagUid, Request $request): RedirectResponse
    {
        $business = $this->business($workspaceUid, $businessUid);
        $this->authorize(self::MANAGE_PERMISSION);
        $tag = $this->tag($business, $tagUid);

        $data = $request->validate(['name' => ['required', 'string', 'max:191']]);

        try {
            $this->tags->renameTag($business, $tag, $data['name']);
        } catch (CrmRuleException $exception) {
            return back()->withInput()->withErrors(['name' => $exception->getMessage()]);
        }

        return back()->with('status', 'Tag renamed.');
    }

    public function archive(string $workspaceUid, string $businessUid, string $tagUid): RedirectResponse
    {
        $business = $this->business($workspaceUid, $businessUid);
        $this->authorize(self::MANAGE_PERMISSION);
        $tag = $this->tag($business, $tagUid);

        $this->tags->archiveTag($business, $tag);

        return back()->with('status', 'Tag archived.');
    }

    /**
     * CORRECTED (independent review, correction round 1). A phone number is
     * never a stable Contact identity — two Contacts in one Business may
     * legitimately share one, and silently choosing `first()` between them
     * could attach the wrong one. `contact_uid` is the same stable,
     * non-numeric identity `detach()` already resolves through, so this
     * carries no new identity scheme.
     */
    public function attach(string $workspaceUid, string $businessUid, string $tagUid, Request $request): RedirectResponse
    {
        $business = $this->business($workspaceUid, $businessUid);
        $this->authorize(self::MANAGE_PERMISSION);
        $tag = $this->tag($business, $tagUid);

        $data = $request->validate(['contact_uid' => ['required', 'string']]);
        $contact = $this->contact($business, $data['contact_uid']);

        try {
            $this->tags->attachTag($business, $contact, $tag);
        } catch (CrmRuleException $exception) {
            return back()->withErrors(['contact_uid' => $exception->getMessage()]);
        }

        return back()->with('status', 'Tag added.');
    }

    public function detach(string $workspaceUid, string $businessUid, string $tagUid, string $contactUid): RedirectResponse
    {
        $business = $this->business($workspaceUid, $businessUid);
        $this->authorize(self::MANAGE_PERMISSION);
        $tag = $this->tag($business, $tagUid);
        $contact = $this->contact($business, $contactUid);

        $this->tags->detachTag($business, $contact, $tag);

        return back()->with('status', 'Tag removed.');
    }

    private function business(string $workspaceUid, string $businessUid): Business
    {
        [, $business] = $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, PlatformFeature::Crm->value);

        return $business;
    }

    private function tag(Business $business, string $uid): Tag
    {
        return $this->tags->tagsForBusiness($business, true)->firstWhere('uid', $uid) ?? abort(404);
    }

    /**
     * Re-derives the Contact's own Location from its persisted row and
     * re-checks `LocationAccessGuard` against it — the exact shape
     * `CrmOpportunitiesController::opportunity()` already uses for a
     * Location-bound CRM row. A null `location_id` is not Location-gated at
     * all; Business-level access alone governs that Contact.
     */
    private function contact(Business $business, string $uid): Contacts
    {
        $contact = Contacts::query()->where('business_id', $business->id)->where('uid', $uid)->first() ?? abort(404);

        $this->assertLocationAccessible($contact);

        return $contact;
    }

    private function assertLocationAccessible(Contacts $contact): void
    {
        if ($contact->location_id === null) {
            return;
        }

        $location = $contact->location;

        if ($location === null || ! app(LocationAccessGuard::class)->userCanAccessLocation((int) Auth::id(), $location)) {
            abort(404);
        }
    }
}

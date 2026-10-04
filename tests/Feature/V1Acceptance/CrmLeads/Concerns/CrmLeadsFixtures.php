<?php

namespace Tests\Feature\V1Acceptance\CrmLeads\Concerns;

use App\Enums\Workspace\LocationAccessScope;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\ContactGroupFields;
use App\Models\Contacts;
use App\Models\ContactsCustomField;
use App\Models\CrmOpportunity;
use App\Models\Customer;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceMembershipLocationRepository;
use Tests\Feature\Crm\Concerns\CreatesCrmFixtures;

/**
 * V1 Final Acceptance 02 (CRM / Leads) — the fixtures every journey in this
 * directory shares. Everything is built through the same seams the existing CRM
 * suites use (CreatesCrmFixtures); nothing here proves a rule the production
 * guards would not agree with.
 */
trait CrmLeadsFixtures
{
    use CreatesCrmFixtures;

    /** What a staff member needs to work the person-first Contacts and the CRM board. */
    protected const STAFF_PERMISSIONS = ['view_contact', 'create_contact', 'update_contact', 'delete_contact', 'view_contact_group'];

    protected function location(Business $business, string $name = 'Downtown'): BusinessLocation
    {
        return BusinessLocation::create([
            'business_id' => $business->id,
            'name' => $name,
            'service_mode' => 'storefront',
            'country_code' => 'US',
        ]);
    }

    /** Staff who reach the Business but hold an explicit grant for ONE Location only. */
    protected function staffAt(Workspace $workspace, BusinessLocation $location): Customer
    {
        $staff = $this->createCustomer();
        $membership = $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);
        $membership->forceFill(['location_access_scope' => LocationAccessScope::Selected])->save();
        app(WorkspaceMembershipLocationRepository::class)->assign($membership, $location);

        return $staff;
    }

    /** A named Contact pinned to one Location (null = the legacy / Business-wide kind). */
    protected function personAt(Business $business, ?BusinessLocation $location, string $first, string $last = 'Tester', ?string $phone = null): Contacts
    {
        $contact = $this->crmContact($business, ['FIRST_NAME' => $first, 'LAST_NAME' => $last], $phone);
        $contact->forceFill(['location_id' => $location?->id])->save();

        return $contact->fresh();
    }

    protected function dealAt(Business $business, ?BusinessLocation $location, Contacts $contact, string $title): CrmOpportunity
    {
        $deal = $this->deal($business, null, $contact, $title);
        $deal->forceFill(['location_id' => $location?->id])->save();

        return $deal->fresh();
    }

    protected function customField(Contacts $contact, string $tag): ?string
    {
        $field = ContactGroupFields::query()->where('contact_group_id', $contact->group_id)->where('tag', $tag)->first();

        return $field === null
            ? null
            : ContactsCustomField::query()->where('contact_id', $contact->id)->where('field_id', $field->id)->value('value');
    }

    protected function peopleUrl(Workspace $workspace, Business $business, ?string $contactUid = null, array $query = []): string
    {
        $url = $contactUid === null
            ? route('customer.workspaces.businesses.people.index', [$workspace->uid, $business->uid])
            : route('customer.workspaces.businesses.people.show', [$workspace->uid, $business->uid, $contactUid]);

        return $query === [] ? $url : $url . '?' . http_build_query($query);
    }
}

<?php

namespace Tests\Feature\BusinessEmail;

use App\Enums\BusinessEmail\BusinessEmailFailureCategory as Category;
use App\Enums\BusinessEmail\BusinessEmailMessageStatus as Status;
use App\Enums\Workspace\LocationAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Models\Business;
use App\Models\BusinessEmailMessage;
use App\Models\BusinessLocation;
use App\Models\Contacts;
use App\Models\Customer;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceMembershipLocationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\BusinessEmail\Concerns\CreatesBusinessEmailFixtures;
use Tests\TestCase;

/**
 * Location ACL on the manual Email surface (Contract 08B semantics, via the
 * canonical LocationAccessGuard): one Business with two Locations and a staff
 * member granted ONLY Location A.
 *
 *  - a Contact with a persisted Location is visible/selectable/sendable only
 *    inside the actor's Location reach;
 *  - a Contact whose Location was never proven (NULL) is never denied on its
 *    own (the CRM precedent);
 *  - recent email history is scoped the same way;
 *  - lists stay bounded and never become N+1.
 */
class BusinessEmailLocationAclTest extends TestCase
{
    use CreatesBusinessEmailFixtures;
    use RefreshDatabase;

    private Customer $owner;

    private Business $business;

    private Workspace $workspace;

    private BusinessLocation $locA;

    private BusinessLocation $locB;

    private Contacts $contactA;

    private Contacts $contactB;

    private Contacts $contactNull;

    private Customer $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->bindFakeEmailProviders();

        [$this->owner, $this->business, $this->workspace] = $this->emailTenant();
        $this->activeAccount($this->business);

        $this->locA = BusinessLocation::query()->where('business_id', $this->business->id)->firstOrFail();
        $this->locB = $this->makeLocation($this->business, 'Location B');

        $this->contactA = $this->contactWithEmails($this->business, ['a@example.com'], $this->locA->id, first: 'Alice');
        $this->contactB = $this->contactWithEmails($this->business, ['b@example.com'], $this->locB->id, first: 'Bob');
        $this->contactNull = $this->contactWithEmails($this->business, ['legacy@example.com'], null, first: 'Legacy');

        $this->staff = $this->createCustomer();
        $this->grant($this->staff, [$this->locA]);
    }

    /** @param list<BusinessLocation> $locations */
    private function grant(Customer $customer, array $locations): void
    {
        $membership = $this->member($this->workspace, $customer->user, WorkspaceMembershipRole::Staff);
        $membership->forceFill(['location_access_scope' => LocationAccessScope::Selected])->save();

        foreach ($locations as $location) {
            app(WorkspaceMembershipLocationRepository::class)->assign($membership, $location);
        }
    }

    private function actAsStaff(): void
    {
        $this->authenticateAs($this->staff, ['chat_box', 'view_contact']);
    }

    private function showUrl(): string
    {
        return route('customer.workspaces.businesses.email.show', [$this->workspace->uid, $this->business->uid]);
    }

    private function sendUrl(): string
    {
        return route('customer.workspaces.businesses.email.send', [$this->workspace->uid, $this->business->uid]);
    }

    /** @return array<string, string> */
    private function form(Contacts $contact, ?BusinessLocation $location = null, string $subject = 'Hello there'): array
    {
        return array_filter([
            'contact_uid' => $contact->uid,
            'location_uid' => $location?->uid,
            'subject' => $subject,
            'body' => 'Body text.',
            'send_token' => (string) Str::uuid(),
        ], fn ($value) => $value !== null);
    }

    private function seedMessage(BusinessLocation $location, Contacts $contact, string $subject): void
    {
        DB::table('business_email_messages')->insert([
            'uid' => (string) Str::uuid(), 'business_id' => $this->business->id, 'location_id' => $location->id,
            'contact_id' => $contact->id, 'operation_key' => 'seed-' . Str::uuid(), 'provider' => 'google',
            'from_email' => 'owner@business.test', 'to_email' => 'x@example.com', 'subject' => $subject,
            'body_text' => 'b', 'status' => 'accepted', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ---- the picker ------------------------------------------------------

    public function test_the_picker_lists_the_permitted_location_contact_and_the_legacy_one_but_not_the_forbidden_one(): void
    {
        $this->actAsStaff();

        $html = $this->get($this->showUrl())->assertOk()->getContent();

        $this->assertStringContainsString('a@example.com', $html);
        $this->assertStringContainsString('legacy@example.com', $html, 'A never-proven (NULL) Location is not denied on its own.');
        $this->assertStringNotContainsString('b@example.com', $html);
        $this->assertStringNotContainsString($this->contactB->uid, $html);
    }

    public function test_the_owner_with_all_locations_sees_every_contact(): void
    {
        $this->authenticateAs($this->owner);

        $html = $this->get($this->showUrl())->assertOk()->getContent();

        foreach (['a@example.com', 'b@example.com', 'legacy@example.com'] as $email) {
            $this->assertStringContainsString($email, $html);
        }
    }

    public function test_a_staff_member_with_no_location_grant_sees_only_never_proven_contacts(): void
    {
        $ungranted = $this->createCustomer();
        $this->grant($ungranted, []);
        $this->authenticateAs($ungranted, ['chat_box', 'view_contact']);

        $html = $this->get($this->showUrl())->assertOk()->getContent();

        $this->assertStringContainsString('legacy@example.com', $html);
        $this->assertStringNotContainsString('a@example.com', $html);
        $this->assertStringNotContainsString('b@example.com', $html);
    }

    public function test_the_location_chooser_offers_only_the_locations_the_actor_may_use(): void
    {
        $this->actAsStaff();

        $html = $this->get($this->showUrl())->assertOk()->getContent();

        $this->assertStringContainsString('name="location_uid"', $html);
        $this->assertStringContainsString($this->locA->uid, $html);
        $this->assertStringNotContainsString($this->locB->uid, $html);
        $this->assertStringNotContainsString('Location B', $html);
    }

    // ---- direct POSTs ----------------------------------------------------

    public function test_a_direct_post_for_a_forbidden_location_contact_is_a_404_with_no_provider_call_and_no_row(): void
    {
        $this->actAsStaff();

        $this->post($this->sendUrl(), $this->form($this->contactB))->assertNotFound();
        $this->post($this->sendUrl(), $this->form($this->contactB, $this->locA))->assertNotFound();

        $this->assertSame(0, $this->fakeGoogle->callCount('send'));
        $this->assertSame(0, $this->fakeGoogle->callCount('exchangeRefreshToken'));
        $this->assertSame(0, BusinessEmailMessage::query()->count());
    }

    public function test_the_forbidden_contact_is_indistinguishable_from_an_unknown_one(): void
    {
        $this->actAsStaff();

        $forbidden = $this->post($this->sendUrl(), $this->form($this->contactB));
        $unknown = $this->post($this->sendUrl(), array_merge($this->form($this->contactB), ['contact_uid' => 'no-such-contact']));

        $this->assertSame($unknown->getStatusCode(), $forbidden->getStatusCode());
        $this->assertSame(404, $forbidden->getStatusCode());
    }

    public function test_a_forbidden_explicit_location_is_a_404_even_for_a_permitted_contact(): void
    {
        $this->actAsStaff();

        $this->post($this->sendUrl(), $this->form($this->contactA, $this->locB))->assertNotFound();
        $this->post($this->sendUrl(), $this->form($this->contactNull, $this->locB))->assertNotFound();

        $this->assertSame(0, $this->fakeGoogle->callCount('send'));
        $this->assertSame(0, BusinessEmailMessage::query()->count());
    }

    public function test_an_archived_or_foreign_explicit_location_is_a_404(): void
    {
        $this->actAsStaff();
        $archived = $this->makeLocation($this->business, 'Closed', archived: true);
        [, $otherBusiness] = $this->emailTenant('Other Business');
        $foreign = BusinessLocation::query()->where('business_id', $otherBusiness->id)->firstOrFail();

        $this->post($this->sendUrl(), $this->form($this->contactNull, $archived))->assertNotFound();
        $this->post($this->sendUrl(), $this->form($this->contactNull, $foreign))->assertNotFound();

        $this->assertSame(0, BusinessEmailMessage::query()->count());
    }

    // ---- permitted sends still work --------------------------------------

    public function test_a_permitted_location_contact_can_be_emailed_and_is_attributed_to_that_location(): void
    {
        $this->actAsStaff();

        $this->post($this->sendUrl(), $this->form($this->contactA))->assertSessionHas('status', 'success');

        $message = BusinessEmailMessage::query()->sole();
        $this->assertSame(Status::Accepted, $message->status);
        $this->assertSame($this->locA->id, (int) $message->location_id);
        $this->assertSame(1, $this->fakeGoogle->callCount('send'));
    }

    public function test_a_legacy_contact_needs_a_location_choice_in_a_multi_location_business(): void
    {
        $this->actAsStaff();

        // No Location named, none provable: refused, never persisted unscoped.
        $this->post($this->sendUrl(), $this->form($this->contactNull))
            ->assertSessionHas('status', 'error')
            ->assertSessionHas('message', Category::LocationRequired->customerMessage());
        $this->assertSame(0, BusinessEmailMessage::query()->count());
        $this->assertSame(0, $this->fakeGoogle->callCount('send'));

        // Naming a permitted Location works.
        $this->post($this->sendUrl(), $this->form($this->contactNull, $this->locA))->assertSessionHas('status', 'success');
        $this->assertSame($this->locA->id, (int) BusinessEmailMessage::query()->sole()->location_id);
    }

    public function test_the_owner_may_send_from_any_location(): void
    {
        $this->authenticateAs($this->owner);

        $this->post($this->sendUrl(), $this->form($this->contactB))->assertSessionHas('status', 'success');

        $this->assertSame($this->locB->id, (int) BusinessEmailMessage::query()->sole()->location_id);
    }

    // ---- recent email history --------------------------------------------

    public function test_recent_email_from_a_forbidden_location_is_not_visible(): void
    {
        $this->seedMessage($this->locA, $this->contactA, 'VISIBLE-SUBJECT-A');
        $this->seedMessage($this->locB, $this->contactB, 'HIDDEN-SUBJECT-B');
        $this->actAsStaff();

        $html = $this->get($this->showUrl())->assertOk()->getContent();

        $this->assertStringContainsString('VISIBLE-SUBJECT-A', $html);
        $this->assertStringNotContainsString('HIDDEN-SUBJECT-B', $html);
    }

    public function test_the_owner_sees_the_history_of_every_location(): void
    {
        $this->seedMessage($this->locA, $this->contactA, 'VISIBLE-SUBJECT-A');
        $this->seedMessage($this->locB, $this->contactB, 'VISIBLE-SUBJECT-B');
        $this->authenticateAs($this->owner);

        $html = $this->get($this->showUrl())->assertOk()->getContent();

        $this->assertStringContainsString('VISIBLE-SUBJECT-A', $html);
        $this->assertStringContainsString('VISIBLE-SUBJECT-B', $html);
    }

    public function test_a_message_sent_by_the_staff_member_is_visible_to_them_afterwards(): void
    {
        $this->actAsStaff();
        $this->post($this->sendUrl(), $this->form($this->contactA, subject: 'SENT-BY-STAFF'));

        $this->assertStringContainsString('SENT-BY-STAFF', $this->get($this->showUrl())->getContent());
    }

    // ---- bounded queries -------------------------------------------------

    public function test_the_scoped_page_stays_bounded_and_its_query_count_does_not_grow_with_contacts(): void
    {
        $this->actAsStaff();

        $measure = function (): array {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $html = $this->get($this->showUrl())->assertOk()->getContent();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return [$queries, substr_count($html, '<option')];
        };

        $measure(); // warm-up: one-time shell lookups on the first request
        [$small, $smallOptions] = $measure();

        for ($i = 0; $i < 60; $i++) {
            $this->contactWithEmails($this->business, ["perm{$i}@example.com"], $this->locA->id);
            $this->contactWithEmails($this->business, ["forbidden{$i}@example.com"], $this->locB->id);
        }

        for ($i = 0; $i < 12; $i++) {
            $this->seedMessage($this->locA, $this->contactA, "m{$i}");
            $this->seedMessage($this->locB, $this->contactB, "hidden{$i}");
        }

        [$big, $bigOptions] = $measure();

        $this->assertSame($small, $big, 'No per-row Location checks: the query count is constant.');
        // 50 contacts + the Location chooser's 2 options (blank + A).
        $this->assertSame(52, $bigOptions);
        $this->assertGreaterThan($smallOptions, $bigOptions);
    }
}

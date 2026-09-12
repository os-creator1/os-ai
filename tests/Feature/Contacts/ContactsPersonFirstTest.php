<?php

namespace Tests\Feature\Contacts;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Models\Business;
use App\Models\Campaigns;
use App\Models\ContactGroupFields;
use App\Models\ContactGroups;
use App\Models\Contacts;
use App\Models\ContactsCustomField;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Contacts is person first: it opens "All contacts" for the selected
 * Business, each contact opens a profile of what is actually stored, and
 * Groups stay fully available as the secondary tab.
 */
class ContactsPersonFirstTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    private int $phoneSequence = 1000;

    public function test_contacts_opens_all_contacts_not_groups(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($owner);

        $links = $this->menuLinks($this->home()->assertOk()->getContent());

        $this->assertContains($this->peopleUrl($workspace, $business), $links);
        $this->assertNotContains(route('customer.workspaces.businesses.contacts.index', [$workspace->uid, $business->uid]), $links);
    }

    public function test_all_contacts_lists_people_with_useful_columns_and_each_opens_its_profile(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $customers = $this->group($business, 'Customers');
        $leads = $this->group($business, 'Leads');
        $ana = $this->person($customers, ['FIRST_NAME' => 'Ana', 'LAST_NAME' => 'Petrauskaite', 'EMAIL' => 'ana@example.test', 'COMPANY' => 'Harbor Bakery']);
        $this->person($leads, ['FIRST_NAME' => 'Ben']);
        $this->authenticateAs($owner);

        $page = $this->get($this->peopleUrl($workspace, $business))->assertOk();

        $page->assertSee('All contacts');
        $page->assertSee('Ana Petrauskaite');
        $page->assertSee('ana@example.test');
        $page->assertSee('Harbor Bakery');
        $page->assertSee('+' . $ana->phone);
        $page->assertSee('Customers');
        $page->assertSee('Leads');
        $page->assertSee('Ben');
        $page->assertSee('href="' . route('customer.workspaces.businesses.people.show', [$workspace->uid, $business->uid, $ana->uid]) . '"', false);
        $this->assertSame(2, substr_count($page->getContent(), 'data-role="contact-row"'));
        $page->assertDontSee('>' . $ana->id . '<', false);
    }

    public function test_groups_remain_reachable_as_the_secondary_tab_with_their_own_action(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($owner);
        $groupsUrl = route('customer.workspaces.businesses.contacts.index', [$workspace->uid, $business->uid]);

        $people = $this->get($this->peopleUrl($workspace, $business))->assertOk();
        $people->assertSee('href="' . $groupsUrl . '"', false);
        $people->assertSee('+ Add contact');
        $people->assertDontSee('New group');

        $groups = $this->get($groupsUrl)->assertOk();
        $groups->assertSee('href="' . $this->peopleUrl($workspace, $business) . '"', false);
        $groups->assertSee('New group');
        $groups->assertDontSee('+ Add contact');
    }

    public function test_the_list_holds_only_the_selected_businesss_contacts(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        $other = $this->addBusiness($owner, $workspace, 'Client Two');
        $this->person($this->group($business, 'One'), ['FIRST_NAME' => 'Visible']);
        $this->person($this->group($other, 'Two'), ['FIRST_NAME' => 'Elsewhere', 'EMAIL' => 'elsewhere@example.test']);
        $this->authenticateAs($owner);

        $page = $this->get($this->peopleUrl($workspace, $business))->assertOk();
        $page->assertSee('Visible');
        $page->assertDontSee('Elsewhere');

        $this->get($this->peopleUrl($workspace, $business) . '?q=elsewhere')->assertOk()->assertDontSee('Elsewhere');
    }

    public function test_search_finds_people_by_name_email_company_or_phone(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $group = $this->group($business, 'Customers');
        $ana = $this->person($group, ['FIRST_NAME' => 'Ana', 'EMAIL' => 'ana@example.test', 'COMPANY' => 'Harbor Bakery']);
        $this->person($group, ['FIRST_NAME' => 'Ben', 'EMAIL' => 'ben@example.test', 'COMPANY' => 'Dock Works']);
        $this->authenticateAs($owner);

        foreach (['ana', 'ana@example', 'bakery', substr((string) $ana->phone, -6)] as $term) {
            $page = $this->get($this->peopleUrl($workspace, $business) . '?q=' . urlencode($term))->assertOk();
            $page->assertSee('Harbor Bakery');
            $page->assertDontSee('Dock Works');
        }

        $this->get($this->peopleUrl($workspace, $business) . '?q=nobody')->assertOk()->assertSee('No contacts match');
    }

    public function test_add_contact_goes_straight_to_the_only_group_and_offers_a_choice_between_several(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $customers = $this->group($business, 'Customers');
        $this->authenticateAs($owner);
        $add = route('customer.workspaces.businesses.people.add', [$workspace->uid, $business->uid]);

        $this->get($add)->assertRedirect(route('customer.workspaces.businesses.contact.create', [$workspace->uid, $business->uid, $customers->uid]));
        $this->get(route('customer.workspaces.businesses.people.import', [$workspace->uid, $business->uid]))
            ->assertRedirect(route('customer.workspaces.businesses.contact.import', [$workspace->uid, $business->uid, $customers->uid]));

        $leads = $this->group($business, 'Leads');
        $choice = $this->get($add)->assertOk();
        $choice->assertSee(route('customer.workspaces.businesses.contact.create', [$workspace->uid, $business->uid, $customers->uid]), false);
        $choice->assertSee(route('customer.workspaces.businesses.contact.create', [$workspace->uid, $business->uid, $leads->uid]), false);
    }

    /**
     * A customer who never used groups is not sent through the Groups
     * screen: one click creates the Business's first list and continues.
     */
    public function test_a_business_without_groups_gets_its_first_list_without_the_groups_screen(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($owner);

        $this->get(route('customer.workspaces.businesses.people.add', [$workspace->uid, $business->uid]))->assertOk()->assertSee('data-role="contacts-first-list"', false);

        $this->post(route('customer.workspaces.businesses.people.first-list', [$workspace->uid, $business->uid]), ['next' => 'add'])
            ->assertRedirect(route('customer.workspaces.businesses.people.add', [$workspace->uid, $business->uid]));

        $group = ContactGroups::query()->where('business_id', $business->id)->sole();
        $this->assertSame('Contacts', $group->name);
        $this->assertSame((int) $business->customer_id, (int) $group->customer_id);

        // Repeating it never makes a second list.
        $this->post(route('customer.workspaces.businesses.people.first-list', [$workspace->uid, $business->uid]));
        $this->assertSame(1, ContactGroups::query()->where('business_id', $business->id)->count());
    }

    public function test_the_profile_shows_only_what_is_stored(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $group = $this->group($business, 'Customers');
        $ana = $this->person($group, ['FIRST_NAME' => 'Ana', 'LAST_NAME' => 'Petrauskaite', 'EMAIL' => 'ana@example.test', 'COMPANY' => 'Harbor Bakery', 'ADDRESS' => 'Gedimino pr. 1, Vilnius']);
        $this->authenticateAs($owner);

        $page = $this->profile($workspace, $business, $ana);

        $this->assertSame('Ana Petrauskaite', trim(strip_tags($this->between($page->getContent(), 'data-role="contact-name">', '</h3>'))));
        $page->assertSee('ana@example.test');
        $page->assertSee('Harbor Bakery');
        $page->assertSee('Gedimino pr. 1, Vilnius');
        $page->assertSee('href="' . route('customer.workspaces.businesses.contacts.show', [$workspace->uid, $business->uid, $group->uid]) . '"', false);
        $page->assertSee('No messages with this contact yet.');

        $body = $this->between($page->getContent(), '<section id="contact-profile">', '</section>');
        foreach (['Source', 'Submitted via', 'Form', 'Notes', 'Tags', 'Lead score'] as $notStored) {
            $this->assertStringNotContainsString($notStored, $body, "{$notStored} is not stored for contacts, so it is not shown.");
        }
    }

    /**
     * Activity comes from this Business only: its campaign messages to the
     * contact and its conversation with the same number.
     */
    public function test_the_profile_shows_this_businesss_campaign_messages_and_conversation_only(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        $other = $this->addBusiness($owner, $workspace, 'Client Two');
        $ana = $this->person($this->group($business, 'Customers'), ['FIRST_NAME' => 'Ana']);
        $lookalike = $this->person($this->group($other, 'Theirs'), ['FIRST_NAME' => 'Ana elsewhere'], (string) $ana->phone);

        $this->trackingLog($business, $this->campaign($business, 'Spring offer'), $ana);
        $this->trackingLog($other, $this->campaign($other, 'Other business promo'), $lookalike);
        // Even a log row naming this contact but filed under the other
        // Business (inconsistent data) stays out of this Business's profile.
        $this->trackingLog($other, $this->campaign($other, 'Misfiled promo'), $ana);
        $this->conversation($business, (string) $ana->phone, 'Is the bakery open Sunday?');
        // The other Business's conversation with the same number is newer —
        // it must still never be picked.
        $this->conversation($other, (string) $ana->phone, 'A message the other Business received', now()->addMinutes(5));
        $this->authenticateAs($owner);

        $page = $this->profile($workspace, $business, $ana);

        $page->assertSee('Spring offer');
        $page->assertDontSee('Other business promo');
        $page->assertDontSee('Misfiled promo');
        $page->assertSee('Is the bakery open Sunday?');
        $page->assertDontSee('A message the other Business received');
        $page->assertSee('Open Inbox');
    }

    public function test_the_conversation_is_hidden_from_someone_the_inbox_would_not_show_it_to(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $ana = $this->person($this->group($business, 'Customers'), ['FIRST_NAME' => 'Ana']);
        $this->conversation($business, (string) $ana->phone, 'Private inbox message');
        $this->authenticateAs($owner, array_values(array_diff($this->allCustomerPermissions(), ['chat_box'])));

        $this->profile($workspace, $business, $ana)->assertDontSee('Private inbox message');
    }

    public function test_a_contact_of_another_business_is_not_found_exactly_like_an_unknown_one(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        $other = $this->addBusiness($owner, $workspace, 'Client Two');
        $foreign = $this->person($this->group($other, 'Theirs'), ['FIRST_NAME' => 'Foreign']);
        $this->authenticateAs($owner);

        $foreignResponse = $this->get(route('customer.workspaces.businesses.people.show', [$workspace->uid, $business->uid, $foreign->uid]))->assertNotFound();
        $unknownResponse = $this->get(route('customer.workspaces.businesses.people.show', [$workspace->uid, $business->uid, 'no-such-contact']))->assertNotFound();

        $this->assertSame($unknownResponse->getContent(), $foreignResponse->getContent());
        $foreignResponse->assertDontSee('Foreign');
    }

    public function test_someone_without_access_to_the_business_is_not_found(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $ana = $this->person($this->group($business, 'Customers'), ['FIRST_NAME' => 'Ana']);
        [$stranger] = $this->tenant(WorkspacePlanTier::Growth, 'Elsewhere', 'Elsewhere Account');
        $this->authenticateAs($stranger);

        foreach ([
            $this->peopleUrl($workspace, $business),
            route('customer.workspaces.businesses.people.show', [$workspace->uid, $business->uid, $ana->uid]),
            route('customer.workspaces.businesses.people.add', [$workspace->uid, $business->uid]),
        ] as $url) {
            $this->get($url)->assertNotFound();
        }

        $this->post(route('customer.workspaces.businesses.people.first-list', [$workspace->uid, $business->uid]))->assertNotFound();
        $this->assertSame(['Customers'], ContactGroups::query()->where('business_id', $business->id)->pluck('name')->all());
    }

    public function test_groups_keep_working_and_campaign_links_to_groups_are_untouched(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $group = $this->group($business, 'Customers');
        $this->person($group, ['FIRST_NAME' => 'Ana']);
        $campaign = $this->campaign($business, 'Spring offer');
        DB::table('campaigns_lists')->insert(['campaign_id' => $campaign->id, 'contact_list_id' => $group->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->authenticateAs($owner);

        $this->post(route('customer.workspaces.businesses.contacts.store', [$workspace->uid, $business->uid]), ['name' => 'Newsletter'])->assertRedirect();
        $this->assertTrue(ContactGroups::query()->where('business_id', $business->id)->where('name', 'Newsletter')->exists());

        $this->get(route('customer.workspaces.businesses.contacts.show', [$workspace->uid, $business->uid, $group->uid]))->assertOk();
        $this->get($this->peopleUrl($workspace, $business))->assertOk()->assertSee('Customers');

        $this->assertTrue(DB::table('campaigns_lists')->where('campaign_id', $campaign->id)->where('contact_list_id', $group->id)->exists());
    }

    /**
     * The list and the profile cost a fixed number of queries, however many
     * contacts, fields or messages there are.
     */
    public function test_no_per_row_queries_on_the_list_or_the_profile(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $group = $this->group($business, 'Customers');
        $first = $this->person($group, ['FIRST_NAME' => 'First', 'EMAIL' => 'first@example.test']);
        $campaign = $this->campaign($business, 'Spring offer');
        $this->trackingLog($business, $campaign, $first);
        $this->authenticateAs($owner);

        // Warm-up: the first request of a test primes shared caches.
        $this->get($this->peopleUrl($workspace, $business))->assertOk();
        $this->profile($workspace, $business, $first);

        $few = $this->queriesFor(fn () => $this->get($this->peopleUrl($workspace, $business))->assertOk());

        for ($i = 0; $i < 12; $i++) {
            $person = $this->person($group, ['FIRST_NAME' => "Person {$i}", 'EMAIL' => "person{$i}@example.test", 'COMPANY' => "Company {$i}"]);
            $this->trackingLog($business, $campaign, $person);
            $this->conversation($business, (string) $person->phone, "Hello {$i}");
        }

        $many = $this->queriesFor(fn () => $this->get($this->peopleUrl($workspace, $business))->assertOk());

        $this->assertSame($few, $many, 'The All contacts list must not query per contact.');

        $this->conversation($business, (string) $first->phone, 'One');
        $profileFew = $this->queriesFor(fn () => $this->profile($workspace, $business, $first));

        foreach (['Second', 'Third', 'Fourth'] as $name) {
            $this->trackingLog($business, $this->campaign($business, $name), $first);
        }
        $box = DB::table('chat_boxes')->where('business_id', $business->id)->where('to', (string) $first->phone)->value('id');
        foreach (['Two', 'Three', 'Four'] as $text) {
            DB::table('chat_box_messages')->insert(['box_id' => $box, 'message' => $text, 'send_by' => 'from', 'direction' => 'outgoing', 'created_at' => now(), 'updated_at' => now()]);
        }
        $profileMany = $this->queriesFor(fn () => $this->profile($workspace, $business, $first));

        $this->assertSame($profileFew, $profileMany, 'The profile must not query per message or campaign.');
    }

    /**
     * The product blocker: Business OS Contacts do not depend on the legacy
     * Ultimate SMS subscription. Contacts are unlimited on Core, Growth and
     * Agency alike, so a customer who has never held an SMS subscription
     * adds their second contact exactly as they added their first — which
     * is where the inherited subscriber_per_list_max / subscriber_max check
     * used to stop them, reading null options off a subscription that does
     * not exist.
     */
    public function test_add_contact_needs_no_legacy_sms_subscription_on_any_plan(): void
    {
        foreach ([WorkspacePlanTier::Core, WorkspacePlanTier::Growth, WorkspacePlanTier::Agency] as $tier) {
            [$owner, $business, $workspace] = $this->tenant($tier, 'Tier ' . $tier->value, 'Account ' . $tier->value);
            $group = $this->group($business, 'Customers');
            // The contact that used to exhaust the phantom quota.
            $this->person($group, ['FIRST_NAME' => 'Ana']);
            $this->authenticateAs($owner);

            $this->assertNull($owner->activeSubscription(), 'Fixture precondition: no legacy SMS subscription.');

            $create = route('customer.workspaces.businesses.contact.create', [$workspace->uid, $business->uid, $group->uid]);
            $this->get(route('customer.workspaces.businesses.people.add', [$workspace->uid, $business->uid]))->assertRedirect($create);
            $this->get($create)->assertOk();

            $phone = '1202555' . str_pad((string) (++$this->phoneSequence), 4, '0', STR_PAD_LEFT);
            $this->post(route('customer.workspaces.businesses.contact.store', [$workspace->uid, $business->uid, $group->uid]), ['PHONE' => $phone])
                ->assertRedirect(route('customer.workspaces.businesses.contacts.show', [$workspace->uid, $business->uid, $group->uid]));

            $this->assertDatabaseHas('contacts', ['group_id' => $group->id, 'business_id' => $business->id, 'phone' => $phone]);
        }
    }

    /**
     * Import is the same boundary: reaching the per-group import screen asks
     * nothing of the legacy subscription.
     */
    public function test_import_is_not_blocked_by_the_legacy_sms_contact_quota(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $group = $this->group($business, 'Customers');
        $this->person($group, ['FIRST_NAME' => 'Ana']);
        $this->authenticateAs($owner);

        $import = route('customer.workspaces.businesses.contact.import', [$workspace->uid, $business->uid, $group->uid]);
        $this->get(route('customer.workspaces.businesses.people.import', [$workspace->uid, $business->uid]))->assertRedirect($import);
        $this->get($import)->assertOk();
    }

    /**
     * Groups are part of Contacts: creating one no longer sends a customer
     * without an SMS subscription to the legacy subscriptions page.
     */
    public function test_a_new_group_needs_no_legacy_sms_subscription(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->group($business, 'Customers');
        $this->authenticateAs($owner);

        $this->get(route('customer.workspaces.businesses.contacts.create', [$workspace->uid, $business->uid]))
            ->assertOk()
            ->assertDontSee(route('customer.subscriptions.index'), false);
    }

    /**
     * And the old rules are not merely unreachable — they are not consulted.
     * This customer holds an active SMS subscription whose plan sets every
     * contact quota to 1 and has already used it up; Contacts carry on,
     * because that plan prices mailing lists, not the CRM.
     */
    public function test_an_exhausted_legacy_sms_contact_quota_does_not_govern_contacts(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $group = $this->group($business, 'Customers');
        $this->person($group, ['FIRST_NAME' => 'Ana']);
        $this->person($group, ['FIRST_NAME' => 'Bruno']);
        $this->giveActiveSmsSubscriptionWithExhaustedContactQuota($owner);
        $this->authenticateAs($owner);

        // Precondition: the legacy plan really does say one of everything.
        $this->assertSame('1', $owner->fresh()->getOption('subscriber_max'));
        $this->assertSame('1', $owner->fresh()->getOption('list_max'));

        $phone = '1202555' . str_pad((string) (++$this->phoneSequence), 4, '0', STR_PAD_LEFT);
        $this->get(route('customer.workspaces.businesses.contact.create', [$workspace->uid, $business->uid, $group->uid]))->assertOk();
        $this->post(route('customer.workspaces.businesses.contact.store', [$workspace->uid, $business->uid, $group->uid]), ['PHONE' => $phone]);
        $this->assertDatabaseHas('contacts', ['group_id' => $group->id, 'phone' => $phone]);

        $this->get(route('customer.workspaces.businesses.contacts.create', [$workspace->uid, $business->uid]))->assertOk();

        $this->post(route('customer.workspaces.businesses.contacts.copy', [$workspace->uid, $business->uid, $group->uid]), ['group_name' => 'Customers copy'])
            ->assertOk()
            ->assertJsonPath('status', 'success');
        $this->assertSame(2, ContactGroups::query()->where('business_id', $business->id)->count());
    }

    /**
     * The legacy quota model itself is untouched — this change removed the
     * Contacts paths that consulted it, not the SMS plan options. Messaging
     * and usage metering read those same options and keep their own limits.
     */
    public function test_the_legacy_sms_plan_quota_model_is_left_intact(): void
    {
        [$owner] = $this->tenant(WorkspacePlanTier::Growth);
        $this->giveActiveSmsSubscriptionWithExhaustedContactQuota($owner);
        $customer = $owner->fresh();

        $this->assertSame('1', $customer->getOption('subscriber_per_list_max'));
        $this->assertSame('1', $customer->maxSubscribers());
        $this->assertSame('1', $customer->maxLists());

        // Sending limits are a different domain and still come through.
        $this->assertSame('1000', $customer->getOption('sending_quota'));
        $this->assertSame('1000_per_hour', $customer->getOption('sending_limit'));
        $this->assertArrayHasKey('subscriber_max', Plan::defaultOptions());
    }

    // -----------------------------------------------------------------

    private function peopleUrl(Workspace $workspace, Business $business): string
    {
        return route('customer.workspaces.businesses.people.index', [$workspace->uid, $business->uid]);
    }

    private function profile(Workspace $workspace, Business $business, Contacts $contact): TestResponse
    {
        return $this->get(route('customer.workspaces.businesses.people.show', [$workspace->uid, $business->uid, $contact->uid]))->assertOk();
    }

    /**
     * An active legacy Ultimate SMS subscription whose plan allows exactly
     * one contact group, one contact per group and one contact in total —
     * the quota the Contacts paths used to enforce, already used up by the
     * fixtures that call this.
     */
    private function giveActiveSmsSubscriptionWithExhaustedContactQuota(Customer $customer): void
    {
        $currency = Currency::query()->where('code', 'CPF')->first()
            ?? Currency::create(['name' => 'Contacts Test Dollar', 'code' => 'CPF', 'format' => '$', 'status' => true]);

        $plan = Plan::create([
            'user_id' => $customer->user->id,
            'name' => 'Legacy SMS Plan',
            'price' => 10,
            'billing_cycle' => 'monthly',
            'frequency_amount' => 1,
            'frequency_unit' => 'month',
            'currency_id' => $currency->id,
            'options' => json_encode(['list_max' => '1', 'subscriber_max' => '1', 'subscriber_per_list_max' => '1']),
            'status' => true,
        ]);

        Subscription::create([
            'user_id' => $customer->user->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'paid' => true,
            'start_at' => now(),
            'end_at' => null,
        ]);
    }

    private function group(Business $business, string $name): ContactGroups
    {
        return ContactGroups::create([
            'customer_id' => $business->customer_id,
            'business_id' => $business->id,
            'name' => $name,
            'status' => true,
        ]);
    }

    /**
     * @param  array<string, string>  $values  custom-field tag => value
     */
    private function person(ContactGroups $group, array $values, ?string $phone = null): Contacts
    {
        $contact = Contacts::create([
            'customer_id' => $group->customer_id,
            'business_id' => $group->business_id,
            'group_id' => $group->id,
            'phone' => $phone ?? '1202555' . str_pad((string) (++$this->phoneSequence), 4, '0', STR_PAD_LEFT),
            'status' => Contacts::STATUS_SUBSCRIBE,
        ]);

        foreach ($values as $tag => $value) {
            // A new group starts with phone, first and last name; any other
            // field is one the customer added to the group.
            $field = ContactGroupFields::query()->firstOrCreate(
                ['contact_group_id' => $group->id, 'tag' => $tag],
                ['label' => ucwords(strtolower(str_replace('_', ' ', $tag))), 'type' => 'text', 'visible' => true, 'required' => false],
            );
            ContactsCustomField::create(['contact_id' => $contact->id, 'field_id' => $field->id, 'value' => $value]);
        }

        return $contact->fresh();
    }

    private function campaign(Business $business, string $name): Campaigns
    {
        return Campaigns::create([
            'user_id' => $business->customer_id,
            'business_id' => $business->id,
            'campaign_name' => $name,
            'message' => 'Hello',
            'sms_type' => 'plain',
            'status' => Campaigns::STATUS_DONE,
        ]);
    }

    private function trackingLog(Business $business, Campaigns $campaign, Contacts $contact): void
    {
        DB::table('tracking_logs')->insert([
            'uid' => uniqid('', true),
            'customer_id' => $business->customer_id,
            'business_id' => $business->id,
            'sending_server_id' => null,
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'contact_group_id' => $contact->group_id,
            'status' => 'Delivered',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function conversation(Business $business, string $phone, string $message, ?\Illuminate\Support\Carbon $updatedAt = null): void
    {
        $boxId = DB::table('chat_boxes')->insertGetId([
            'uid' => (string) Str::uuid(),
            'user_id' => $business->customer_id,
            'business_id' => $business->id,
            'from' => '18005550100',
            'to' => $phone,
            'notification' => 0,
            'created_at' => now(),
            'updated_at' => $updatedAt ?? now(),
        ]);

        DB::table('chat_box_messages')->insert([
            'box_id' => $boxId,
            'message' => $message,
            'send_by' => 'to',
            'direction' => 'incoming',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function queriesFor(callable $request): int
    {
        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });

        $request();

        return $count;
    }

    private function between(string $haystack, string $start, string $end): string
    {
        $from = strpos($haystack, $start);
        $this->assertNotFalse($from, "Missing [{$start}].");
        $from += strlen($start);
        $to = strpos($haystack, $end, $from);

        return substr($haystack, $from, $to - $from);
    }
}

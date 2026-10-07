<?php

namespace Tests\Feature\Security;

use App\Enums\Forms\FormContactResolution;
use App\Enums\Messaging\InboundWebhookEventKind;
use App\Library\Contacts\ContactPhone;
use App\Library\Messaging\DTO\InboundWebhookEvent;
use App\Library\Messaging\LegacyWebhookRouteRegistry;
use App\Models\Blacklists;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\ContactGroups;
use App\Models\Contacts;
use App\Models\Customer;
use App\Models\SendingServer;
use App\Models\User;
use App\Repositories\Contracts\BusinessLocationRepository;
use App\Repositories\Eloquent\EloquentContactsRepository;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\TestCase;

/**
 * V1 release-risk closure — items 1, 4, 5, 6 and 7 (items 2 and 3 live in
 * PlatformBilling\V1ReleaseRiskOnboardingTest).
 *
 * Every test names the defect it proves closed, adversarially: the actor is
 * always an unauthenticated caller or a tenant reaching for more than it owns.
 */
class V1ReleaseRiskClosureTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAutomationFixtures;
    use CreatesMessagingFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureRequiredAppConfigRowsExist();
        $this->platformAdminId();
        config(['no-captcha.registration' => false]);
    }

    // =====================================================================
    // 1. Managed inbound STOP
    // =====================================================================

    /** @return array{0: Business, 1: \App\Models\BusinessMessagingIdentity, 2: \App\Models\BusinessMessagingNumber} */
    private function managedBusinessWithNumber(): array
    {
        $this->bindFakeAdapter();
        [, $business] = $this->entitledTenant();
        $identity = $this->attachIdentity($business);
        $number = $this->attachNumber($identity, $this->uniqueNumber());

        return [$business, $identity, $number];
    }

    private function contactOf(Business $business, string $phone, string $status = Contacts::STATUS_SUBSCRIBE): Contacts
    {
        $group = ContactGroups::query()->where('business_id', $business->id)->first()
            ?? ContactGroups::create([
                'customer_id' => $business->customer_id,
                'business_id' => $business->id,
                'name' => 'People',
                'status' => true,
            ]);

        return Contacts::create([
            'customer_id' => $business->customer_id,
            'business_id' => $business->id,
            'group_id' => $group->id,
            'phone' => $phone,
            'status' => $status,
        ]);
    }

    private function managedInbound($identity, $number, string $from, string $messageId, string $body): void
    {
        $this->fakeAdapter->queueInboundWebhook(new InboundWebhookEvent(
            kind: InboundWebhookEventKind::MessageReceived,
            messagingProfileId: $identity->messaging_profile_id,
            destinationNumber: $number->phone_number,
            fromNumber: $from,
            body: $body,
            mediaUrls: [],
            providerMessageId: $messageId,
            deliveryStatus: null,
            occurredAt: CarbonImmutable::now(),
        ));

        $this->postJson(route('inbound.telnyx_managed'), ['probe' => true])->assertOk();
    }

    public function test_a_managed_stop_unsubscribes_the_contact_blacklists_the_number_and_keeps_the_conversation(): void
    {
        [$business, $identity, $number] = $this->managedBusinessWithNumber();
        $contact = $this->contactOf($business, '14155559101');

        $this->managedInbound($identity, $number, '+14155559101', 'pm_stop_1', 'STOP');

        $this->assertSame(Contacts::STATUS_UNSUBSCRIBE, $contact->fresh()->status);

        $row = Blacklists::query()->where('business_id', $business->id)->sole();
        $this->assertSame('14155559101', $row->number);
        $this->assertSame((int) $business->customer_id, (int) $row->user_id);

        // The inbound message and its conversation are retained.
        $box = DB::table('chat_boxes')->where('business_id', $business->id)->sole();
        $this->assertSame('STOP', DB::table('chat_box_messages')->where('box_id', $box->id)->value('message'));
    }

    public function test_a_duplicate_stop_is_idempotent(): void
    {
        [$business, $identity, $number] = $this->managedBusinessWithNumber();
        $contact = $this->contactOf($business, '14155559102');

        $this->managedInbound($identity, $number, '+14155559102', 'pm_stop_a', 'stop');
        $this->managedInbound($identity, $number, '+14155559102', 'pm_stop_b', ' Stop. ');
        $this->managedInbound($identity, $number, '+14155559102', 'pm_stop_b', 'STOP'); // provider redelivery

        $this->assertSame(Contacts::STATUS_UNSUBSCRIBE, $contact->fresh()->status);
        $this->assertSame(1, Blacklists::query()->where('business_id', $business->id)->count());
    }

    public function test_a_stop_acts_only_inside_the_receiving_business(): void
    {
        [$business, $identity, $number] = $this->managedBusinessWithNumber();
        [, $other] = $this->entitledTenant();

        $mine = $this->contactOf($business, '14155559103');
        $theirs = $this->contactOf($other, '14155559103');

        $this->managedInbound($identity, $number, '+14155559103', 'pm_stop_scope', 'STOP');

        $this->assertSame(Contacts::STATUS_UNSUBSCRIBE, $mine->fresh()->status);
        $this->assertSame(Contacts::STATUS_SUBSCRIBE, $theirs->fresh()->status, 'Another Business keeps its own consent record.');
        $this->assertSame(0, Blacklists::query()->where('business_id', $other->id)->count());
    }

    public function test_a_stop_matches_a_contact_stored_in_national_form(): void
    {
        [$business, $identity, $number] = $this->managedBusinessWithNumber();
        $historical = $this->contactOf($business, '4155559104'); // written before canonicalization

        $this->managedInbound($identity, $number, '+14155559104', 'pm_stop_nat', 'UNSUBSCRIBE');

        $this->assertSame(Contacts::STATUS_UNSUBSCRIBE, $historical->fresh()->status);
    }

    public function test_ordinary_replies_and_start_never_change_consent(): void
    {
        [$business, $identity, $number] = $this->managedBusinessWithNumber();
        $subscribed = $this->contactOf($business, '14155559105');
        $optedOut = $this->contactOf($business, '14155559106', Contacts::STATUS_UNSUBSCRIBE);

        $this->managedInbound($identity, $number, '+14155559105', 'pm_chat_1', 'please stop by at 3');
        $this->managedInbound($identity, $number, '+14155559105', 'pm_chat_2', 'Cancel my appointment, thanks');
        $this->managedInbound($identity, $number, '+14155559106', 'pm_start', 'START');

        $this->assertSame(Contacts::STATUS_SUBSCRIBE, $subscribed->fresh()->status);
        $this->assertSame(Contacts::STATUS_UNSUBSCRIBE, $optedOut->fresh()->status, 'Re-consent stays explicit; START is not invented.');
        $this->assertSame(0, Blacklists::query()->count());
    }

    public function test_a_stop_from_an_unknown_sender_still_blocks_future_sends_to_that_number(): void
    {
        [$business, $identity, $number] = $this->managedBusinessWithNumber();

        $this->managedInbound($identity, $number, '+14155559107', 'pm_stop_nobody', 'STOP');

        $this->assertTrue(Blacklists::query()->where('business_id', $business->id)->where('number', '14155559107')->exists());
        $this->assertSame(0, Contacts::query()->count());
    }

    // =====================================================================
    // 4. Legacy inbound / DLR routes
    // =====================================================================

    /** @return list<\Illuminate\Routing\Route> */
    private function legacyFamilyRoutes(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => LegacyWebhookRouteRegistry::isLegacyFamilyUri($route->uri()))
            ->values()
            ->all();
    }

    public function test_every_unsupported_legacy_gateway_route_is_refused_before_it_can_touch_any_state(): void
    {
        $routes = $this->legacyFamilyRoutes();
        $this->assertGreaterThan(60, count($routes), 'The inventory must actually cover the legacy family.');

        [$business] = $this->managedBusinessWithNumber();
        $before = [
            'reports' => DB::table('reports')->count(),
            'chat_boxes' => DB::table('chat_boxes')->count(),
            'contacts' => DB::table('contacts')->count(),
            'blacklists' => DB::table('blacklists')->count(),
        ];

        $refused = 0;
        $kept = [];

        foreach ($routes as $route) {
            $name = (string) $route->getName();

            if (LegacyWebhookRouteRegistry::isSupported($name)) {
                $kept[] = $name;
                continue;
            }

            $uri = preg_replace('#/\{[^}/]+\??\}#', '/x', '/' . $route->uri());
            $response = $this->call('POST', $uri, [
                'From' => '+14155559999', 'To' => '+14155550000', 'Body' => 'STOP',
                'MessageSid' => 'SMforged', 'MessageStatus' => 'delivered',
            ]);

            $this->assertSame(410, $response->getStatusCode(), "Route [{$name}] {$uri} must be disabled.");
            $refused++;
        }

        $this->assertGreaterThan(55, $refused);
        $this->assertEqualsCanonicalizing(array_keys(LegacyWebhookRouteRegistry::SUPPORTED), $kept, 'Every supported route exists, and nothing else is supported.');
        $this->assertSame($before, [
            'reports' => DB::table('reports')->count(),
            'chat_boxes' => DB::table('chat_boxes')->count(),
            'contacts' => DB::table('contacts')->count(),
            'blacklists' => DB::table('blacklists')->count(),
        ]);
    }

    public function test_an_unsigned_twilio_dlr_changes_no_report_and_a_signed_one_is_accepted(): void
    {
        [$business] = $this->managedBusinessWithNumber();
        SendingServer::create([
            'name' => 'Twilio ' . uniqid(), 'user_id' => $business->customer_id,
            'settings' => SendingServer::TYPE_TWILIO, 'status' => true, 'two_way' => true, 'plain' => true,
            'account_sid' => 'ACtest', 'auth_token' => 'authtest',
        ]);

        $params = ['MessageSid' => 'SM_dlr_probe_1', 'MessageStatus' => 'delivered'];
        $reportsBefore = DB::table('reports')->count();

        $this->call('POST', route('dlr.twilio'), $params)->assertStatus(403);
        $this->call('POST', route('dlr.twilio'), $params, [], [], ['HTTP_X_TWILIO_SIGNATURE' => 'forged'])->assertStatus(403);

        $signature = (new \Twilio\Security\RequestValidator('authtest'))->computeSignature(route('dlr.twilio'), $params);
        $this->assertNotSame(403, $this->call('POST', route('dlr.twilio'), $params, [], [], ['HTTP_X_TWILIO_SIGNATURE' => $signature])->getStatusCode());
        $this->assertSame($reportsBefore, DB::table('reports')->count());
    }

    public function test_an_unsigned_inbound_webhook_never_forwards_to_the_owner_url(): void
    {
        Http::fake();
        [$business] = $this->managedBusinessWithNumber();
        $owner = User::query()->findOrFail($business->customer_id);
        $owner->forceFill(['webhook_url' => 'https://hooks.example.com/sms'])->save();
        SendingServer::create([
            'name' => 'Twilio ' . uniqid(), 'user_id' => $owner->id,
            'settings' => SendingServer::TYPE_TWILIO, 'status' => true, 'two_way' => true, 'plain' => true,
            'account_sid' => 'ACtest', 'auth_token' => 'authtest',
        ]);

        $this->call('POST', route('inbound.webhook', $owner->uid), ['From' => '+14155559998', 'To' => '+14155550000', 'Body' => 'hi']);

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('chat_boxes')->count());
    }

    // =====================================================================
    // 5. Sub-account privilege ceiling
    // =====================================================================

    private function parentCustomer(array $held): Customer
    {
        Mail::fake();
        $customer = $this->createCustomer();
        $customer->user->forceFill(['email_verified_at' => now()])->save();
        $customer->forceFill(['permissions' => json_encode($held)])->save();
        $this->withSession(['permissions' => collect($held)]);
        $this->actingAs($customer->user);

        return $customer;
    }

    private function subAccountForm(array $permissions, array $extra = []): array
    {
        return array_merge([
            'first_name' => 'Sam',
            'last_name' => 'Sub',
            'email' => 'sub' . uniqid() . '@example.test',
            'permissions' => $permissions,
        ], $extra);
    }

    public function test_a_parent_cannot_grant_a_permission_it_does_not_hold(): void
    {
        $this->parentCustomer(['access_backend', 'view_reports']);
        $users = User::query()->count();

        foreach (['automations', 'website', 'is_admin', 'superadmin', 'access_backend_admin', '*'] as $forged) {
            $this->post(route('customer.sub_accounts.store'), $this->subAccountForm([
                'access_backend' => 'access_backend', 'x' => $forged,
            ]))->assertSessionHasErrors('permissions');
        }

        $this->assertSame($users, User::query()->count(), 'A refused request creates no account.');
    }

    public function test_a_parent_can_still_grant_permissions_within_its_own_set(): void
    {
        $parent = $this->parentCustomer(['access_backend', 'view_reports', 'automations']);

        $this->post(route('customer.sub_accounts.store'), $this->subAccountForm([
            'access_backend' => 'access_backend', 0 => 'view_reports',
        ]))->assertSessionHasNoErrors()->assertRedirect(route('customer.sub_accounts.index'));

        $sub = User::query()->where('parent_id', $parent->user_id)->sole();
        $this->assertFalse((bool) $sub->is_admin);
        $this->assertEqualsCanonicalizing(['access_backend', 'view_reports'], json_decode($sub->customer->permissions, true));
    }

    public function test_an_update_cannot_raise_a_sub_account_above_its_parent(): void
    {
        $parent = $this->parentCustomer(['access_backend', 'view_reports']);
        $this->post(route('customer.sub_accounts.store'), $this->subAccountForm(['access_backend' => 'access_backend']));
        $sub = User::query()->where('parent_id', $parent->user_id)->sole();

        $this->post(route('customer.sub_accounts.update', $sub->uid), [
            'first_name' => 'Sam', 'email' => $sub->email,
            'permissions' => ['access_backend' => 'access_backend', 0 => 'automations'],
        ])->assertSessionHasErrors('permissions');

        $this->assertSame(['access_backend'], json_decode($sub->fresh()->customer->permissions, true));
    }

    public function test_a_parent_cannot_reach_another_tenants_sub_account(): void
    {
        $this->parentCustomer(['access_backend', 'view_reports']);
        $strangerParent = $this->createCustomer();
        $stranger = new User(['first_name' => 'Str', 'last_name' => 'Anger', 'email' => 'str' . uniqid() . '@example.test']);
        $stranger->forceFill(['parent_id' => $strangerParent->user_id, 'status' => true, 'is_admin' => false, 'is_customer' => true])->save();
        Customer::create(['user_id' => $stranger->id, 'permissions' => json_encode(['access_backend'])]);

        $this->post(route('customer.sub_accounts.update', $stranger->uid), [
            'first_name' => 'Pwn', 'email' => $stranger->email,
            'permissions' => ['access_backend' => 'access_backend', 0 => 'view_reports'],
        ])->assertStatus(404);

        $this->assertSame(['access_backend'], json_decode($stranger->fresh()->customer->permissions, true));
    }

    // =====================================================================
    // 6. Public subscribe / unsubscribe throttling
    // =====================================================================

    /**
     * A refused request is a 429, which this application's handler renders as
     * its 404 page for every HttpException outside local (unchanged,
     * pre-existing behaviour) — the proof that matters is that the form
     * controller's own redirect (302) is no longer reached.
     */
    private function anyThrottled(array $statuses): bool
    {
        return count(array_diff($statuses, [302])) > 0 && in_array(true, array_map(fn ($s) => in_array($s, [404, 429], true), $statuses), true);
    }

    public function test_public_unsubscribe_is_throttled_per_group_and_still_unsubscribes_inside_its_own_group(): void
    {
        [, $business] = $this->entitledTenant();
        [, $otherBusiness] = $this->entitledTenant();
        $contact = $this->contactOf($business, '14155559201');
        $foreign = $this->contactOf($otherBusiness, '14155559202');
        $group = ContactGroups::query()->findOrFail($contact->group_id);
        $otherGroup = ContactGroups::query()->findOrFail($foreign->group_id);

        // Legitimate use: the first request works, and the person's own formatting resolves.
        $this->post(route('contacts.unsubscribe_url.store', $group->uid), ['phone' => '+1 (415) 555-9201'])->assertRedirect();
        $this->assertSame(Contacts::STATUS_UNSUBSCRIBE, $contact->fresh()->status);

        // Business A's group URL can never reach Business B's Contact.
        $this->post(route('contacts.unsubscribe_url.store', $group->uid), ['phone' => '14155559202'])->assertRedirect();
        $this->assertSame(Contacts::STATUS_SUBSCRIBE, $foreign->fresh()->status);

        // Abuse: past the per-visitor bound the endpoint refuses.
        $statuses = [];
        for ($i = 0; $i < 12; $i++) {
            $statuses[] = $this->post(route('contacts.unsubscribe_url.store', $group->uid), ['phone' => '1415555' . (9300 + $i)])->getStatusCode();
        }
        $this->assertTrue($this->anyThrottled($statuses), 'Past the per-visitor bound the endpoint refuses: ' . implode(',', $statuses));

        // The bound is per group: the other Business's list is unaffected.
        $this->post(route('contacts.unsubscribe_url.store', $otherGroup->uid), ['phone' => '14155559202'])->assertRedirect();
        $this->assertSame(Contacts::STATUS_UNSUBSCRIBE, $foreign->fresh()->status, 'The other group still works, and only for its own contact.');
    }

    public function test_public_subscribe_post_is_throttled(): void
    {
        [, $business] = $this->entitledTenant();
        $group = ContactGroups::create([
            'customer_id' => $business->customer_id, 'business_id' => $business->id, 'name' => 'Opt-in', 'status' => true,
        ]);

        $statuses = [];
        for ($i = 0; $i < 12; $i++) {
            $statuses[] = $this->post(route('contacts.subscribe_url.store', $group->uid), ['PHONE' => 'nope'])->getStatusCode();
        }

        $this->assertTrue($this->anyThrottled($statuses), implode(',', $statuses));
        $this->assertFalse($this->anyThrottled(array_slice($statuses, 0, 5)), 'Ordinary use is not throttled.');
    }

    // =====================================================================
    // 7. Canonical phone normalization / Contact matching
    // =====================================================================

    private function locationOf(Business $business): BusinessLocation
    {
        return app(BusinessLocationRepository::class)->upsertPrimary($business, [
            'service_mode' => 'storefront', 'address_line_1' => '1 Main St', 'city' => 'Austin',
            'region' => 'TX', 'country_code' => 'US', 'public_address' => true,
        ]);
    }

    private function resolveForm(BusinessLocation $location, string $raw): array
    {
        return DB::transaction(fn () => app(EloquentContactsRepository::class)->findOrCreateForForm($location, $raw, []));
    }

    public function test_equivalent_formattings_resolve_to_one_contact(): void
    {
        [, $business] = $this->entitledTenant();
        $location = $this->locationOf($business);

        [$first, $how] = $this->resolveForm($location, '+1 (415) 555-1234');
        $this->assertSame(FormContactResolution::Created, $how);

        foreach (['+14155551234', '415-555-1234', '(415) 555 1234', '14155551234'] as $raw) {
            [$again, $how] = $this->resolveForm($location, $raw);
            $this->assertSame(FormContactResolution::Matched, $how, $raw);
            $this->assertSame($first->id, $again->id, $raw);
        }

        $this->assertSame(1, Contacts::query()->where('business_id', $business->id)->count());
        $this->assertSame('14155551234', (string) $first->fresh()->phone);
    }

    public function test_an_international_number_is_preserved_and_kept_apart_from_a_us_one(): void
    {
        [, $business] = $this->entitledTenant();
        $location = $this->locationOf($business);

        [$us] = $this->resolveForm($location, '415-555-1234');
        [$uk, $how] = $this->resolveForm($location, '+44 20 7946 0958');
        [$ukAgain, $howAgain] = $this->resolveForm($location, '+442079460958');

        $this->assertSame(FormContactResolution::Created, $how);
        $this->assertSame('442079460958', (string) $uk->fresh()->phone);
        $this->assertSame(FormContactResolution::Matched, $howAgain);
        $this->assertSame($uk->id, $ukAgain->id);
        $this->assertNotSame($us->id, $uk->id);
    }

    public function test_the_same_phone_in_two_businesses_stays_two_contacts(): void
    {
        [, $a] = $this->entitledTenant();
        [, $b] = $this->entitledTenant();
        $locationA = $this->locationOf($a);
        $locationB = $this->locationOf($b);

        [$ca] = $this->resolveForm($locationA, '+1 415 555 1234');
        [$cb, $how] = $this->resolveForm($locationB, '(415) 555-1234');

        $this->assertSame(FormContactResolution::Created, $how);
        $this->assertNotSame($ca->id, $cb->id);
        $this->assertSame((int) $a->id, (int) $ca->fresh()->business_id);
        $this->assertSame((int) $b->id, (int) $cb->fresh()->business_id);
    }

    public function test_genuinely_different_numbers_remain_separate(): void
    {
        [, $business] = $this->entitledTenant();
        $location = $this->locationOf($business);

        [$one] = $this->resolveForm($location, '415-555-1234');
        [$two] = $this->resolveForm($location, '415-555-1235');
        [$three] = $this->resolveForm($location, '+1 650 555 1234');

        $this->assertCount(3, array_unique([$one->id, $two->id, $three->id]));
    }

    public function test_a_national_number_is_not_guessed_when_no_country_context_exists(): void
    {
        [, $business] = $this->entitledTenant();
        $location = $this->locationOf($business);
        DB::table('businesses')->where('id', $business->id)->update(['country_code' => null]);
        DB::table('business_locations')->where('id', $location->id)->update(['country_code' => null]);
        $location = $location->fresh();

        [$bare] = $this->resolveForm($location, '415-555-1234');
        [$explicit, $how] = $this->resolveForm($location, '+1 415 555 1234');

        $this->assertSame('4155551234', (string) $bare->fresh()->phone, 'Un-prefixed digits are kept exactly as they always were.');
        $this->assertSame(FormContactResolution::Created, $how, 'Ambiguous context is never resolved by guessing.');
        $this->assertNotSame($bare->id, $explicit->id);
    }

    public function test_a_historical_national_form_row_is_matched_not_duplicated(): void
    {
        [, $business] = $this->entitledTenant();
        $location = $this->locationOf($business);
        $historical = $this->contactOf($business, '4155551234');
        $historical->forceFill(['location_id' => $location->id])->save();

        [$matched, $how] = $this->resolveForm($location, '+1 (415) 555-1234');

        $this->assertSame(FormContactResolution::Matched, $how);
        $this->assertSame($historical->id, $matched->id);
        $this->assertSame('4155551234', (string) $historical->fresh()->phone, 'History is never rewritten.');
    }

    public function test_the_booking_seam_uses_the_same_normalization(): void
    {
        [, $business] = $this->entitledTenant();
        $location = $this->locationOf($business);
        $group = ContactGroups::create([
            'customer_id' => $business->customer_id, 'business_id' => $business->id, 'name' => 'Contacts', 'status' => true,
        ]);

        $repo = app(EloquentContactsRepository::class);
        $first = DB::transaction(fn () => $repo->findOrCreateForBooking($location, $group, '(415) 555-1234'));
        $again = DB::transaction(fn () => $repo->findOrCreateForBooking($location, $group, '+14155551234'));

        $this->assertSame($first->id, $again->id);
        $this->assertSame('14155551234', (string) $first->fresh()->phone);
    }

    public function test_manual_contact_creation_refuses_an_equivalent_formatting_of_an_existing_contact(): void
    {
        [, $business] = $this->entitledTenant();
        $group = ContactGroups::create([
            'customer_id' => $business->customer_id, 'business_id' => $business->id, 'name' => 'People', 'status' => true,
        ]);
        $repo = app(EloquentContactsRepository::class);

        [$validator, $created] = $repo->createContactFromRequest($group, ['PHONE' => '(415) 555-0151']);
        $this->assertNotNull($created, (string) $validator->errors()->first());
        $this->assertSame('14155550151', (string) $created->fresh()->phone);

        [$validator, $duplicate] = $repo->createContactFromRequest($group, ['PHONE' => '+1 415 555 0151']);
        $this->assertNull($duplicate);
        $this->assertTrue($validator->errors()->has('PHONE'));
        $this->assertSame(1, Contacts::query()->where('group_id', $group->id)->count());
    }

    public function test_another_businesses_stop_does_not_block_this_businesss_form(): void
    {
        [, $a] = $this->entitledTenant();
        [, $b] = $this->entitledTenant();
        Blacklists::create(['user_id' => $a->customer_id, 'business_id' => $a->id, 'number' => '14155551234', 'reason' => 'Optout by User']);

        [$contact, $how] = $this->resolveForm($this->locationOf($b), '+1 415 555 1234');

        $this->assertSame(FormContactResolution::Created, $how);
        $this->assertNotNull($contact);
    }
}

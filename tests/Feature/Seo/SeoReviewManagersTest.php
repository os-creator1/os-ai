<?php

namespace Tests\Feature\Seo;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Seo\SeoReviewRequestStatus;
use App\Exceptions\Seo\SeoReviewException;
use App\Library\Seo\SeoReviewLinkManager;
use App\Library\Seo\SeoReviewRequestManager;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Customer;
use App\Models\SeoLocationReviewLink;
use App\Models\SeoReviewRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Seo\Concerns\CreatesSeoReviewFixtures;
use Tests\TestCase;

/**
 * Contract 18 §8.6 / §15.F — the two managers: the manual review link, the
 * request ledger, Contact/Location equality, the cooldown, and the
 * serialization that keeps the cooldown from being raced.
 */
class SeoReviewManagersTest extends TestCase
{
    use RefreshDatabase;
    use CreatesSeoReviewFixtures;

    /**
     * @return array{0: Customer, 1: Business, 2: \App\Models\Workspace, 3: BusinessLocation}
     */
    private function tenant(): array
    {
        [$customer, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $location = $this->reviewLocation($business, 'Main');

        $this->authenticateAsSeoCustomer($customer, $this->reviewPermissions());

        return [$customer, $business, $workspace, $location];
    }

    private function links(): SeoReviewLinkManager
    {
        return app(SeoReviewLinkManager::class);
    }

    private function requests(): SeoReviewRequestManager
    {
        return app(SeoReviewRequestManager::class);
    }

    private function actor(Customer $customer): User
    {
        return $customer->user;
    }

    private function assertRefusal(string $reason, callable $action): void
    {
        try {
            $action();
            $this->fail("Expected a [{$reason}] refusal.");
        } catch (SeoReviewException $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    // -----------------------------------------------------------------
    // Review link
    // -----------------------------------------------------------------

    public function test_a_link_is_created_then_replaced_never_duplicated(): void
    {
        [$customer, $business, , $location] = $this->tenant();

        $first = $this->links()->set($customer->user_id, $business, (string) $location->uid, 'https://g.page/r/one/review');
        $second = $this->links()->set($customer->user_id, $business, (string) $location->uid, '  https://g.page/r/two/review  ');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, SeoLocationReviewLink::query()->count());
        $this->assertSame('https://g.page/r/two/review', SeoLocationReviewLink::query()->sole()->review_url);
        $this->assertSame($customer->user_id, SeoLocationReviewLink::query()->sole()->set_by_user_id);
        $this->assertSame($business->id, SeoLocationReviewLink::query()->sole()->business_id);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unsafeLinks(): array
    {
        return [
            'http' => ['http://g.page/r/x'],
            'javascript' => ['javascript:alert(1)'],
            'data' => ['data:text/html,x'],
            'protocol relative' => ['//g.page/r/x'],
            'userinfo' => ['https://google.com@evil.example/x'],
            'bare host' => ['g.page/r/x'],
            'empty' => [''],
            'too long' => ['https://g.page/' . '____PAD____'],
        ];
    }

    #[DataProvider('unsafeLinks')]
    public function test_an_unsafe_link_is_refused_and_nothing_is_stored(string $url): void
    {
        [$customer, $business, , $location] = $this->tenant();

        if ($url === 'https://g.page/____PAD____') {
            $url = 'https://g.page/' . str_repeat('a', 2100);
        }

        $this->assertRefusal(SeoReviewException::INVALID_URL, fn () => $this->links()->set($customer->user_id, $business, (string) $location->uid, $url));
        $this->assertSame(0, SeoLocationReviewLink::query()->count());
    }

    public function test_clearing_removes_the_manual_link_and_is_idempotent(): void
    {
        [$customer, $business, , $location] = $this->tenant();
        $this->makeReviewLink($business, $location);

        $this->links()->clear($customer->user_id, $business, (string) $location->uid);
        $this->links()->clear($customer->user_id, $business, (string) $location->uid);

        $this->assertSame(0, SeoLocationReviewLink::query()->count());
    }

    public function test_link_writes_refuse_an_unknown_foreign_or_inaccessible_location_identically(): void
    {
        [$customer, $business, $workspace, $granted] = $this->tenant();
        $hidden = $this->reviewLocation($business, 'Hidden');
        [, $foreignBusiness] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $foreign = $this->reviewLocation($foreignBusiness, 'Foreign');
        $member = $this->selectedScopeMember($workspace, [$granted]);

        $this->assertRefusal(SeoReviewException::ACCESS_DENIED, fn () => $this->links()->set($member->user_id, $business, (string) $hidden->uid, 'https://g.page/r/x'));
        $this->assertRefusal(SeoReviewException::ACCESS_DENIED, fn () => $this->links()->set($customer->user_id, $business, (string) $foreign->uid, 'https://g.page/r/x'));
        $this->assertRefusal(SeoReviewException::ACCESS_DENIED, fn () => $this->links()->set($customer->user_id, $business, 'no-such-uid', 'https://g.page/r/x'));
        $this->assertRefusal(SeoReviewException::ACCESS_DENIED, fn () => $this->links()->clear($member->user_id, $business, (string) $hidden->uid));

        $this->assertSame(0, SeoLocationReviewLink::query()->count());
    }

    public function test_a_stranger_with_no_business_access_is_refused(): void
    {
        [, $business, , $location] = $this->tenant();
        $stranger = $this->createCustomer();

        $this->assertRefusal(SeoReviewException::ACCESS_DENIED, fn () => $this->links()->set($stranger->user_id, $business, (string) $location->uid, 'https://g.page/r/x'));
        $this->assertRefusal(SeoReviewException::ACCESS_DENIED, fn () => $this->requests()->record($stranger->user, $business, (string) $location->uid, 'in_person'));
    }

    public function test_an_archived_location_refuses_link_and_request_writes(): void
    {
        [$customer, $business, , $location] = $this->tenant();
        $this->archiveLocation($location);

        $this->assertRefusal(SeoReviewException::LOCATION_NOT_ACTIVE, fn () => $this->links()->set($customer->user_id, $business, (string) $location->uid, 'https://g.page/r/x'));
        $this->assertRefusal(SeoReviewException::LOCATION_NOT_ACTIVE, fn () => $this->requests()->record($customer->user, $business, (string) $location->uid, 'in_person'));
    }

    // -----------------------------------------------------------------
    // Request creation
    // -----------------------------------------------------------------

    public function test_a_request_is_recorded_with_the_contact_the_channel_and_no_message_or_rating(): void
    {
        [$customer, $business, , $location] = $this->tenant();
        $contact = $this->reviewContact($business, $location);

        $request = $this->requests()->record($customer->user, $business, (string) $location->uid, 'sms', (string) $contact->uid);

        $this->assertSame($business->id, $request->business_id);
        $this->assertSame($location->id, $request->business_location_id);
        $this->assertSame($contact->id, $request->contact_id);
        $this->assertSame('sms', $request->channel->value);
        $this->assertSame(SeoReviewRequestStatus::Requested, $request->status);
        $this->assertNull($request->resolved_at);
        $this->assertNotNull($request->requested_at);
        $this->assertSame($customer->user_id, $request->created_by_user_id);
    }

    public function test_a_request_with_no_contact_is_recorded_and_has_no_cooldown(): void
    {
        [$customer, $business, , $location] = $this->tenant();

        $this->requests()->record($customer->user, $business, (string) $location->uid, 'in_person');
        $this->requests()->record($customer->user, $business, (string) $location->uid, 'in_person');

        $this->assertSame(2, SeoReviewRequest::query()->count());
    }

    public function test_an_unknown_channel_is_refused(): void
    {
        [$customer, $business, , $location] = $this->tenant();

        foreach (['carrier_pigeon', '', 'SMS'] as $bad) {
            $this->assertRefusal(SeoReviewException::INVALID_CHANNEL, fn () => $this->requests()->record($customer->user, $business, (string) $location->uid, $bad));
        }

        $this->assertSame(0, SeoReviewRequest::query()->count());
    }

    // -----------------------------------------------------------------
    // Contact integrity + PII authority
    // -----------------------------------------------------------------

    public function test_a_foreign_or_unknown_contact_is_refused_identically(): void
    {
        [$customer, $business, , $location] = $this->tenant();
        [, $foreignBusiness] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $foreignLocation = $this->reviewLocation($foreignBusiness);
        $foreignContact = $this->reviewContact($foreignBusiness, $foreignLocation);

        $this->assertRefusal(SeoReviewException::CONTACT_INVALID, fn () => $this->requests()->record($customer->user, $business, (string) $location->uid, 'sms', (string) $foreignContact->uid));
        $this->assertRefusal(SeoReviewException::CONTACT_INVALID, fn () => $this->requests()->record($customer->user, $business, (string) $location->uid, 'sms', 'does-not-exist'));

        $this->assertSame(0, SeoReviewRequest::query()->count());
    }

    public function test_a_contact_that_belongs_to_a_different_location_is_refused(): void
    {
        [$customer, $business, , $location] = $this->tenant();
        $other = $this->reviewLocation($business, 'Other');
        $contact = $this->reviewContact($business, $other);

        $this->assertRefusal(SeoReviewException::CONTACT_LOCATION_MISMATCH, fn () => $this->requests()->record($customer->user, $business, (string) $location->uid, 'sms', (string) $contact->uid));

        $this->assertSame(0, SeoReviewRequest::query()->count());
    }

    public function test_a_contact_with_no_proven_location_is_refused_because_null_never_equals_a_location(): void
    {
        [$customer, $business, , $location] = $this->tenant();
        $contact = $this->reviewContact($business, null);

        $this->assertRefusal(SeoReviewException::CONTACT_LOCATION_MISMATCH, fn () => $this->requests()->record($customer->user, $business, (string) $location->uid, 'sms', (string) $contact->uid));
    }

    public function test_a_contact_cannot_be_used_without_the_existing_contacts_capability(): void
    {
        [$customer, $business, , $location] = $this->tenant();
        $contact = $this->reviewContact($business, $location);

        // manage_seo but NOT view_contact.
        $this->authenticateAsSeoCustomer($customer, $this->seoPermissions());

        $this->assertRefusal(SeoReviewException::CONTACT_FORBIDDEN, fn () => $this->requests()->record($customer->user, $business, (string) $location->uid, 'sms', (string) $contact->uid));

        // ... but the same actor may still record a request with no contact.
        $this->assertNotNull($this->requests()->record($customer->user, $business, (string) $location->uid, 'in_person')->id);
    }

    public function test_a_request_for_an_inaccessible_location_is_refused_before_the_contact_is_examined(): void
    {
        [, $business, $workspace, $granted] = $this->tenant();
        $hidden = $this->reviewLocation($business, 'Hidden');
        $contact = $this->reviewContact($business, $hidden);
        $member = $this->selectedScopeMember($workspace, [$granted]);
        $this->authenticateAsSeoCustomer($member, $this->reviewPermissions());

        // The same refusal whether or not the contact is real: nothing about
        // the hidden Location's Contacts is confirmable.
        $this->assertRefusal(SeoReviewException::ACCESS_DENIED, fn () => $this->requests()->record($member->user, $business, (string) $hidden->uid, 'sms', (string) $contact->uid));
        $this->assertRefusal(SeoReviewException::ACCESS_DENIED, fn () => $this->requests()->record($member->user, $business, (string) $hidden->uid, 'sms', 'made-up'));

        $this->assertSame(0, SeoReviewRequest::query()->count());
    }

    public function test_the_business_and_location_are_rederived_not_trusted_from_the_caller(): void
    {
        [$customer, $business, , $location] = $this->tenant();
        [, $foreignBusiness] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $foreignLocation = $this->reviewLocation($foreignBusiness);

        // A tampered in-memory Business claiming the foreign Business's id.
        $lying = new Business();
        $lying->forceFill(['id' => $foreignBusiness->id, 'workspace_id' => $business->workspace_id, 'customer_id' => $business->customer_id]);

        $this->assertRefusal(SeoReviewException::ACCESS_DENIED, fn () => $this->requests()->record($customer->user, $lying, (string) $foreignLocation->uid, 'in_person'));
        $this->assertRefusal(SeoReviewException::ACCESS_DENIED, fn () => $this->requests()->record($customer->user, $business, (string) $foreignLocation->uid, 'in_person'));
        $this->assertSame(0, SeoReviewRequest::query()->count());
    }

    // -----------------------------------------------------------------
    // Opportunity linkage
    // -----------------------------------------------------------------

    public function test_an_objective_opportunity_can_be_linked(): void
    {
        [$customer, $business, , $location] = $this->tenant();
        $contact = $this->reviewContact($business, $location);
        $opportunity = $this->reviewOpportunity($business, $contact);
        DB::table('crm_opportunities')->where('id', $opportunity->id)->update(['location_id' => $location->id]);

        $request = $this->requests()->record($customer->user, $business, (string) $location->uid, 'email', (string) $contact->uid, (string) $opportunity->uid);

        $this->assertSame($opportunity->id, $request->crm_opportunity_id);
    }

    public function test_a_foreign_or_mismatched_opportunity_is_refused(): void
    {
        [$customer, $business, , $location] = $this->tenant();
        $other = $this->reviewLocation($business, 'Other');
        $contact = $this->reviewContact($business, $location);
        $someoneElse = $this->reviewContact($business, $location);
        [, $foreignBusiness] = $this->entitledTenant(WorkspacePlanTier::Growth);

        $foreignOpp = $this->reviewOpportunity($foreignBusiness);
        $otherLocationOpp = $this->reviewOpportunity($business, $contact);
        DB::table('crm_opportunities')->where('id', $otherLocationOpp->id)->update(['location_id' => $other->id]);
        $otherContactOpp = $this->reviewOpportunity($business, $someoneElse);

        foreach ([$foreignOpp, $otherLocationOpp, $otherContactOpp] as $bad) {
            $this->assertRefusal(SeoReviewException::OPPORTUNITY_INVALID, fn () => $this->requests()->record($customer->user, $business, (string) $location->uid, 'sms', (string) $contact->uid, (string) $bad->uid));
        }

        $this->assertRefusal(SeoReviewException::OPPORTUNITY_INVALID, fn () => $this->requests()->record($customer->user, $business, (string) $location->uid, 'sms', (string) $contact->uid, 'nope'));
        $this->assertSame(0, SeoReviewRequest::query()->count());
    }

    // -----------------------------------------------------------------
    // Cooldown (default 90 days, non-declined, per contact + location)
    // -----------------------------------------------------------------

    public function test_a_second_request_inside_the_cooldown_window_is_refused(): void
    {
        [$customer, $business, , $location] = $this->tenant();
        $contact = $this->reviewContact($business, $location);

        $this->requests()->record($customer->user, $business, (string) $location->uid, 'sms', (string) $contact->uid);

        $this->assertRefusal(SeoReviewException::COOLDOWN, fn () => $this->requests()->record($customer->user, $business, (string) $location->uid, 'email', (string) $contact->uid));
        $this->assertRefusal(SeoReviewException::COOLDOWN, fn () => $this->requests()->record($customer->user, $business, (string) $location->uid, 'sms', (string) $contact->uid));

        $this->assertSame(1, SeoReviewRequest::query()->count());
    }

    public function test_the_window_is_ninety_days_and_eligibility_returns_after_it(): void
    {
        [$customer, $business, , $location] = $this->tenant();
        $contact = $this->reviewContact($business, $location);
        $first = $this->makeReviewRequest($business, $location, $contact, ['requested_at' => now()->subDays(89)]);

        $this->assertRefusal(SeoReviewException::COOLDOWN, fn () => $this->requests()->record($customer->user, $business, (string) $location->uid, 'sms', (string) $contact->uid));

        $first->forceFill(['requested_at' => now()->subDays(91)])->save();

        $second = $this->requests()->record($customer->user, $business, (string) $location->uid, 'sms', (string) $contact->uid);
        $this->assertNotNull($second->id);
        $this->assertSame(2, SeoReviewRequest::query()->count());
    }

    public function test_a_reviewed_request_still_counts_but_a_declined_one_does_not(): void
    {
        [$customer, $business, , $location] = $this->tenant();
        $contact = $this->reviewContact($business, $location);
        $first = $this->makeReviewRequest($business, $location, $contact, ['status' => 'reviewed', 'requested_at' => now()->subDays(10)]);

        $this->assertRefusal(SeoReviewException::COOLDOWN, fn () => $this->requests()->record($customer->user, $business, (string) $location->uid, 'sms', (string) $contact->uid));

        $first->forceFill(['status' => 'declined'])->save();

        $this->assertNotNull($this->requests()->record($customer->user, $business, (string) $location->uid, 'sms', (string) $contact->uid)->id);
    }

    public function test_marking_a_request_declined_through_the_manager_frees_the_contact(): void
    {
        [$customer, $business, , $location] = $this->tenant();
        $contact = $this->reviewContact($business, $location);
        $first = $this->requests()->record($customer->user, $business, (string) $location->uid, 'sms', (string) $contact->uid);

        $this->requests()->resolve($customer->user_id, $business, (string) $first->uid, 'declined');

        $this->assertNotNull($this->requests()->record($customer->user, $business, (string) $location->uid, 'sms', (string) $contact->uid)->id);
    }

    public function test_the_cooldown_is_per_contact(): void
    {
        [$customer, $business, , $location] = $this->tenant();
        $one = $this->reviewContact($business, $location);
        $two = $this->reviewContact($business, $location);

        $this->requests()->record($customer->user, $business, (string) $location->uid, 'sms', (string) $one->uid);

        $this->assertNotNull($this->requests()->record($customer->user, $business, (string) $location->uid, 'sms', (string) $two->uid)->id);
    }

    public function test_the_window_length_comes_from_config_and_falls_back_safely(): void
    {
        [$customer, $business, , $location] = $this->tenant();
        $contact = $this->reviewContact($business, $location);
        $this->makeReviewRequest($business, $location, $contact, ['requested_at' => now()->subDays(40)]);

        config(['seo.reviews.request_cooldown_days' => 30]);
        $this->assertNotNull($this->requests()->record($customer->user, $business, (string) $location->uid, 'sms', (string) $contact->uid)->id);
    }

    public function test_a_broken_config_value_can_neither_disable_nor_widen_the_cooldown(): void
    {
        [$customer, $business, , $location] = $this->tenant();
        $contact = $this->reviewContact($business, $location);
        $this->makeReviewRequest($business, $location, $contact, ['requested_at' => now()->subDays(10)]);

        foreach ([0, -5, 'abc', null, 99999, '0'] as $bad) {
            config(['seo.reviews.request_cooldown_days' => $bad]);

            $this->assertRefusal(SeoReviewException::COOLDOWN, fn () => $this->requests()->record($customer->user, $business, (string) $location->uid, 'sms', (string) $contact->uid));
        }
    }

    // -----------------------------------------------------------------
    // Serialization: the cooldown is checked under the Business row lock
    // -----------------------------------------------------------------

    public function test_the_cooldown_check_runs_after_the_business_row_is_locked_in_the_same_transaction(): void
    {
        [$customer, $business, , $location] = $this->tenant();
        $contact = $this->reviewContact($business, $location);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->requests()->record($customer->user, $business, (string) $location->uid, 'sms', (string) $contact->uid);
        $sql = array_map(fn ($entry) => strtolower((string) $entry['query']), DB::getQueryLog());
        DB::disableQueryLog();

        $lock = null;
        $cooldown = null;
        $insert = null;

        foreach ($sql as $index => $statement) {
            if ($lock === null && str_contains($statement, 'from `businesses`') && str_contains($statement, 'for update')) {
                $lock = $index;
            }

            if ($cooldown === null && str_contains($statement, 'from `seo_review_requests`') && str_contains($statement, '`requested_at` >')) {
                $cooldown = $index;
            }

            if ($insert === null && str_contains($statement, 'insert into `seo_review_requests`')) {
                $insert = $index;
            }
        }

        $this->assertNotNull($lock, 'The Business row must be locked FOR UPDATE.');
        $this->assertNotNull($cooldown, 'The cooldown lookup must run.');
        $this->assertNotNull($insert);
        $this->assertLessThan($cooldown, $lock, 'Lock BEFORE the cooldown check, so a concurrent request waits and then sees this one.');
        $this->assertLessThan($insert, $cooldown, 'Check BEFORE the insert, inside the same locked section.');
    }

    public function test_the_lock_and_the_write_share_one_transaction(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Library/Seo/SeoReviewRequestManager.php');

        $record = substr($source, (int) strpos($source, 'public function record('));
        $record = substr($record, 0, (int) strpos($record, 'public function resolve('));

        $transaction = strpos($record, 'DB::transaction(');
        $lock = strpos($record, 'lockAuthorizedBusiness(');
        $cooldown = strpos($record, 'assertOutsideCooldown(');
        $save = strpos($record, '->save()');

        foreach ([$transaction, $lock, $cooldown, $save] as $position) {
            $this->assertNotFalse($position);
        }

        $this->assertLessThan($lock, $transaction);
        $this->assertLessThan($cooldown, $lock);
        $this->assertLessThan($save, $cooldown);

        $gate = file_get_contents(dirname(__DIR__, 3) . '/app/Library/Seo/SeoReviewWriteGate.php');
        $this->assertStringContainsString('lockForUpdate()', $gate);
    }

    // -----------------------------------------------------------------
    // Outcome
    // -----------------------------------------------------------------

    public function test_reviewed_and_declined_are_recorded_once_with_a_resolved_time(): void
    {
        [$customer, $business, , $location] = $this->tenant();

        $a = $this->requests()->record($customer->user, $business, (string) $location->uid, 'in_person');
        $b = $this->requests()->record($customer->user, $business, (string) $location->uid, 'in_person');

        $reviewed = $this->requests()->resolve($customer->user_id, $business, (string) $a->uid, 'reviewed');
        $declined = $this->requests()->resolve($customer->user_id, $business, (string) $b->uid, 'declined');

        $this->assertSame(SeoReviewRequestStatus::Reviewed, $reviewed->status);
        $this->assertSame(SeoReviewRequestStatus::Declined, $declined->status);
        $this->assertNotNull($reviewed->resolved_at);

        $this->assertRefusal(SeoReviewException::ALREADY_RESOLVED, fn () => $this->requests()->resolve($customer->user_id, $business, (string) $a->uid, 'declined'));
        $this->assertRefusal(SeoReviewException::INVALID_OUTCOME, fn () => $this->requests()->resolve($customer->user_id, $business, (string) $this->requests()->record($customer->user, $business, (string) $location->uid, 'other')->uid, 'requested'));
        $this->assertRefusal(SeoReviewException::INVALID_OUTCOME, fn () => $this->requests()->resolve($customer->user_id, $business, (string) $this->requests()->record($customer->user, $business, (string) $location->uid, 'other')->uid, 'happy'));
    }

    public function test_resolving_a_guessed_foreign_or_inaccessible_request_is_refused_and_leaves_it_untouched(): void
    {
        [$customer, $business, $workspace, $granted] = $this->tenant();
        $hidden = $this->reviewLocation($business, 'Hidden');
        $hiddenRequest = $this->makeReviewRequest($business, $hidden);
        [, $foreignBusiness] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $foreignRequest = $this->makeReviewRequest($foreignBusiness, $this->reviewLocation($foreignBusiness));
        $member = $this->selectedScopeMember($workspace, [$granted]);
        $before = $this->dbFingerprint(['seo_review_requests']);

        $this->assertRefusal(SeoReviewException::ACCESS_DENIED, fn () => $this->requests()->resolve($member->user_id, $business, (string) $hiddenRequest->uid, 'reviewed'));
        $this->assertRefusal(SeoReviewException::ACCESS_DENIED, fn () => $this->requests()->resolve($customer->user_id, $business, (string) $foreignRequest->uid, 'reviewed'));
        $this->assertRefusal(SeoReviewException::ACCESS_DENIED, fn () => $this->requests()->resolve($customer->user_id, $business, 'made-up', 'reviewed'));

        $this->assertSame($before, $this->dbFingerprint(['seo_review_requests']));
    }

    public function test_an_archived_locations_request_cannot_be_resolved(): void
    {
        [$customer, $business, , $location] = $this->tenant();
        $request = $this->requests()->record($customer->user, $business, (string) $location->uid, 'in_person');
        $this->archiveLocation($location);

        $this->assertRefusal(SeoReviewException::LOCATION_NOT_ACTIVE, fn () => $this->requests()->resolve($customer->user_id, $business, (string) $request->uid, 'reviewed'));
    }

    // -----------------------------------------------------------------
    // Reads take only ids the caller already authorized
    // -----------------------------------------------------------------

    public function test_reads_never_return_a_location_that_was_not_passed_in(): void
    {
        [, $business, , $location] = $this->tenant();
        $hidden = $this->reviewLocation($business, 'Hidden');
        $this->makeReviewRequest($business, $location);
        $this->makeReviewRequest($business, $hidden);
        $this->makeReviewLink($business, $location);
        $this->makeReviewLink($business, $hidden);

        $rows = $this->requests()->forAccessibleLocations($business, [(int) $location->id]);
        $links = $this->links()->forAccessibleLocations($business, [(int) $location->id]);

        $this->assertSame([(int) $location->id], $rows->pluck('business_location_id')->unique()->map(fn ($id) => (int) $id)->all());
        $this->assertSame([(int) $location->id], $links->keys()->all());
        $this->assertCount(0, $this->requests()->forAccessibleLocations($business, []));
    }
}

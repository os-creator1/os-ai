<?php

namespace Tests\Feature\BusinessEmail;

use App\DTO\BusinessEmail\BusinessEmailSendRequest;
use App\Enums\Business\BusinessLocationLifecycleState;
use App\Enums\BusinessEmail\BusinessEmailAccountState;
use App\Enums\BusinessEmail\BusinessEmailFailureCategory as Category;
use App\Enums\BusinessEmail\BusinessEmailMessageStatus as Status;
use App\Enums\BusinessEmail\BusinessEmailProviderType;
use App\Enums\BusinessEmail\BusinessEmailSource;
use App\Exceptions\BusinessEmail\BusinessEmailProviderException;
use App\Exceptions\BusinessEmail\BusinessEmailSendRefusedException;
use App\Library\BusinessEmail\BusinessEmailSender;
use App\Models\BusinessEmailAccount;
use App\Models\BusinessEmailMessage;
use App\Models\BusinessLocation;
use App\Models\Contacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\BusinessEmail\Concerns\CreatesBusinessEmailFixtures;
use Tests\TestCase;

/**
 * The canonical Business → Contact boundary: sender resolution, recipient
 * rules, the idempotency ledger, the failure taxonomy and bounded retry. The
 * provider is an in-memory fake; no network is reachable.
 */
class BusinessEmailSenderTest extends TestCase
{
    use CreatesBusinessEmailFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->bindFakeEmailProviders();
    }

    private function request(object $business, object $contact, string $key = 'op-1', string $subject = 'Hello', string $body = 'Hi there', ?BusinessLocation $location = null): BusinessEmailSendRequest
    {
        return new BusinessEmailSendRequest($business, $contact, $subject, $body, $key, location: $location);
    }

    private function sender(): BusinessEmailSender
    {
        return app(BusinessEmailSender::class);
    }

    /** @return array{0: \App\Models\Business, 1: \App\Models\Contacts} */
    private function readyTenant(string $email = 'Pat@Example.com'): array
    {
        [, $business] = $this->emailTenant();
        $this->activeAccount($business);

        return [$business, $this->contactWithEmails($business, [$email])];
    }

    public function test_sends_to_a_business_contact_from_the_default_account_and_records_acceptance(): void
    {
        [$business, $contact] = $this->readyTenant();

        $message = $this->sender()->send($this->request($business, $contact));

        $this->assertSame(Status::Accepted, $message->status);
        $this->assertSame(1, $this->fakeGoogle->callCount('send'));
        $this->assertSame('owner@business.test', $this->fakeGoogle->sent[0]->fromEmail);
        $this->assertSame('pat@example.com', $this->fakeGoogle->sent[0]->toEmail);
        $this->assertSame('owner@business.test', $message->from_email);
        $this->assertSame('pat@example.com', $message->to_email);
        $this->assertSame('pm-1', $message->provider_message_id);
        $this->assertSame('thread-1', $message->provider_thread_id);
        $this->assertNotNull($message->accepted_at);
        $this->assertSame(1, $message->attempts);
        $this->assertSame('manual', $message->source->value);
        $this->assertSame('outbound', $message->direction);
        $this->assertSame($business->id, (int) $message->business_id);
        $this->assertSame($contact->id, (int) $message->contact_id);
    }

    public function test_accepted_is_not_delivered_and_the_enum_has_no_delivered_state(): void
    {
        $this->assertNull(Status::tryFrom('delivered'));
        $this->assertTrue(Status::Accepted->isTerminal());
    }

    public function test_the_microsoft_account_is_sent_through_the_microsoft_adapter(): void
    {
        [, $business] = $this->emailTenant();
        $this->activeAccount($business, BusinessEmailProviderType::Microsoft, 'owner@outlook.test');
        $contact = $this->contactWithEmails($business, ['pat@example.com']);

        $message = $this->sender()->send($this->request($business, $contact));

        $this->assertSame(Status::Accepted, $message->status);
        $this->assertSame(0, $this->fakeGoogle->callCount('send'));
        $this->assertSame(1, $this->fakeMicrosoft->callCount('send'));
        $this->assertSame('microsoft', $message->provider->value);
    }

    public function test_no_connected_account_is_refused_with_no_rows_and_no_provider_call(): void
    {
        [, $business] = $this->emailTenant();
        $contact = $this->contactWithEmails($business, ['pat@example.com']);

        try {
            $this->sender()->send($this->request($business, $contact));
            $this->fail('Expected a refusal.');
        } catch (BusinessEmailSendRefusedException $exception) {
            $this->assertSame(Category::DisconnectedAccount, $exception->category);
        }

        $this->assertSame(0, BusinessEmailMessage::query()->count());
        $this->assertSame(0, $this->fakeGoogle->callCount('send'));
    }

    public function test_a_foreign_business_account_can_never_be_used(): void
    {
        [, $businessA] = $this->emailTenant('Business A');
        [, $businessB] = $this->emailTenant('Business B');
        $this->activeAccount($businessB, BusinessEmailProviderType::Google, 'b@business.test');
        $contactA = $this->contactWithEmails($businessA, ['pat@example.com']);

        // Business A has no account of its own; B's account must not resolve.
        $this->expectException(BusinessEmailSendRefusedException::class);

        try {
            $this->sender()->send($this->request($businessA, $contactA));
        } finally {
            $this->assertSame(0, $this->fakeGoogle->callCount('send'));
        }
    }

    public function test_a_foreign_contact_fails_closed_without_any_side_effect(): void
    {
        [$businessA] = $this->readyTenant();
        [, $businessB] = $this->emailTenant('Business B');
        $foreign = $this->contactWithEmails($businessB, ['victim@example.com']);

        try {
            $this->sender()->send($this->request($businessA, $foreign));
            $this->fail('Expected a refusal.');
        } catch (BusinessEmailSendRefusedException $exception) {
            $this->assertSame(Category::ContactUnavailable, $exception->category);
        }

        $this->assertSame(0, BusinessEmailMessage::query()->count());
        $this->assertSame(0, $this->fakeGoogle->callCount('send'));
    }

    /**
     * @param list<string> $emails
     */
    #[DataProvider('unusableRecipients')]
    public function test_a_contact_without_exactly_one_valid_address_is_refused(array $emails): void
    {
        [, $business] = $this->emailTenant();
        $this->activeAccount($business);
        $contact = $this->contactWithEmails($business, $emails);

        try {
            $this->sender()->send($this->request($business, $contact));
            $this->fail('Expected a refusal.');
        } catch (BusinessEmailSendRefusedException $exception) {
            $this->assertSame(Category::RecipientInvalid, $exception->category);
        }

        $this->assertSame(0, $this->fakeGoogle->callCount('send'));
        $this->assertSame(0, BusinessEmailMessage::query()->count());
    }

    /** @return array<string, array{0: list<string>}> */
    public static function unusableRecipients(): array
    {
        return [
            'no email' => [[]],
            'not an address' => [['not-an-email']],
            'two different addresses (never guess)' => [['a@example.com', 'b@example.com']],
            'header injection attempt' => [["x@example.com\r\nBcc: spy@example.com"]],
            'display-name form' => [['Pat <pat@example.com>']],
        ];
    }

    public function test_the_same_address_twice_is_still_one_recipient(): void
    {
        [, $business] = $this->emailTenant();
        $this->activeAccount($business);
        $contact = $this->contactWithEmails($business, ['Pat@Example.com', ' pat@example.com ']);

        $message = $this->sender()->send($this->request($business, $contact));

        $this->assertSame(Status::Accepted, $message->status);
    }

    #[DataProvider('invalidContent')]
    public function test_invalid_subject_or_body_is_refused_before_anything_happens(string $subject, string $body): void
    {
        [$business, $contact] = $this->readyTenant();

        try {
            $this->sender()->send($this->request($business, $contact, 'op-x', $subject, $body));
            $this->fail('Expected a refusal.');
        } catch (BusinessEmailSendRefusedException $exception) {
            $this->assertSame(Category::MessageInvalid, $exception->category);
        }

        $this->assertSame(0, BusinessEmailMessage::query()->count());
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function invalidContent(): array
    {
        return [
            'empty subject' => ['   ', 'body'],
            'empty body' => ['subject', "  \n "],
            'subject too long' => [str_repeat('s', 201), 'body'],
            'body too long' => ['subject', str_repeat('b', 20001)],
        ];
    }

    public function test_a_newline_in_the_subject_cannot_become_a_header(): void
    {
        [$business, $contact] = $this->readyTenant();

        $message = $this->sender()->send($this->request($business, $contact, 'op-nl', "Hi\r\nBcc: spy@example.com", 'body'));

        $this->assertStringNotContainsString("\n", $message->subject);
        $this->assertStringNotContainsString("\r", $this->fakeGoogle->sent[0]->subject);
    }

    public function test_an_invalid_operation_key_is_refused(): void
    {
        [$business, $contact] = $this->readyTenant();

        $this->expectException(BusinessEmailSendRefusedException::class);
        $this->sender()->send($this->request($business, $contact, '   '));
    }

    // ---- idempotency -----------------------------------------------------

    public function test_the_same_operation_key_never_produces_a_second_provider_call(): void
    {
        [$business, $contact] = $this->readyTenant();

        $first = $this->sender()->send($this->request($business, $contact, 'automation:step:42'));
        $second = $this->sender()->send($this->request($business, $contact, 'automation:step:42'));
        // Incidental whitespace/control-character differences normalize to the
        // same logical request, so they converge too.
        $third = $this->sender()->send($this->request($business, $contact, 'automation:step:42', "  Hello\r\n", "\n Hi there  "));

        $this->assertSame(1, $this->fakeGoogle->callCount('send'));
        $this->assertSame(1, BusinessEmailMessage::query()->count());
        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->id, $third->id);
        $this->assertSame('Hello', $third->subject);
    }

    // ---- replay is bound to the original logical payload -----------------

    private function assertConflictWithZeroExtraCalls(callable $replay, int $expectedProviderCalls = 1): void
    {
        try {
            $replay();
            $this->fail('Expected an idempotency conflict.');
        } catch (BusinessEmailSendRefusedException $exception) {
            $this->assertSame(Category::IdempotencyConflict, $exception->category);
        }

        $this->assertSame($expectedProviderCalls, $this->fakeGoogle->callCount('send'), 'A mismatched replay must never reach a provider.');
        $this->assertSame(1, BusinessEmailMessage::query()->count());
    }

    public function test_the_same_key_with_a_changed_subject_is_refused(): void
    {
        [$business, $contact] = $this->readyTenant();
        $this->sender()->send($this->request($business, $contact, 'op-bind'));

        $this->assertConflictWithZeroExtraCalls(fn () => $this->sender()->send($this->request($business, $contact, 'op-bind', 'A different subject')));
    }

    public function test_the_same_key_with_a_changed_body_is_refused(): void
    {
        [$business, $contact] = $this->readyTenant();
        $this->sender()->send($this->request($business, $contact, 'op-bind'));

        $this->assertConflictWithZeroExtraCalls(fn () => $this->sender()->send($this->request($business, $contact, 'op-bind', 'Hello', 'A different body')));
    }

    public function test_the_same_key_for_a_different_contact_is_refused_not_replayed(): void
    {
        [$business, $contact] = $this->readyTenant();
        $other = $this->contactWithEmails($business, ['other@example.com']);
        $this->sender()->send($this->request($business, $contact, 'op-shared'));

        $this->assertConflictWithZeroExtraCalls(fn () => $this->sender()->send($this->request($business, $other, 'op-shared')));
    }

    public function test_the_same_key_with_a_different_source_or_automation_step_is_refused(): void
    {
        [$business, $contact] = $this->readyTenant();
        $first = $this->sender()->send(new BusinessEmailSendRequest($business, $contact, 'Hello', 'Hi there', 'op-src', BusinessEmailSource::Automation, automationStepRunId: 7));

        $this->assertSame(7, (int) $first->automation_step_run_id);
        $this->assertSame(BusinessEmailSource::Automation, $first->source);

        // Exact replay converges.
        $again = $this->sender()->send(new BusinessEmailSendRequest($business, $contact, 'Hello', 'Hi there', 'op-src', BusinessEmailSource::Automation, automationStepRunId: 7));
        $this->assertSame($first->id, $again->id);

        $this->assertConflictWithZeroExtraCalls(fn () => $this->sender()->send(new BusinessEmailSendRequest($business, $contact, 'Hello', 'Hi there', 'op-src', BusinessEmailSource::Manual, automationStepRunId: 7)));
        $this->assertConflictWithZeroExtraCalls(fn () => $this->sender()->send(new BusinessEmailSendRequest($business, $contact, 'Hello', 'Hi there', 'op-src', BusinessEmailSource::Automation, automationStepRunId: 8)));
        $this->assertConflictWithZeroExtraCalls(fn () => $this->sender()->send(new BusinessEmailSendRequest($business, $contact, 'Hello', 'Hi there', 'op-src', BusinessEmailSource::Automation)));
    }

    public function test_the_same_key_with_a_different_explicit_location_is_refused_but_a_derived_one_converges(): void
    {
        [$business, $contact] = $this->readyTenant();
        $primary = BusinessLocation::query()->where('business_id', $business->id)->firstOrFail();
        $other = $this->makeLocation($business, 'Second');

        $first = $this->sender()->send($this->request($business, $contact, 'op-loc', location: $primary));

        $again = $this->sender()->send($this->request($business, $contact, 'op-loc', location: $primary));
        $this->assertSame($first->id, $again->id);

        $this->assertConflictWithZeroExtraCalls(fn () => $this->sender()->send($this->request($business, $contact, 'op-loc', location: $other)));

        // A caller that leaves the Location to be derived still converges on
        // the durable snapshot, even though the Business now has two Locations.
        $derived = $this->sender()->send($this->request($business, $contact, 'op-loc'));
        $this->assertSame($first->id, $derived->id);
        $this->assertSame($primary->id, (int) $derived->location_id);
    }

    public function test_a_replay_after_the_contact_moved_still_converges_on_the_original_row(): void
    {
        [$business, $contact] = $this->readyTenant();
        $first = $this->sender()->send($this->request($business, $contact, 'op-moved'));
        $second = $this->makeLocation($business, 'Second');
        DB::table('contacts')->where('id', $contact->id)->update(['location_id' => $second->id]);

        $again = $this->sender()->send($this->request($business, $contact, 'op-moved'));

        $this->assertSame($first->id, $again->id);
        $this->assertSame($first->location_id, $again->location_id);
        $this->assertSame(1, $this->fakeGoogle->callCount('send'));
    }

    // ---- the Contact is re-derived from persistence ----------------------

    public function test_a_contact_forged_in_memory_into_this_business_is_refused(): void
    {
        [$businessA] = $this->readyTenant();
        [, $businessB] = $this->emailTenant('Business B');
        $victim = $this->contactWithEmails($businessB, ['victim@example.com']);

        // The row belongs to Business B; only the in-memory model claims A.
        $forged = Contacts::query()->findOrFail($victim->id);
        $forged->business_id = $businessA->id;
        $this->assertSame($businessA->id, (int) $forged->business_id);

        try {
            $this->sender()->send($this->request($businessA, $forged, 'op-forged'));
            $this->fail('Expected a refusal.');
        } catch (BusinessEmailSendRefusedException $exception) {
            $this->assertSame(Category::ContactUnavailable, $exception->category);
        }

        $this->assertSame(0, BusinessEmailMessage::query()->count());
        $this->assertSame(0, $this->fakeGoogle->callCount('send'));
    }

    public function test_a_stale_contact_uses_the_authoritative_current_location(): void
    {
        [$business, $contact] = $this->readyTenant();
        $stale = Contacts::query()->findOrFail($contact->id);
        $this->assertNull($stale->location_id);

        // Two active Locations now exist and the Contact moved to the second.
        $second = $this->makeLocation($business, 'Second');
        DB::table('contacts')->where('id', $contact->id)->update(['location_id' => $second->id]);

        // The stale model says "no Location" (which would be ambiguous with two
        // Locations); the authoritative row says Second.
        $message = $this->sender()->send($this->request($business, $stale, 'op-stale-loc'));

        $this->assertSame($second->id, (int) $message->location_id);
    }

    public function test_a_stale_contact_sends_only_to_the_authoritative_current_email(): void
    {
        [$business, $contact] = $this->readyTenant('old@example.com');
        $stale = Contacts::query()->findOrFail($contact->id);

        DB::table('contacts_custom_field')->where('contact_id', $contact->id)->update(['value' => 'new@example.com']);

        $message = $this->sender()->send($this->request($business, $stale, 'op-stale-email'));

        $this->assertSame('new@example.com', $message->to_email);
        $this->assertSame('new@example.com', $this->fakeGoogle->sent[0]->toEmail);
        $this->assertSame(1, $this->fakeGoogle->callCount('send'));
    }

    public function test_a_contact_deleted_since_is_refused(): void
    {
        [$business, $contact] = $this->readyTenant();
        $stale = Contacts::query()->findOrFail($contact->id);
        DB::table('contacts')->where('id', $contact->id)->delete();

        $this->expectException(BusinessEmailSendRefusedException::class);
        $this->sender()->send($this->request($business, $stale, 'op-deleted'));
    }

    public function test_a_replay_still_works_after_the_account_was_disconnected(): void
    {
        [$business, $contact] = $this->readyTenant();
        $first = $this->sender()->send($this->request($business, $contact, 'op-replay'));

        DB::table('business_email_accounts')->where('business_id', $business->id)->update([
            'state' => BusinessEmailAccountState::Disconnected->value,
            'refresh_token_encrypted' => null,
        ]);

        $again = $this->sender()->send($this->request($business, $contact, 'op-replay'));

        $this->assertSame($first->id, $again->id);
        $this->assertSame(Status::Accepted, $again->status);
        $this->assertSame(1, $this->fakeGoogle->callCount('send'));
    }

    public function test_operation_keys_are_scoped_per_business(): void
    {
        [$businessA, $contactA] = $this->readyTenant();
        [, $businessB] = $this->emailTenant('Business B');
        $this->activeAccount($businessB, BusinessEmailProviderType::Google, 'b@business.test');
        $contactB = $this->contactWithEmails($businessB, ['pat@example.com']);

        $a = $this->sender()->send($this->request($businessA, $contactA, 'same-key'));
        $b = $this->sender()->send($this->request($businessB, $contactB, 'same-key'));

        $this->assertNotSame($a->id, $b->id);
        $this->assertSame(2, $this->fakeGoogle->callCount('send'));
        $this->assertSame('b@business.test', $b->from_email);
    }

    public function test_a_duplicate_arriving_while_the_first_is_in_flight_does_not_send_twice(): void
    {
        [$business, $contact] = $this->readyTenant();
        $inner = null;

        // While the first request is inside the provider call, an identical
        // request arrives (a double click, a parallel job).
        $this->fakeGoogle->duringSend = function () use ($business, $contact, &$inner): void {
            $this->fakeGoogle->duringSend = null;
            $inner = $this->sender()->send($this->request($business, $contact, 'op-race'));
        };

        $outer = $this->sender()->send($this->request($business, $contact, 'op-race'));

        $this->assertSame(1, $this->fakeGoogle->callCount('send'));
        $this->assertSame(Status::Sending, $inner->status);
        $this->assertSame(Status::Accepted, $outer->status);
        $this->assertSame(1, BusinessEmailMessage::query()->count());
    }

    public function test_the_unique_index_is_the_hard_backstop_for_a_racing_insert(): void
    {
        [$business, $contact] = $this->readyTenant();
        $this->sender()->send($this->request($business, $contact, 'op-unique'));

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        BusinessEmailMessage::create([
            'business_id' => $business->id,
            'location_id' => BusinessLocation::query()->where('business_id', $business->id)->value('id'),
            'operation_key' => 'op-unique',
            'provider' => 'google',
            'from_email' => 'a@b.test',
            'to_email' => 'c@d.test',
            'subject' => 's',
            'body_text' => 'b',
        ]);
    }

    // ---- failure taxonomy, retry and ambiguity ---------------------------

    public function test_a_permanent_failure_is_recorded_once_and_never_retried(): void
    {
        [$business, $contact] = $this->readyTenant();
        $this->fakeGoogle->sendScript = [new BusinessEmailProviderException(Category::PermanentProviderFailure, '404')];

        $first = $this->sender()->send($this->request($business, $contact, 'op-perm'));
        $this->travel(2)->hours();
        $again = $this->sender()->send($this->request($business, $contact, 'op-perm'));

        $this->assertSame(Status::Failed, $first->status);
        $this->assertSame(Category::PermanentProviderFailure, $first->failure_category);
        $this->assertNull($first->next_attempt_at);
        $this->assertSame('404', $first->failure_provider_code);
        $this->assertSame(1, $this->fakeGoogle->callCount('send'));
        $this->assertSame($first->id, $again->id);
        // The operator-only code never leaves the model through serialization.
        $this->assertArrayNotHasKey('failure_provider_code', $first->toArray());
    }

    public function test_a_retryable_failure_waits_for_backoff_then_retries_within_a_bounded_budget(): void
    {
        [$business, $contact] = $this->readyTenant();
        $rate = new BusinessEmailProviderException(Category::ProviderRateLimited, '429');
        $this->fakeGoogle->sendScript = [$rate, $rate, $rate];

        $first = $this->sender()->send($this->request($business, $contact, 'op-retry'));
        $this->assertSame(Status::Failed, $first->status);
        $this->assertNotNull($first->next_attempt_at);

        // Immediately again: the backoff has not elapsed — no provider call.
        $this->sender()->send($this->request($business, $contact, 'op-retry'));
        $this->assertSame(1, $this->fakeGoogle->callCount('send'));

        $this->travel(2)->minutes();
        $second = $this->sender()->send($this->request($business, $contact, 'op-retry'));
        $this->assertSame(2, $this->fakeGoogle->callCount('send'));
        $this->assertSame(Status::Failed, $second->status);
        $this->assertSame(2, $second->attempts);

        $this->travel(10)->minutes();
        $third = $this->sender()->send($this->request($business, $contact, 'op-retry'));
        $this->assertSame(3, $this->fakeGoogle->callCount('send'));
        $this->assertSame(Status::Failed, $third->status);
        $this->assertNull($third->next_attempt_at, 'The budget is spent: no further retry is scheduled.');

        // The budget is exhausted — never an automatic infinite retry.
        $this->travel(5)->hours();
        $this->sender()->send($this->request($business, $contact, 'op-retry'));
        $this->assertSame(3, $this->fakeGoogle->callCount('send'));
    }

    public function test_a_retry_that_succeeds_ends_accepted(): void
    {
        [$business, $contact] = $this->readyTenant();
        $this->fakeGoogle->sendScript = [new BusinessEmailProviderException(Category::TemporaryProviderFailure, '503'), null];

        $this->sender()->send($this->request($business, $contact, 'op-heal'));
        $this->travel(2)->minutes();
        $healed = $this->sender()->send($this->request($business, $contact, 'op-heal'));

        $this->assertSame(Status::Accepted, $healed->status);
        $this->assertNull($healed->failure_category);
        $this->assertSame(2, $healed->attempts);
    }

    public function test_an_ambiguous_outcome_is_unconfirmed_and_never_resent(): void
    {
        [$business, $contact] = $this->readyTenant();
        $this->fakeGoogle->sendScript = [new BusinessEmailProviderException(Category::TemporaryProviderFailure, 'transport', ambiguous: true)];

        $first = $this->sender()->send($this->request($business, $contact, 'op-amb'));
        $this->travel(5)->hours();
        $again = $this->sender()->send($this->request($business, $contact, 'op-amb'));

        $this->assertSame(Status::Unconfirmed, $first->status);
        $this->assertSame(Status::Unconfirmed, $again->status);
        $this->assertSame(1, $this->fakeGoogle->callCount('send'));
    }

    public function test_a_crashed_sending_claim_becomes_unconfirmed_and_is_not_resent(): void
    {
        [$business, $contact] = $this->readyTenant();
        $message = $this->sender()->send($this->request($business, $contact, 'op-crash'));

        // Simulate a worker that died mid-call long ago.
        DB::table('business_email_messages')->where('id', $message->id)->update([
            'status' => Status::Sending->value,
            'claimed_at' => now()->subHour(),
            'accepted_at' => null,
        ]);

        $recovered = $this->sender()->send($this->request($business, $contact, 'op-crash'));

        $this->assertSame(Status::Unconfirmed, $recovered->status);
        $this->assertSame(1, $this->fakeGoogle->callCount('send'));
    }

    public function test_an_expired_refresh_grant_revokes_the_account_and_fails_safely(): void
    {
        [$business, $contact] = $this->readyTenant();
        $this->fakeGoogle->refreshFailure = new BusinessEmailProviderException(Category::AuthenticationExpired, 'invalid_grant', revocation: true);

        $message = $this->sender()->send($this->request($business, $contact, 'op-expired'));

        $this->assertSame(Status::Failed, $message->status);
        $this->assertSame(Category::AuthenticationExpired, $message->failure_category);
        $this->assertNull($message->next_attempt_at);
        $this->assertSame(0, $this->fakeGoogle->callCount('send'));

        $account = BusinessEmailAccount::query()->where('business_id', $business->id)->first();
        $this->assertSame(BusinessEmailAccountState::Revoked, $account->state);
        $this->assertNull($account->refresh()->refresh_token_encrypted);

        // From now on the account no longer resolves at all.
        $this->expectException(BusinessEmailSendRefusedException::class);
        $this->sender()->send($this->request($business, $contact, 'op-after-revoke'));
    }

    public function test_a_temporary_refresh_failure_is_retryable_and_does_not_revoke(): void
    {
        [$business, $contact] = $this->readyTenant();
        $this->fakeGoogle->refreshFailure = new BusinessEmailProviderException(Category::TemporaryProviderFailure, '503');

        $message = $this->sender()->send($this->request($business, $contact, 'op-refresh-503'));

        $this->assertSame(Status::Failed, $message->status);
        $this->assertTrue($message->failure_category->isRetryable());
        $this->assertNotNull($message->next_attempt_at);
        $account = BusinessEmailAccount::query()->where('business_id', $business->id)->first();
        $this->assertSame(BusinessEmailAccountState::Active, $account->state);
        $this->assertSame('temporary_provider_failure', $account->failure_classification);
    }

    public function test_a_queued_row_whose_account_was_disconnected_fails_without_a_provider_call(): void
    {
        [$business, $contact] = $this->readyTenant();
        $message = $this->sender()->send($this->request($business, $contact, 'op-queued'));

        DB::table('business_email_messages')->where('id', $message->id)->update([
            'status' => Status::Queued->value,
            'accepted_at' => null,
            'attempts' => 0,
        ]);
        DB::table('business_email_accounts')->where('business_id', $business->id)->update([
            'state' => BusinessEmailAccountState::Disconnected->value,
            'refresh_token_encrypted' => null,
        ]);

        $result = $this->sender()->send($this->request($business, $contact, 'op-queued'));

        $this->assertSame(Status::Failed, $result->status);
        $this->assertSame(Category::DisconnectedAccount, $result->failure_category);
        $this->assertSame(1, $this->fakeGoogle->callCount('send'));
    }

    public function test_the_failure_taxonomy_is_closed_and_retryability_is_explicit(): void
    {
        $retryable = array_filter(Category::cases(), fn (Category $c) => $c->isRetryable());

        $this->assertEqualsCanonicalizing(
            [Category::ProviderRateLimited, Category::TemporaryProviderFailure],
            array_values($retryable),
        );

        foreach (Category::cases() as $category) {
            $this->assertNotSame('', $category->customerMessage());
        }
    }

    // ---- abuse bounds ----------------------------------------------------

    public function test_a_per_contact_hourly_bound_stops_a_runaway_loop(): void
    {
        config(['business_email.send.per_contact_per_hour' => 3]);
        [$business, $contact] = $this->readyTenant();

        for ($i = 1; $i <= 3; $i++) {
            $this->sender()->send($this->request($business, $contact, "loop-{$i}"));
        }

        try {
            $this->sender()->send($this->request($business, $contact, 'loop-4'));
            $this->fail('Expected the bound to refuse.');
        } catch (BusinessEmailSendRefusedException $exception) {
            $this->assertSame(Category::SendLimitExceeded, $exception->category);
        }

        $this->assertSame(3, $this->fakeGoogle->callCount('send'));

        // A replay of an existing key is never counted against the bound.
        $this->sender()->send($this->request($business, $contact, 'loop-1'));
        $this->assertSame(3, $this->fakeGoogle->callCount('send'));
    }

    public function test_a_per_business_hourly_bound_applies_across_contacts(): void
    {
        config(['business_email.send.per_business_per_hour' => 2]);
        [$business, $contact] = $this->readyTenant();
        $other = $this->contactWithEmails($business, ['b@example.com']);
        $third = $this->contactWithEmails($business, ['c@example.com']);

        $this->sender()->send($this->request($business, $contact, 'k1'));
        $this->sender()->send($this->request($business, $other, 'k2'));

        $this->expectException(BusinessEmailSendRefusedException::class);
        $this->sender()->send($this->request($business, $third, 'k3'));
    }

    // ---- Location attribution (V1: every operational record has one) -----

    private function primary(object $business): BusinessLocation
    {
        return BusinessLocation::query()->where('business_id', $business->id)->orderBy('id')->firstOrFail();
    }

    private function refusedWith(callable $send, Category $expected): void
    {
        try {
            $send();
            $this->fail('Expected a refusal.');
        } catch (BusinessEmailSendRefusedException $exception) {
            $this->assertSame($expected, $exception->category);
        }
    }

    public function test_an_explicit_valid_location_wins(): void
    {
        [$business, $contact] = $this->readyTenant();
        $other = $this->makeLocation($business, 'Second');
        DB::table('contacts')->where('id', $contact->id)->update(['location_id' => $this->primary($business)->id]);

        $message = $this->sender()->send($this->request($business, $contact, 'loc-explicit', location: $other));

        $this->assertSame($other->id, (int) $message->location_id);
    }

    public function test_the_authoritative_contact_location_is_used_when_none_is_supplied(): void
    {
        [$business, $contact] = $this->readyTenant();
        $second = $this->makeLocation($business, 'Second');
        DB::table('contacts')->where('id', $contact->id)->update(['location_id' => $second->id]);

        $message = $this->sender()->send($this->request($business, $contact, 'loc-contact'));

        $this->assertSame($second->id, (int) $message->location_id);
    }

    public function test_the_single_active_location_is_the_fallback_for_a_contact_without_one(): void
    {
        [$business, $contact] = $this->readyTenant();
        $this->assertNull($contact->location_id);

        $message = $this->sender()->send($this->request($business, $contact, 'loc-single'));

        $this->assertSame($this->primary($business)->id, (int) $message->location_id);
    }

    public function test_an_archived_contact_location_falls_back_to_the_single_active_one(): void
    {
        [$business, $contact] = $this->readyTenant();
        $archived = $this->makeLocation($business, 'Closed', archived: true);
        DB::table('contacts')->where('id', $contact->id)->update(['location_id' => $archived->id]);

        $message = $this->sender()->send($this->request($business, $contact, 'loc-archived-contact'));

        $this->assertSame($this->primary($business)->id, (int) $message->location_id);
    }

    public function test_several_active_locations_and_none_provable_requires_a_choice_and_persists_nothing(): void
    {
        [$business, $contact] = $this->readyTenant();
        $this->makeLocation($business, 'Second');

        $this->refusedWith(fn () => $this->sender()->send($this->request($business, $contact, 'loc-ambiguous')), Category::LocationRequired);

        $this->assertSame(0, BusinessEmailMessage::query()->count(), 'Never an unscoped operational row.');
        $this->assertSame(0, $this->fakeGoogle->callCount('send'));
    }

    public function test_a_business_with_no_active_location_cannot_send(): void
    {
        [, $business] = $this->tenant();
        $this->activeAccount($business);
        $contact = $this->contactWithEmails($business, ['pat@example.com']);

        $this->refusedWith(fn () => $this->sender()->send($this->request($business, $contact, 'loc-none')), Category::LocationRequired);

        $this->assertSame(0, BusinessEmailMessage::query()->count());
    }

    public function test_a_foreign_or_archived_explicit_location_is_refused(): void
    {
        [$business, $contact] = $this->readyTenant();
        [, $other] = $this->emailTenant('Other Business');
        $foreign = $this->primary($other);
        $archived = $this->makeLocation($business, 'Closed', archived: true);

        foreach ([$foreign, $archived] as $i => $bad) {
            $this->refusedWith(fn () => $this->sender()->send($this->request($business, $contact, "bad-loc-{$i}", location: $bad)), Category::MessageInvalid);
        }

        $this->assertSame(0, BusinessEmailMessage::query()->count());
        $this->assertSame(0, $this->fakeGoogle->callCount('send'));
    }

    public function test_the_historical_location_never_changes_when_the_contact_later_moves(): void
    {
        [$business, $contact] = $this->readyTenant();
        $second = $this->makeLocation($business, 'Second');
        $first = $this->sender()->send($this->request($business, $contact, 'loc-hist', location: $second));

        DB::table('contacts')->where('id', $contact->id)->update(['location_id' => $this->primary($business)->id]);

        $this->assertSame($second->id, (int) $first->fresh()->location_id);
        $this->assertSame($second->id, (int) $this->sender()->send($this->request($business, $contact, 'loc-hist'))->location_id);
    }

    public function test_the_database_itself_refuses_an_unscoped_message_row(): void
    {
        [$business, $contact] = $this->readyTenant();
        $row = [
            'uid' => (string) \Illuminate\Support\Str::uuid(), 'business_id' => $business->id, 'contact_id' => $contact->id,
            'operation_key' => 'raw-null', 'provider' => 'google', 'from_email' => 'a@b.test', 'to_email' => 'c@d.test',
            'subject' => 's', 'body_text' => 'b', 'created_at' => now(), 'updated_at' => now(),
        ];

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('business_email_messages')->insert($row + ['location_id' => null]);
    }
    public function test_no_secret_is_ever_persisted_on_the_message_or_serialized_from_the_account(): void
    {
        [$business, $contact] = $this->readyTenant();
        $message = $this->sender()->send($this->request($business, $contact, 'op-secret'));

        $row = json_encode(DB::table('business_email_messages')->where('id', $message->id)->first());
        $this->assertStringNotContainsString('stored-refresh-token', (string) $row);
        $this->assertStringNotContainsString('fake-access-token', (string) $row);

        $account = BusinessEmailAccount::query()->where('business_id', $business->id)->first();
        $this->assertArrayNotHasKey('refresh_token_encrypted', $account->toArray());
        $this->assertArrayNotHasKey('oauth_state_nonce', $account->toArray());
    }
}

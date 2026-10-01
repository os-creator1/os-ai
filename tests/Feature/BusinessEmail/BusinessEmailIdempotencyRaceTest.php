<?php

namespace Tests\Feature\BusinessEmail;

use App\DTO\BusinessEmail\BusinessEmailSendRequest;
use App\Enums\BusinessEmail\BusinessEmailFailureCategory as Category;
use App\Enums\BusinessEmail\BusinessEmailMessageStatus as Status;
use App\Enums\BusinessEmail\BusinessEmailSource;
use App\Exceptions\BusinessEmail\BusinessEmailSendRefusedException;
use App\Library\BusinessEmail\BusinessEmailSender;
use App\Models\Business;
use App\Models\BusinessEmailAccount;
use App\Models\BusinessEmailMessage;
use App\Models\BusinessLocation;
use App\Models\Contacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\BusinessEmail\Concerns\CreatesBusinessEmailFixtures;
use Tests\TestCase;

/**
 * The concurrent idempotency collision: two requests with the same
 * (business_id, operation_key) at the same instant.
 *
 * WHY THIS IS DETERMINISTIC, NOT MULTI-PROCESS. The race is "A checked and found
 * no row, B inserted its row, A's INSERT then hits UNIQUE(business_id,
 * operation_key)". A faithful multi-process test would need separate PHP
 * processes, a runner script and sleep-based timing against the shared database
 * (the repository has such harnesses for its money lanes) — disproportionate
 * for a single branch, and inherently flaky. Instead each test registers a model
 * `creating` hook that INSERTS THE WINNER'S ROW at the exact instant between
 * the sender's existence check and its own INSERT. The sender then genuinely
 * executes the real `UniqueConstraintViolationException` loser path in
 * createQueued(): nothing is mocked, and the unique index (not PHP) decides who
 * lost.
 */
class BusinessEmailIdempotencyRaceTest extends TestCase
{
    use CreatesBusinessEmailFixtures;
    use RefreshDatabase;

    private Business $business;

    private Contacts $contact;

    private BusinessLocation $primary;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->bindFakeEmailProviders();

        [, $this->business] = $this->emailTenant();
        $this->activeAccount($this->business);
        $this->contact = $this->contactWithEmails($this->business, ['pat@example.com']);
        $this->primary = BusinessLocation::query()->where('business_id', $this->business->id)->firstOrFail();
    }

    private function sender(): BusinessEmailSender
    {
        return app(BusinessEmailSender::class);
    }

    /** The loser: manual, Hello / Hi there, to $this->contact, Location derived. */
    private function loserRequest(string $subject = 'Hello', string $body = 'Hi there', ?Contacts $contact = null, ?BusinessLocation $location = null, BusinessEmailSource $source = BusinessEmailSource::Manual, ?int $step = null): BusinessEmailSendRequest
    {
        return new BusinessEmailSendRequest($this->business, $contact ?? $this->contact, $subject, $body, 'op-race', $source, $location, null, $step);
    }

    /**
     * Arranges for the WINNER of the race to be committed between the sender's
     * existence check and its own INSERT. Fires once.
     *
     * @param array<string, mixed> $overrides columns of the winner row
     */
    private function winnerCommitsFirst(array $overrides = []): void
    {
        $fired = false;

        BusinessEmailMessage::creating(function () use (&$fired, $overrides): void {
            if ($fired) {
                return;
            }

            $fired = true;

            $overrides = array_map(fn ($value) => $value === '__now__' ? now() : $value, $overrides);

            DB::table('business_email_messages')->insert(array_merge([
                'uid' => (string) Str::uuid(),
                'business_id' => $this->business->id,
                'location_id' => $this->primary->id,
                'business_email_account_id' => BusinessEmailAccount::query()->where('business_id', $this->business->id)->value('id'),
                'contact_id' => $this->contact->id,
                'direction' => 'outbound',
                'source' => 'manual',
                'automation_step_run_id' => null,
                'operation_key' => 'op-race',
                'provider' => 'google',
                'from_email' => 'owner@business.test',
                'to_email' => 'pat@example.com',
                'subject' => 'Hello',
                'body_text' => 'Hi there',
                'status' => Status::Queued->value,
                'attempts' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ], $overrides));
        });
    }

    private function assertLoserRefusedAsConflict(BusinessEmailSendRequest $request): void
    {
        try {
            $this->sender()->send($request);
            $this->fail('The losing request must be refused as an idempotency conflict.');
        } catch (BusinessEmailSendRefusedException $exception) {
            $this->assertSame(Category::IdempotencyConflict, $exception->category);
        }

        $this->assertSame(0, $this->fakeGoogle->callCount('send'), 'The losing conflicting request never invokes the provider.');
        $this->assertSame(0, $this->fakeGoogle->callCount('exchangeRefreshToken'));
        $this->assertSame(1, BusinessEmailMessage::query()->count(), 'Exactly one ledger row remains: the winner\'s.');

        // The winner's row is untouched by the loser.
        $winner = BusinessEmailMessage::query()->sole();
        $this->assertSame(Status::Queued, $winner->status);
        $this->assertSame(0, $winner->attempts);
    }

    // ---- 1. exact same logical request converges --------------------------

    public function test_a_concurrent_exact_replay_converges_on_the_winner_and_sends_exactly_once(): void
    {
        $this->winnerCommitsFirst();

        $result = $this->sender()->send($this->loserRequest());

        $winner = BusinessEmailMessage::query()->sole();
        $this->assertSame($winner->id, $result->id);
        $this->assertSame(Status::Accepted, $result->status);
        $this->assertSame(1, $this->fakeGoogle->callCount('send'), 'One logical request, one provider send.');
        $this->assertSame(1, BusinessEmailMessage::query()->count());
    }

    public function test_the_unique_violation_path_really_ran(): void
    {
        $this->winnerCommitsFirst();

        $result = $this->sender()->send($this->loserRequest());

        // The returned row is the winner's, not a row this request inserted.
        $this->assertFalse($result->wasRecentlyCreated);
        $this->assertSame(1, BusinessEmailMessage::query()->count());
    }

    // ---- 2-5. any material difference is an idempotency_conflict ----------

    public function test_a_concurrent_request_with_a_different_subject_is_refused(): void
    {
        $this->winnerCommitsFirst();

        $this->assertLoserRefusedAsConflict($this->loserRequest(subject: 'A different subject'));
    }

    public function test_a_concurrent_request_with_a_different_body_is_refused(): void
    {
        $this->winnerCommitsFirst();

        $this->assertLoserRefusedAsConflict($this->loserRequest(body: 'A different body'));
    }

    public function test_a_concurrent_request_for_a_different_contact_is_refused(): void
    {
        $other = $this->contactWithEmails($this->business, ['other@example.com']);
        $this->winnerCommitsFirst();

        $this->assertLoserRefusedAsConflict($this->loserRequest(contact: $other));
    }

    public function test_a_concurrent_request_with_a_different_explicit_location_is_refused(): void
    {
        $second = $this->makeLocation($this->business, 'Second');
        $this->winnerCommitsFirst(['location_id' => $this->primary->id]);

        $this->assertLoserRefusedAsConflict($this->loserRequest(location: $second));
    }

    public function test_a_concurrent_request_with_a_different_source_is_refused(): void
    {
        $this->winnerCommitsFirst(['source' => 'automation', 'automation_step_run_id' => 7]);

        // Same step identity, different source.
        $this->assertLoserRefusedAsConflict($this->loserRequest(source: BusinessEmailSource::Manual, step: 7));
    }

    public function test_a_concurrent_request_with_a_different_automation_step_is_refused(): void
    {
        $this->winnerCommitsFirst(['source' => 'automation', 'automation_step_run_id' => 7]);

        $this->assertLoserRefusedAsConflict($this->loserRequest(source: BusinessEmailSource::Automation, step: 8));
    }

    public function test_a_concurrent_request_missing_the_winners_automation_step_is_refused(): void
    {
        $this->winnerCommitsFirst(['source' => 'automation', 'automation_step_run_id' => 7]);

        $this->assertLoserRefusedAsConflict($this->loserRequest(source: BusinessEmailSource::Automation));
    }

    public function test_a_concurrent_request_adding_an_automation_step_the_winner_lacks_is_refused(): void
    {
        $this->winnerCommitsFirst();

        $this->assertLoserRefusedAsConflict($this->loserRequest(step: 7));
    }

    public function test_the_same_automation_identity_converges_concurrently(): void
    {
        $this->winnerCommitsFirst(['source' => 'automation', 'automation_step_run_id' => 7]);

        $result = $this->sender()->send($this->loserRequest(source: BusinessEmailSource::Automation, step: 7));

        $this->assertSame(Status::Accepted, $result->status);
        $this->assertSame(7, (int) $result->automation_step_run_id);
        $this->assertSame(1, $this->fakeGoogle->callCount('send'));
        $this->assertSame(1, BusinessEmailMessage::query()->count());
    }

    public function test_incidental_whitespace_differences_still_converge_concurrently(): void
    {
        $this->winnerCommitsFirst();

        $result = $this->sender()->send($this->loserRequest(subject: "  Hello\r\n", body: "\n Hi there  "));

        $this->assertSame(Status::Accepted, $result->status);
        $this->assertSame(1, $this->fakeGoogle->callCount('send'));
        $this->assertSame(1, BusinessEmailMessage::query()->count());
    }

    // ---- a matching winner is handled like any recorded row ---------------

    /** @param array<string, mixed> $winnerState */
    #[DataProvider('matchingWinnerStates')]
    public function test_a_matching_winner_in_any_recorded_state_is_never_blindly_resent(string $expectedStatus, array $winnerState): void
    {
        $this->winnerCommitsFirst($winnerState);

        $result = $this->sender()->send($this->loserRequest());

        $this->assertSame(Status::from($expectedStatus), $result->status);
        $this->assertSame(0, $this->fakeGoogle->callCount('send'), 'The race loser must not re-send a recorded winner.');
        $this->assertSame(1, BusinessEmailMessage::query()->count());
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>}> */
    public static function matchingWinnerStates(): array
    {
        return [
            'accepted' => ['accepted', ['status' => 'accepted', 'provider_message_id' => 'pm-win', 'attempts' => 1]],
            'permanently failed' => ['failed', ['status' => 'failed', 'failure_category' => 'permanent_provider_failure', 'attempts' => 1]],
            'unconfirmed' => ['unconfirmed', ['status' => 'unconfirmed', 'attempts' => 1]],
            'sending inside its lease' => ['sending', ['status' => 'sending', 'claimed_at' => '__now__', 'attempts' => 1]],
        ];
    }
    public function test_the_ordinary_non_racing_conflict_is_unchanged(): void
    {
        $this->sender()->send($this->loserRequest());

        $this->expectException(BusinessEmailSendRefusedException::class);

        try {
            $this->sender()->send($this->loserRequest(subject: 'Changed'));
        } finally {
            $this->assertSame(1, $this->fakeGoogle->callCount('send'));
            $this->assertSame(1, BusinessEmailMessage::query()->count());
        }
    }
}

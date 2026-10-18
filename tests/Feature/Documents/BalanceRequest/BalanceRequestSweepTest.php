<?php

namespace Tests\Feature\Documents\BalanceRequest;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspaceEntitlementOverrideState;
use App\Jobs\Documents\SendDocumentBalanceRequestEmail;
use App\Jobs\Documents\SendDocumentLinkSms;
use App\Library\Documents\Delivery\DocumentBalanceRequestDispatcher;
use App\Library\Documents\DocumentManager;
use App\Library\Entitlement\EntitlementManager;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentPayment;
use App\Models\BusinessDocumentPaymentScheduleItem;
use App\Models\ContactGroupFields;
use App\Models\ContactsCustomField;
use App\Notifications\Documents\DocumentBalanceRequestNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Testing\Fakes\NotificationFake;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Tests\Feature\Payments\Concerns\CreatesRefundablePayments;
use Tests\TestCase;

/**
 * Contract 17B §3/§7 — the automatic balance payment request: deposit paid,
 * balance scheduled, the frozen due_at arrives, the customer is emailed a
 * FRESH secure link exactly once. Everything runs through the production
 * managers, the production finalizer (fake Stripe gateway) and the real job;
 * only the mail channel and the gateway are faked.
 */
class BalanceRequestSweepTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRefundablePayments;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindFakeGateway();
        config(['documents.enabled' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ---- fixtures -----------------------------------------------------------------------------------

    /**
     * A signed Deposit + Balance document, deposit PAID through the production
     * finalizer, balance pending and due in `$dueInDays` days (null = right
     * after the deposit).
     *
     * @return array{tenant: array, document: BusinessDocument, token: string, connection: mixed}
     */
    private function depositPaid(?int $dueInDays = 2, bool $payDeposit = true): array
    {
        $fixture = $this->payableDocument([
            ['kind' => 'deposit', 'amount_minor' => 20000, 'currency_code' => 'USD'],
            ['kind' => 'balance', 'amount_minor' => 30000, 'currency_code' => 'USD',
                'due_at' => $dueInDays === null ? null : now()->addDays($dueInDays)->toDateTimeString()],
        ]);

        if ($payDeposit) {
            $this->captureNextItem($fixture);
        }

        // Count only what the balance request itself sends.
        Notification::fake();

        return $fixture;
    }

    private function balance(BusinessDocument $document): BusinessDocumentPaymentScheduleItem
    {
        return BusinessDocumentPaymentScheduleItem::where('business_document_version_id', $document->refresh()->current_version_id)
            ->where('kind', 'balance')->sole();
    }

    private function deposit(BusinessDocument $document): BusinessDocumentPaymentScheduleItem
    {
        return BusinessDocumentPaymentScheduleItem::where('business_document_version_id', $document->refresh()->current_version_id)
            ->where('kind', 'deposit')->sole();
    }

    private function dueArrives(): void
    {
        Carbon::setTestNow(now()->addDays(3));
    }

    private function sweep(int $limit = 100): int
    {
        return app(DocumentBalanceRequestDispatcher::class)->dispatchDue($limit);
    }

    private function assertRequestsSent(int $times): void
    {
        Notification::assertSentOnDemandTimes(DocumentBalanceRequestNotification::class, $times);
    }

    /** @return array{0: object, 1: string} the sent notification and its plaintext token */
    private function lastRequest(): array
    {
        $found = null;
        Notification::assertSentOnDemand(DocumentBalanceRequestNotification::class, function ($notification) use (&$found) {
            $found = $notification;

            return true;
        });

        return [$found, (string) (new ReflectionProperty($found, 'plaintextToken'))->getValue($found)];
    }

    private function runJob(BusinessDocument $document, string $token): void
    {
        $item = $this->balance($document);
        (new SendDocumentBalanceRequestEmail((int) $document->id, (int) $item->id, $token))
            ->handle(app(DocumentBalanceRequestDispatcher::class));
    }

    // ---- not yet due / null due_at ---------------------------------------------------------------------------

    public function test_nothing_is_sent_before_the_due_date(): void
    {
        $fixture = $this->depositPaid(2);

        $this->assertSame(0, $this->sweep());
        $this->artisan('documents:dispatch-balance-requests')->expectsOutput('Dispatched 0 balance payment request(s).')->assertExitCode(0);

        $this->assertRequestsSent(0);
        $item = $this->balance($fixture['document']);
        $this->assertNull($item->payment_request_claimed_at);
        $this->assertSame(0, (int) $item->payment_request_attempts);
        $this->assertTrue(Hash::check($fixture['token'], (string) $fixture['document']->refresh()->access_token_hash), 'the link was not rotated');
    }

    public function test_a_balance_with_no_due_date_is_never_scheduled_because_it_is_already_payable(): void
    {
        $fixture = $this->depositPaid(null);

        // Well past any due date a date-less balance could have, yet inside the link's 30-day life.
        Carbon::setTestNow(now()->addDays(20));

        $this->assertSame(0, $this->sweep());
        $this->assertRequestsSent(0);

        // ...and it IS payable right now through the ORIGINAL link.
        $this->postJson($this->payUrl($fixture['document'], $fixture['token']))->assertOk();
        $this->assertSame(30000, (int) BusinessDocumentPayment::where('business_document_id', $fixture['document']->id)->orderByDesc('id')->first()->amount_minor);
    }

    public function test_the_request_goes_out_at_the_first_moment_of_the_due_day_in_the_business_timezone(): void
    {
        $fixture = $this->payableDocument([
            ['kind' => 'deposit', 'amount_minor' => 20000, 'currency_code' => 'USD'],
            // Frozen as the END of 1 June 2030 in New York, as the plan compiler does.
            ['kind' => 'balance', 'amount_minor' => 30000, 'currency_code' => 'USD',
                'due_at' => Carbon::parse('2030-06-01 23:59:59', 'America/New_York')->setTimezone(config('app.timezone'))->toDateTimeString()],
        ]);
        DB::table('businesses')->where('id', $fixture['tenant']['business']->id)->update(['timezone' => 'America/New_York']);
        $this->captureNextItem($fixture);
        Notification::fake();
        $dueAt = $this->balance($fixture['document'])->due_at->toDateTimeString();

        // The day before the due date, right up to its last second: nothing.
        Carbon::setTestNow(Carbon::parse('2030-05-31 12:00:00', 'America/New_York'));
        $this->assertSame(0, $this->sweep());
        Carbon::setTestNow(Carbon::parse('2030-05-31 23:59:59', 'America/New_York'));
        $this->assertSame(0, $this->sweep());
        $this->assertRequestsSent(0);

        // The first moment of 1 June in New York: exactly one request, though due_at is still hours away.
        Carbon::setTestNow(Carbon::parse('2030-06-01 00:00:00', 'America/New_York'));
        $this->assertSame(1, $this->sweep());
        $this->assertRequestsSent(1);

        // Same-day replays and sweeps, early and late: still one.
        [, $token] = $this->lastRequest();
        $this->runJob($fixture['document'], $token);
        $this->assertSame(0, $this->sweep());
        Carbon::setTestNow(Carbon::parse('2030-06-01 23:59:59', 'America/New_York'));
        $this->assertSame(0, $this->sweep());
        Carbon::setTestNow(Carbon::parse('2030-06-02 09:00:00', 'America/New_York'));
        $this->assertSame(0, $this->sweep());
        $this->assertRequestsSent(1);

        // due_at is untouched: still the frozen end of the due day.
        $this->assertSame($dueAt, $this->balance($fixture['document'])->due_at->toDateTimeString());
    }

    // ---- the happy path --------------------------------------------------------------------------------------

    public function test_when_the_due_date_arrives_exactly_one_request_goes_to_the_frozen_recipient_with_a_working_fresh_link(): void
    {
        $fixture = $this->depositPaid(2);
        $document = $fixture['document'];
        $oldToken = $fixture['token'];

        $this->dueArrives();
        $this->assertSame(1, $this->sweep());

        $this->assertRequestsSent(1);
        Notification::assertSentOnDemand(DocumentBalanceRequestNotification::class, function ($notification, $channels, $notifiable) {
            return $notifiable->routes['mail'] === 'client@example.test';
        });
        [$notification, $newToken] = $this->lastRequest();
        $this->assertNotSame($oldToken, $newToken);
        $this->assertSame(64, strlen($newToken));

        $mail = $notification->toMail(new AnonymousNotifiable());
        $this->assertSame(route('public.documents.show', ['uid' => $document->uid, 'token' => $newToken]) . '#pay', $mail->actionUrl);
        $this->assertStringContainsString('A payment of 300.00 USD for “Kitchen renovation proposal” is due', $mail->subject);

        $item = $this->balance($document);
        $this->assertNotNull($item->payment_request_sent_at);
        $this->assertNotNull($item->payment_request_claimed_at);
        $this->assertNull($item->payment_request_failed_at);
        $this->assertSame(1, (int) $item->payment_request_attempts);
        $this->assertNotNull($document->refresh()->link_delivered_at, 'the link markers describe the fresh link');

        // The frozen terms did not move.
        $this->assertSame(30000, (int) $item->amount_minor);
        $this->assertSame('pending', $item->status->value);

        // The fresh link works and shows the balance payable; the old link is dead.
        $this->get($this->publicUrl($document, $newToken))->assertOk();
        $this->get($this->publicUrl($document, $oldToken))->assertNotFound();
        $this->postJson($this->payUrl($document, $oldToken))->assertNotFound();
        $this->postJson($this->payUrl($document, $newToken))->assertOk();
        $this->assertSame(30000, (int) BusinessDocumentPayment::where('business_document_id', $document->id)->orderByDesc('id')->first()->amount_minor);
    }

    public function test_job_replay_and_repeated_sweeps_still_send_exactly_once(): void
    {
        $fixture = $this->depositPaid(2);
        $this->dueArrives();

        $this->assertSame(1, $this->sweep());
        [, $token] = $this->lastRequest();

        $this->runJob($fixture['document'], $token);
        $this->runJob($fixture['document'], $token);
        $this->assertSame(0, $this->sweep());
        $this->assertSame(0, $this->sweep());
        $this->artisan('documents:dispatch-balance-requests')->expectsOutput('Dispatched 0 balance payment request(s).')->assertExitCode(0);

        $this->assertRequestsSent(1);
        $this->assertSame(1, (int) $this->balance($fixture['document'])->payment_request_attempts);
        $this->assertTrue(Hash::check($token, (string) $fixture['document']->refresh()->access_token_hash), 'no later sweep rotated the link again');
    }

    public function test_a_second_claim_of_the_same_item_loses_even_with_a_stale_candidate_list(): void
    {
        $fixture = $this->depositPaid(2);
        $this->dueArrives();
        Bus::fake([SendDocumentBalanceRequestEmail::class]);

        $item = $this->balance($fixture['document']);
        $claim = new ReflectionMethod(DocumentBalanceRequestDispatcher::class, 'claim');
        $claim->setAccessible(true);

        // Two dispatcher instances holding the same (stale) candidate.
        $first = new DocumentBalanceRequestDispatcher(app(DocumentManager::class));
        $second = new DocumentBalanceRequestDispatcher(app(DocumentManager::class));

        $winner = $claim->invoke($first, (int) $fixture['document']->id, (int) $item->id);
        $loser = $claim->invoke($second, (int) $fixture['document']->id, (int) $item->id);

        $this->assertIsString($winner);
        $this->assertNull($loser);
        $this->assertSame(1, (int) $this->balance($fixture['document'])->payment_request_attempts);
        $this->assertTrue(Hash::check($winner, (string) $fixture['document']->refresh()->access_token_hash), 'only the winner rotated the link');
    }

    public function test_an_unreported_claim_is_honoured_for_the_lease_then_retried_and_the_stale_job_sends_nothing(): void
    {
        $fixture = $this->depositPaid(2);
        $this->dueArrives();
        Bus::fake([SendDocumentBalanceRequestEmail::class]);

        $this->assertSame(1, $this->sweep());
        $this->assertSame(0, $this->sweep(), 'a fresh claim blocks a concurrent sweep');
        Bus::assertDispatchedTimes(SendDocumentBalanceRequestEmail::class, 1);

        $firstToken = $this->dispatchedToken(0);

        // The worker never reported back; after the lease the item may be claimed again.
        Carbon::setTestNow(now()->addMinutes(31));
        $this->assertSame(1, $this->sweep());
        Bus::assertDispatchedTimes(SendDocumentBalanceRequestEmail::class, 2);
        $this->assertSame(2, (int) $this->balance($fixture['document'])->payment_request_attempts);
        $secondToken = $this->dispatchedToken(1);
        $this->assertNotSame($firstToken, $secondToken);

        // The first job finally runs: its link was rotated away, so it sends and records nothing.
        $this->runJob($fixture['document'], $firstToken);
        $this->assertRequestsSent(0);
        $this->assertNull($this->balance($fixture['document'])->payment_request_sent_at);

        $this->runJob($fixture['document'], $secondToken);
        $this->assertRequestsSent(1);
        $this->assertNotNull($this->balance($fixture['document'])->payment_request_sent_at);
    }

    private function dispatchedToken(int $index): string
    {
        $jobs = [];
        Bus::assertDispatched(SendDocumentBalanceRequestEmail::class, function ($job) use (&$jobs) {
            $jobs[] = $job;

            return true;
        });

        return (string) (new ReflectionProperty($jobs[$index], 'plaintextToken'))->getValue($jobs[$index]);
    }

    // ---- fail closed -----------------------------------------------------------------------------------------

    public function test_a_balance_already_paid_deposit_unpaid_or_between_claim_and_delivery_sends_nothing(): void
    {
        // Balance already paid.
        $paid = $this->depositPaid(2);
        $this->dueArrives();
        DB::table('business_document_payment_schedule_items')->where('id', $this->balance($paid['document'])->id)->update(['status' => 'paid', 'paid_at' => now()]);
        $this->assertSame(0, $this->sweep());

        // Deposit not yet paid.
        $unpaid = $this->depositPaid(2, payDeposit: false);
        Carbon::setTestNow(now()->addDays(5));
        $this->assertSame(0, $this->sweep());
        $this->assertRequestsSent(0);

        // Paid BETWEEN claim and delivery: the job re-checks and sends nothing.
        Carbon::setTestNow();
        $racing = $this->depositPaid(2);
        $this->dueArrives();
        Bus::fake([SendDocumentBalanceRequestEmail::class]);
        $this->assertSame(1, $this->sweep());
        $token = $this->dispatchedToken(0);
        DB::table('business_document_payment_schedule_items')->where('id', $this->balance($racing['document'])->id)->update(['status' => 'paid', 'paid_at' => now()]);
        $this->runJob($racing['document'], $token);
        $this->assertRequestsSent(0);
        $this->assertNull($this->balance($racing['document'])->payment_request_sent_at);
    }

    /** @return array<string, array{0: string}> */
    public static function closedStates(): array
    {
        return [
            'void' => ['void'],
            'expired' => ['expired'],
            'paid' => ['paid'],
            'draft' => ['draft'],
            'sent but unsigned while a signature is required' => ['unsigned'],
            'deposit refunded' => ['deposit_refunded'],
            'balance void' => ['balance_void'],
            'version superseded' => ['superseded'],
            'offer lapsed' => ['offer_lapsed'],
            'recipient email missing' => ['no_recipient'],
        ];
    }

    /** @dataProvider closedStates */
    public function test_a_document_that_is_not_payable_sends_nothing_and_claims_nothing(string $case): void
    {
        $fixture = $this->depositPaid(2);
        $document = $fixture['document'];
        $this->dueArrives();

        match ($case) {
            'void', 'expired', 'paid', 'draft' => DB::table('business_documents')->where('id', $document->id)->update(['status' => $case]),
            'unsigned' => DB::table('business_documents')->where('id', $document->id)->update(['status' => 'sent', 'requires_signature' => true]),
            'deposit_refunded' => DB::table('business_document_payment_schedule_items')->where('id', $this->deposit($document)->id)->update(['status' => 'refunded']),
            'balance_void' => DB::table('business_document_payment_schedule_items')->where('id', $this->balance($document)->id)->update(['status' => 'void']),
            'superseded' => DB::table('business_document_versions')->where('id', $document->current_version_id)->update(['state' => 'superseded']),
            'offer_lapsed' => DB::table('business_documents')->where('id', $document->id)->update(['expires_at' => now()->subMinute()]),
            'no_recipient' => DB::table('business_documents')->where('id', $document->id)->update(['recipient_email_snapshot' => null]),
        };

        $this->assertSame(0, $this->sweep());
        $this->assertRequestsSent(0);

        $item = BusinessDocumentPaymentScheduleItem::where('business_document_version_id', $document->current_version_id)->where('kind', 'balance')->sole();
        $this->assertSame(0, (int) $item->payment_request_attempts);
        $this->assertNull($item->payment_request_claimed_at);
        $this->assertTrue(Hash::check($fixture['token'], (string) $document->refresh()->access_token_hash), 'a refused item never rotates the link');
    }

    public function test_an_unentitled_or_inactive_account_sends_nothing_and_resumes_when_restored(): void
    {
        $fixture = $this->depositPaid(2);
        $tenant = $fixture['tenant'];
        $this->dueArrives();

        // Payments & Contracts denied through the REAL entitlement authority.
        app(EntitlementManager::class)->createOrChangeOverride(
            $tenant['workspace'], PlatformFeature::PaymentsContracts, WorkspaceEntitlementOverrideState::Deny,
            $this->platformAdminId(), 'Balance request test: deny the feature.',
        );
        $this->assertSame(0, $this->sweep());

        app(EntitlementManager::class)->createOrChangeOverride(
            $tenant['workspace'], PlatformFeature::PaymentsContracts, WorkspaceEntitlementOverrideState::Allow,
            $this->platformAdminId(), 'Balance request test: restore the feature.',
        );

        DB::table('businesses')->where('id', $tenant['business']->id)->update(['status' => 'inactive']);
        $this->assertSame(0, $this->sweep());
        DB::table('businesses')->where('id', $tenant['business']->id)->update(['status' => 'active']);

        DB::table('workspaces')->where('id', $tenant['workspace']->id)->update(['is_active' => false]);
        $this->assertSame(0, $this->sweep());
        DB::table('workspaces')->where('id', $tenant['workspace']->id)->update(['is_active' => true]);

        $this->assertRequestsSent(0);
        $this->assertSame(0, (int) $this->balance($fixture['document'])->payment_request_attempts, 'refusals did not use up an attempt');

        // Everything restored: the very same item is now sent, once.
        $this->assertSame(1, $this->sweep());
        $this->assertRequestsSent(1);
    }

    // ---- delivery failure + retry cap --------------------------------------------------------------------------

    private function failingNotifications(): object
    {
        $fake = new class extends NotificationFake {
            public bool $failing = true;

            public function sendNow($notifiables, $notification, ?array $channels = null)
            {
                if ($this->failing) {
                    throw new RuntimeException('mail transport down');
                }

                parent::sendNow($notifiables, $notification, $channels);
            }
        };
        Notification::swap($fake);

        return $fake;
    }

    public function test_a_failed_delivery_is_recorded_and_retried_until_the_cap_then_stops(): void
    {
        $fixture = $this->depositPaid(2);
        $fake = $this->failingNotifications();
        $this->dueArrives();

        foreach ([1, 2, 3] as $attempt) {
            $this->assertSame(1, $this->sweep(), "attempt {$attempt} is made");
            $item = $this->balance($fixture['document']);
            $this->assertSame($attempt, (int) $item->payment_request_attempts);
            $this->assertNotNull($item->payment_request_failed_at);
            $this->assertNull($item->payment_request_claimed_at, 'the claim is released so the next sweep may retry');
            $this->assertNull($item->payment_request_sent_at);
        }

        $this->assertSame(0, $this->sweep(), 'the attempts cap stops the retries');
        $this->assertSame(3, (int) $this->balance($fixture['document'])->payment_request_attempts);
        $this->assertNotNull($fixture['document']->refresh()->link_delivery_failed_at);
        $this->assertSame([], $fake->sentNotifications());
    }

    public function test_a_retry_after_a_failure_succeeds_once_and_clears_the_failure(): void
    {
        $fixture = $this->depositPaid(2);
        $fake = $this->failingNotifications();
        $this->dueArrives();

        $this->assertSame(1, $this->sweep());
        $this->assertNotNull($this->balance($fixture['document'])->payment_request_failed_at);

        $fake->failing = false;
        $this->assertSame(1, $this->sweep());
        $this->assertSame(0, $this->sweep());

        $item = $this->balance($fixture['document']);
        $this->assertNotNull($item->payment_request_sent_at);
        $this->assertNull($item->payment_request_failed_at);
        $this->assertSame(2, (int) $item->payment_request_attempts);
        $this->assertRequestsSent(1);
    }

    // ---- the Contact is not an input -----------------------------------------------------------------------------

    public function test_changing_the_contact_after_signing_changes_neither_the_due_date_nor_the_recipient(): void
    {
        $fixture = $this->depositPaid(2);
        $document = $fixture['document'];
        $tenant = $fixture['tenant'];
        $before = $this->balance($document)->only(['sequence', 'kind', 'amount_minor', 'currency_code', 'due_at']);

        DB::table('contacts')->where('id', $tenant['contact']->id)->update(['phone' => '14155550000']);
        // The Contact's name / email live in its group's custom fields.
        foreach (['FIRST_NAME' => 'Renamed', 'EMAIL' => 'moved@example.test'] as $tag => $value) {
            $field = ContactGroupFields::firstOrCreate(
                ['contact_group_id' => $tenant['contact']->group_id, 'tag' => $tag],
                ['label' => ucfirst(strtolower(str_replace('_', ' ', $tag))), 'type' => $tag === 'EMAIL' ? 'email' : 'text'],
            );
            ContactsCustomField::updateOrCreate(['field_id' => $field->id, 'contact_id' => $tenant['contact']->id], ['value' => $value]);
        }

        $this->dueArrives();
        $this->assertSame(1, $this->sweep());

        Notification::assertSentOnDemand(DocumentBalanceRequestNotification::class, fn ($n, $c, $notifiable) => $notifiable->routes['mail'] === 'client@example.test');
        $this->assertEquals($before, $this->balance($document)->only(['sequence', 'kind', 'amount_minor', 'currency_code', 'due_at']));
        $this->assertSame('client@example.test', $document->refresh()->recipient_email_snapshot);
    }

    // ---- content -----------------------------------------------------------------------------------------------------

    public function test_the_email_shows_the_frozen_amount_and_due_date_in_the_business_timezone_escapes_content_and_carries_no_card_data(): void
    {
        $fixture = $this->payableDocument([
            ['kind' => 'deposit', 'amount_minor' => 20000, 'currency_code' => 'USD'],
            ['kind' => 'balance', 'amount_minor' => 30000, 'currency_code' => 'USD',
                'due_at' => Carbon::parse('2030-06-01 23:59:59', 'America/New_York')->setTimezone(config('app.timezone'))->toDateTimeString()],
        ]);
        DB::table('businesses')->where('id', $fixture['tenant']['business']->id)->update(['timezone' => 'America/New_York', 'name' => 'Snap & <b>Co</b>']);
        DB::table('business_documents')->where('id', $fixture['document']->id)->update(['title' => 'Booth <script>alert(1)</script>']);
        $this->captureNextItem($fixture);
        Notification::fake();

        Carbon::setTestNow(Carbon::parse('2030-06-02 12:00:00', 'UTC'));
        $this->assertSame(1, $this->sweep());

        [$notification] = $this->lastRequest();
        $html = (string) $notification->toMail(new AnonymousNotifiable())->render();

        $this->assertStringContainsString('300.00 USD', $html);
        $this->assertStringContainsString('1 June 2030', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('Snap &amp; &lt;b&gt;Co&lt;/b&gt;', $html);
        foreach (['card number', 'cvc', 'cvv', 'client_secret', 'pi_', 'acct_'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $html);
        }
    }

    public function test_no_text_message_is_sent_even_when_the_original_send_used_sms(): void
    {
        $fixture = $this->depositPaid(2);
        DB::table('business_documents')->where('id', $fixture['document']->id)->update(['sms_link_delivered_at' => now()]);
        $this->dueArrives();
        Bus::fake([SendDocumentLinkSms::class]);

        $this->assertSame(1, $this->sweep());

        Bus::assertNotDispatched(SendDocumentLinkSms::class);
        $this->assertRequestsSent(1);
    }
}

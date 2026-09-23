<?php

namespace Tests\Feature\Documents;

use App\Library\Documents\DocumentManager;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentPaymentScheduleItem;
use App\Notifications\Documents\DocumentReminderNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Payments\Concerns\CreatesRefundablePayments;
use Tests\TestCase;

/**
 * Implementation Contract 17 §8.4 — reminders are deduped by DURABLE MARKERS
 * the manager writes under the row lock, target only the currently payable
 * version, and are email-only.
 */
class DocumentReminderSweepTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRefundablePayments;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindFakeGateway();
        $this->allowPaymentEntitlement();
        config(['documents.enabled' => true, 'documents.reminder_offsets_days' => [3, 1]]);
        Notification::fake();
    }

    /** An INVOICE with real due dates, payable while merely `sent`. */
    private function sentInvoice(array $tenant, array $schedule): array
    {
        $manager = app(DocumentManager::class);
        $document = $manager->create($tenant['business'], $tenant['location'], $tenant['contact'], null,
            'invoice', 'Kitchen invoice', $tenant['customer']->user);
        $manager->addCustomLine($document, 'Work', null, 1, 50000);
        $manager->setSchedule($document, $schedule);
        $manager->edit($document, ['recipient_email_snapshot' => 'client@example.test']);
        [$document, $token] = $this->sendAndCaptureToken($document->refresh());

        return ['tenant' => $tenant, 'document' => $document->refresh(), 'token' => $token];
    }

    /** @return array<int, array<string, mixed>> */
    private function fullDueIn(int $days): array
    {
        return [['kind' => 'full', 'amount_minor' => 50000, 'currency_code' => 'USD',
            'due_at' => now()->addDays($days)->toDateTimeString()]];
    }

    /** @return array<int, array<string, mixed>> */
    private function depositAndBalanceDueIn(int $days): array
    {
        return [
            ['kind' => 'deposit', 'amount_minor' => 20000, 'currency_code' => 'USD', 'due_at' => now()->addDays($days)->toDateTimeString()],
            ['kind' => 'balance', 'amount_minor' => 30000, 'currency_code' => 'USD', 'due_at' => now()->addDays($days)->toDateTimeString()],
        ];
    }

    // =================================================================
    // §8.4 — the durable marker is what dedupes
    // =================================================================

    public function test_a_due_payment_reminder_is_sent_and_the_marker_is_written(): void
    {
        $fixture = $this->sentInvoice($this->sendableTenant(), $this->fullDueIn(2));

        $this->assertSame(1, app(DocumentManager::class)->dispatchDueReminders(100));

        $item = BusinessDocumentPaymentScheduleItem::query()->sole();
        $this->assertSame(1, (int) $item->reminder_count);
        $this->assertNotNull($item->reminder_last_sent_at);
        Notification::assertSentOnDemandTimes(DocumentReminderNotification::class, 1);
    }

    public function test_a_rerun_in_the_same_window_sends_nothing(): void
    {
        $this->sentInvoice($this->sendableTenant(), $this->fullDueIn(2));
        $manager = app(DocumentManager::class);

        $this->assertSame(1, $manager->dispatchDueReminders(100));
        $marker = BusinessDocumentPaymentScheduleItem::query()->sole()->reminder_last_sent_at;

        $this->assertSame(0, $manager->dispatchDueReminders(100));
        $this->assertSame(0, $manager->dispatchDueReminders(100));

        $this->assertEquals($marker, BusinessDocumentPaymentScheduleItem::query()->sole()->reminder_last_sent_at);
        Notification::assertSentOnDemandTimes(DocumentReminderNotification::class, 1);
    }

    public function test_the_next_window_sends_exactly_one_more(): void
    {
        $this->sentInvoice($this->sendableTenant(), $this->fullDueIn(2));
        $manager = app(DocumentManager::class);

        $this->assertSame(1, $manager->dispatchDueReminders(100));

        // Into the one-day window.
        Carbon::setTestNow(now()->addDays(1.5));
        $this->assertSame(1, $manager->dispatchDueReminders(100));
        $this->assertSame(0, $manager->dispatchDueReminders(100));

        $this->assertSame(2, (int) BusinessDocumentPaymentScheduleItem::query()->sole()->reminder_count);
        Notification::assertSentOnDemandTimes(DocumentReminderNotification::class, 2);

        Carbon::setTestNow();
    }

    public function test_several_already_open_windows_produce_one_reminder_not_a_backlog(): void
    {
        // Due in twelve hours: BOTH the three-day and the one-day window are
        // already open the first time this document is ever seen.
        $this->sentInvoice($this->sendableTenant(), [[
            'kind' => 'full', 'amount_minor' => 50000, 'currency_code' => 'USD',
            'due_at' => now()->addHours(12)->toDateTimeString(),
        ]]);
        $manager = app(DocumentManager::class);

        $this->assertSame(1, $manager->dispatchDueReminders(100));
        $this->assertSame(0, $manager->dispatchDueReminders(100));

        $this->assertSame(2, (int) BusinessDocumentPaymentScheduleItem::query()->sole()->reminder_count,
            'The count jumps to the latest open window rather than replaying the earlier one.');
        Notification::assertSentOnDemandTimes(DocumentReminderNotification::class, 1);
    }

    public function test_an_item_due_beyond_every_window_is_not_reminded(): void
    {
        $this->sentInvoice($this->sendableTenant(), $this->fullDueIn(30));

        $this->assertSame(0, app(DocumentManager::class)->dispatchDueReminders(100));
        Notification::assertSentOnDemandTimes(DocumentReminderNotification::class, 0);
    }

    public function test_an_item_with_no_due_date_is_never_reminded(): void
    {
        $this->sentInvoice($this->sendableTenant(), [
            ['kind' => 'full', 'amount_minor' => 50000, 'currency_code' => 'USD'],
        ]);

        $this->assertSame(0, app(DocumentManager::class)->dispatchDueReminders(100));
        Notification::assertSentOnDemandTimes(DocumentReminderNotification::class, 0);
    }

    // =================================================================
    // §5.9 — the CURRENT version only
    // =================================================================

    public function test_a_superseded_versions_item_is_never_reminded(): void
    {
        $tenant = $this->sendableTenant();
        $fixture = $this->sentInvoice($tenant, $this->fullDueIn(2));
        $oldItemId = (int) BusinessDocumentPaymentScheduleItem::query()->sole()->id;

        app(DocumentManager::class)->revise($fixture['document']->refresh(), $tenant['customer']->user);
        $this->sendAndCaptureToken($fixture['document']->refresh());

        $this->assertSame(1, app(DocumentManager::class)->dispatchDueReminders(100));

        $old = BusinessDocumentPaymentScheduleItem::query()->findOrFail($oldItemId);
        $this->assertSame(0, (int) $old->reminder_count, 'A superseded term is never reminded.');
        $this->assertNull($old->reminder_last_sent_at);

        $current = BusinessDocumentPaymentScheduleItem::query()
            ->where('business_document_version_id', $fixture['document']->refresh()->current_version_id)->sole();
        $this->assertSame(1, (int) $current->reminder_count);
        Notification::assertSentOnDemandTimes(DocumentReminderNotification::class, 1);
    }

    // =================================================================
    // Deposit / balance semantics
    // =================================================================

    public function test_only_the_currently_payable_item_is_reminded(): void
    {
        $this->sentInvoice($this->sendableTenant(), $this->depositAndBalanceDueIn(2));

        $this->assertSame(1, app(DocumentManager::class)->dispatchDueReminders(100));

        $schedule = BusinessDocumentPaymentScheduleItem::query()->orderBy('sequence')->get();
        $this->assertSame(1, (int) $schedule[0]->reminder_count, 'The deposit is what is owed now.');
        $this->assertSame(0, (int) $schedule[1]->reminder_count, 'The balance behind it is not chased yet.');
        Notification::assertSentOnDemandTimes(DocumentReminderNotification::class, 1);
    }

    public function test_a_deposit_paid_document_still_receives_balance_reminders(): void
    {
        $tenant = $this->sendableTenant();
        $this->chargeReadyConnection($tenant['business']);
        $fixture = $this->sentInvoice($tenant, $this->depositAndBalanceDueIn(2));

        $deposit = $this->captureNextItem($fixture);
        $this->assertSame(20000, (int) $deposit->amount_minor);

        $this->assertSame(1, app(DocumentManager::class)->dispatchDueReminders(100));

        $schedule = BusinessDocumentPaymentScheduleItem::query()->orderBy('sequence')->get();
        $this->assertSame(0, (int) $schedule[0]->reminder_count, 'A settled deposit is never chased.');
        $this->assertSame(1, (int) $schedule[1]->reminder_count, 'The balance is now what is owed.');
        Notification::assertSentOnDemandTimes(DocumentReminderNotification::class, 1);
    }

    // =================================================================
    // §8.6/§8.4 — the offer-expiry warning and its own marker
    // =================================================================

    public function test_an_approaching_offer_expiry_warns_once_per_window(): void
    {
        $fixture = $this->sentInvoice($this->sendableTenant(), $this->fullDueIn(30));
        DB::table('business_documents')->where('id', $fixture['document']->id)
            ->update(['expires_at' => now()->addDays(2)->toDateTimeString()]);
        $manager = app(DocumentManager::class);

        $this->assertSame(1, $manager->dispatchDueReminders(100));
        $this->assertSame(0, $manager->dispatchDueReminders(100));

        $document = $fixture['document']->refresh();
        $this->assertSame(1, (int) $document->expiry_reminder_count);
        $this->assertNotNull($document->expiry_reminder_last_sent_at);
        Notification::assertSentOnDemandTimes(DocumentReminderNotification::class, 1);
    }

    public function test_a_document_that_can_no_longer_expire_gets_no_expiry_warning(): void
    {
        $tenant = $this->sendableTenant();
        $this->chargeReadyConnection($tenant['business']);
        $fixture = $this->sentInvoice($tenant, $this->depositAndBalanceDueIn(30));
        $this->captureNextItem($fixture);

        DB::table('business_documents')->where('id', $fixture['document']->id)
            ->update(['expires_at' => now()->addDays(2)->toDateTimeString()]);

        $this->assertSame(0, app(DocumentManager::class)->dispatchDueReminders(100));
        $this->assertSame(0, (int) $fixture['document']->refresh()->expiry_reminder_count,
            '§8.6 — a partially paid document never expires, so warning about it would be false.');
        Notification::assertSentOnDemandTimes(DocumentReminderNotification::class, 0);
    }

    public function test_a_terminal_document_is_never_reminded(): void
    {
        $fixture = $this->sentInvoice($this->sendableTenant(), $this->fullDueIn(2));
        app(DocumentManager::class)->void($fixture['document'], 'Cancelled.');

        $this->assertSame(0, app(DocumentManager::class)->dispatchDueReminders(100));
        Notification::assertSentOnDemandTimes(DocumentReminderNotification::class, 0);
    }

    public function test_an_empty_offsets_configuration_sends_nothing(): void
    {
        config(['documents.reminder_offsets_days' => []]);
        $this->sentInvoice($this->sendableTenant(), $this->fullDueIn(1));

        $this->assertSame(0, app(DocumentManager::class)->dispatchDueReminders(100));
        Notification::assertSentOnDemandTimes(DocumentReminderNotification::class, 0);
    }

    public function test_the_batch_is_bounded_by_the_limit(): void
    {
        $tenant = $this->sendableTenant();
        foreach (range(1, 3) as $ignored) {
            $this->sentInvoice($tenant, $this->fullDueIn(2));
        }

        $manager = app(DocumentManager::class);
        $this->assertSame(2, $manager->dispatchDueReminders(2));
        $this->assertSame(1, $manager->dispatchDueReminders(2));
        $this->assertSame(0, $manager->dispatchDueReminders(2));
    }

    public function test_the_reminder_is_email_only_and_carries_no_secure_link(): void
    {
        $fixture = $this->sentInvoice($this->sendableTenant(), $this->fullDueIn(2));
        app(DocumentManager::class)->dispatchDueReminders(100);

        Notification::assertSentOnDemand(DocumentReminderNotification::class,
            function (DocumentReminderNotification $notification, array $channels, object $notifiable) use ($fixture) {
                $this->assertSame(['mail'], $channels, '§11.3 — email only.');
                $this->assertSame(['mail' => 'client@example.test'], $notifiable->routes);

                $mail = $notification->toMail($notifiable);
                $this->assertNull($mail->actionUrl, 'The plaintext token is unrecoverable, so no link is rebuilt.');
                $rendered = implode("\n", array_merge($mail->introLines, $mail->outroLines));
                $this->assertStringNotContainsString((string) BusinessDocument::query()
                    ->findOrFail($fixture['document']->id)->uid, $rendered);

                return true;
            });
    }
}

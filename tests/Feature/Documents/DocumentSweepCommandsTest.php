<?php

namespace Tests\Feature\Documents;

use App\Console\Kernel;
use App\Enums\Documents\BusinessDocumentPaymentStatus;
use App\Enums\Documents\DocumentStatus;
use App\Events\DocumentExpired;
use App\Library\Documents\DocumentManager;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentPayment;
use App\Models\BusinessDocumentPaymentScheduleItem;
use App\Notifications\Documents\DocumentReminderNotification;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use ReflectionMethod;
use RuntimeException;
use Tests\Feature\Payments\Concerns\CreatesRefundablePayments;
use Tests\TestCase;

/**
 * Implementation Contract 17 §12.F — the three scheduled commands, held to the
 * SweepExpiredOpportunitySnoozes convention: the command owns the flag gate,
 * the `--limit` validation and `self::INVALID`; the manager owns the rows; the
 * schedule registers all three unconditionally.
 */
class DocumentSweepCommandsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRefundablePayments;

    private const COMMANDS = [
        'documents:expire-due',
        'documents:dispatch-due-reminders',
        'documents:reconcile-stale-payments',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindFakeGateway();
        $this->allowPaymentEntitlement();
        config(['documents.enabled' => true, 'documents.reminder_offsets_days' => [3, 1]]);
    }

    /** An INVOICE, expirable and remindable while merely `sent`. */
    private function sentInvoice(array $tenant, ?array $schedule = null): array
    {
        $manager = app(DocumentManager::class);
        $document = $manager->create($tenant['business'], $tenant['location'], $tenant['contact'], null,
            'invoice', 'Kitchen invoice', $tenant['customer']->user);
        $manager->addCustomLine($document, 'Work', null, 1, 50000);
        $manager->setSchedule($document, $schedule ?? [['kind' => 'full', 'amount_minor' => 50000, 'currency_code' => 'USD']]);
        $manager->edit($document, ['recipient_email_snapshot' => 'client@example.test']);
        [$document, $token] = $this->sendAndCaptureToken($document->refresh());

        return ['tenant' => $tenant, 'document' => $document->refresh(), 'token' => $token];
    }

    private function expiredInvoice(array $tenant): BusinessDocument
    {
        $fixture = $this->sentInvoice($tenant);
        DB::table('business_documents')->where('id', $fixture['document']->id)
            ->update(['expires_at' => now()->subDay()->toDateTimeString()]);

        return $fixture['document'];
    }

    private function dueInvoice(array $tenant): BusinessDocument
    {
        return $this->sentInvoice($tenant, [[
            'kind' => 'full', 'amount_minor' => 50000, 'currency_code' => 'USD',
            'due_at' => now()->addDays(2)->toDateTimeString(),
        ]])['document'];
    }

    // =================================================================
    // Enabled — exit code and exact output
    // =================================================================

    public function test_the_expiry_command_reports_what_it_expired(): void
    {
        $tenant = $this->sendableTenant();
        $this->expiredInvoice($tenant);
        $this->expiredInvoice($tenant);

        $this->artisan('documents:expire-due')
            ->expectsOutput('Expired 2 document(s).')
            ->assertExitCode(0);
    }

    public function test_the_reminder_command_reports_what_it_dispatched(): void
    {
        Notification::fake();
        $this->dueInvoice($this->sendableTenant());

        $this->artisan('documents:dispatch-due-reminders')
            ->expectsOutput('Dispatched 1 document reminder(s).')
            ->assertExitCode(0);

        Notification::assertSentOnDemandTimes(DocumentReminderNotification::class, 1);
    }

    public function test_the_reconciliation_command_reports_what_it_reconciled(): void
    {
        $fixture = $this->payableDocument();
        app(\App\Library\Payments\PaymentManager::class)->start($this->accessFor($fixture['document'], $fixture['token']));
        $payment = BusinessDocumentPayment::query()->sole();
        DB::table('business_document_payments')->where('id', $payment->id)
            ->update(['updated_at' => now()->subHours(3)]);
        $this->gateway->setIntentStatus((string) $payment->provider_payment_intent_id,
            BusinessDocumentPaymentStatus::Succeeded);

        $this->artisan('documents:reconcile-stale-payments')
            ->expectsOutput('Reconciled 1 stale payment attempt(s).')
            ->assertExitCode(0);

        $this->assertSame(BusinessDocumentPaymentStatus::Succeeded, $payment->refresh()->status);
    }

    // =================================================================
    // `--limit`
    // =================================================================

    public function test_every_command_declares_an_optional_limit(): void
    {
        foreach (self::COMMANDS as $name) {
            $option = Artisan::all()[$name]->getDefinition()->getOption('limit');

            $this->assertTrue($option->acceptValue(), "{$name} takes a --limit value.");
            $this->assertNull($option->getDefault(), "{$name} falls back to its config limit, not a baked default.");
        }
    }

    public function test_an_absent_limit_falls_back_to_the_config_limit(): void
    {
        config(['documents.expire_sweep_limit' => 1]);
        $tenant = $this->sendableTenant();
        $this->expiredInvoice($tenant);
        $this->expiredInvoice($tenant);

        $this->artisan('documents:expire-due')
            ->expectsOutput('Expired 1 document(s).')
            ->assertExitCode(0);
    }

    public function test_an_explicit_limit_is_honored(): void
    {
        $tenant = $this->sendableTenant();
        $this->expiredInvoice($tenant);
        $this->expiredInvoice($tenant);
        $this->expiredInvoice($tenant);

        $this->artisan('documents:expire-due', ['--limit' => 2])
            ->expectsOutput('Expired 2 document(s).')
            ->assertExitCode(0);

        $this->assertSame(1, BusinessDocument::query()->where('status', DocumentStatus::Sent->value)->count());
    }

    /**
     * @param  string  $limit
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidLimits')]
    public function test_every_invalid_limit_form_is_refused_by_every_command($limit): void
    {
        foreach (self::COMMANDS as $name) {
            $this->artisan($name, ['--limit' => $limit])
                ->expectsOutput('The --limit option must be a positive integer.')
                ->assertExitCode(2);
        }
    }

    /** @return array<string, array<int, string>> */
    public static function invalidLimits(): array
    {
        return [
            'zero' => ['0'],
            'negative' => ['-5'],
            'decimal' => ['5.5'],
            'alphabetic' => ['abc'],
            'blank' => [''],
            'whitespace' => [' '],
            'leading plus' => ['+5'],
            'hex' => ['0x10'],
        ];
    }

    public function test_an_invalid_limit_mutates_nothing(): void
    {
        $tenant = $this->sendableTenant();
        $document = $this->expiredInvoice($tenant);

        $this->artisan('documents:expire-due', ['--limit' => 'abc'])->assertExitCode(2);

        $this->assertSame(DocumentStatus::Sent, $document->refresh()->status);
        $this->assertNull($document->refresh()->expired_at);
    }

    // =================================================================
    // Disabled — deterministic no-op, zero mutation, zero provider calls
    // =================================================================

    public function test_each_disabled_command_prints_its_exact_skip_message(): void
    {
        config(['documents.enabled' => false]);

        $this->artisan('documents:expire-due')
            ->expectsOutput('Payments & Contracts is disabled; document expiry sweep skipped.')
            ->assertExitCode(0);
        $this->artisan('documents:dispatch-due-reminders')
            ->expectsOutput('Payments & Contracts is disabled; document reminder sweep skipped.')
            ->assertExitCode(0);
        $this->artisan('documents:reconcile-stale-payments')
            ->expectsOutput('Payments & Contracts is disabled; stale payment reconciliation skipped.')
            ->assertExitCode(0);
    }

    public function test_disabled_commands_mutate_nothing_and_touch_no_provider(): void
    {
        Notification::fake();
        $tenant = $this->sendableTenant();
        $expired = $this->expiredInvoice($tenant);
        $due = $this->dueInvoice($tenant);

        $fixture = $this->payableDocument();
        app(\App\Library\Payments\PaymentManager::class)->start($this->accessFor($fixture['document'], $fixture['token']));
        $payment = BusinessDocumentPayment::query()->sole();
        DB::table('business_document_payments')->where('id', $payment->id)
            ->update(['updated_at' => now()->subHours(3)]);
        $this->gateway->setIntentStatus((string) $payment->provider_payment_intent_id,
            BusinessDocumentPaymentStatus::Succeeded);

        config(['documents.enabled' => false]);
        $providerCallsBefore = count($this->gateway->calls);
        $before = md5(DB::table('business_documents')->orderBy('id')->get()->toJson()
            . DB::table('business_document_payment_schedule_items')->orderBy('id')->get()->toJson()
            . DB::table('business_document_payments')->orderBy('id')->get()->toJson());

        foreach (self::COMMANDS as $name) {
            $this->artisan($name)->assertExitCode(0);
        }

        $this->assertSame($providerCallsBefore, count($this->gateway->calls), 'A disabled sweep never reaches Stripe.');
        $this->assertSame($before, md5(DB::table('business_documents')->orderBy('id')->get()->toJson()
            . DB::table('business_document_payment_schedule_items')->orderBy('id')->get()->toJson()
            . DB::table('business_document_payments')->orderBy('id')->get()->toJson()));
        $this->assertSame(DocumentStatus::Sent, $expired->refresh()->status);
        $this->assertSame(0, (int) BusinessDocumentPaymentScheduleItem::query()
            ->where('business_document_version_id', $due->refresh()->current_version_id)->sole()->reminder_count);
        Notification::assertSentOnDemandTimes(DocumentReminderNotification::class, 0);
    }

    // =================================================================
    // Double runs
    // =================================================================

    public function test_running_every_command_twice_changes_nothing_the_second_time(): void
    {
        Notification::fake();
        $tenant = $this->sendableTenant();
        $this->expiredInvoice($tenant);
        $this->dueInvoice($tenant);

        foreach (self::COMMANDS as $name) {
            $this->artisan($name)->assertExitCode(0);
        }

        $this->artisan('documents:expire-due')->expectsOutput('Expired 0 document(s).')->assertExitCode(0);
        $this->artisan('documents:dispatch-due-reminders')->expectsOutput('Dispatched 0 document reminder(s).')->assertExitCode(0);
        $this->artisan('documents:reconcile-stale-payments')->expectsOutput('Reconciled 0 stale payment attempt(s).')->assertExitCode(0);

        Notification::assertSentOnDemandTimes(DocumentReminderNotification::class, 1);
    }

    // =================================================================
    // §12.F — a Throwable on one row does not abort the batch
    // =================================================================

    public function test_a_failure_on_one_row_does_not_stop_the_sweep(): void
    {
        $tenant = $this->sendableTenant();
        $first = $this->expiredInvoice($tenant);
        $second = $this->expiredInvoice($tenant);

        Event::listen(DocumentExpired::class, function (DocumentExpired $event) use ($first) {
            if ($event->documentId === (int) $first->id) {
                throw new RuntimeException('Downstream listener exploded.');
            }
        });

        $this->artisan('documents:expire-due')->assertExitCode(0);

        $this->assertSame(DocumentStatus::Expired, $first->refresh()->status);
        $this->assertSame(DocumentStatus::Expired, $second->refresh()->status,
            'The second row is still swept after the first one blew up.');
    }

    // =================================================================
    // Schedule registration
    // =================================================================

    private ?Schedule $registeredSchedule = null;

    /**
     * Kernel::schedule() stays protected — production visibility is not
     * relaxed for testing. It is invoked ONCE per test through reflection,
     * because Schedule is a singleton and re-invoking it would register every
     * command a second time and make "exactly once" unprovable.
     *
     * @return array<int, ScheduledEvent>
     */
    private function scheduledEventsFor(string $command): array
    {
        if ($this->registeredSchedule === null) {
            // A FRESH Schedule, not the container singleton: constructing a
            // Kernel already registers everything on that singleton through
            // its booted callback, so reusing it would count every command
            // twice and make "exactly once" meaningless.
            $this->registeredSchedule = new Schedule();
            $method = new ReflectionMethod(Kernel::class, 'schedule');
            $method->setAccessible(true);
            $method->invoke(app(Kernel::class), $this->registeredSchedule);
        }

        return array_values(array_filter($this->registeredSchedule->events(),
            fn (ScheduledEvent $event) => str_contains($event->command ?? '', $command)));
    }

    public function test_every_command_is_registered_exactly_once(): void
    {
        foreach (self::COMMANDS as $name) {
            $this->assertCount(1, $this->scheduledEventsFor($name), "{$name} is registered once.");
        }
    }

    public function test_registration_is_unconditional(): void
    {
        config(['documents.enabled' => false]);

        foreach (self::COMMANDS as $name) {
            $this->assertCount(1, $this->scheduledEventsFor($name),
                "{$name} stays registered while the feature is off — the command owns the no-op.");
        }
    }

    public function test_no_unrelated_scheduler_options_are_attached(): void
    {
        foreach (self::COMMANDS as $name) {
            $event = $this->scheduledEventsFor($name)[0];

            $this->assertFalse($event->withoutOverlapping);
            $this->assertFalse($event->runInBackground);
        }
    }

    public function test_each_command_has_its_own_cadence(): void
    {
        $this->assertSame('*/15 * * * *', $this->scheduledEventsFor('documents:expire-due')[0]->expression);
        $this->assertSame('0 * * * *', $this->scheduledEventsFor('documents:dispatch-due-reminders')[0]->expression);
        $this->assertSame('*/5 * * * *', $this->scheduledEventsFor('documents:reconcile-stale-payments')[0]->expression);
    }
}

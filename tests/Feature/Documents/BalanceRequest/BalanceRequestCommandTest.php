<?php

namespace Tests\Feature\Documents\BalanceRequest;

use App\Console\Kernel;
use App\Models\BusinessDocumentPaymentScheduleItem;
use App\Notifications\Documents\DocumentBalanceRequestNotification;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use ReflectionMethod;
use Tests\Feature\Payments\Concerns\CreatesRefundablePayments;
use Tests\TestCase;

/**
 * Contract 17B §3/§7 — documents:dispatch-balance-requests: the command owns
 * the flag gate and `--limit`, the schedule registers it hourly and
 * unconditionally, and a due balance is requested through the real command.
 */
class BalanceRequestCommandTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRefundablePayments;

    private const NAME = 'documents:dispatch-balance-requests';

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

    /** A signed deposit+balance document with the deposit paid and the balance due in two days. */
    private function dueSoon(): array
    {
        $fixture = $this->payableDocument([
            ['kind' => 'deposit', 'amount_minor' => 20000, 'currency_code' => 'USD'],
            ['kind' => 'balance', 'amount_minor' => 30000, 'currency_code' => 'USD', 'due_at' => now()->addDays(2)->toDateTimeString()],
        ]);
        $this->captureNextItem($fixture);
        Notification::fake();

        return $fixture;
    }

    /** @return array<int, ScheduledEvent> */
    private function scheduled(): array
    {
        $schedule = new Schedule();
        $method = new ReflectionMethod(Kernel::class, 'schedule');
        $method->setAccessible(true);
        $method->invoke(app(Kernel::class), $schedule);

        return array_values(array_filter($schedule->events(), fn (ScheduledEvent $e) => str_contains($e->command ?? '', self::NAME)));
    }

    public function test_the_command_requests_a_due_balance_and_reports_it(): void
    {
        $fixture = $this->dueSoon();
        Carbon::setTestNow(now()->addDays(3));

        $this->artisan(self::NAME)->expectsOutput('Dispatched 1 balance payment request(s).')->assertExitCode(0);
        $this->artisan(self::NAME)->expectsOutput('Dispatched 0 balance payment request(s).')->assertExitCode(0);

        Notification::assertSentOnDemandTimes(DocumentBalanceRequestNotification::class, 1);
        $this->assertNotNull(BusinessDocumentPaymentScheduleItem::where('kind', 'balance')->sole()->payment_request_sent_at);
    }

    public function test_the_command_is_registered_once_hourly_unconditionally_and_without_overlapping(): void
    {
        $events = $this->scheduled();
        $this->assertCount(1, $events);
        $this->assertSame('0 * * * *', $events[0]->expression);
        $this->assertTrue($events[0]->withoutOverlapping);
        $this->assertFalse($events[0]->runInBackground);

        config(['documents.enabled' => false]);
        $this->assertCount(1, $this->scheduled(), 'registered while the feature is off — the command owns the no-op');
    }

    public function test_disabled_the_command_skips_and_sends_nothing_even_when_a_balance_is_due(): void
    {
        $this->dueSoon();
        Carbon::setTestNow(now()->addDays(3));
        config(['documents.enabled' => false]);

        $this->artisan(self::NAME)->expectsOutput('Payments & Contracts is disabled; balance payment request sweep skipped.')->assertExitCode(0);

        Notification::assertSentOnDemandTimes(DocumentBalanceRequestNotification::class, 0);
        $item = BusinessDocumentPaymentScheduleItem::where('kind', 'balance')->sole();
        $this->assertNull($item->payment_request_claimed_at);
        $this->assertSame(0, (int) $item->payment_request_attempts);
    }

    public function test_the_limit_option_is_declared_validated_and_honoured(): void
    {
        $option = Artisan::all()[self::NAME]->getDefinition()->getOption('limit');
        $this->assertTrue($option->acceptValue());
        $this->assertNull($option->getDefault());

        foreach (['0', '-1', 'abc', '1.5', ''] as $bad) {
            $this->artisan(self::NAME, ['--limit' => $bad])->expectsOutput('The --limit option must be a positive integer.')->assertExitCode(2);
        }

        $this->dueSoon();
        $this->dueSoon();
        Carbon::setTestNow(now()->addDays(3));

        $this->artisan(self::NAME, ['--limit' => '1'])->expectsOutput('Dispatched 1 balance payment request(s).')->assertExitCode(0);
        $this->artisan(self::NAME, ['--limit' => '5'])->expectsOutput('Dispatched 1 balance payment request(s).')->assertExitCode(0);
        Notification::assertSentOnDemandTimes(DocumentBalanceRequestNotification::class, 2);
    }
}

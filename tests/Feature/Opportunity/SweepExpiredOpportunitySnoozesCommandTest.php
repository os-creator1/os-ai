<?php

namespace Tests\Feature\Opportunity;

use App\Enums\Opportunity\OpportunityStatus;
use App\Library\Opportunity\OpportunityManager;
use App\Models\Business;
use App\Models\Opportunity;
use App\Models\OpportunityTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Tests\Feature\Opportunity\Concerns\CreatesOpportunityTestData;
use Tests\TestCase;

class SweepExpiredOpportunitySnoozesCommandTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOpportunityTestData;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('opportunity.enabled', true);
    }

    public function test_enabled_command_reopens_expired_snoozes(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->expiredSnoozedOpportunity($business);

        $this->artisan('opportunity:sweep-expired-snoozes')->assertExitCode(0);

        $this->assertSame(OpportunityStatus::Open, $opportunity->fresh()->status);
    }

    public function test_enabled_command_exits_with_success(): void
    {
        $business = $this->createBusinessForOpportunities();
        $this->expiredSnoozedOpportunity($business);

        $this->artisan('opportunity:sweep-expired-snoozes')->assertExitCode(0);
    }

    public function test_exact_success_output_contains_the_reopened_count(): void
    {
        $business = $this->createBusinessForOpportunities();
        $this->expiredSnoozedOpportunity($business);
        $this->expiredSnoozedOpportunity($business);

        $this->artisan('opportunity:sweep-expired-snoozes')
            ->expectsOutput('Reopened 2 expired opportunity snooze(s).')
            ->assertExitCode(0);
    }

    public function test_default_limit_is_100(): void
    {
        $command = Artisan::all()['opportunity:sweep-expired-snoozes'];
        $default = $command->getDefinition()->getOption('limit')->getDefault();

        $this->assertSame('100', $default);
    }

    public function test_explicit_limit_is_honored(): void
    {
        $business = $this->createBusinessForOpportunities();
        for ($i = 0; $i < 5; $i++) {
            $this->expiredSnoozedOpportunity($business);
        }

        $this->artisan('opportunity:sweep-expired-snoozes', ['--limit' => 3])
            ->expectsOutput('Reopened 3 expired opportunity snooze(s).')
            ->assertExitCode(0);

        $this->assertSame(3, Opportunity::where('business_id', $business->id)->where('status', OpportunityStatus::Open->value)->count());
        $this->assertSame(2, Opportunity::where('business_id', $business->id)->where('status', OpportunityStatus::Snoozed->value)->count());
    }

    public function test_limit_of_1_processes_only_one_candidate(): void
    {
        $business = $this->createBusinessForOpportunities();
        $this->expiredSnoozedOpportunity($business);
        $this->expiredSnoozedOpportunity($business);

        $this->artisan('opportunity:sweep-expired-snoozes', ['--limit' => 1])
            ->expectsOutput('Reopened 1 expired opportunity snooze(s).')
            ->assertExitCode(0);

        $this->assertSame(1, Opportunity::where('business_id', $business->id)->where('status', OpportunityStatus::Open->value)->count());
    }

    public function test_running_the_command_twice_is_idempotent(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->expiredSnoozedOpportunity($business);

        $this->artisan('opportunity:sweep-expired-snoozes')
            ->expectsOutput('Reopened 1 expired opportunity snooze(s).')
            ->assertExitCode(0);

        $this->artisan('opportunity:sweep-expired-snoozes')
            ->expectsOutput('Reopened 0 expired opportunity snooze(s).')
            ->assertExitCode(0);

        $this->assertSame(1, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());
    }

    public function test_disabled_command_exits_successfully_with_the_exact_skipped_message_and_no_mutation(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->expiredSnoozedOpportunity($business);

        config()->set('opportunity.enabled', false);

        $this->artisan('opportunity:sweep-expired-snoozes')
            ->expectsOutput('Opportunity engine is disabled; expired snooze sweep skipped.')
            ->assertExitCode(0);

        $updated = $opportunity->fresh();
        $this->assertSame(OpportunityStatus::Snoozed, $updated->status);
        $this->assertNotNull($updated->snoozed_until);
        $this->assertSame(0, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());
    }

    public function test_disabled_command_does_not_invoke_the_manager(): void
    {
        config()->set('opportunity.enabled', false);

        $this->app->bind(OpportunityManager::class, fn () => new class extends OpportunityManager {
            public function __construct()
            {
            }

            public function sweepExpiredSnoozes(int $limit): int
            {
                throw new RuntimeException('The manager must not be invoked while the opportunity engine is disabled.');
            }
        });

        $this->artisan('opportunity:sweep-expired-snoozes')->assertExitCode(0);
    }

    public function test_limit_zero_returns_invalid(): void
    {
        $this->artisan('opportunity:sweep-expired-snoozes', ['--limit' => '0'])
            ->expectsOutput('The --limit option must be a positive integer.')
            ->assertExitCode(2);
    }

    public function test_negative_limit_returns_invalid(): void
    {
        $this->artisan('opportunity:sweep-expired-snoozes', ['--limit' => '-5'])
            ->expectsOutput('The --limit option must be a positive integer.')
            ->assertExitCode(2);
    }

    public function test_decimal_limit_returns_invalid(): void
    {
        $this->artisan('opportunity:sweep-expired-snoozes', ['--limit' => '5.5'])
            ->expectsOutput('The --limit option must be a positive integer.')
            ->assertExitCode(2);
    }

    public function test_alphabetic_limit_returns_invalid(): void
    {
        $this->artisan('opportunity:sweep-expired-snoozes', ['--limit' => 'abc'])
            ->expectsOutput('The --limit option must be a positive integer.')
            ->assertExitCode(2);
    }

    public function test_blank_limit_returns_invalid(): void
    {
        $this->artisan('opportunity:sweep-expired-snoozes', ['--limit' => ''])
            ->expectsOutput('The --limit option must be a positive integer.')
            ->assertExitCode(2);
    }

    public function test_invalid_limit_performs_no_mutation(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->expiredSnoozedOpportunity($business);

        $this->artisan('opportunity:sweep-expired-snoozes', ['--limit' => 'abc'])->assertExitCode(2);

        $updated = $opportunity->fresh();
        $this->assertSame(OpportunityStatus::Snoozed, $updated->status);
        $this->assertNotNull($updated->snoozed_until);
        $this->assertSame(0, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());
    }

    public function test_unexpected_manager_exception_is_not_swallowed(): void
    {
        $this->app->bind(OpportunityManager::class, fn () => new class extends OpportunityManager {
            public function __construct()
            {
            }

            public function sweepExpiredSnoozes(int $limit): int
            {
                throw new RuntimeException('Simulated unexpected failure.');
            }
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Simulated unexpected failure.');

        $this->artisan('opportunity:sweep-expired-snoozes')->run();
    }

    private function expiredSnoozedOpportunity(Business $business, array $overrides = []): Opportunity
    {
        return $this->createOpportunity($business, array_merge([
            'status' => OpportunityStatus::Snoozed->value,
            'snoozed_until' => now()->subMinute(),
        ], $overrides));
    }
}

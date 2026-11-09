<?php

namespace Tests\Feature\Opportunity;

use App\Enums\Opportunity\OpportunityActionExecutionStatus;
use App\Enums\Opportunity\OpportunityCompletionPolicy;
use App\Enums\Opportunity\OpportunityStatus;
use App\Library\Business\BusinessManager;
use App\Library\Business\UrlNormalizer;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Opportunity\Exceptions\InvalidOpportunityActionParametersException;
use App\Library\Opportunity\Exceptions\OpportunityActionNotConfigurableException;
use App\Library\Opportunity\Exceptions\OpportunityActionNotExecutableException;
use App\Library\Opportunity\Exceptions\OpportunityActionVerificationException;
use App\Library\Opportunity\OpportunityActionExecutor;
use App\Library\Opportunity\OpportunityActionHash;
use App\Library\Opportunity\OpportunityManager;
use App\Library\Workspace\WorkspaceManager;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Opportunity;
use App\Models\OpportunityActionExecution;
use App\Repositories\Contracts\BusinessLocationRepository;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\BusinessServiceRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Opportunity\Concerns\CreatesOpportunityTestData;
use Tests\TestCase;

/**
 * add_email and add_description: executed through the canonical BusinessManager, verified against persisted Business state, and
 * rejected for any value outside the registry's rule. add_website is deliberately NOT executable (see the registry test).
 */
class OpportunityProfileActionExecutorTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOpportunityTestData;

    private const DESCRIPTION = 'We rent photo booths for weddings, birthdays and corporate events across the city, with on-site attendants and instant prints.';

    private function action(string $key, mixed $value): array
    {
        return ['schema_version' => 1, 'action_key' => $key, 'parameters' => ['value' => $value], 'approval_required' => true, 'completion_policy' => 'system_verified'];
    }

    /** @return array{0: Opportunity, 1: OpportunityActionExecution} */
    private function running(Business $business, string $key, mixed $value, string $status = 'in_progress'): array
    {
        $action = $this->action($key, $value);
        $hash = (new OpportunityActionHash())->compute($action);
        $opportunity = $this->createOpportunity($business, [
            'status' => $status, 'recommended_action' => $action, 'recommended_action_hash' => $hash, 'action_schema_version' => 1, 'occurrence_number' => 1,
        ]);
        $execution = $this->createOpportunityActionExecution($opportunity, $this->createUser(), [
            'action_key' => $key, 'recommended_action_hash' => $hash, 'action_schema_version' => 1, 'occurrence_number' => 1,
            'status' => OpportunityActionExecutionStatus::Running->value, 'completion_policy' => OpportunityCompletionPolicy::SystemVerified->value,
        ]);

        return [$opportunity, $execution];
    }

    public function test_add_email_writes_and_verifies_the_exact_approved_value(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->running($business, 'add_email', 'hello@photobooths.example');

        $summary = app(OpportunityActionExecutor::class)->execute($opportunity, $execution);

        $this->assertSame('hello@photobooths.example', $business->fresh()->email);
        $this->assertSame('Business email updated and verified.', $summary);
        $this->assertSame(OpportunityStatus::InProgress, $opportunity->fresh()->status, 'Completion belongs to the existing lifecycle, not the executor.');
    }

    public function test_add_description_writes_and_verifies_the_exact_approved_value(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->running($business, 'add_description', self::DESCRIPTION);

        $summary = app(OpportunityActionExecutor::class)->execute($opportunity, $execution);

        $this->assertSame(self::DESCRIPTION, $business->fresh()->description);
        $this->assertSame('Business description updated and verified.', $summary);
    }

    public function test_a_value_outside_the_registry_rule_never_executes_or_writes(): void
    {
        $business = $this->createBusinessForOpportunities();
        $before = $business->fresh()->only(['email', 'description']);

        foreach ([['add_email', 'not-an-email'], ['add_email', ''], ['add_description', 'Too short.'], ['add_description', str_repeat('x', 5001)]] as [$key, $value]) {
            [$opportunity, $execution] = $this->running($business, $key, $value);

            try {
                app(OpportunityActionExecutor::class)->execute($opportunity, $execution);
                $this->fail("{$key} accepted an invalid value.");
            } catch (OpportunityActionNotExecutableException) {
                // expected
            }
        }

        $this->assertSame($before, $business->fresh()->only(['email', 'description']));
    }

    public function test_an_opportunity_that_is_not_in_progress_cannot_execute(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->running($business, 'add_email', 'hello@photobooths.example', 'awaiting_approval');

        $this->expectException(OpportunityActionNotExecutableException::class);

        try {
            app(OpportunityActionExecutor::class)->execute($opportunity, $execution);
        } finally {
            $this->assertNull($business->fresh()->email, 'No write before approval.');
        }
    }

    public function test_a_write_that_does_not_persist_the_approved_value_is_a_failed_verification(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->running($business, 'add_email', 'hello@photobooths.example');
        $this->app->instance(BusinessManager::class, new class(
            app(BusinessRepository::class), app(BusinessLocationRepository::class), app(BusinessServiceRepository::class),
            app(UrlNormalizer::class), app(WorkspaceManager::class), app(EntitlementManager::class),
        ) extends BusinessManager {
            public function updateBusiness(Customer $customer, Business $business, array $attributes): Business
            {
                return parent::updateBusiness($customer, $business, ['email' => 'someone.else@example.test']);
            }
        });

        $this->expectException(OpportunityActionVerificationException::class);

        app(OpportunityActionExecutor::class)->execute($opportunity, $execution);
    }

    public function test_configuration_accepts_only_valid_values_and_only_the_three_supported_actions(): void
    {
        $business = $this->createBusinessForOpportunities();
        $customer = $business->customer;
        $manager = app(OpportunityManager::class);

        $email = $this->createOpportunity($business, ['status' => 'open', 'recommended_action' => $this->action('add_email', ''), 'recommended_action_hash' => (new OpportunityActionHash())->compute($this->action('add_email', '')), 'action_schema_version' => 1]);

        try {
            $manager->configureAction($email, $customer, ['value' => 'nope']);
            $this->fail('An invalid email was accepted.');
        } catch (InvalidOpportunityActionParametersException) {
            // expected
        }

        $configured = $manager->configureAction($email, $customer, ['value' => 'hello@photobooths.example']);
        $this->assertSame(['value' => 'hello@photobooths.example'], $configured->recommended_action['parameters']);
        $this->assertNull($business->fresh()->email, 'Configuring writes nothing to the Business.');

        $website = $this->createOpportunity($business, ['status' => 'open', 'recommended_action' => $this->action('add_website', ''), 'recommended_action_hash' => (new OpportunityActionHash())->compute($this->action('add_website', '')), 'action_schema_version' => 1]);
        $this->expectException(OpportunityActionNotConfigurableException::class);
        $manager->configureAction($website, $customer, ['value' => 'https://example.test']);
    }

    public function test_reconfiguring_changes_the_action_hash_so_a_stale_execution_no_longer_matches(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->running($business, 'add_email', 'old@photobooths.example');
        $changed = $this->action('add_email', 'new@photobooths.example');
        $opportunity->forceFill(['recommended_action' => $changed, 'recommended_action_hash' => (new OpportunityActionHash())->compute($changed)])->save();

        try {
            app(OpportunityActionExecutor::class)->execute($opportunity->fresh(), $execution);
            $this->fail('A stale execution ran against a changed proposal.');
        } catch (OpportunityActionNotExecutableException) {
            // expected
        }

        $this->assertNull($business->fresh()->email);
    }
}

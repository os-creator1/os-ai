<?php

namespace Tests\Feature\Coo\Insight;

use App\Enums\Coo\CooInsightInvalidationReason;
use App\Enums\Coo\CooInsightTrigger;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Events\Business\BusinessUpdated;
use App\Events\GoogleBusinessProfile\GoogleBusinessProfileConnected;
use App\Events\GoogleBusinessProfile\GoogleBusinessProfileConnectionRevoked;
use App\Events\GoogleBusinessProfile\GoogleBusinessProfileDisconnected;
use App\Events\Opportunity\OpportunityCompleted;
use App\Events\Opportunity\OpportunityDismissed;
use App\Events\Opportunity\OpportunityExecutionFailed;
use App\Events\Opportunity\OpportunityExecutionSucceeded;
use App\Events\Website\WebsitePublished;
use App\Jobs\Coo\GenerateCooInsight;
use App\Models\AiUsageLedgerEntry;
use App\Models\Business;
use App\Models\CooInsight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Coo\Insight\Concerns\CreatesCooInsightFixtures;
use Tests\TestCase;

/**
 * AI-3 — contract §9.3 (T-INS-2) and the E-2 / scheduled triggers that queue.
 *
 * Every signal invalidates; invalidation is soft (rows survive), scoped to its
 * own Business, and by itself queues nothing and spends nothing.
 */
class CooInsightInvalidationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCooInsightFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCooInsights();
    }

    public function test_each_context_event_retires_the_businesss_performance_diagnosis(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth);

        $events = [
            'website published' => fn () => new WebsitePublished(1, 1, (int) $business->id),
            'google connected' => fn () => new GoogleBusinessProfileConnected((int) $business->id, 1, null),
            'google disconnected' => fn () => new GoogleBusinessProfileDisconnected((int) $business->id, 1, null),
            'google revoked' => fn () => new GoogleBusinessProfileConnectionRevoked((int) $business->id, 1),
            'business updated' => fn () => new BusinessUpdated((int) $business->id, ['name']),
        ];

        foreach ($events as $name => $event) {
            Queue::fake();
            $insight = $this->cachedInsight($business);

            event($event());

            $insight->refresh();
            $this->assertSame(CooInsightInvalidationReason::ContextChanged, $insight->invalidation_reason, $name);
            $this->assertNotNull($insight->invalidated_at, $name);
            Queue::assertNotPushed(GenerateCooInsight::class);
        }

        $this->assertSame(count($events), CooInsight::query()->count(), 'Invalidation never deletes.');
        $this->assertSame(0, $this->fakeAi->callCount());
        $this->assertSame(0, AiUsageLedgerEntry::query()->count());
    }

    public function test_opportunity_work_retires_the_insights_that_cited_that_work_and_only_those(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $opportunityId = $this->recommendation($business, ['type' => 'missing_phone']);
        $actor = (int) $customer->user_id;

        $events = [
            'completed' => fn () => new OpportunityCompleted($opportunityId, (int) $business->id, $actor, null, 'customer_attested'),
            'dismissed' => fn () => new OpportunityDismissed($opportunityId, (int) $business->id, $actor, 'open'),
            'execution succeeded' => fn () => new OpportunityExecutionSucceeded($opportunityId, (int) $business->id, $actor, 1, 'add_phone'),
            'execution failed' => fn () => new OpportunityExecutionFailed($opportunityId, (int) $business->id, $actor, 1, 'add_phone', 'Something went wrong.'),
        ];

        foreach ($events as $name => $event) {
            config(['services.openai.active' => false]);
            $citing = $this->cachedInsight($business, ['facts_snapshot' => ['facts' => [['ref' => 'opportunity.missing_phone', 'opportunity_type' => 'missing_phone']]]]);
            $unrelated = $this->cachedInsight($business, ['facts_snapshot' => ['facts' => [['ref' => 'opportunity.missing_email', 'opportunity_type' => 'missing_email']]]]);

            event($event());

            $this->assertSame(CooInsightInvalidationReason::SubjectWorkChanged, $citing->refresh()->invalidation_reason, $name);
            $this->assertNull($unrelated->refresh()->invalidated_at, "{$name}: an insight that never cited this work stays.");
            $unrelated->forceFill(['invalidated_at' => now(), 'invalidation_reason' => 'context_changed'])->save();
        }

        $this->assertSame(0, $this->fakeAi->callCount());
        $this->assertSame(0, AiUsageLedgerEntry::query()->count());
    }

    public function test_an_event_for_one_business_never_touches_another(): void
    {
        [, $mine] = $this->tenant(WorkspacePlanTier::Growth, 'Mine Venue', 'Mine Account');
        [, $theirs] = $this->tenant(WorkspacePlanTier::Growth, 'Theirs Venue', 'Theirs Account');
        $theirInsight = $this->cachedInsight($theirs, ['facts_snapshot' => ['facts' => [['ref' => 'opportunity.missing_phone', 'opportunity_type' => 'missing_phone']]]]);
        $opportunityId = $this->recommendation($theirs, ['type' => 'missing_phone']);

        event(new BusinessUpdated((int) $mine->id, ['name']));
        event(new OpportunityDismissed($opportunityId, (int) $mine->id, 1, 'open'));

        $this->assertNull($theirInsight->refresh()->invalidated_at);
    }

    public function test_work_finished_queues_the_e2_trigger_separately_and_only_with_ai_on(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $opportunityId = $this->recommendation($business, ['type' => 'missing_phone']);

        Queue::fake();
        event(new OpportunityDismissed($opportunityId, (int) $business->id, (int) $customer->user_id, 'open'));
        Queue::assertNotPushed(GenerateCooInsight::class, 'Dismissing is not work finishing.');

        event(new OpportunityCompleted($opportunityId, (int) $business->id, (int) $customer->user_id, null, 'customer_attested'));
        Queue::assertPushed(GenerateCooInsight::class, fn (GenerateCooInsight $job): bool => $job->businessId === (int) $business->id && $job->trigger === CooInsightTrigger::WorkFinished->value);

        Queue::fake();
        config(['services.openai.active' => false]);
        event(new OpportunityExecutionFailed($opportunityId, (int) $business->id, (int) $customer->user_id, 1, 'add_phone', 'Failed.'));
        Queue::assertNothingPushed();

        $this->assertSame(0, $this->fakeAi->callCount(), 'Queuing is not spending: the job decides, off-request.');
    }

    public function test_the_scheduled_sweep_queues_e1_daily_and_e3_monthly_for_active_businesses_only(): void
    {
        [, $active] = $this->tenant(WorkspacePlanTier::Growth, 'Active Venue', 'Active Account');
        [, $inactive] = $this->tenant(WorkspacePlanTier::Growth, 'Inactive Venue', 'Inactive Account');
        Business::query()->whereKey($inactive->id)->update(['status' => 'inactive']);

        Queue::fake();
        Artisan::call('coo:dispatch-insight-reviews');
        Queue::assertPushed(GenerateCooInsight::class, fn (GenerateCooInsight $job): bool => $job->businessId === (int) $active->id && $job->trigger === CooInsightTrigger::MultiSignalChange->value);
        Queue::assertNotPushed(GenerateCooInsight::class, fn (GenerateCooInsight $job): bool => $job->businessId === (int) $inactive->id);

        Queue::fake();
        Artisan::call('coo:dispatch-insight-reviews', ['--monthly' => true]);
        Queue::assertPushed(GenerateCooInsight::class, fn (GenerateCooInsight $job): bool => $job->trigger === CooInsightTrigger::MonthlyReview->value);

        Queue::fake();
        Artisan::call('coo:dispatch-insight-reviews', ['--limit' => 1, '--page' => 1]);
        Queue::assertPushedTimes(GenerateCooInsight::class, 1);

        Queue::fake();
        config(['services.openai.active' => false]);
        Artisan::call('coo:dispatch-insight-reviews');
        Queue::assertNothingPushed();
    }
}

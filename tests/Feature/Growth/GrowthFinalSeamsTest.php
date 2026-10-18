<?php

namespace Tests\Feature\Growth;

use App\Events\DocumentPaymentFailed;
use App\Jobs\Growth\RunGrowthEvaluation;
use App\Library\Growth\GrowthFactSnapshotBuilder;
use App\Listeners\Automation\Workflow\EnrollFromDocumentEvent;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Growth\Concerns\CreatesGrowthFixtures;
use Tests\TestCase;

/**
 * Integrated-preview reconciliation: Growth's Proposal/Payments readers against
 * the FINAL Documents schedule (Deposit + Balance with a scheduled balance
 * request), and Growth's listeners alongside Automations' on the same event.
 */
class GrowthFinalSeamsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGrowthFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGrowthBusiness();
    }

    private function documentsFacts(): array
    {
        return app(GrowthFactSnapshotBuilder::class)->build($this->business->fresh())->set('documents')->get('by_location')[$this->primaryLocation->id] ?? [];
    }

    public function test_a_paid_deposit_with_the_balance_still_ahead_is_not_signed_but_unpaid(): void
    {
        $this->document('signed', ['signed_at' => now()->subDays(6)], [
            [30000, -6, 'paid', 'deposit'],
            [70000, 20, 'pending', 'balance'],
        ]);

        $by = $this->documentsFacts();

        $this->assertSame(0, $by['signed_unpaid']['count'] ?? 0, 'the scheduled balance request handles it; it is not a finding');
        $this->assertSame(0, $by['overdue']['count'] ?? 0);
    }

    public function test_a_pending_deposit_counts_only_the_deposit_not_the_future_balance(): void
    {
        $this->document('signed', ['signed_at' => now()->subDays(6)], [
            [30000, null, 'pending', 'deposit'],
            [70000, 20, 'pending', 'balance'],
        ]);

        $by = $this->documentsFacts();

        $this->assertSame(1, $by['signed_unpaid']['count']);
        $this->assertSame(30000, $by['signed_unpaid']['value_minor'], 'only what is owed now');
    }

    public function test_an_overdue_balance_is_overdue_and_never_also_signed_unpaid(): void
    {
        $this->document('signed', ['signed_at' => now()->subDays(30)], [
            [30000, -25, 'paid', 'deposit'],
            [70000, -2, 'pending', 'balance'],
        ]);

        $by = $this->documentsFacts();

        $this->assertSame(1, $by['overdue']['count']);
        $this->assertSame(70000, $by['overdue']['value_minor']);
        $this->assertSame(0, $by['signed_unpaid']['count'], 'one document, one finding — no contradictory duplicate');
    }

    public function test_a_failed_payment_event_reaches_both_growth_and_automations(): void
    {
        config(['opportunity.enabled' => true]);
        Queue::fake();

        event(new DocumentPaymentFailed(1, 1, $this->business->id));

        Queue::assertPushed(RunGrowthEvaluation::class);
        Queue::assertPushed(CallQueuedListener::class, fn ($job) => $job->class === EnrollFromDocumentEvent::class);
    }

    public function test_citation_expectations_follow_the_final_country_scoped_catalog(): void
    {
        $canada = $this->growthLocation('Toronto Studio');
        DB::table('business_locations')->where('id', $canada->id)->update(['country_code' => 'CA']);
        $this->assertGreaterThan(0, DB::table('seo_citation_directories')->whereNull('business_id')->where('is_active', true)->where('is_platform_core', true)->where('country_scope', 'US')->count(), 'the final catalog carries US-scoped core directories');

        $facts = app(GrowthFactSnapshotBuilder::class)->build($this->business->fresh())->set('citations')->get('locations');

        $us = $facts[$this->primaryLocation->id]['directories'];
        $ca = $facts[$canada->id]['directories'];

        $this->assertGreaterThan(0, $us);
        $this->assertLessThan($us + 1, $ca + 0, 'a non-US Location is never expected to list on US-only directories');
        $this->assertSame($ca, $facts[$canada->id]['not_checked'] + $facts[$canada->id]['needs_attention'], 'every offered directory is "not checked" until the owner records it');
    }

    public function test_the_website_package_sync_rule_reads_the_website_modules_own_verdict(): void
    {
        $item = app(\App\Library\Catalog\CatalogItemManager::class)->create($this->business, ['type' => 'package', 'name' => 'Essential Booth', 'price_minor' => 69900, 'currency_code' => 'USD']);
        $websiteId = DB::table('websites')->insertGetId([
            'uid' => (string) \Illuminate\Support\Str::uuid(), 'public_id' => (string) \Illuminate\Support\Str::uuid(), 'business_id' => $this->business->id,
            'name' => 'Spark', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $revisionId = DB::table('website_revisions')->insertGetId([
            'uid' => (string) \Illuminate\Support\Str::uuid(), 'website_id' => $websiteId, 'version_number' => 1, 'schema_version' => 1,
            'created_by' => (int) DB::table('users')->value('id'), 'created_at' => now()->subDay(),
            'snapshot' => json_encode(['pages' => [['uid' => 'p1', 'slug' => 'packages', 'sections' => [['type' => 'services', 'data' => ['items' => [['catalog_item_uid' => $item->uid, 'name' => 'Essential Booth']]]]]]], 'assets' => []]),
        ]);
        DB::table('websites')->where('id', $websiteId)->update(['status' => 'published', 'published_revision_id' => $revisionId]);

        // Published AFTER the last catalog change: in sync, so no finding (and the rule counts as passing).
        DB::table('catalog_items')->where('id', $item->id)->update(['updated_at' => now()->subDays(2)]);
        $this->assertEmpty($this->growthOpportunities('website.package_out_of_sync:v1'));

        // The catalog moves on after publishing: the Website module says out of sync, Growth reports it.
        DB::table('catalog_items')->where('id', $item->id)->update(['updated_at' => now()]);
        $this->evaluateGrowth();
        $found = $this->growthOpportunities('website.package_out_of_sync:v1');

        $this->assertCount(1, $found);
        $this->assertSame(1, $found->first()->evidence[0]['observed_value']['count']);
        $this->assertSame('website', $found->first()->worker_key->value);
    }
}

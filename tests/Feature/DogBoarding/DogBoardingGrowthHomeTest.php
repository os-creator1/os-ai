<?php

namespace Tests\Feature\DogBoarding;

use App\Library\NicheBlueprint\NicheBlueprintInstaller;
use App\Models\CrmPipeline;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Growth\Concerns\CreatesGrowthFixtures;
use Tests\TestCase;

/**
 * Growth Home reads canonical CRM facts, never a niche. A Dog Boarding stay request waiting in the Stay Requests pipeline
 * must therefore surface through the EXISTING "unanswered new leads" rule, from real state only, with no contact detail
 * (and no pet-care detail) in the evidence.
 */
class DogBoardingGrowthHomeTest extends TestCase
{
    use CreatesGrowthFixtures;
    use RefreshDatabase;

    private const TYPE = 'crm.unanswered_new_leads:v1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGrowthBusiness();

        User::query()->whereKey($this->platformAdminId())->update(['is_admin' => true]);
        $this->artisan('blueprint:seed-niche', ['niche' => 'dog_boarding', '--actor' => $this->platformAdminId()])->assertExitCode(0);

        app(\App\Library\Business\BusinessKnowledgeProfileManager::class)->getOrCreate($this->business);
        DB::table('business_knowledge_profiles')->where('business_id', $this->business->id)->update(['vertical_key' => 'dog_boarding']);
        app(NicheBlueprintInstaller::class)->installForBusiness($this->business->fresh());
    }

    public function test_an_unanswered_stay_request_surfaces_through_the_existing_lead_rule_without_pet_or_contact_detail(): void
    {
        $pipeline = CrmPipeline::query()->where('business_id', $this->business->id)->where('name', 'Stay Requests')->firstOrFail();
        $deal = $this->deal($this->business, $pipeline, null, 'Jordan Rivera, Request a Stay');
        DB::table('crm_opportunities')->where('id', $deal->id)->update([
            'created_at' => now()->subHours(50), 'updated_at' => now()->subHours(50), 'stage_entered_at' => now()->subHours(50),
            'contact_status' => 'no_contact', 'location_id' => $this->primaryLocation->id,
        ]);
        DB::table('crm_opportunity_history')->where('opportunity_id', $deal->id)->update(['created_at' => now()->subHours(50)]);

        $result = $this->evaluateGrowth();
        $this->assertTrue($result['ran']);

        $opportunities = $this->growthOpportunities(self::TYPE);
        $this->assertCount(1, $opportunities, 'The waiting stay request is a real Growth finding.');
        $this->assertSame(1, $opportunities->first()->evidence[0]['observed_value']['count']);
        $blob = json_encode($opportunities->first()->evidence);
        $this->assertStringNotContainsString('Jordan', $blob, 'No contact name in evidence.');
        $this->assertStringNotContainsString('Request a Stay', $blob);
    }

    public function test_nothing_is_invented_when_there_is_no_stay_request(): void
    {
        $this->evaluateGrowth();

        $this->assertCount(0, $this->growthOpportunities(self::TYPE), 'No lead, no recommendation: Growth never invents inquiries.');
    }

    public function test_the_deal_leaves_the_finding_once_the_owner_has_contacted_the_person(): void
    {
        $pipeline = CrmPipeline::query()->where('business_id', $this->business->id)->where('name', 'Stay Requests')->firstOrFail();
        $deal = $this->deal($this->business, $pipeline, null, 'Stay request');
        DB::table('crm_opportunities')->where('id', $deal->id)->update([
            'created_at' => now()->subHours(50), 'stage_entered_at' => now()->subHours(50), 'contact_status' => 'no_contact', 'location_id' => $this->primaryLocation->id,
        ]);
        DB::table('crm_opportunity_history')->where('opportunity_id', $deal->id)->update(['created_at' => now()->subHours(50)]);
        $this->evaluateGrowth();
        $this->assertCount(1, $this->growthOpportunities(self::TYPE));

        $this->markDealContacted($deal);
        $this->evaluateGrowth();

        $this->assertSame(\App\Enums\Opportunity\OpportunityFreshness::Stale, $this->growthOpportunities(self::TYPE)->first()->freshness, 'Resolved by re-evaluation of real state, not a button click.');
    }
}

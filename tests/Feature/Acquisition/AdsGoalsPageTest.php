<?php

namespace Tests\Feature\Acquisition;

use App\Library\Acquisition\AcquisitionPurposeManager;
use App\Models\AcquisitionPurpose;
use App\Models\Business;
use App\Models\MetaAdsCampaign;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Acquisition\Support\SeedsAcquisitionFunnel;
use Tests\Feature\MetaAds\Http\Concerns\CreatesMetaAdsHttpFixtures;
use Tests\TestCase;

/**
 * Ads > Goals & economics: the page, the economics form ("I don't know yet" is
 * a real answer), explicit campaign assignment and its tenancy.
 */
class AdsGoalsPageTest extends TestCase
{
    use CreatesMetaAdsHttpFixtures;
    use RefreshDatabase;
    use SeedsAcquisitionFunnel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareMetaHttp(withGoogle: true);
    }

    protected function tearDown(): void
    {
        $this->finishMetaHttp();
        parent::tearDown();
    }

    private function url(Workspace $workspace, Business $business, string $suffix = ''): string
    {
        return route('customer.workspaces.businesses.ads.goals' . $suffix, [$workspace->uid, $business->uid]);
    }

    /** @return array{0: Business, 1: Workspace, 2: AcquisitionPurpose, 3: \App\Models\MetaAdsAccount} */
    private function setUpGoal(): array
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $account = $this->metaSelected($business);
        $this->asMetaUser($customer, [self::META_VIEW, self::META_MANAGE, self::G_VIEW, self::G_MANAGE]);

        $pipeline = $this->seedPipeline($business, 'Student Enrollment', [['New inquiry', 'new_inquiry'], ['Contacted', 'contacted']]);
        $purpose = app(AcquisitionPurposeManager::class)->createManual($business, 'Student Enrollment', (int) $pipeline->id);
        $purpose->forceFill(['calculator_key' => 'recurring_lessons', 'outcome_type' => 'student'])->save();

        return [$business, $workspace, $purpose->fresh(), $account];
    }

    public function test_the_page_lists_the_goal_with_its_economics_questions(): void
    {
        [$business, $workspace, $purpose] = $this->setUpGoal();

        $html = $this->get($this->url($workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('Goals &amp; economics', $html);
        $this->assertStringContainsString('data-goal="student_enrollment"', $html);
        $this->assertStringContainsString('Average price charged per paid lesson', $html);
        $this->assertStringContainsString("I don't know yet", $html);
        $this->assertStringContainsString('How it connects', $html);
        $this->assertStringContainsString('Until the price, direct cost and typical length are known', $html);
    }

    public function test_saving_numbers_and_marking_others_unknown_updates_the_summary(): void
    {
        [$business, $workspace, $purpose] = $this->setUpGoal();

        $response = $this->post(route('customer.workspaces.businesses.ads.goals.economics', [$workspace->uid, $business->uid, $purpose->uid]), [
            'answers' => ['lesson_price' => '10', 'variable_cost_per_lesson' => '6', 'median_paid_lessons' => '20', 'qualified_to_customer_pct' => '25', 'target_cac' => '25', 'hard_cac' => '40'],
        ]);
        $response->assertRedirect($this->url($workspace, $business));

        $html = $this->get($this->url($workspace, $business))->getContent();
        $this->assertStringContainsString('6.25', $html, 'target cost per qualified lead = 25 x 25%');
        $this->assertStringContainsString('80.00', $html, 'life contribution = (10 - 6) x 20');
        $this->assertStringNotContainsString('data-role="no-profit-claim"', $html);

        $this->post(route('customer.workspaces.businesses.ads.goals.economics', [$workspace->uid, $business->uid, $purpose->uid]), [
            'answers' => ['lesson_price' => '10', 'variable_cost_per_lesson' => '6', 'median_paid_lessons' => '20'],
            'unknown' => ['median_paid_lessons' => '1'],
        ]);

        $purpose = $purpose->fresh();
        $this->assertSame(['median_paid_lessons'], $purpose->unknownKeys());
        $this->assertArrayNotHasKey('median_paid_lessons', $purpose->answers());
        $this->assertNull($purpose->economicsProfile()->contributionLtvMicros);
        $this->assertStringContainsString('data-role="no-profit-claim"', $this->get($this->url($workspace, $business))->getContent());
    }

    public function test_a_hard_maximum_below_its_target_is_refused_without_saving(): void
    {
        [$business, $workspace, $purpose] = $this->setUpGoal();

        $this->post(route('customer.workspaces.businesses.ads.goals.economics', [$workspace->uid, $business->uid, $purpose->uid]), [
            'answers' => ['target_cac' => '40', 'hard_cac' => '25'],
        ])->assertRedirect()->assertSessionHas('status', 'error');

        $this->assertNull($purpose->fresh()->economics);
    }

    public function test_assigning_a_campaign_is_explicit_and_unassigning_clears_it(): void
    {
        [$business, $workspace, $purpose, $account] = $this->setUpGoal();
        $campaign = $this->seedMetaCampaign($account, 'VBE parents - autumn');

        $this->post(route('customer.workspaces.businesses.ads.goals.campaigns', [$workspace->uid, $business->uid]), [
            'provider' => 'meta', 'campaign_uid' => $campaign->uid, 'purpose_uid' => $purpose->uid,
        ])->assertRedirect();

        $this->assertSame($purpose->id, MetaAdsCampaign::query()->find($campaign->id)->acquisition_purpose_id);

        $this->post(route('customer.workspaces.businesses.ads.goals.campaigns', [$workspace->uid, $business->uid]), [
            'provider' => 'meta', 'campaign_uid' => $campaign->uid,
        ])->assertRedirect();

        $this->assertNull(MetaAdsCampaign::query()->find($campaign->id)->acquisition_purpose_id);
    }

    public function test_a_campaign_name_never_assigns_a_goal(): void
    {
        [$business, , $purpose, $account] = $this->setUpGoal();
        $campaign = $this->seedMetaCampaign($account, 'Student Enrollment');

        $this->assertNull($campaign->fresh()->acquisition_purpose_id);
        $this->assertSame(0, $purpose->metaCampaigns()->count());
    }

    public function test_another_business_cannot_assign_or_read_this_business_goal_or_campaign(): void
    {
        [$business, $workspace, $purpose, $account] = $this->setUpGoal();
        $campaign = $this->seedMetaCampaign($account, 'Mine');

        [$otherCustomer, $otherBusiness, $otherWorkspace] = $this->metaHttpTenant(name: 'Rival Co');
        $this->metaSelected($otherBusiness);
        $this->asMetaUser($otherCustomer, [self::META_VIEW, self::META_MANAGE, self::G_VIEW, self::G_MANAGE]);

        // Their own page never lists my goal.
        $html = $this->get($this->url($otherWorkspace, $otherBusiness))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-goal="student_enrollment"', $html);

        // Assigning my campaign to my goal through THEIR business refuses and changes nothing.
        $this->post(route('customer.workspaces.businesses.ads.goals.campaigns', [$otherWorkspace->uid, $otherBusiness->uid]), [
            'provider' => 'meta', 'campaign_uid' => $campaign->uid, 'purpose_uid' => $purpose->uid,
        ])->assertRedirect()->assertSessionHas('status', 'error');
        $this->assertNull($campaign->fresh()->acquisition_purpose_id);

        // Their own business id on my purpose path changes nothing either.
        $this->post(route('customer.workspaces.businesses.ads.goals.economics', [$otherWorkspace->uid, $otherBusiness->uid, $purpose->uid]), [
            'answers' => ['target_cac' => '99'],
        ])->assertRedirect()->assertSessionHas('status', 'error');
        $this->assertNull($purpose->fresh()->economics);
    }

    public function test_a_viewer_without_manage_permission_sees_the_page_but_cannot_change_it(): void
    {
        [$business, $workspace, $purpose] = $this->setUpGoal();
        $customer = \App\Models\Customer::query()->first();
        $this->asMetaUser($customer, [self::META_VIEW]);

        $html = $this->get($this->url($workspace, $business))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-role="save-economics"', $html);

        $this->post(route('customer.workspaces.businesses.ads.goals.economics', [$workspace->uid, $business->uid, $purpose->uid]), ['answers' => ['target_cac' => '5']])
            ->assertStatus(401);
        $this->assertNull($purpose->fresh()->economics);
    }

    public function test_a_manual_goal_can_be_added_and_paused(): void
    {
        [$business, $workspace] = $this->setUpGoal();

        $this->post(route('customer.workspaces.businesses.ads.goals.store', [$workspace->uid, $business->uid]), ['name' => 'Wedding bookings'])->assertRedirect();
        $new = AcquisitionPurpose::query()->where('business_id', $business->id)->where('name', 'Wedding bookings')->firstOrFail();
        $this->assertSame('simple_outcome', $new->calculator_key);
        $this->assertSame('wedding_bookings', $new->purpose_key);

        $this->post(route('customer.workspaces.businesses.ads.goals.toggle', [$workspace->uid, $business->uid, $new->uid]), ['active' => 0])->assertRedirect();
        $this->assertFalse($new->fresh()->is_active);
    }

    public function test_pipeline_and_destination_links_are_validated_inside_the_business(): void
    {
        [$business, $workspace, $purpose] = $this->setUpGoal();
        [, $otherBusiness] = $this->metaTenant('Other Co');
        $foreign = $this->seedPipeline($otherBusiness, 'Foreign pipeline', [['New inquiry', 'new_inquiry']]);

        $this->post(route('customer.workspaces.businesses.ads.goals.links', [$workspace->uid, $business->uid, $purpose->uid]), ['pipeline_id' => $foreign->id])
            ->assertRedirect()->assertSessionHas('status', 'error');
        $this->assertNotSame($foreign->id, $purpose->fresh()->crm_pipeline_id);

        $this->post(route('customer.workspaces.businesses.ads.goals.links', [$workspace->uid, $business->uid, $purpose->uid]), [
            'pipeline_id' => $purpose->crm_pipeline_id, 'destination_type' => 'external_url', 'destination_url' => 'javascript:alert(1)',
        ])->assertRedirect()->assertSessionHas('status', 'error');

        $this->post(route('customer.workspaces.businesses.ads.goals.links', [$workspace->uid, $business->uid, $purpose->uid]), [
            'pipeline_id' => $purpose->crm_pipeline_id, 'destination_type' => 'external_url', 'destination_url' => 'https://example.lt/pamokos',
        ])->assertRedirect();
        $this->assertSame('https://example.lt/pamokos', $purpose->fresh()->destination_url);
    }
}

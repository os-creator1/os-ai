<?php

namespace Tests\Feature\Acquisition;

use App\Enums\MetaAds\MetaAdsLevel;
use App\Library\Acquisition\AcquisitionPurposeManager;
use App\Library\Ads\Decisions\AdsDecisionPanelReader;
use App\Library\Ads\Decisions\AdsDecisionState;
use App\Library\Ads\Decisions\ProviderPurposeDeliveryReader;
use App\Library\MetaAds\Reporting\MetaAdsPeriod;
use App\Models\Business;
use App\Models\MetaAdsAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Acquisition\Support\SeedsAcquisitionFunnel;
use Tests\Feature\MetaAds\Http\Concerns\CreatesMetaAdsHttpFixtures;
use Tests\TestCase;

/**
 * The Meta account's selected result type travels, unchanged, from the account
 * through ProviderPurposeDeliveryReader and AdsDecisionPanelReader into the
 * decision engine, and only a result type comparable to an inquiry may be set
 * against CRM inquiries. Real tables, real readers, the real engine.
 *
 * Every scenario: 3 days x 30.00 spend (90.00), 3 Meta-tagged inquiries that
 * left the first stage. Where results are seeded there are 10 per day of the
 * selected type (30 in total), plus a DECOY of 50 per day of another type that
 * must never be added into the count.
 */
class AdsDecisionResultTypeIntegrationTest extends TestCase
{
    use CreatesMetaAdsHttpFixtures;
    use RefreshDatabase;
    use SeedsAcquisitionFunnel;

    private const WEBSITE_LEAD = 'offsite_conversion.fb_pixel_lead';

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

    /**
     * @return array{0: Business, 1: MetaAdsAccount, 2: \App\Models\MetaAdsCampaign}
     */
    private function scenario(?string $type, bool $seedResults = true): array
    {
        [$customer, $business] = $this->metaHttpTenant();
        $account = $this->metaSelected($business, ['result_action_type' => $type]);
        $this->asMetaUser($customer, [self::META_VIEW, self::META_MANAGE, self::G_VIEW, self::G_MANAGE]);

        $pipeline = $this->seedPipeline($business, 'Student Enrollment', [['New inquiry', 'new_inquiry'], ['Contacted', 'contacted'], ['Trial booked', 'trial_booked']]);
        $manager = app(AcquisitionPurposeManager::class);
        $purpose = $manager->createManual($business, 'Student Enrollment', (int) $pipeline->id);
        $purpose->forceFill(['calculator_key' => 'recurring_lessons', 'outcome_type' => 'student'])->save();
        $manager->saveEconomics($business, $purpose, ['answers' => [
            'lesson_price' => 10, 'variable_cost_per_lesson' => 6, 'median_paid_lessons' => 20,
            'qualified_to_customer_pct' => 25, 'target_cac' => 25, 'hard_cac' => 40,
        ]]);

        $campaign = $this->seedMetaCampaign($account, 'Parents autumn');
        $this->seedMetaDays($account, MetaAdsLevel::Campaign, (int) $campaign->id, '2026-09-20', '2026-09-22', 30_000_000);
        $manager->assignCampaign($business, 'meta', (string) $campaign->uid, (string) $purpose->uid);

        if ($seedResults) {
            foreach (['2026-09-20', '2026-09-21', '2026-09-22'] as $day) {
                $this->seedMetaResult($account, MetaAdsLevel::Campaign, (int) $campaign->id, $day, 10, null, $type ?? self::WEBSITE_LEAD);
                $this->seedMetaResult($account, MetaAdsLevel::Campaign, (int) $campaign->id, $day, 50, null, 'onsite_conversion.lead_grouped_decoy');
            }
        }

        for ($i = 0; $i < 3; $i++) {
            $this->seedLead($business, $pipeline, 1, 'open', ['utm_source' => 'facebook']);
        }

        return [$business, $account->fresh(), $campaign];
    }

    private function decide(Business $business, MetaAdsAccount $account): \App\Library\Ads\Decisions\AdsDecision
    {
        $panel = app(AdsDecisionPanelReader::class)->forMeta($business, $account, MetaAdsPeriod::resolve(null, $account));

        $this->assertCount(1, $panel->decisions);

        return $panel->decisions[0];
    }

    private function text(\App\Library\Ads\Decisions\AdsDecision $d): string
    {
        return strtolower($d->headline . ' ' . implode(' ', $d->reasons));
    }

    public function test_the_selected_result_type_and_only_its_own_count_reach_the_delivery_facts(): void
    {
        [, $account, $campaign] = $this->scenario(self::WEBSITE_LEAD);

        $delivery = app(ProviderPurposeDeliveryReader::class)->meta($account, [(int) $campaign->id], '2026-09-04', '2026-10-03');

        $this->assertSame(self::WEBSITE_LEAD, $delivery['result_type'], 'The selected type, unchanged.');
        $this->assertSame(30.0, $delivery['results'], 'Only rows of the selected type: the decoy type is never added in.');
    }

    public function test_a_comparable_website_lead_result_with_a_genuine_mismatch_triggers_check_tracking(): void
    {
        [$business, $account] = $this->scenario(self::WEBSITE_LEAD);

        $d = $this->decide($business, $account);

        $this->assertSame(AdsDecisionState::CheckTracking, $d->state);
        $this->assertStringContainsString('reports 30 results', $this->text($d));
        $this->assertStringContainsString('recorded only 3 inquiries', $this->text($d));
        $this->assertStringContainsString('do not pause or edit the ads', strtolower((string) $d->doNotChange));
    }

    /** @return array<string, array{0: string}> */
    public static function nonComparableTypes(): array
    {
        return [
            'landing page views' => ['landing_page_view'],
            'link clicks' => ['link_click'],
            'messaging conversations' => ['onsite_conversion.messaging_conversation_started_7d'],
            'lead (includes on-Facebook forms)' => ['lead'],
            'on-Facebook lead forms' => ['onsite_conversion.lead_grouped'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonComparableTypes')]
    public function test_a_non_comparable_result_type_never_produces_a_false_mismatch(string $type): void
    {
        [$business, $account, $campaign] = $this->scenario($type);

        $delivery = app(ProviderPurposeDeliveryReader::class)->meta($account, [(int) $campaign->id], '2026-09-04', '2026-10-03');
        $this->assertSame($type, $delivery['result_type']);
        $this->assertSame(30.0, $delivery['results']);

        $d = $this->decide($business, $account);

        $this->assertNotSame(AdsDecisionState::CheckTracking, $d->state);
        $this->assertStringNotContainsString('reports 30 results', $this->text($d));
    }

    public function test_a_missing_result_type_stays_unknown_and_never_invents_results(): void
    {
        [$business, $account, $campaign] = $this->scenario(null, seedResults: false);

        $delivery = app(ProviderPurposeDeliveryReader::class)->meta($account, [(int) $campaign->id], '2026-09-04', '2026-10-03');

        $this->assertNull($delivery['results']);
        $this->assertNull($delivery['result_type']);

        $d = $this->decide($business, $account);

        $this->assertNotSame(AdsDecisionState::CheckTracking, $d->state);
        $rows = array_column($d->evidence, 'value', 'label');
        $this->assertSame('Not available', $rows['Meta reported result cost']);
    }
}

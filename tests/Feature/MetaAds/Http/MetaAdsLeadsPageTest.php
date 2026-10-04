<?php

namespace Tests\Feature\MetaAds\Http;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\GoogleAds\LeadAttributionEntrySurface;
use App\Enums\GoogleAds\LeadAttributionSubjectType;
use App\Library\Crm\CrmOpportunityService;
use App\Library\Forms\FormSubmissionService;
use App\Library\GoogleAds\Attribution\LeadAttributionRecorder;
use App\Library\MetaAds\Attribution\MetaAdsLeadAttributionReader;
use App\Models\Business;
use App\Models\FormDeployment;
use App\Models\FormSubmission;
use App\Models\MetaAdsAccount;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Forms\Concerns\CreatesFormsFixtures;
use Tests\Feature\GoogleAds\Attribution\Concerns\BuildsAttributionCookies;
use Tests\Feature\MetaAds\Http\Concerns\CreatesMetaAdsHttpFixtures;
use Tests\TestCase;

/**
 * Meta Ads Module V1 (contract 24 §10) — the Leads page: Meta-reported results
 * and Business OS outcomes as two separate measurements; leads shown ONLY from
 * existing first-touch rows whose utm_source is a Meta tag, labelled "Campaign
 * tags only"; no Meta campaign / ad set / ad is ever named; the Pixel / CAPI /
 * click-id boundary is stated; CRM money in the Business currency; isolation.
 */
class MetaAdsLeadsPageTest extends TestCase
{
    use BuildsAttributionCookies;
    // The Meta fixtures own tenancy and customers; the Forms fixtures are used only for forms / locations / pipelines.
    use CreatesFormsFixtures, CreatesMetaAdsHttpFixtures {
        CreatesMetaAdsHttpFixtures::platformAdminId insteadof CreatesFormsFixtures;
        CreatesMetaAdsHttpFixtures::ensureRequiredAppConfigRowsExist insteadof CreatesFormsFixtures;
        CreatesMetaAdsHttpFixtures::createCustomer insteadof CreatesFormsFixtures;
        CreatesMetaAdsHttpFixtures::businessAttributes insteadof CreatesFormsFixtures;
        CreatesMetaAdsHttpFixtures::createBusinessWithWorkspace insteadof CreatesFormsFixtures;
    }
    use RefreshDatabase;

    private int $phoneSeed = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareMetaHttp();
    }

    protected function tearDown(): void
    {
        $this->finishMetaHttp();
        parent::tearDown();
    }

    /** One public-form lead (a distinct contact) for $business, optionally with attribution cookies. */
    private function lead(Business $business, FormDeployment $deployment, array $cookies = []): FormSubmission
    {
        $phone = '+1415555' . str_pad((string) (++$this->phoneSeed + random_int(1000, 8999)), 4, '0', STR_PAD_LEFT);
        $submission = app(FormSubmissionService::class)
            ->submit($deployment->uid, $this->submitInput($deployment, ['phone' => $phone, 'your_name' => 'Lead ' . $this->phoneSeed]))->submission;

        app(LeadAttributionRecorder::class)->record(
            $submission->business_id, $submission->business_location_id, $submission->contact_id,
            LeadAttributionSubjectType::FormSubmission, $submission->id, LeadAttributionEntrySurface::PublicForm,
            Request::create('/', 'POST', [], $cookies),
        );

        return $submission;
    }

    /** @return array{0: Workspace, 1: Business, 2: FormDeployment, 3: MetaAdsAccount} */
    private function ready(array $accountOverrides = [], WorkspacePlanTier $tier = WorkspacePlanTier::Growth): array
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant($tier);
        $account = $this->metaSelected($business, $accountOverrides);
        $this->seedMetaOctober($account);
        $location = $this->formsLocation($business);
        [, $deployment] = $this->liveForm($business, $location);
        $this->asMetaUser($customer);

        return [$workspace, $business, $deployment, $account];
    }

    private function html(Workspace $workspace, Business $business, array $query = []): string
    {
        return $this->get($this->metaPage($workspace, $business, 'leads.index', $query))->assertOk()->getContent();
    }

    private function plain(string $html): string
    {
        return preg_replace('/\s+/', ' ', strip_tags($html)) ?? '';
    }

    public function test_only_leads_whose_first_touch_carries_a_meta_source_tag_appear_as_campaign_tags_only(): void
    {
        [$workspace, $business, $deployment] = $this->ready();

        $included = [];
        foreach (['facebook', 'fb', 'meta', 'instagram', 'ig', 'Facebook'] as $tag) {
            $included[] = $this->lead($business, $deployment, $this->touchCookies(['utm_source' => $tag, 'utm_campaign' => 'Spring ' . $tag]));
        }

        $excluded = [
            $this->lead($business, $deployment, $this->touchCookies(['utm_source' => 'google', 'utm_campaign' => 'GoogleOnly'])),
            $this->lead($business, $deployment, $this->touchCookies(['gclid' => self::CLICK])),
            $this->lead($business, $deployment, $this->touchCookies(['utm_source' => 'facebook-ads-partner', 'utm_campaign' => 'NotExact'])),
            $this->lead($business, $deployment, $this->touchCookies(['utm_source' => 'newsletter'])),
            $this->lead($business, $deployment),
        ];

        $html = $this->html($workspace, $business);

        preg_match_all('/data-role="lead-row"/', $html, $rows);
        $this->assertCount(6, $rows[0], 'exactly the Meta-tagged leads');
        $this->assertSame(6, substr_count($html, 'Campaign tags only'));
        foreach (['facebook', 'fb', 'meta', 'instagram', 'ig'] as $tag) {
            $this->assertStringContainsString('source tag: ' . $tag, $html, 'the tag text is shown');
        }
        foreach ($included as $lead) {
            $this->assertStringContainsString($lead->contact->uid, $html);
        }
        foreach ($excluded as $lead) {
            $this->assertStringNotContainsString($lead->contact->uid, $html);
        }
        $this->assertStringNotContainsString('GoogleOnly', $html);
        $this->assertStringNotContainsString('NotExact', $html);

        $summary = $this->plain(substr($html, (int) strpos($html, 'data-role="lead-summary"'), 1500));
        $this->assertStringContainsString('6 leads with a Meta tag', $summary);
        $this->assertStringContainsString('11 leads in total', $summary);
        $this->assertSame(0, $this->fakeMeta->callCount());
    }

    public function test_the_tag_match_is_case_insensitive_whatever_case_is_stored(): void
    {
        [$workspace, $business, $deployment] = $this->ready();
        $lead = $this->lead($business, $deployment, $this->touchCookies(['utm_source' => 'facebook']));
        DB::table('lead_attribution_touches')->where('business_id', $business->id)->where('contact_id', $lead->contact_id)->update(['utm_source' => 'FaceBook']);

        $html = $this->html($workspace, $business);

        $this->assertStringContainsString($lead->contact->uid, $html);
        $this->assertStringContainsString('source tag: FaceBook', $html, 'the tag text is shown as stored, escaped');
    }

    public function test_only_the_first_touch_decides(): void
    {
        [$workspace, $business, $deployment] = $this->ready();
        $metaFirst = $this->lead($business, $deployment, $this->touchCookies(['utm_source' => 'facebook'], ['utm_source' => 'google']));
        $googleFirst = $this->lead($business, $deployment, $this->touchCookies(['utm_source' => 'google'], ['utm_source' => 'facebook']));

        $html = $this->html($workspace, $business);

        $this->assertStringContainsString($metaFirst->contact->uid, $html);
        $this->assertStringNotContainsString($googleFirst->contact->uid, $html, 'a later Meta tag does not make it a Meta lead');
    }

    public function test_the_page_states_the_tracking_boundary_and_never_names_a_meta_campaign_ad_set_or_ad(): void
    {
        [$workspace, $business, $deployment] = $this->ready();
        $this->lead($business, $deployment, $this->touchCookies(['utm_source' => 'facebook', 'utm_campaign' => 'Spring Promo']));
        $campaign = \App\Models\MetaAdsCampaign::query()->where('business_id', $business->id)->firstOrFail();
        $adSet = $this->seedMetaAdSet($campaign, 'Warm Audience Ad Set');
        $this->seedMetaAd($adSet, 'Carousel Ad Creative');

        $html = $this->html($workspace, $business);
        $plain = $this->plain($html);

        $this->assertStringContainsString('Meta Pixel, the Conversions API and Meta click-ID capture are not enabled', $plain);
        $this->assertStringContainsString('visitor-consent mechanism', $plain);
        $this->assertStringContainsString('A tag is not proof of a paid click', $plain);
        $this->assertStringContainsString('never tells us which Meta campaign, ad set or ad', $plain);
        $this->assertStringContainsString('campaign tag: Spring Promo', $html, 'the lead keeps its own tag text');

        $leads = substr($html, (int) strpos($html, 'data-role="leads-table"'));
        foreach (['Wedding Photo Booth', 'Warm Audience Ad Set', 'Carousel Ad Creative'] as $metaName) {
            $this->assertStringNotContainsString($metaName, $leads, 'no lead row is attributed to a Meta campaign / ad set / ad');
        }
        $this->assertSame(0, $this->fakeMeta->callCount());
    }

    public function test_meta_reported_results_and_business_os_outcomes_are_separate_blocks_with_separate_currencies(): void
    {
        [$workspace, $business, $deployment] = $this->ready(['currency_code' => 'EUR']);
        $lead = $this->lead($business, $deployment, $this->touchCookies(['utm_source' => 'instagram']));
        app(CrmOpportunityService::class)->create($business, $this->formsPipeline($business), $lead->contact, 'Wedding booking', 250000);
        DB::table('businesses')->where('id', $business->id)->update(['currency_code' => 'USD']);

        $html = $this->html($workspace, $business);

        $meta = substr($html, (int) strpos($html, 'data-role="meta-results"'), (int) strpos($html, 'data-role="business-outcomes"') - (int) strpos($html, 'data-role="meta-results"'));
        $outcomes = substr($html, (int) strpos($html, 'data-role="business-outcomes"'));

        $this->assertStringContainsString('EUR 78.00', $meta, 'Meta amounts are in the ad account currency');
        $this->assertStringContainsString('Leads (on-Facebook forms)', $meta);
        $this->assertStringNotContainsString('USD', $meta);
        $this->assertStringContainsString('USD 2,500', $outcomes, 'CRM opportunity value is in the Business currency');
        $this->assertStringNotContainsString('EUR', $outcomes);
        $this->assertStringContainsString('never added to or compared with ad spend', $this->plain($outcomes));
        $this->assertStringNotContainsString('ROAS', $html);
        $this->assertStringNotContainsString('2,578', $html);
    }

    public function test_without_a_result_type_the_meta_block_asks_to_choose_one(): void
    {
        [$workspace, $business] = $this->ready(['result_action_type' => null]);

        $html = $this->html($workspace, $business);

        $this->assertStringContainsString('data-role="result-type-prompt"', $html);
        $this->assertStringContainsString('Choose a result type', $html);
    }

    public function test_a_lead_links_to_the_existing_contact_page(): void
    {
        [$workspace, $business, $deployment] = $this->ready();
        $lead = $this->lead($business, $deployment, $this->touchCookies(['utm_source' => 'fb']));

        $html = $this->html($workspace, $business);

        $url = route('customer.workspaces.businesses.people.show', [$workspace->uid, $business->uid, $lead->contact->uid]);
        $this->assertStringContainsString('href="' . $url . '"', $html);
    }

    public function test_another_businesses_leads_never_appear(): void
    {
        [$workspace, $business, $deployment] = $this->ready();
        $mine = $this->lead($business, $deployment, $this->touchCookies(['utm_source' => 'facebook']));

        [, $other] = $this->metaHttpTenant(name: 'Other Co');
        $otherLocation = $this->formsLocation($other, 'Elsewhere');
        [, $otherDeployment] = $this->liveForm($other, $otherLocation);
        $theirs = $this->lead($other, $otherDeployment, $this->touchCookies(['utm_source' => 'facebook', 'utm_campaign' => 'TheirSecretCampaign']));

        $html = $this->html($workspace, $business);

        $this->assertStringContainsString('1 lead with a Meta tag', $this->plain($html));
        $this->assertStringNotContainsString('TheirSecretCampaign', $html);
        $this->assertStringNotContainsString($theirs->contact->uid, $html);
        $this->assertStringContainsString($mine->contact->uid, $html);
    }

    public function test_an_empty_period_says_so_and_the_query_count_does_not_grow_with_the_leads(): void
    {
        [$workspace, $business, $deployment] = $this->ready();

        $empty = $this->html($workspace, $business);
        $this->assertStringContainsString('data-role="no-leads"', $empty);
        $this->assertStringContainsString('0 leads with a Meta tag', $this->plain($empty));

        $count = function () use ($workspace, $business): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get($this->metaPage($workspace, $business, 'leads.index'))->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->lead($business, $deployment, $this->touchCookies(['utm_source' => 'facebook']));
        $this->lead($business, $deployment);
        $few = $count();

        for ($i = 0; $i < 4; $i++) {
            $this->lead($business, $deployment, $this->touchCookies(['utm_source' => 'ig']));
        }
        $many = $count();

        $this->assertSame($few, $many, 'the page costs the same number of queries for 2 leads as for 6');
        $this->assertLessThanOrEqual(80, $many);
    }

    public function test_the_period_filters_the_leads(): void
    {
        [$workspace, $business, $deployment] = $this->ready();
        $this->lead($business, $deployment, $this->touchCookies(['utm_source' => 'facebook']));

        $this->assertStringContainsString('1 lead with a Meta tag', $this->plain($this->html($workspace, $business, ['period' => 'last_7'])));
        $this->assertStringContainsString('0 leads with a Meta tag', $this->plain($this->html($workspace, $business, ['period' => 'previous_month'])));
    }

    public function test_the_reader_exposes_the_documented_source_tags(): void
    {
        $this->assertSame(['facebook', 'fb', 'meta', 'instagram', 'ig'], MetaAdsLeadAttributionReader::SOURCE_TAGS);
    }

    public function test_core_gets_404_and_a_missing_capability_is_refused_after_tenancy(): void
    {
        [$workspace, $business] = $this->ready(tier: WorkspacePlanTier::Core);
        $this->get($this->metaPage($workspace, $business, 'leads.index'))->assertNotFound();

        [$customer, $growthBusiness, $growthWorkspace] = $this->metaHttpTenant(name: 'Growth Leads Co');
        $this->metaSelected($growthBusiness);
        $this->asMetaUser($customer, []);
        $this->get($this->metaPage($growthWorkspace, $growthBusiness, 'leads.index'))->assertStatus(401);
    }

    public function test_without_a_selected_account_the_standard_empty_state_is_shown(): void
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $this->asMetaUser($customer);

        $html = $this->get($this->metaPage($workspace, $business, 'leads.index'))->assertOk()->getContent();

        $this->assertStringContainsString('data-state="not_connected"', $html);
    }
}

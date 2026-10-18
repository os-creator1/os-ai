<?php

namespace Tests\Feature\GoogleAds\Http\Data;

use App\Enums\GoogleAds\LeadAttributionEntrySurface;
use App\Enums\GoogleAds\LeadAttributionSubjectType;
use App\Library\Crm\CrmOpportunityService;
use App\Library\Forms\FormSubmissionService;
use App\Library\GoogleAds\Attribution\LeadAttributionRecorder;
use App\Library\GoogleAds\PhotoBoothFixture;
use App\Models\Business;
use App\Models\FormDeployment;
use App\Models\FormSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Forms\Concerns\CreatesFormsFixtures;
use Tests\Feature\GoogleAds\Attribution\Concerns\BuildsAttributionCookies;
use Tests\Feature\GoogleAds\Http\Data\Concerns\CreatesAdsDataFixtures;
use Tests\TestCase;

/**
 * Google Ads Module V1 contract §11 — Leads & conversions: Google conversions
 * and Business OS outcomes as two separate measurements; attribution levels
 * without false precision; CRM money in the Business currency, never mixed with
 * Ads-currency amounts; Business isolation; bounded queries.
 */
class AdsLeadsPageTest extends TestCase
{
    use BuildsAttributionCookies;
    // The Ads fixtures own tenancy and customers; the Forms fixtures are used only for forms / locations / pipelines.
    use CreatesAdsDataFixtures, CreatesFormsFixtures {
        CreatesAdsDataFixtures::platformAdminId insteadof CreatesFormsFixtures;
        CreatesAdsDataFixtures::ensureRequiredAppConfigRowsExist insteadof CreatesFormsFixtures;
        CreatesAdsDataFixtures::createCustomer insteadof CreatesFormsFixtures;
        CreatesAdsDataFixtures::businessAttributes insteadof CreatesFormsFixtures;
        CreatesAdsDataFixtures::createBusinessWithWorkspace insteadof CreatesFormsFixtures;
    }
    use RefreshDatabase;

    private int $phoneSeed = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareAdsHttp();
    }

    protected function tearDown(): void
    {
        $this->unpinAdsClock();
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

    /** @return array{0: \App\Models\Workspace, 1: Business, 2: FormDeployment, 3: \App\Models\GoogleAdsAccount} */
    private function ready(array $accountOverrides = []): array
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        [$account] = $this->mirroredAdsAccount($business, $accountOverrides);
        $this->seedPhotoBoothMetrics($account);
        $location = $this->formsLocation($business);
        [, $deployment] = $this->liveForm($business, $location);
        $this->asAdsUser($customer);

        return [$workspace, $business, $deployment, $account];
    }

    private function html($workspace, $business, array $query = []): string
    {
        return $this->get($this->adsUrl($workspace, $business, 'leads.index', $query))->assertOk()->getContent();
    }

    public function test_levels_tags_and_no_false_campaign_attribution(): void
    {
        [$workspace, $business, $deployment] = $this->ready();
        $click = $this->lead($business, $deployment, $this->touchCookies(['gclid' => self::CLICK]));
        $tags = $this->lead($business, $deployment, $this->touchCookies(['utm_source' => 'google', 'utm_campaign' => 'Spring Promo', 'utm_term' => 'photo booth']));
        $none = $this->lead($business, $deployment);

        $html = $this->html($workspace, $business);

        preg_match_all('/<tr[^>]*data-role="lead-row" data-level="([a-z_]+)".*?<\/tr>/s', $html, $rows);
        $this->assertEqualsCanonicalizing(['google_click', 'campaign_tags', 'not_captured'], $rows[1]);

        $this->assertStringContainsString('Google click ID captured', $html);
        $this->assertStringContainsString('Campaign tags only', $html);
        $this->assertStringContainsString('Source not captured', $html);
        $this->assertStringContainsString('campaign tag: Spring Promo', $html, 'tags are shown as tag text');
        $this->assertStringContainsString('term tag: photo booth', $html);
        $this->assertStringContainsString('Form', $html, 'entry surface');
        $this->assertStringContainsString('/sites/demo', $html, 'landing page');

        $summary = preg_replace('/\s+/', ' ', strip_tags(substr($html, (int) strpos($html, 'data-role="lead-summary"'), 1500)));
        $this->assertStringContainsString('3 leads', $summary);
        $this->assertStringContainsString('1 with a Google click ID', $summary);
        $this->assertStringContainsString('1 with campaign tags only', $summary);
        $this->assertStringContainsString('1 source not captured', $summary);

        // The explainer says what the click id proves, and that nothing is guessed.
        $plain = preg_replace('/\s+/', ' ', strip_tags($html));
        $this->assertStringContainsString('does not tell us which campaign or keyword', $plain);
        $this->assertStringContainsString('offline conversion import', $plain);

        // Not one lead row is attributed to a Google campaign or keyword.
        $leadsBlock = substr($html, (int) strpos($html, 'data-role="leads-table"'));
        foreach (['Photo Booth Rental', 'Wedding Photo Booth', '360 Booth'] as $campaignName) {
            $this->assertStringNotContainsString($campaignName, $leadsBlock);
        }
        $this->assertSame(0, $this->fakeAds->callCount());
    }

    public function test_google_conversions_and_business_outcomes_are_separate_blocks_with_separate_currencies(): void
    {
        [$workspace, $business, $deployment, $account] = $this->ready(['currency_code' => 'EUR']);
        $lead = $this->lead($business, $deployment, $this->touchCookies(['gclid' => self::CLICK]));
        $pipeline = $this->formsPipeline($business);
        app(CrmOpportunityService::class)->create($business, $pipeline, $lead->contact, 'Wedding booking', 250000);
        DB::table('businesses')->where('id', $business->id)->update(['currency_code' => 'USD']);

        $html = $this->html($workspace, $business);

        $google = substr($html, (int) strpos($html, 'data-role="google-conversions"'), (int) strpos($html, 'data-role="business-outcomes"') - (int) strpos($html, 'data-role="google-conversions"'));
        $business_ = substr($html, (int) strpos($html, 'data-role="business-outcomes"'));

        $this->assertStringContainsString('EUR 24.00', $google, 'Google amounts are in the Ads account currency');
        $this->assertStringNotContainsString('USD', $google);
        $this->assertStringContainsString('USD 2,500', $business_, 'CRM opportunity value is in the Business currency');
        $this->assertStringNotContainsString('EUR', $business_);
        $this->assertStringContainsString('never added to or compared with ad spend', preg_replace('/\s+/', ' ', strip_tags($business_)));

        // No combined / compared figure exists anywhere.
        $this->assertStringNotContainsString('2,524', $html);
        $this->assertStringNotContainsString('ROAS', $html);
        $this->assertStringContainsString('Google conversions', $google);
        $this->assertStringNotContainsString('Business OS leads', $google);
    }

    public function test_a_lead_links_to_the_existing_contact_page(): void
    {
        [$workspace, $business, $deployment] = $this->ready();
        $lead = $this->lead($business, $deployment, $this->touchCookies(['gclid' => self::CLICK]));

        $html = $this->html($workspace, $business);

        $url = route('customer.workspaces.businesses.people.show', [$workspace->uid, $business->uid, $lead->contact->uid]);
        $this->assertStringContainsString('href="' . $url . '"', $html);
    }

    public function test_another_businesses_leads_never_appear(): void
    {
        [$workspace, $business, $deployment] = $this->ready();
        $mine = $this->lead($business, $deployment, $this->touchCookies(['gclid' => self::CLICK]));

        [, $other] = $this->adsHttpTenant();
        $otherLocation = $this->formsLocation($other, 'Elsewhere');
        [, $otherDeployment] = $this->liveForm($other, $otherLocation);
        $theirs = $this->lead($other, $otherDeployment, $this->touchCookies(['gclid' => 'TheirClickIdAAAAAAA1', 'utm_campaign' => 'TheirSecretCampaign']));

        $html = $this->html($workspace, $business);

        $this->assertStringContainsString('1 lead', preg_replace('/\s+/', ' ', strip_tags($html)));
        $this->assertStringNotContainsString('TheirSecretCampaign', $html);
        $this->assertStringNotContainsString($theirs->contact->uid, $html);
        $this->assertStringContainsString($mine->contact->uid, $html);
    }

    public function test_an_empty_period_says_so_and_the_query_count_does_not_grow_with_the_leads(): void
    {
        [$workspace, $business, $deployment] = $this->ready();

        $empty = $this->html($workspace, $business);
        $this->assertStringContainsString('data-role="no-leads"', $empty);
        $this->assertStringContainsString('0 leads', preg_replace('/\s+/', ' ', strip_tags($empty)));

        $count = function () use ($workspace, $business): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get($this->adsUrl($workspace, $business, 'leads.index'))->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->lead($business, $deployment, $this->touchCookies(['gclid' => self::CLICK]));
        $this->lead($business, $deployment);
        $few = $count();

        for ($i = 0; $i < 4; $i++) {
            $this->lead($business, $deployment, $this->touchCookies(['utm_source' => 'google']));
        }
        $many = $count();

        $this->assertSame($few, $many, 'the page costs the same number of queries for 2 leads as for 6');
        $this->assertLessThanOrEqual(70, $many, 'and stays small in absolute terms');
    }

    public function test_the_google_block_lists_campaign_conversions_from_cached_facts_only(): void
    {
        [$workspace, $business] = $this->ready();

        $html = $this->html($workspace, $business);

        $this->assertStringContainsString('data-role="google-conversion-row"', $html);
        $this->assertStringContainsString('Photo Booth Rental', $html);
        $this->assertSame(0, $this->fakeAds->callCount());
    }
}

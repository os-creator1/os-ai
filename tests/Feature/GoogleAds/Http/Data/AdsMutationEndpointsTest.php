<?php

namespace Tests\Feature\GoogleAds\Http\Data;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\GoogleAds\GoogleAdsEntityStatus;
use App\Enums\GoogleBusinessProfile\GoogleOperationStatus;
use App\Exceptions\GoogleAds\GoogleAdsProviderException;
use App\Http\Middleware\VerifyCsrfToken;
use App\Library\GoogleAds\PhotoBoothFixture;
use App\Library\ViewAs\ViewAsProhibitedActions;
use App\Models\BusinessGoogleOperation;
use App\Models\GoogleAdsKeyword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\GoogleAds\Http\Data\Concerns\CreatesAdsDataFixtures;
use Tests\TestCase;

/**
 * Google Ads Module V1 contract §6 — the HTTP side of pause / resume (campaign
 * and keyword). The FAKE provider only. Every request carries LOCAL
 * identifiers; a foreign or Google-style id is a 404 with no provider call; a
 * resubmit never sends a second change; an ambiguous answer is shown calmly
 * as "pending confirmation" and never replayed.
 */
class AdsMutationEndpointsTest extends TestCase
{
    use CreatesAdsDataFixtures;
    use RefreshDatabase;

    private const MUTATION_ROUTES = [
        'campaigns.pause', 'campaigns.resume', 'keywords.pause', 'keywords.resume',
        'search-terms.negative.preview', 'search-terms.negative.store', 'search-terms.ignore', 'search-terms.unignore',
    ];

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

    /** @return array{0: \App\Models\Workspace, 1: \App\Models\Business, 2: \App\Models\GoogleAdsAccount, 3: array<string, \App\Models\GoogleAdsCampaign>} */
    private function ready(WorkspacePlanTier $tier = WorkspacePlanTier::Growth, array $permissions = [self::VIEW, self::MANAGE]): array
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant($tier);
        [$account, $campaigns] = $this->mirroredAdsAccount($business);
        $this->asAdsUser($customer, $permissions);

        return [$workspace, $business, $account, $campaigns];
    }

    private function statusOf(string $table, int $id): string
    {
        return (string) \DB::table($table)->where('id', $id)->value('status');
    }

    // ---------------------------------------------------------------
    // Campaign pause / resume
    // ---------------------------------------------------------------

    public function test_pausing_a_campaign_succeeds_with_exactly_one_provider_call(): void
    {
        [$workspace, $business, , $campaigns] = $this->ready();
        $rental = $campaigns[PhotoBoothFixture::CAMPAIGN_RENTAL];

        $response = $this->adsPost($workspace, $business, 'campaigns.pause', [$rental->uid], ['from' => 'campaigns']);

        $response->assertRedirect($this->adsUrl($workspace, $business, 'campaigns.index'));
        $response->assertSessionHas('status', 'success');
        $this->assertSame(1, $this->fakeAds->callCount('setCampaignStatus'));
        $this->assertSame(PhotoBoothFixture::CAMPAIGN_RENTAL, $this->fakeAds->callsTo('setCampaignStatus')[0]['args']['campaign_id'], 'the external id was derived server-side');
        $this->assertSame('PAUSED', $this->statusOf('google_ads_campaigns', $rental->id));
        $this->assertSame(GoogleAdsEntityStatus::Paused, $this->fakeAds->campaignStatus(PhotoBoothFixture::CUSTOMER_ID, PhotoBoothFixture::CAMPAIGN_RENTAL));
    }

    public function test_resuming_a_paused_campaign_succeeds_once(): void
    {
        [$workspace, $business, , $campaigns] = $this->ready();
        $rental = $campaigns[PhotoBoothFixture::CAMPAIGN_RENTAL];
        $this->adsPost($workspace, $business, 'campaigns.pause', [$rental->uid]);

        $this->adsPost($workspace, $business, 'campaigns.resume', [$rental->uid])->assertSessionHas('status', 'success');

        $this->assertSame(2, $this->fakeAds->callCount('setCampaignStatus'));
        $this->assertSame('ENABLED', $this->statusOf('google_ads_campaigns', $rental->id));
    }

    public function test_a_double_click_or_resubmit_never_sends_a_second_change(): void
    {
        [$workspace, $business, , $campaigns] = $this->ready();
        $rental = $campaigns[PhotoBoothFixture::CAMPAIGN_RENTAL];

        $this->adsPost($workspace, $business, 'campaigns.pause', [$rental->uid]);
        $second = $this->adsPost($workspace, $business, 'campaigns.pause', [$rental->uid]);

        $second->assertSessionHas('status', 'info');
        $this->assertSame(1, $this->fakeAds->callCount('setCampaignStatus'), 'the repeat is answered locally');
        $this->assertSame(1, BusinessGoogleOperation::query()->where('operation_type', 'ads_campaign_status_changed')->count());
    }

    public function test_an_ambiguous_timeout_is_shown_as_pending_confirmation_and_changes_nothing_locally(): void
    {
        [$workspace, $business, , $campaigns] = $this->ready();
        $rental = $campaigns[PhotoBoothFixture::CAMPAIGN_RENTAL];
        $this->fakeAds->failNext('setCampaignStatus', GoogleAdsProviderException::timeout(true));

        $response = $this->adsPost($workspace, $business, 'campaigns.pause', [$rental->uid], ['from' => 'campaigns']);

        $response->assertSessionHas('status', 'warning');
        $response->assertSessionHas('message', "Google didn't confirm this change. We'll verify it on the next update. Nothing was changed twice.");
        $response->assertSessionHas('message_title', 'Pending confirmation');
        $this->assertSame('ENABLED', $this->statusOf('google_ads_campaigns', $rental->id), 'the local row is untouched');
        $this->assertSame(GoogleOperationStatus::Unknown, BusinessGoogleOperation::query()->where('operation_type', 'ads_campaign_status_changed')->firstOrFail()->status);

        // The owner can see the state is not yet certain ...
        $html = $this->get($this->adsUrl($workspace, $business, 'campaigns.index'))->getContent();
        $this->assertStringContainsString('data-role="pending-confirmation"', $html);

        // ... and pressing again is answered from the ledger, never replayed.
        $again = $this->adsPost($workspace, $business, 'campaigns.pause', [$rental->uid]);
        $again->assertSessionHas('status', 'warning');
        $this->assertSame(1, $this->fakeAds->callCount('setCampaignStatus'));
    }

    public function test_a_provider_rejection_is_a_calm_failure_and_changes_nothing(): void
    {
        [$workspace, $business, , $campaigns] = $this->ready();
        $rental = $campaigns[PhotoBoothFixture::CAMPAIGN_RENTAL];
        $this->fakeAds->failNext('setCampaignStatus', GoogleAdsProviderException::validation());

        $response = $this->adsPost($workspace, $business, 'campaigns.pause', [$rental->uid]);

        $response->assertSessionHas('status', 'error');
        $this->assertSame('ENABLED', $this->statusOf('google_ads_campaigns', $rental->id));
        $this->assertStringNotContainsString('googleads', (string) session('message'));
    }

    public function test_a_removed_campaign_is_refused_with_a_message_and_no_provider_call(): void
    {
        [$workspace, $business, , $campaigns] = $this->ready();
        $rental = $campaigns[PhotoBoothFixture::CAMPAIGN_RENTAL];
        $rental->forceFill(['status' => 'REMOVED'])->save();

        $this->adsPost($workspace, $business, 'campaigns.pause', [$rental->uid])->assertSessionHas('status', 'error');

        $this->assertSame(0, $this->fakeAds->callCount('setCampaignStatus'));
    }

    public function test_the_action_returns_to_the_detail_page_with_the_chosen_filters(): void
    {
        [$workspace, $business, , $campaigns] = $this->ready();
        $rental = $campaigns[PhotoBoothFixture::CAMPAIGN_RENTAL];

        $this->adsPost($workspace, $business, 'campaigns.pause', [$rental->uid], [
            'from' => 'campaign', 'return_campaign' => $rental->uid, 'q' => 'period=last_7&evil=1',
        ])->assertRedirect($this->adsUrl($workspace, $business, 'campaigns.show') . '/' . $rental->uid . '?period=last_7');

        // A hostile "from" / "return" is never followed anywhere else.
        $this->adsPost($workspace, $business, 'campaigns.resume', [$rental->uid], ['from' => 'https://evil.test', 'return_campaign' => 'https://evil.test', 'q' => 'period=last_7'])
            ->assertRedirect($this->adsUrl($workspace, $business, 'campaigns.index'));
    }

    // ---------------------------------------------------------------
    // Keyword pause / resume
    // ---------------------------------------------------------------

    public function test_pausing_and_resuming_a_keyword_each_make_one_provider_call(): void
    {
        [$workspace, $business, $account] = $this->ready();
        $keyword = $this->keywordNamed($account, 'photo booth rental');

        $this->adsPost($workspace, $business, 'keywords.pause', [$keyword->uid], ['from' => 'keywords'])
            ->assertRedirect($this->adsUrl($workspace, $business, 'keywords.index'))
            ->assertSessionHas('status', 'success');
        $this->assertSame(1, $this->fakeAds->callCount('setKeywordStatus'));
        $this->assertSame('PAUSED', $this->statusOf('google_ads_keywords', $keyword->id));

        $this->adsPost($workspace, $business, 'keywords.pause', [$keyword->uid])->assertSessionHas('status', 'info');
        $this->assertSame(1, $this->fakeAds->callCount('setKeywordStatus'), 'a resubmit sends nothing');

        $this->adsPost($workspace, $business, 'keywords.resume', [$keyword->uid])->assertSessionHas('status', 'success');
        $this->assertSame(2, $this->fakeAds->callCount('setKeywordStatus'));
        $this->assertSame('ENABLED', $this->statusOf('google_ads_keywords', $keyword->id));
    }

    public function test_a_negative_keyword_cannot_be_paused_and_nothing_is_sent(): void
    {
        [$workspace, $business, $account] = $this->ready();
        $negative = GoogleAdsKeyword::query()->where('google_ads_account_id', $account->id)->where('is_negative', true)->firstOrFail();

        $this->adsPost($workspace, $business, 'keywords.pause', [$negative->uid])->assertSessionHas('status', 'error');

        $this->assertSame(0, $this->fakeAds->callCount('setKeywordStatus'));
    }

    // ---------------------------------------------------------------
    // Isolation
    // ---------------------------------------------------------------

    public function test_a_foreign_uid_or_a_google_id_is_a_404_with_zero_provider_calls(): void
    {
        [$workspace, $business] = $this->ready();
        [, $otherBusiness] = $this->adsHttpTenant();
        [$otherAccount, $otherCampaigns] = $this->mirroredAdsAccount($otherBusiness);
        $foreignCampaign = $otherCampaigns[PhotoBoothFixture::CAMPAIGN_RENTAL];
        $foreignKeyword = $this->keywordNamed($otherAccount, 'photo booth rental');

        $this->adsPost($workspace, $business, 'campaigns.pause', [$foreignCampaign->uid])->assertNotFound();
        $this->adsPost($workspace, $business, 'campaigns.resume', [$foreignCampaign->uid])->assertNotFound();
        $this->adsPost($workspace, $business, 'keywords.pause', [$foreignKeyword->uid])->assertNotFound();
        $this->adsPost($workspace, $business, 'keywords.resume', [$foreignKeyword->uid])->assertNotFound();

        // Google's own ids are never accepted as identifiers, in the path or the body.
        $this->adsPost($workspace, $business, 'campaigns.pause', [PhotoBoothFixture::CAMPAIGN_RENTAL])->assertNotFound();
        $own = $this->rentalCampaign($this->selectedOwn($business));
        $this->adsPost($workspace, $business, 'campaigns.pause', ['00000000-0000-4000-8000-000000000000'], ['campaign_id' => PhotoBoothFixture::CAMPAIGN_RENTAL, 'external_campaign_id' => PhotoBoothFixture::CAMPAIGN_RENTAL])->assertNotFound();

        $this->assertSame(0, $this->fakeAds->callCount('setCampaignStatus'));
        $this->assertSame(0, $this->fakeAds->callCount('setKeywordStatus'));
        $this->assertSame('ENABLED', $this->statusOf('google_ads_campaigns', $foreignCampaign->id));
        $this->assertSame('ENABLED', $this->statusOf('google_ads_campaigns', $own->id));
    }

    private function selectedOwn(\App\Models\Business $business): \App\Models\GoogleAdsAccount
    {
        return \App\Models\GoogleAdsAccount::query()->where('business_id', $business->id)->firstOrFail();
    }

    public function test_another_customers_business_route_is_a_404(): void
    {
        [$workspace, $business, , $campaigns] = $this->ready();
        [$stranger] = $this->adsHttpTenant();
        $this->asAdsUser($stranger);

        $this->adsPost($workspace, $business, 'campaigns.pause', [$campaigns[PhotoBoothFixture::CAMPAIGN_RENTAL]->uid])->assertNotFound();
        $this->assertSame(0, $this->fakeAds->callCount('setCampaignStatus'));
    }

    public function test_a_core_business_cannot_mutate(): void
    {
        [$workspace, $business, , $campaigns] = $this->ready(WorkspacePlanTier::Core);

        $this->adsPost($workspace, $business, 'campaigns.pause', [$campaigns[PhotoBoothFixture::CAMPAIGN_RENTAL]->uid])->assertNotFound();
        $this->assertSame(0, $this->fakeAds->callCount('setCampaignStatus'));
    }

    // ---------------------------------------------------------------
    // Authorization, View As, CSRF
    // ---------------------------------------------------------------

    public function test_without_manage_permission_every_mutation_is_refused_and_changes_nothing(): void
    {
        [$workspace, $business, $account, $campaigns] = $this->ready(WorkspacePlanTier::Growth, [self::VIEW]);
        $rental = $campaigns[PhotoBoothFixture::CAMPAIGN_RENTAL];
        $keyword = $this->keywordNamed($account, 'photo booth rental');

        $this->adsPost($workspace, $business, 'campaigns.pause', [$rental->uid])->assertStatus(401);
        $this->adsPost($workspace, $business, 'campaigns.resume', [$rental->uid])->assertStatus(401);
        $this->adsPost($workspace, $business, 'keywords.pause', [$keyword->uid])->assertStatus(401);
        $this->adsPost($workspace, $business, 'keywords.resume', [$keyword->uid])->assertStatus(401);
        $this->adsPost($workspace, $business, 'search-terms.negative.preview', [], ['search_term_id' => 1, 'campaign' => $rental->uid])->assertStatus(401);
        $this->adsPost($workspace, $business, 'search-terms.negative.store', [], ['search_term_id' => 1, 'campaign' => $rental->uid])->assertStatus(401);
        $this->adsPost($workspace, $business, 'search-terms.ignore', [], ['search_term_id' => 1])->assertStatus(401);
        $this->adsPost($workspace, $business, 'search-terms.unignore', [], ['search_term_id' => 1])->assertStatus(401);

        $this->assertSame(0, $this->fakeAds->callCount());
        $this->assertSame('ENABLED', $this->statusOf('google_ads_campaigns', $rental->id));
    }

    public function test_view_as_prohibits_every_mutation_route(): void
    {
        $prohibited = app(ViewAsProhibitedActions::class);

        foreach (self::MUTATION_ROUTES as $name) {
            $route = Route::getRoutes()->getByName('customer.workspaces.businesses.ads.' . $name);
            $this->assertNotNull($route, "[{$name}] is registered");
            $this->assertTrue($prohibited->isProhibitedRoute($route, 'POST'), "[{$name}] is prohibited while viewing as a client.");
        }
    }

    public function test_every_mutation_route_rejects_a_missing_csrf_token_with_419(): void
    {
        $this->app->bind(VerifyCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends VerifyCsrfToken {
            protected function runningUnitTests()
            {
                return false;
            }
        });

        [$workspace, $business, , $campaigns] = $this->ready();
        $uid = $campaigns[PhotoBoothFixture::CAMPAIGN_RENTAL]->uid;

        foreach (self::MUTATION_ROUTES as $name) {
            $parameters = str_starts_with($name, 'campaigns.') || str_starts_with($name, 'keywords.') ? [$uid] : [];
            $this->adsPost($workspace, $business, $name, $parameters, ['search_term_id' => 1, 'campaign' => $uid])->assertStatus(419);
        }

        $this->assertSame(0, $this->fakeAds->callCount());
    }

    public function test_the_mutation_routes_are_throttled_post_only(): void
    {
        foreach (self::MUTATION_ROUTES as $name) {
            $route = Route::getRoutes()->getByName('customer.workspaces.businesses.ads.' . $name);

            $this->assertSame(['POST'], array_values(array_diff($route->methods(), ['OPTIONS'])), "[{$name}] is POST only");
            $this->assertContains('throttle:20,1', $route->gatherMiddleware(), "[{$name}] is throttled");
        }
    }
}

<?php

namespace Tests\Feature\GoogleAds\Http\Data;

use App\Enums\GoogleBusinessProfile\GoogleOperationStatus;
use App\Exceptions\GoogleAds\GoogleAdsProviderException;
use App\Library\GoogleAds\PhotoBoothFixture;
use App\Models\BusinessGoogleOperation;
use App\Models\GoogleAdsKeyword;
use App\Models\GoogleAdsSearchTerm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\GoogleAds\Http\Data\Concerns\CreatesAdsDataFixtures;
use Tests\TestCase;

/**
 * Google Ads Module V1 contract §6 step 7 — the server-driven "Add negative"
 * confirmation and the Ignore / Un-ignore classification.
 *
 * The preview shows exactly the term, the place and the match type, makes NO
 * provider call, and offers only exact (default) and phrase; the store posts
 * LOCAL ids only (the term text comes from the cached row); campaign scope is
 * the default; a repeat or an already-excluded term sends nothing; Ignore is a
 * local, Business-scoped, reversible classification.
 */
class AdsNegativeKeywordFlowTest extends TestCase
{
    use CreatesAdsDataFixtures;
    use RefreshDatabase;

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

    /** @return array{0: \App\Models\Workspace, 1: \App\Models\Business, 2: \App\Models\GoogleAdsAccount, 3: \App\Models\GoogleAdsCampaign, 4: \App\Models\GoogleAdsSearchTerm} */
    private function ready(string $term = 'cheap photo booth hire'): array
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        [$account, $campaigns] = $this->mirroredAdsAccount($business);
        $this->asAdsUser($customer);

        return [$workspace, $business, $account, $campaigns[PhotoBoothFixture::CAMPAIGN_RENTAL], $this->seedTerm($account, $term, 24_000_000, 12, '0')];
    }

    private function text(string $html): string
    {
        return trim(preg_replace('/\s+/', ' ', strip_tags($html)));
    }

    // ---------------------------------------------------------------
    // Preview
    // ---------------------------------------------------------------

    public function test_the_preview_shows_the_exact_term_place_and_match_and_makes_zero_provider_calls(): void
    {
        [$workspace, $business, , $campaign, $term] = $this->ready();

        $html = $this->adsPost($workspace, $business, 'search-terms.negative.preview', [], [
            'search_term_id' => $term->id, 'campaign' => $campaign->uid, 'from' => 'search-terms',
        ])->assertOk()->getContent();

        $text = $this->text($html);
        $this->assertStringContainsString('Search term "cheap photo booth hire"', $text);
        $this->assertStringContainsString('Will be excluded from Photo Booth Rental (campaign)', $text);
        $this->assertStringContainsString('Match exact', $text);
        $this->assertMatchesRegularExpression('/id="match-exact"[^>]*checked/', $html, 'exact is the default');
        $this->assertMatchesRegularExpression('/id="scope-campaign"[^>]*checked/', $html, 'campaign scope is the default');
        $this->assertStringNotContainsString('value="broad"', strtolower($html), 'broad is never offered');
        $this->assertStringContainsString('Ad group: Photo Booth Rental - General', $text);
        $this->assertStringContainsString('Nothing changes in Google Ads until you press', $text);
        $this->assertStringContainsString('name="_token"', $html);
        $this->assertSame(0, $this->fakeAds->callCount(), 'a preview never calls Google');
        $this->assertSame(0, BusinessGoogleOperation::query()->where('operation_type', 'ads_negative_keyword_added')->count(), 'and writes no ledger row');

        // The confirm form carries LOCAL ids only, never the term text or a Google id.
        $this->assertStringContainsString('name="search_term_id" value="' . $term->id . '"', $html);
        $this->assertStringNotContainsString(PhotoBoothFixture::CAMPAIGN_RENTAL, $html);
        $this->assertStringNotContainsString('name="term"', $html);
    }

    public function test_the_preview_reflects_a_chosen_ad_group_scope_and_phrase_match(): void
    {
        [$workspace, $business, , $campaign, $term] = $this->ready();

        $html = $this->adsPost($workspace, $business, 'search-terms.negative.preview', [], [
            'search_term_id' => $term->id, 'campaign' => $campaign->uid, 'scope' => 'ad_group', 'match' => 'phrase',
        ])->assertOk()->getContent();

        $text = $this->text($html);
        $this->assertStringContainsString('Will be excluded from Photo Booth Rental - General (ad group)', $text);
        $this->assertStringContainsString('Match phrase', $text);
        $this->assertMatchesRegularExpression('/id="scope-ad_group"[^>]*checked/', $html);
        $this->assertMatchesRegularExpression('/id="match-phrase"[^>]*checked/', $html);
        $this->assertSame(0, $this->fakeAds->callCount());
    }

    public function test_broad_or_unknown_choices_fall_back_to_exact_and_campaign(): void
    {
        [$workspace, $business, , $campaign, $term] = $this->ready();

        $html = $this->adsPost($workspace, $business, 'search-terms.negative.preview', [], [
            'search_term_id' => $term->id, 'campaign' => $campaign->uid, 'scope' => 'account', 'match' => 'broad',
        ])->assertOk()->getContent();

        $text = $this->text($html);
        $this->assertStringContainsString('Match exact', $text);
        $this->assertStringContainsString('(campaign)', $text);
    }

    public function test_the_preview_warns_when_the_negative_already_exists_and_disables_confirming(): void
    {
        [$workspace, $business, , $campaign] = $this->ready();
        $already = $this->seedTerm($this->selectedOwnAccount($business), 'diy photo booth', 22_000_000, 9, '0');

        // The synced campaign-level negative for "diy photo booth" is a phrase match.
        $html = $this->adsPost($workspace, $business, 'search-terms.negative.preview', [], [
            'search_term_id' => $already->id, 'campaign' => $campaign->uid, 'match' => 'phrase',
        ])->assertOk()->getContent();

        $this->assertStringContainsString('data-role="already-excluded"', $html);
        $this->assertMatchesRegularExpression('/data-role="confirm-negative"[^>]*disabled/', $html);

        // An exact match is a different negative, so it is not flagged.
        $exact = $this->adsPost($workspace, $business, 'search-terms.negative.preview', [], [
            'search_term_id' => $already->id, 'campaign' => $campaign->uid, 'match' => 'exact',
        ])->getContent();
        $this->assertStringNotContainsString('data-role="already-excluded"', $exact);
        $this->assertSame(0, $this->fakeAds->callCount());
    }

    // ---------------------------------------------------------------
    // Store
    // ---------------------------------------------------------------

    public function test_confirming_defaults_to_campaign_scope_and_exact_match_with_one_provider_call(): void
    {
        [$workspace, $business, $account, $campaign, $term] = $this->ready();

        $response = $this->adsPost($workspace, $business, 'search-terms.negative.store', [], [
            'search_term_id' => $term->id, 'campaign' => $campaign->uid, 'from' => 'search-terms',
            // A tampered term text / external id in the body is never read.
            'term' => 'something else', 'text' => 'something else', 'campaign_id' => PhotoBoothFixture::CAMPAIGN_360,
        ]);

        $response->assertRedirect($this->adsUrl($workspace, $business, 'search-terms.index'));
        $response->assertSessionHas('status', 'success');

        $calls = $this->fakeAds->callsTo('addNegativeKeyword');
        $this->assertCount(1, $calls);
        $this->assertSame('campaign', $calls[0]['args']['scope']);
        $this->assertSame('EXACT', $calls[0]['args']['match_type']);
        $this->assertSame('cheap photo booth hire', $calls[0]['args']['text']);
        $this->assertSame(PhotoBoothFixture::CAMPAIGN_RENTAL, $calls[0]['args']['parent_id'], 'the parent id was derived server-side');

        $this->assertTrue(GoogleAdsKeyword::query()->where('google_ads_account_id', $account->id)->where('is_negative', true)
            ->where('level', 'campaign')->where('match_type', 'EXACT')->whereRaw('LOWER(text) = ?', ['cheap photo booth hire'])->exists());
    }

    public function test_an_ad_group_scope_negative_is_sent_to_the_terms_ad_group(): void
    {
        [$workspace, $business, , $campaign, $term] = $this->ready();

        $this->adsPost($workspace, $business, 'search-terms.negative.store', [], [
            'search_term_id' => $term->id, 'campaign' => $campaign->uid, 'scope' => 'ad_group', 'match' => 'phrase',
        ])->assertSessionHas('status', 'success');

        $call = $this->fakeAds->callsTo('addNegativeKeyword')[0]['args'];
        $this->assertSame('ad_group', $call['scope']);
        $this->assertSame('PHRASE', $call['match_type']);
        $this->assertSame('3000000001', $call['parent_id']);
    }

    public function test_a_repeat_or_an_already_excluded_term_sends_nothing(): void
    {
        [$workspace, $business, , $campaign, $term] = $this->ready();
        $data = ['search_term_id' => $term->id, 'campaign' => $campaign->uid];

        $this->adsPost($workspace, $business, 'search-terms.negative.store', [], $data)->assertSessionHas('status', 'success');
        $again = $this->adsPost($workspace, $business, 'search-terms.negative.store', [], $data);

        $again->assertSessionHas('status', 'info');
        $this->assertSame(1, $this->fakeAds->callCount('addNegativeKeyword'), 'a double click sends one change');
        $this->assertSame(1, BusinessGoogleOperation::query()->where('operation_type', 'ads_negative_keyword_added')->count());

        // A negative that already exists in Google Ads (synced) is never sent again.
        $diy = $this->seedTerm($this->selectedOwnAccount($business), 'diy photo booth', 22_000_000, 9, '0');
        $this->adsPost($workspace, $business, 'search-terms.negative.store', [], ['search_term_id' => $diy->id, 'campaign' => $campaign->uid, 'match' => 'phrase'])
            ->assertSessionHas('status', 'info');
        $this->assertSame(1, $this->fakeAds->callCount('addNegativeKeyword'));
    }

    public function test_an_ambiguous_timeout_shows_awaiting_confirmation_and_a_pending_marker(): void
    {
        [$workspace, $business, , $campaign, $term] = $this->ready();
        $this->fakeAds->failNext('addNegativeKeyword', GoogleAdsProviderException::timeout(true));

        $response = $this->adsPost($workspace, $business, 'search-terms.negative.store', [], ['search_term_id' => $term->id, 'campaign' => $campaign->uid]);

        $response->assertSessionHas('status', 'warning');
        $response->assertSessionHas('message', "Google didn't confirm this change. We'll verify it on the next update. Nothing was changed twice.");
        $this->assertSame(GoogleOperationStatus::Unknown, BusinessGoogleOperation::query()->where('operation_type', 'ads_negative_keyword_added')->firstOrFail()->status);
        $this->assertFalse(GoogleAdsKeyword::query()->where('is_negative', true)->whereRaw('LOWER(text) = ?', ['cheap photo booth hire'])->exists(), 'no local change');

        $row = $this->get($this->adsUrl($workspace, $business, 'search-terms.index'))->getContent();
        $this->assertStringContainsString('data-role="pending-confirmation"', $row);

        $this->adsPost($workspace, $business, 'search-terms.negative.store', [], ['search_term_id' => $term->id, 'campaign' => $campaign->uid]);
        $this->assertSame(1, $this->fakeAds->callCount('addNegativeKeyword'), 'never replayed');
    }

    public function test_foreign_and_mismatched_identifiers_are_404_with_zero_provider_calls(): void
    {
        [$workspace, $business, , $campaign, $term] = $this->ready();
        [, $otherBusiness] = $this->adsHttpTenant();
        [$otherAccount, $otherCampaigns] = $this->mirroredAdsAccount($otherBusiness);
        $foreignTerm = $this->seedTerm($otherAccount, 'cheap photo booth hire', 24_000_000, 12, '0');
        $foreignCampaign = $otherCampaigns[PhotoBoothFixture::CAMPAIGN_RENTAL];

        foreach (['search-terms.negative.preview', 'search-terms.negative.store', 'search-terms.ignore', 'search-terms.unignore'] as $route) {
            // Another Business's term, with either Business's campaign uid.
            $this->adsPost($workspace, $business, $route, [], ['search_term_id' => $foreignTerm->id, 'campaign' => $campaign->uid])->assertNotFound();
            $this->adsPost($workspace, $business, $route, [], ['search_term_id' => $foreignTerm->id, 'campaign' => $foreignCampaign->uid])->assertNotFound();
            // My term, with a campaign that is not its own.
            $this->adsPost($workspace, $business, $route, [], ['search_term_id' => $term->id, 'campaign' => $foreignCampaign->uid])->assertNotFound();
            // A Google id instead of a uid, and a non-numeric / missing term id.
            $this->adsPost($workspace, $business, $route, [], ['search_term_id' => $term->id, 'campaign' => PhotoBoothFixture::CAMPAIGN_RENTAL])->assertNotFound();
            $this->adsPost($workspace, $business, $route, [], ['search_term_id' => 'abc', 'campaign' => $campaign->uid])->assertNotFound();
            $this->adsPost($workspace, $business, $route, [], ['campaign' => $campaign->uid])->assertNotFound();
        }

        $this->assertSame(0, $this->fakeAds->callCount());
        $this->assertSame('unreviewed', $this->reviewState($foreignTerm));
    }

    // ---------------------------------------------------------------
    // Ignore / Un-ignore
    // ---------------------------------------------------------------

    private function reviewState(GoogleAdsSearchTerm $term): string
    {
        $state = GoogleAdsSearchTerm::query()->whereKey($term->id)->value('review_state');

        return $state instanceof \BackedEnum ? $state->value : (string) $state;
    }

    private function selectedOwnAccount(\App\Models\Business $business): \App\Models\GoogleAdsAccount
    {
        return \App\Models\GoogleAdsAccount::query()->where('business_id', $business->id)->firstOrFail();
    }

    public function test_ignoring_a_term_is_local_reversible_and_removes_it_from_the_waste_summary(): void
    {
        [$workspace, $business, , $campaign, $term] = $this->ready();

        $before = $this->get($this->adsUrl($workspace, $business, 'search-terms.index'))->getContent();
        $this->assertStringContainsString('data-role="waste-summary"', $before);
        $this->assertStringContainsString('data-role="ignore-term"', $before);

        $this->adsPost($workspace, $business, 'search-terms.ignore', [], ['search_term_id' => $term->id, 'campaign' => $campaign->uid, 'from' => 'search-terms'])
            ->assertRedirect($this->adsUrl($workspace, $business, 'search-terms.index'))
            ->assertSessionHas('status', 'success');

        $this->assertSame('ignored', $this->reviewState($term));
        $after = $this->get($this->adsUrl($workspace, $business, 'search-terms.index'))->getContent();
        $this->assertStringNotContainsString('data-role="waste-summary"', $after, 'an ignored term is not potential waste');
        $this->assertMatchesRegularExpression('/data-class="ignored"/', $after);
        $this->assertStringContainsString('data-role="unignore-term"', $after);

        $this->adsPost($workspace, $business, 'search-terms.unignore', [], ['search_term_id' => $term->id, 'campaign' => $campaign->uid])->assertSessionHas('status', 'success');
        $this->assertSame('unreviewed', $this->reviewState($term));
        $this->assertStringContainsString('data-role="waste-summary"', $this->get($this->adsUrl($workspace, $business, 'search-terms.index'))->getContent());

        $this->assertSame(0, $this->fakeAds->callCount(), 'ignoring is not a provider mutation');
        $this->assertSame(0, BusinessGoogleOperation::query()->count(), 'and has no ledger row');
    }

    public function test_ignore_applies_to_that_term_in_that_campaign_and_ad_group_only_and_survives_new_days(): void
    {
        [$workspace, $business, $account, $campaign, $term] = $this->ready();
        $sameTermAnotherCampaign = $this->seedSearchTerm(
            \App\Models\GoogleAdsCampaign::query()->where('google_ads_account_id', $account->id)->where('external_campaign_id', PhotoBoothFixture::CAMPAIGN_360)->firstOrFail(),
            \App\Models\GoogleAdsAdGroup::query()->where('external_ad_group_id', '3000000005')->where('google_ads_account_id', $account->id)->firstOrFail(),
            'cheap photo booth hire', '2026-10-02', 24_000_000, 12, '0',
        );
        $earlierDay = $this->seedSearchTerm($campaign, $term->adGroup, 'cheap photo booth hire', '2026-10-01', 1_000_000, 1, '0');

        $this->adsPost($workspace, $business, 'search-terms.ignore', [], ['search_term_id' => $term->id, 'campaign' => $campaign->uid]);

        $this->assertSame('ignored', $this->reviewState($term));
        $this->assertSame('ignored', $this->reviewState($earlierDay), 'every cached day of that term/campaign/ad group');
        $this->assertSame('unreviewed', $this->reviewState($sameTermAnotherCampaign), 'another campaign is untouched');

        // A day that arrives AFTER the decision is stored unreviewed but stays ignored.
        $this->seedSearchTerm($campaign, $term->adGroup, 'cheap photo booth hire', '2026-10-03', 2_000_000, 2, '0');
        $html = $this->get($this->adsUrl($workspace, $business, 'search-terms.index', ['period' => 'last_7']))->getContent();
        preg_match_all('/<tr[^>]*data-role="search-term-row" data-class="([a-z_]+)".*?<\/tr>/s', $html, $rows);
        $this->assertContains('ignored', $rows[1]);
    }

    public function test_the_confirmation_page_is_not_reachable_by_get(): void
    {
        [$workspace, $business] = $this->ready();

        $this->assertContains($this->get($this->adsUrl($workspace, $business, 'search-terms.index') . '/negative/preview')->getStatusCode(), [404, 405]);
        $this->assertContains($this->get($this->adsUrl($workspace, $business, 'search-terms.index') . '/negative')->getStatusCode(), [404, 405]);
        $this->assertSame(0, $this->fakeAds->callCount());
    }
}

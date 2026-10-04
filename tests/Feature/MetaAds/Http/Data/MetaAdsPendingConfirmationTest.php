<?php

namespace Tests\Feature\MetaAds\Http\Data;

use App\Enums\MetaAds\MetaOperationStatus;
use App\Exceptions\MetaAds\MetaProviderException;
use App\Models\BusinessMetaOperation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\MetaAds\Http\Data\Concerns\CreatesMetaAdsDataFixtures;
use Tests\TestCase;

/**
 * Meta Ads Module V1 contract 24 section 7 — "Pending confirmation": a
 * pause / resume whose answer Meta never gave leaves its ledger operation
 * `unknown`; the owner sees that on the row (campaigns, ad sets, ads and the
 * campaign detail) until the next update settles it, and only on that row.
 */
class MetaAdsPendingConfirmationTest extends TestCase
{
    use CreatesMetaAdsDataFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareMetaHttp();
    }

    protected function tearDown(): void
    {
        $this->unpinMetaClock();
        parent::tearDown();
    }

    /** @return array{0: \App\Models\Workspace, 1: \App\Models\Business, 2: \App\Models\MetaAdsAccount} */
    private function ready(): array
    {
        $t = $this->mutationTenant();
        $this->asMetaUser($t['customer']);

        return [$t['workspace'], $t['business'], $t['account'], $t['customer']];
    }

    private function badgeCount(string $html): int
    {
        return substr_count($html, 'data-role="pending-confirmation"');
    }

    public function test_no_badge_without_an_outstanding_change(): void
    {
        [$workspace, $business] = $this->ready();

        foreach (['campaigns.index', 'ad-sets.index', 'ads.index'] as $name) {
            $this->assertSame(0, $this->badgeCount($this->get($this->metaUrl($workspace, $business, $name))->getContent()), $name);
        }
    }

    public function test_an_ambiguous_campaign_change_badges_that_campaign_on_the_list_and_the_detail(): void
    {
        [$workspace, $business, $account] = $this->ready();
        $campaign = $this->campaignOf($account);
        $this->fakeMeta->failNext('setStatus', MetaProviderException::timeout(true));
        $this->metaPost($workspace, $business, 'campaigns.pause', [$campaign->uid]);

        $list = $this->get($this->metaUrl($workspace, $business, 'campaigns.index'))->getContent();
        $this->assertSame(1, $this->badgeCount($list), 'only the affected campaign');
        $this->assertStringContainsString('Pending confirmation', $this->rowOf($list, $campaign->name));

        $detail = $this->get($this->metaCampaignUrl($workspace, $business, $campaign->uid))->getContent();
        $this->assertSame(1, $this->badgeCount($detail));

        $other = $this->get($this->metaCampaignUrl($workspace, $business, $this->campaignOf($account, \App\Library\MetaAds\MetaPhotoBoothFixture::CAMPAIGN_WEDDING)->uid))->getContent();
        $this->assertSame(0, $this->badgeCount($other));
    }

    public function test_an_ambiguous_ad_set_change_badges_the_ad_set_table_only(): void
    {
        [$workspace, $business, $account] = $this->ready();
        $adSet = $this->adSetOf($account);
        $this->fakeMeta->failNext('setStatus', MetaProviderException::timeout(true));
        $this->metaPost($workspace, $business, 'ad-sets.pause', [$adSet->uid]);

        $this->assertSame(1, $this->badgeCount($this->get($this->metaUrl($workspace, $business, 'ad-sets.index'))->getContent()));
        $this->assertSame(0, $this->badgeCount($this->get($this->metaUrl($workspace, $business, 'ads.index'))->getContent()));
        $this->assertSame(0, $this->badgeCount($this->get($this->metaUrl($workspace, $business, 'campaigns.index'))->getContent()));
    }

    public function test_an_ambiguous_ad_change_badges_the_ad_table_only(): void
    {
        [$workspace, $business, $account] = $this->ready();
        $ad = $this->adOf($account);
        $this->fakeMeta->failNext('setStatus', MetaProviderException::timeout(true));
        $this->metaPost($workspace, $business, 'ads.pause', [$ad->uid]);

        $this->assertSame(1, $this->badgeCount($this->get($this->metaUrl($workspace, $business, 'ads.index'))->getContent()));
        $this->assertSame(0, $this->badgeCount($this->get($this->metaUrl($workspace, $business, 'ad-sets.index'))->getContent()));
    }

    public function test_the_badge_goes_away_once_the_operation_is_settled(): void
    {
        [$workspace, $business, $account] = $this->ready();
        $campaign = $this->campaignOf($account);
        $this->fakeMeta->failNext('setStatus', MetaProviderException::timeout(true));
        $this->metaPost($workspace, $business, 'campaigns.pause', [$campaign->uid]);
        $this->assertSame(1, $this->badgeCount($this->get($this->metaUrl($workspace, $business, 'campaigns.index'))->getContent()));

        BusinessMetaOperation::query()->where('operation_type', 'campaign_status_changed')->update(['status' => MetaOperationStatus::Failed->value]);

        $this->assertSame(0, $this->badgeCount($this->get($this->metaUrl($workspace, $business, 'campaigns.index'))->getContent()));
    }

    public function test_a_definite_failure_or_a_deferral_is_not_pending(): void
    {
        [$workspace, $business, $account] = $this->ready();
        $this->fakeMeta->failNext('setStatus', MetaProviderException::validation(100));
        $this->metaPost($workspace, $business, 'campaigns.pause', [$this->campaignOf($account)->uid]);
        $this->fakeMeta->failNext('setStatus', MetaProviderException::rateLimited(80004));
        $this->metaPost($workspace, $business, 'ad-sets.pause', [$this->adSetOf($account)->uid]);

        foreach (['campaigns.index', 'ad-sets.index', 'ads.index'] as $name) {
            $this->assertSame(0, $this->badgeCount($this->get($this->metaUrl($workspace, $business, $name))->getContent()), $name);
        }
    }

    public function test_another_businesss_pending_change_never_shows_here(): void
    {
        [$workspace, $business, , $mineCustomer] = $this->ready();

        $other = $this->mutationTenant(name: 'Other Co');
        $this->asMetaUser($other['customer']);
        $this->fakeMeta->failNext('setStatus', MetaProviderException::timeout(true));
        $this->metaPost($other['workspace'], $other['business'], 'campaigns.pause', [$this->campaignOf($other['account'])->uid]);
        $this->assertSame(1, $this->badgeCount($this->get($this->metaUrl($other['workspace'], $other['business'], 'campaigns.index'))->getContent()));

        $this->asMetaUser($mineCustomer);
        $this->assertSame(0, $this->badgeCount($this->get($this->metaUrl($workspace, $business, 'campaigns.index'))->getContent()));
    }

    private function rowOf(string $html, string $needle): string
    {
        preg_match_all('/<tr data-role="[a-z-]+-row">.*?<\/tr>/s', $html, $matches);

        foreach ($matches[0] as $row) {
            if (str_contains($row, $needle)) {
                return $row;
            }
        }

        $this->fail('No row contains [' . $needle . ']');
    }
}

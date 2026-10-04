<?php

namespace Tests\Feature\MetaAds\Http\Data;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\MetaAds\MetaConnectionState;
use App\Enums\MetaAds\MetaOperationStatus;
use App\Exceptions\MetaAds\MetaProviderException;
use App\Http\Middleware\VerifyCsrfToken;
use App\Library\MetaAds\MetaPhotoBoothFixture;
use App\Library\ViewAs\ViewAsProhibitedActions;
use App\Models\BusinessMetaOperation;
use App\Models\MetaAdsAd;
use App\Models\MetaAdsAdSet;
use App\Models\MetaAdsCampaign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Feature\MetaAds\Http\Data\Concerns\CreatesMetaAdsDataFixtures;
use Tests\TestCase;

/**
 * Meta Ads Module V1 contract 24 section 7 — the HTTP side of pause / resume
 * for campaigns, ad sets and ads. FAKE provider only. Every request carries a
 * LOCAL uid; a foreign or Meta-style id is a 404 with no provider call; a
 * resubmit never sends a second change; an ambiguous answer is shown calmly as
 * "pending confirmation" and never replayed; flashes are fixed platform copy
 * and the redirect target is never taken from the request.
 */
class MetaAdsMutationEndpointsTest extends TestCase
{
    use CreatesMetaAdsDataFixtures;
    use RefreshDatabase;

    private const ROUTES = [
        'campaigns.pause', 'campaigns.resume', 'ad-sets.pause', 'ad-sets.resume', 'ads.pause', 'ads.resume',
    ];

    private const ACCOUNT = MetaPhotoBoothFixture::AD_ACCOUNT_ID;

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

    /**
     * @return array{0: \App\Models\Workspace, 1: \App\Models\Business, 2: \App\Models\MetaAdsAccount, 3: array<string, mixed>}
     */
    private function ready(WorkspacePlanTier $tier = WorkspacePlanTier::Growth, bool $canManage = true, array $connectionOverrides = [], array $permissions = [self::VIEW, self::MANAGE]): array
    {
        $t = $this->mutationTenant($tier, $canManage, connectionOverrides: $connectionOverrides);
        $this->asMetaUser($t['customer'], $permissions);

        return [$t['workspace'], $t['business'], $t['account'], $t];
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string}> */
    public static function kinds(): array
    {
        return [
            'campaign' => ['campaigns', 'campaignOf', 'campaigns.index', 'campaigns'],
            'ad set' => ['ad-sets', 'adSetOf', 'ad-sets.index', 'ad-sets'],
            'ad' => ['ads', 'adOf', 'ads.index', 'ads'],
        ];
    }

    private function statusOf(string $table, int $id): string
    {
        return (string) DB::table($table)->where('id', $id)->value('status');
    }

    private function tableFor(string $routeGroup): string
    {
        return ['campaigns' => 'meta_ads_campaigns', 'ad-sets' => 'meta_ads_ad_sets', 'ads' => 'meta_ads_ads'][$routeGroup];
    }

    private function providerStatus(string $group, $row): ?string
    {
        return match ($group) {
            'campaigns' => $this->fakeMeta->campaignStatus(self::ACCOUNT, (string) $row->external_campaign_id),
            'ad-sets' => $this->fakeMeta->adSetStatus(self::ACCOUNT, (string) $row->external_ad_set_id),
            default => $this->fakeMeta->adStatus(self::ACCOUNT, (string) $row->external_ad_id),
        };
    }

    private function statusOperations(): int
    {
        return BusinessMetaOperation::query()->whereIn('operation_type', ['campaign_status_changed', 'ad_set_status_changed', 'ad_status_changed'])->count();
    }

    // ---------------------------------------------------------------
    // Success
    // ---------------------------------------------------------------

    #[\PHPUnit\Framework\Attributes\DataProvider('kinds')]
    public function test_pause_then_resume_each_make_exactly_one_provider_call(string $group, string $finder, string $listRoute, string $from): void
    {
        [$workspace, $business, $account] = $this->ready();
        $row = $this->{$finder}($account);

        $this->metaPost($workspace, $business, $group . '.pause', [$row->uid], ['from' => $from])
            ->assertRedirect($this->metaUrl($workspace, $business, $listRoute))
            ->assertSessionHas('status', 'success');

        $this->assertSame(1, $this->fakeMeta->callCount('setStatus'));
        $this->assertSame('PAUSED', $this->statusOf($this->tableFor($group), $row->id));
        $this->assertSame('PAUSED', $this->providerStatus($group, $row));

        $this->metaPost($workspace, $business, $group . '.resume', [$row->uid], ['from' => $from])
            ->assertSessionHas('status', 'success')
            ->assertSessionHas('message', 'Done. Meta Ads has been updated.');

        $this->assertSame(2, $this->fakeMeta->callCount('setStatus'));
        $this->assertSame('ACTIVE', $this->statusOf($this->tableFor($group), $row->id));
    }

    public function test_the_external_id_is_derived_server_side_and_a_body_id_is_ignored(): void
    {
        [$workspace, $business, $account] = $this->ready();
        $campaign = $this->campaignOf($account);

        $this->metaPost($workspace, $business, 'campaigns.pause', [$campaign->uid], [
            'campaign_id' => MetaPhotoBoothFixture::CAMPAIGN_WEDDING,
            'external_campaign_id' => MetaPhotoBoothFixture::CAMPAIGN_WEDDING,
            'external_id' => MetaPhotoBoothFixture::CAMPAIGN_WEDDING,
        ]);

        $calls = $this->fakeMeta->callsTo('setStatus');
        $this->assertCount(1, $calls);
        $this->assertSame(MetaPhotoBoothFixture::CAMPAIGN_LEADS, $calls[0]['args']['external_id']);
        $this->assertSame('ACTIVE', $this->providerStatus('campaigns', $this->campaignOf($account, MetaPhotoBoothFixture::CAMPAIGN_WEDDING)));
    }

    public function test_a_double_click_or_resubmit_never_sends_a_second_change(): void
    {
        [$workspace, $business, $account] = $this->ready();
        $campaign = $this->campaignOf($account);

        $this->metaPost($workspace, $business, 'campaigns.pause', [$campaign->uid]);
        $second = $this->metaPost($workspace, $business, 'campaigns.pause', [$campaign->uid]);

        $second->assertSessionHas('status', 'info');
        $second->assertSessionHas('message', 'Already in that state. Nothing was changed.');
        $this->assertSame(1, $this->fakeMeta->callCount('setStatus'));
        $this->assertSame(1, $this->statusOperations());
    }

    // ---------------------------------------------------------------
    // Outcomes: fixed copy, no provider text
    // ---------------------------------------------------------------

    public function test_an_ambiguous_timeout_is_pending_confirmation_with_fixed_copy_and_is_never_replayed(): void
    {
        [$workspace, $business, $account] = $this->ready();
        $campaign = $this->campaignOf($account);
        $this->fakeMeta->failNext('setStatus', MetaProviderException::timeout(true, 2, 'FB-TRACE-SECRET'));

        $response = $this->metaPost($workspace, $business, 'campaigns.pause', [$campaign->uid], ['from' => 'campaigns']);

        $response->assertSessionHas('status', 'warning');
        $response->assertSessionHas('message', "Meta didn't confirm this change. We'll verify it on the next update. Nothing was changed twice.");
        $response->assertSessionHas('message_title', 'Pending confirmation');
        $this->assertSame('ACTIVE', $this->statusOf('meta_ads_campaigns', $campaign->id), 'the local row is untouched');
        $this->assertSame(MetaOperationStatus::Unknown, BusinessMetaOperation::query()->where('operation_type', 'campaign_status_changed')->firstOrFail()->status);

        $html = $this->get($this->metaUrl($workspace, $business, 'campaigns.index'))->getContent();
        $this->assertStringContainsString('data-role="pending-confirmation"', $html);

        // Pressing again, in either direction, is answered from the ledger.
        foreach (['campaigns.pause', 'campaigns.resume'] as $name) {
            $again = $this->metaPost($workspace, $business, $name, [$campaign->uid]);
            $again->assertSessionHas('status', 'warning');
            $again->assertSessionHas('message', "Meta didn't confirm this change. We'll verify it on the next update. Nothing was changed twice.");
        }
        $this->assertSame(1, $this->fakeMeta->callCount('setStatus'));
        $this->assertStringNotContainsString('FB-TRACE-SECRET', (string) session('message'));
    }

    public function test_a_throttled_answer_is_deferred_calmly_and_not_retried(): void
    {
        [$workspace, $business, $account] = $this->ready();
        $campaign = $this->campaignOf($account);
        $this->fakeMeta->failNext('setStatus', MetaProviderException::rateLimited(80004, 'FB-TRACE-SECRET', 600));

        $response = $this->metaPost($workspace, $business, 'campaigns.pause', [$campaign->uid]);

        $response->assertSessionHas('status', 'warning');
        $response->assertSessionHas('message', 'Meta is busy right now. Nothing was changed; please try again shortly.');
        $this->assertSame(1, $this->fakeMeta->callCount('setStatus'), 'no inline retry');
        $this->assertSame('ACTIVE', $this->statusOf('meta_ads_campaigns', $campaign->id));
        $this->assertStringNotContainsString('80004', (string) session('message'));
    }

    public function test_a_provider_rejection_is_a_fixed_failure_and_changes_nothing(): void
    {
        [$workspace, $business, $account] = $this->ready();
        $adSet = $this->adSetOf($account);
        $this->fakeMeta->failNext('setStatus', MetaProviderException::validation(100, 'FB-TRACE-SECRET'));

        $response = $this->metaPost($workspace, $business, 'ad-sets.pause', [$adSet->uid]);

        $response->assertSessionHas('status', 'error');
        $response->assertSessionHas('message', 'Meta did not accept this change. Nothing was changed.');
        $this->assertSame('ACTIVE', $this->statusOf('meta_ads_ad_sets', $adSet->id));
        $this->assertStringNotContainsString('FB-TRACE-SECRET', (string) session('message'));
    }

    public function test_a_dead_token_asks_to_reconnect(): void
    {
        [$workspace, $business, $account] = $this->ready();
        $this->fakeMeta->failNext('setStatus', MetaProviderException::tokenExpired('FB-TRACE-SECRET'));

        $response = $this->metaPost($workspace, $business, 'ads.pause', [$this->adOf($account)->uid]);

        $response->assertSessionHas('status', 'error');
        $response->assertSessionHas('message', 'Your Meta connection needs to be renewed. Reconnect Meta to continue. Nothing was changed.');
        $this->assertStringNotContainsString('FB-TRACE-SECRET', (string) session('message'));
    }

    public function test_a_read_only_connection_says_to_reconnect_and_sends_nothing(): void
    {
        [$workspace, $business, $account] = $this->ready(connectionOverrides: ['granted_scopes' => 'ads_read']);

        $response = $this->metaPost($workspace, $business, 'campaigns.pause', [$this->campaignOf($account)->uid], ['from' => 'campaigns']);

        $response->assertRedirect($this->metaUrl($workspace, $business, 'campaigns.index'));
        $response->assertSessionHas('status', 'error');
        $response->assertSessionHas('message', 'Reconnect Meta to allow pause/resume.');
        $this->assertSame(0, $this->fakeMeta->callCount());
        $this->assertSame(0, $this->statusOperations());
    }

    public function test_an_expired_connection_refuses_calmly_and_sends_nothing(): void
    {
        [$workspace, $business, $account] = $this->ready(connectionOverrides: ['state' => MetaConnectionState::Expired, 'access_token_encrypted' => null]);

        $response = $this->metaPost($workspace, $business, 'campaigns.pause', [$this->campaignOf($account)->uid]);

        $response->assertSessionHas('status', 'error');
        $this->assertSame(0, $this->fakeMeta->callCount());
    }

    public function test_deleted_targets_and_unapproved_ads_are_refused_with_a_message_and_no_provider_call(): void
    {
        [$workspace, $business, $account] = $this->ready();

        $campaign = $this->campaignOf($account);
        $campaign->forceFill(['status' => 'DELETED'])->save();
        $this->metaPost($workspace, $business, 'campaigns.pause', [$campaign->uid])->assertSessionHas('status', 'error');

        $ad = $this->adOf($account, MetaPhotoBoothFixture::AD_DISAPPROVED);
        $ad->forceFill(['status' => 'PAUSED', 'effective_status' => 'DISAPPROVED'])->save();
        $this->metaPost($workspace, $business, 'ads.resume', [$ad->uid])
            ->assertSessionHas('status', 'error')
            ->assertSessionHas('message', 'This ad is not approved yet and cannot be resumed.');

        $this->assertSame(0, $this->fakeMeta->callCount('setStatus'));
    }

    // ---------------------------------------------------------------
    // Redirect target is never taken from the request
    // ---------------------------------------------------------------

    public function test_the_action_returns_to_the_detail_page_with_only_whitelisted_filters(): void
    {
        [$workspace, $business, $account] = $this->ready();
        $campaign = $this->campaignOf($account);

        $this->metaPost($workspace, $business, 'campaigns.pause', [$campaign->uid], [
            'from' => 'campaign', 'return_campaign' => $campaign->uid, 'q' => 'period=last_7&evil=1&status=active',
        ])->assertRedirect($this->metaCampaignUrl($workspace, $business, $campaign->uid, ['period' => 'last_7', 'status' => 'active']));
    }

    public function test_the_action_returns_to_the_list_it_came_from_with_filters(): void
    {
        [$workspace, $business, $account] = $this->ready();

        $this->metaPost($workspace, $business, 'ad-sets.pause', [$this->adSetOf($account)->uid], ['from' => 'ad-sets', 'q' => 'campaign=abc&sort=spend&dir=desc&page=2&x=y'])
            ->assertRedirect($this->metaUrl($workspace, $business, 'ad-sets.index', ['sort' => 'spend', 'dir' => 'desc', 'campaign' => 'abc', 'page' => 2]));

        $this->metaPost($workspace, $business, 'ads.pause', [$this->adOf($account)->uid], ['from' => 'ads', 'q' => 'ad_set=zzz&status=paused'])
            ->assertRedirect($this->metaUrl($workspace, $business, 'ads.index', ['status' => 'paused', 'ad_set' => 'zzz']));
    }

    public function test_a_hostile_from_return_or_referer_is_never_followed(): void
    {
        [$workspace, $business, $account] = $this->ready();
        $campaign = $this->campaignOf($account);
        $listing = $this->metaUrl($workspace, $business, 'campaigns.index');

        $hostile = [
            ['from' => 'https://evil.test', 'return_campaign' => 'https://evil.test', 'q' => 'period=last_7'],
            ['from' => '//evil.test/x', 'return_campaign' => '//evil.test'],
            ['from' => 'campaign', 'return_campaign' => 'javascript:alert(1)'],
            ['from' => ['campaigns'], 'return_campaign' => [$campaign->uid], 'q' => ['period' => 'last_7']],
            ['from' => 'campaign', 'return_campaign' => str_repeat('a', 36)],
        ];

        foreach ($hostile as $data) {
            // A resume of an ACTIVE campaign is a harmless no-op: only the redirect is under test.
            $response = $this->withHeader('Referer', 'https://evil.test/back')->metaPost($workspace, $business, 'campaigns.resume', [$campaign->uid], $data);
            $location = (string) $response->headers->get('Location');

            $this->assertStringStartsWith(url('/'), $location);
            $this->assertStringNotContainsString('evil.test', $location);
            $this->assertStringNotContainsString('javascript', $location);
        }

        $this->withHeader('Referer', 'https://evil.test/back')->metaPost($workspace, $business, 'campaigns.resume', [$campaign->uid], ['from' => 'https://evil.test'])
            ->assertRedirect($listing);
    }

    // ---------------------------------------------------------------
    // Isolation
    // ---------------------------------------------------------------

    public function test_a_foreign_uid_or_a_meta_id_is_a_404_with_zero_provider_calls_on_every_route(): void
    {
        [$workspace, $business, , $mine] = $this->ready();
        $other = $this->mutationTenant(name: 'Other Co');
        $foreign = [
            'campaigns' => $this->campaignOf($other['account']),
            'ad-sets' => $this->adSetOf($other['account']),
            'ads' => $this->adOf($other['account']),
        ];
        $external = [
            'campaigns' => MetaPhotoBoothFixture::CAMPAIGN_LEADS,
            'ad-sets' => MetaPhotoBoothFixture::AD_SET_FATIGUED,
            'ads' => MetaPhotoBoothFixture::AD_WITH_THUMBNAIL,
        ];

        $this->asMetaUser($mine['customer']);

        foreach (['campaigns', 'ad-sets', 'ads'] as $group) {
            foreach (['pause', 'resume'] as $verb) {
                $this->metaPost($workspace, $business, "{$group}.{$verb}", [$foreign[$group]->uid])->assertNotFound();
                $this->metaPost($workspace, $business, "{$group}.{$verb}", [$external[$group]])->assertNotFound();
                $this->metaPost($workspace, $business, "{$group}.{$verb}", ['00000000-0000-4000-8000-000000000000'], ['external_id' => $external[$group]])->assertNotFound();
            }
        }

        $this->assertSame(0, $this->fakeMeta->callCount('setStatus'));
        $this->assertSame(0, $this->statusOperations());
        $this->assertSame('ACTIVE', $this->statusOf('meta_ads_campaigns', $foreign['campaigns']->id));
    }

    public function test_another_customers_business_route_is_a_404(): void
    {
        [$workspace, $business, $account] = $this->ready();
        $other = $this->mutationTenant(name: 'Stranger Co');
        $this->asMetaUser($other['customer']);

        $this->metaPost($workspace, $business, 'campaigns.pause', [$this->campaignOf($account)->uid])->assertNotFound();
        $this->assertSame(0, $this->fakeMeta->callCount());
    }

    public function test_a_core_business_cannot_mutate(): void
    {
        [$workspace, $business, $account] = $this->ready(WorkspacePlanTier::Core);

        foreach (self::ROUTES as $name) {
            $this->metaPost($workspace, $business, $name, [$this->campaignOf($account)->uid])->assertNotFound();
        }
        $this->assertSame(0, $this->fakeMeta->callCount());
    }

    public function test_a_business_with_no_plan_cannot_mutate(): void
    {
        [$customer, $business, $workspace] = $this->dataTenant(null, withAccount: false);
        $this->asMetaUser($customer);

        $this->metaPost($workspace, $business, 'campaigns.pause', ['00000000-0000-4000-8000-000000000000'])->assertNotFound();
        $this->assertSame(0, $this->fakeMeta->callCount());
    }

    // ---------------------------------------------------------------
    // Authorization, View As, CSRF, method
    // ---------------------------------------------------------------

    public function test_without_manage_permission_every_mutation_is_401_and_changes_nothing(): void
    {
        [$workspace, $business, $account] = $this->ready(WorkspacePlanTier::Growth, true, [], [self::VIEW]);
        $uids = ['campaigns' => $this->campaignOf($account)->uid, 'ad-sets' => $this->adSetOf($account)->uid, 'ads' => $this->adOf($account)->uid];

        foreach (['campaigns', 'ad-sets', 'ads'] as $group) {
            foreach (['pause', 'resume'] as $verb) {
                $this->metaPost($workspace, $business, "{$group}.{$verb}", [$uids[$group]])->assertStatus(401);
            }
        }

        $this->assertSame(0, $this->fakeMeta->callCount());
        $this->assertSame(0, $this->statusOperations());
        $this->assertSame('ACTIVE', $this->statusOf('meta_ads_campaigns', $this->campaignOf($account)->id));
    }

    public function test_the_permission_check_follows_tenancy_so_a_stranger_without_it_still_gets_404(): void
    {
        [$workspace, $business, $account] = $this->ready();
        $stranger = $this->mutationTenant(name: 'No Manage Co', canManage: false);
        $this->asMetaUser($stranger['customer'], [self::VIEW]);

        $this->metaPost($workspace, $business, 'campaigns.pause', [$this->campaignOf($account)->uid])->assertNotFound();
    }

    public function test_view_as_prohibits_every_mutation_route(): void
    {
        $prohibited = app(ViewAsProhibitedActions::class);

        foreach (self::ROUTES as $name) {
            $route = Route::getRoutes()->getByName('customer.workspaces.businesses.ads.meta.' . $name);
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

        [$workspace, $business, $account] = $this->ready();
        $uid = $this->campaignOf($account)->uid;

        foreach (self::ROUTES as $name) {
            $this->metaPost($workspace, $business, $name, [$uid])->assertStatus(419);
        }

        $this->assertSame(0, $this->fakeMeta->callCount());
    }

    public function test_the_mutation_routes_are_throttled_post_only_and_a_get_is_not_allowed(): void
    {
        foreach (self::ROUTES as $name) {
            $route = Route::getRoutes()->getByName('customer.workspaces.businesses.ads.meta.' . $name);

            $this->assertSame(['POST'], array_values(array_diff($route->methods(), ['OPTIONS'])), "[{$name}] is POST only");
            $this->assertContains('throttle:20,1', $route->gatherMiddleware(), "[{$name}] is throttled");
        }

        [$workspace, $business, $account] = $this->ready();
        // The app maps a wrong-method request to its 404 page; either way nothing is changed.
        $status = $this->get(route('customer.workspaces.businesses.ads.meta.campaigns.pause', [$workspace->uid, $business->uid, $this->campaignOf($account)->uid]))->getStatusCode();
        $this->assertContains($status, [404, 405]);
        $this->assertSame(0, $this->fakeMeta->callCount());
    }

    public function test_the_controller_stays_thin_it_only_calls_the_service(): void
    {
        $source = (string) file_get_contents(app_path('Http/Controllers/Customer/Business/MetaAdsMutationController.php'));

        foreach (['MetaMutationClient', 'setStatus', 'Http::', 'GuzzleHttp', 'BusinessMetaOperation', 'MetaAdsOperationLedger'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source, "the controller never talks to the provider or the ledger: {$forbidden}");
        }
        $this->assertStringContainsString('MetaAdsMutationService', $source);
    }
}

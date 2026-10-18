<?php

namespace Tests\Feature\MetaAds\Mutations;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\MetaAds\MetaConnectionState;
use App\Enums\MetaAds\MetaOperationType;
use App\Exceptions\MetaAds\MetaAdsMutationConnectionException;
use App\Exceptions\MetaAds\MetaAdsMutationException;
use App\Exceptions\MetaAds\MetaAdsMutationForbiddenException;
use App\Exceptions\MetaAds\MetaAdsMutationNotEntitledException;
use App\Exceptions\MetaAds\MetaAdsMutationNotFoundException;
use App\Exceptions\MetaAds\MetaAdsMutationValidationException;
use App\Exceptions\MetaAds\MetaProviderException;
use App\Library\MetaAds\MetaAdsConnectionManager;
use App\Library\MetaAds\MetaPhotoBoothFixture;
use App\Library\MetaAds\Mutations\MetaAdsMutationService;
use App\Models\BusinessMetaOperation;
use App\Models\MetaAdsAccount;
use App\Models\MetaAdsMutation;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\MetaAds\Mutations\Concerns\CreatesMetaMutationFixtures;
use Tests\TestCase;

/**
 * Meta Ads Module V1 contract 24 §7 steps 1-2 — the service's own gate:
 * everything resolves inside the Business's SELECTED account, and permission,
 * entitlement and connection are enforced with ZERO provider calls and zero
 * ledger rows when refused. (View As is blocked at the HTTP layer; that is the
 * UI/route lane's contract, not the service's.)
 */
class MetaAdsMutationGuardsTest extends TestCase
{
    use CreatesMetaMutationFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindFakeMeta();
    }

    private function service(): MetaAdsMutationService
    {
        return app(MetaAdsMutationService::class);
    }

    private function assertNothingHappened(): void
    {
        $this->assertSame(0, $this->fakeMeta->callCount());
        $this->assertSame(0, BusinessMetaOperation::query()->whereIn('operation_type', [
            MetaOperationType::CampaignStatusChanged->value, MetaOperationType::AdSetStatusChanged->value, MetaOperationType::AdStatusChanged->value,
        ])->count());
        $this->assertSame(0, MetaAdsMutation::query()->count());
    }

    /** @param  array<string, mixed>  $t */
    private function act(array $t, string $method, string $uid): mixed
    {
        return $this->service()->{$method}($t['business'], $t['workspace'], (int) $t['actor']->id, $uid);
    }

    /** @return array<string, array{0: string, 1: string}> method, target finder */
    public static function everyOperation(): array
    {
        return [
            'pause campaign' => ['pauseCampaign', 'campaign'],
            'resume campaign' => ['resumeCampaign', 'campaign'],
            'pause ad set' => ['pauseAdSet', 'adSet'],
            'resume ad set' => ['resumeAdSet', 'adSet'],
            'pause ad' => ['pauseAd', 'ad'],
            'resume ad' => ['resumeAd', 'ad'],
        ];
    }

    #[DataProvider('everyOperation')]
    public function test_another_businesss_uid_fails_closed_as_not_found_with_no_call_and_no_ledger_row(string $method, string $kind): void
    {
        $mine = $this->mutationTenant(name: 'Mine');
        $theirs = $this->mutationTenant(name: 'Theirs');
        $row = match ($kind) {
            'campaign' => $this->campaignOf($theirs['account']),
            'adSet' => $this->adSetOf($theirs['account']),
            'ad' => $this->adOf($theirs['account']),
        };
        $before = $row->status;

        try {
            $this->act($mine, $method, $row->uid);
            $this->fail('expected the foreign target to be refused');
        } catch (MetaAdsMutationNotFoundException $e) {
            $this->assertSame(404, $e->httpStatus());
            $this->assertSame('target_not_found', $e->reason);
        }

        $this->assertNothingHappened();
        $this->assertSame($before, $row->fresh()->status);
    }

    public function test_a_request_supplied_external_id_or_junk_is_never_trusted_as_a_target(): void
    {
        $t = $this->mutationTenant();

        foreach ([
            fn () => $this->act($t, 'pauseCampaign', MetaPhotoBoothFixture::CAMPAIGN_LEADS),
            fn () => $this->act($t, 'pauseAdSet', MetaPhotoBoothFixture::AD_SET_FATIGUED),
            fn () => $this->act($t, 'pauseAd', MetaPhotoBoothFixture::AD_WITH_THUMBNAIL),
            fn () => $this->act($t, 'pauseCampaign', "x' OR 1=1 --"),
            fn () => $this->act($t, 'pauseCampaign', ''),
            fn () => $this->act($t, 'pauseCampaign', '00000000-0000-0000-0000-000000000000'),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('expected a not-found refusal');
            } catch (MetaAdsMutationNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertNothingHappened();
    }

    public function test_a_campaign_uid_cannot_be_used_as_an_ad_set_or_ad_target(): void
    {
        $t = $this->mutationTenant();
        $campaign = $this->campaignOf($t['account']);

        foreach (['pauseAdSet', 'pauseAd'] as $method) {
            try {
                $this->act($t, $method, $campaign->uid);
                $this->fail('expected a not-found refusal');
            } catch (MetaAdsMutationNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertNothingHappened();
    }

    public function test_a_business_with_no_selected_account_is_not_found(): void
    {
        $t = $this->mutationTenant();
        $campaign = $this->campaignOf($t['account']);

        MetaAdsAccount::query()->whereKey($t['account']->id)->update(['selected_at' => null]);

        try {
            $this->act($t, 'pauseCampaign', $campaign->uid);
            $this->fail('expected a refusal');
        } catch (MetaAdsMutationNotFoundException $e) {
            $this->assertSame('account_not_found', $e->reason);
        }

        $this->assertNothingHappened();
        $this->assertSame('ACTIVE', $campaign->fresh()->status);
    }

    public function test_a_disconnect_unselects_the_account_and_blocks_writes(): void
    {
        $t = $this->mutationTenant();
        $campaign = $this->campaignOf($t['account']);

        app(MetaAdsConnectionManager::class)->disconnect($t['connection'], (int) $t['actor']->id);

        $this->expectException(MetaAdsMutationNotFoundException::class);

        try {
            $this->act($t, 'pauseCampaign', $campaign->uid);
        } finally {
            $this->assertNothingHappened();
        }
    }

    #[DataProvider('everyOperation')]
    public function test_an_actor_without_manage_meta_ads_is_forbidden(string $method, string $kind): void
    {
        $t = $this->mutationTenant(canManage: false);
        $row = match ($kind) {
            'campaign' => $this->campaignOf($t['account']),
            'adSet' => $this->adSetOf($t['account']),
            'ad' => $this->adOf($t['account']),
        };

        try {
            $this->act($t, $method, $row->uid);
            $this->fail('expected a refusal');
        } catch (MetaAdsMutationForbiddenException $e) {
            $this->assertSame(403, $e->httpStatus());
            $this->assertSame('missing_permission', $e->reason);
        }

        $this->assertNothingHappened();
    }

    public function test_an_unknown_actor_is_forbidden(): void
    {
        $t = $this->mutationTenant();

        $this->expectException(MetaAdsMutationForbiddenException::class);

        $this->service()->pauseCampaign($t['business'], $t['workspace'], 987654321, $this->campaignOf($t['account'])->uid);
    }

    public function test_a_core_plan_business_is_not_entitled(): void
    {
        $t = $this->mutationTenant(WorkspacePlanTier::Core);
        $campaign = $this->campaignOf($t['account']);

        try {
            $this->act($t, 'pauseCampaign', $campaign->uid);
            $this->fail('expected a refusal');
        } catch (MetaAdsMutationNotEntitledException $e) {
            $this->assertSame(404, $e->httpStatus());
        }

        $this->assertNothingHappened();
    }

    public function test_an_agency_plan_business_is_entitled(): void
    {
        $t = $this->mutationTenant(WorkspacePlanTier::Agency);

        $outcome = $this->act($t, 'pauseCampaign', $this->campaignOf($t['account'])->uid);

        $this->assertTrue($outcome->isSuccess());
    }

    public function test_the_workspace_argument_is_optional_and_resolved_from_the_business(): void
    {
        $t = $this->mutationTenant();

        $outcome = $this->service()->pauseCampaign($t['business'], null, (int) $t['actor']->id, $this->campaignOf($t['account'])->uid);

        $this->assertTrue($outcome->isSuccess());
    }

    public function test_a_workspace_that_is_not_the_businesss_own_fails_closed(): void
    {
        $mine = $this->mutationTenant(name: 'Mine');
        $theirs = $this->mutationTenant(name: 'Theirs');
        $campaign = $this->campaignOf($mine['account']);

        $this->expectException(MetaAdsMutationNotEntitledException::class);

        try {
            $this->service()->pauseCampaign($mine['business'], $theirs['workspace'], (int) $mine['actor']->id, $campaign->uid);
        } finally {
            $this->assertNothingHappened();
        }
    }

    public function test_a_business_whose_entitlement_cannot_be_resolved_fails_closed(): void
    {
        $t = $this->mutationTenant();
        $campaign = $this->campaignOf($t['account']);
        $t['business']->workspace_id = null;

        $this->expectException(MetaAdsMutationNotEntitledException::class);

        try {
            $this->service()->pauseCampaign($t['business'], null, (int) $t['actor']->id, $campaign->uid);
        } finally {
            $this->assertNothingHappened();
        }
    }

    public function test_an_inactive_workspace_is_not_entitled(): void
    {
        $t = $this->mutationTenant();
        Workspace::query()->whereKey($t['workspace']->id)->update(['is_active' => false]);

        $this->expectException(MetaAdsMutationNotEntitledException::class);

        try {
            $this->service()->pauseCampaign($t['business'], null, (int) $t['actor']->id, $this->campaignOf($t['account'])->uid);
        } finally {
            $this->assertNothingHappened();
        }
    }

    public function test_a_read_only_connection_without_ads_management_is_refused_with_a_reconnect_reason(): void
    {
        $t = $this->mutationTenant(connectionOverrides: ['granted_scopes' => 'ads_read']);
        $campaign = $this->campaignOf($t['account']);

        foreach (['pauseCampaign', 'resumeCampaign'] as $method) {
            try {
                $this->act($t, $method, $campaign->uid);
                $this->fail('expected a refusal');
            } catch (MetaAdsMutationConnectionException $e) {
                $this->assertSame(409, $e->httpStatus());
                $this->assertSame('connection_read_only', $e->reason);
                $this->assertStringContainsString('Reconnect', $e->getMessage());
            }
        }

        $this->assertNothingHappened();
        $this->assertSame('ACTIVE', $campaign->fresh()->status);
    }

    public function test_a_connection_re_authorised_as_a_different_meta_user_is_refused(): void
    {
        $t = $this->mutationTenant();
        $t['connection']->forceFill(['meta_user_id' => '99990000999900009'])->save();

        try {
            $this->act($t, 'pauseCampaign', $this->campaignOf($t['account'])->uid);
            $this->fail('expected a refusal');
        } catch (MetaAdsMutationConnectionException $e) {
            $this->assertSame('connection_mismatch', $e->reason);
        }

        $this->assertNothingHappened();
    }

    /** @return array<string, array{0: string}> */
    public static function unusableConnections(): array
    {
        return ['expired' => ['expired'], 'revoked' => ['revoked'], 'pending' => ['pending'], 'token gone' => ['no_token']];
    }

    #[DataProvider('unusableConnections')]
    public function test_a_connection_that_is_not_active_is_refused_with_no_call(string $case): void
    {
        $t = $this->mutationTenant();
        $campaign = $this->campaignOf($t['account']);

        match ($case) {
            'expired' => $t['connection']->forceFill(['state' => MetaConnectionState::Expired, 'access_token_encrypted' => null])->save(),
            'revoked' => $t['connection']->forceFill(['state' => MetaConnectionState::Revoked, 'access_token_encrypted' => null])->save(),
            'pending' => $t['connection']->forceFill(['state' => MetaConnectionState::Pending])->save(),
            'no_token' => $t['connection']->forceFill(['access_token_encrypted' => null])->save(),
        };

        try {
            $this->act($t, 'pauseCampaign', $campaign->uid);
            $this->fail('expected a refusal');
        } catch (MetaAdsMutationConnectionException $e) {
            $this->assertSame(409, $e->httpStatus());
            $this->assertSame('connection_not_active', $e->reason);
        }

        $this->assertNothingHappened();
        $this->assertSame('ACTIVE', $campaign->fresh()->status);
    }

    public function test_validation_refusals_are_typed_and_open_nothing(): void
    {
        $t = $this->mutationTenant();
        $campaign = $this->campaignOf($t['account']);
        $campaign->forceFill(['status' => 'ARCHIVED'])->save();

        try {
            $this->act($t, 'resumeCampaign', $campaign->uid);
            $this->fail('expected a refusal');
        } catch (MetaAdsMutationValidationException $e) {
            $this->assertSame(422, $e->httpStatus());
        }

        $this->assertNothingHappened();
    }

    public function test_every_refusal_is_a_typed_exception_with_an_http_status(): void
    {
        foreach ([
            MetaAdsMutationNotFoundException::class => 404,
            MetaAdsMutationNotEntitledException::class => 404,
            MetaAdsMutationForbiddenException::class => 403,
            MetaAdsMutationConnectionException::class => 409,
        ] as $class => $status) {
            $exception = new $class();
            $this->assertInstanceOf(MetaAdsMutationException::class, $exception);
            $this->assertSame($status, $exception->httpStatus());
            $this->assertNotSame('', $exception->reason);
        }

        $this->assertSame(422, (new MetaAdsMutationValidationException('entity_not_changeable'))->httpStatus());
    }

    public function test_ledger_and_detail_rows_never_contain_a_token_or_provider_text(): void
    {
        $t = $this->mutationTenant();

        $this->act($t, 'pauseCampaign', $this->campaignOf($t['account'])->uid);
        $this->fakeMeta->failNext('setStatus', MetaProviderException::timeout(true));
        $this->act($t, 'pauseAdSet', $this->adSetOf($t['account'])->uid);
        $this->fakeMeta->failNext('setStatus', MetaProviderException::validation(100, 'trace_abc123'));
        $this->act($t, 'pauseAd', $this->adOf($t['account'])->uid);

        $dump = json_encode([
            DB::table('business_meta_operations')->get()->all(),
            DB::table('meta_ads_mutations')->get()->all(),
        ]);

        $this->assertStringNotContainsString('plain-meta-access-token', $dump);
        $this->assertStringNotContainsString('access_token', $dump);
        $this->assertStringNotContainsStringIgnoringCase('bearer', $dump);
        $this->assertStringNotContainsStringIgnoringCase('appsecret', $dump);
        $this->assertStringNotContainsString('trace_abc123', $dump);
        $this->assertStringNotContainsString('Meta rejected', $dump);
    }

    public function test_the_only_status_writing_operation_types_are_the_three_documented_ones(): void
    {
        $statusTypes = array_filter(
            array_map(static fn (MetaOperationType $type): string => $type->value, MetaOperationType::cases()),
            static fn (string $value): bool => str_ends_with($value, '_status_changed'),
        );

        $this->assertEqualsCanonicalizing(
            ['campaign_status_changed', 'ad_set_status_changed', 'ad_status_changed'],
            array_values($statusTypes),
        );
    }
}

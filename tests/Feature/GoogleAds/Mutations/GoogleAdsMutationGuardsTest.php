<?php

namespace Tests\Feature\GoogleAds\Mutations;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\GoogleAds\GoogleAdsKeywordLevel;
use App\Enums\GoogleBusinessProfile\GoogleConnectionState;
use App\Library\GoogleAds\GoogleAdsConnectionManager;
use App\Library\GoogleAds\Mutations\GoogleAdsMutationConnectionException;
use App\Library\GoogleAds\Mutations\GoogleAdsMutationException;
use App\Library\GoogleAds\Mutations\GoogleAdsMutationForbiddenException;
use App\Library\GoogleAds\Mutations\GoogleAdsMutationNotEntitledException;
use App\Library\GoogleAds\Mutations\GoogleAdsMutationNotFoundException;
use App\Library\GoogleAds\Mutations\GoogleAdsMutationOperations;
use App\Library\GoogleAds\Mutations\GoogleAdsMutationService;
use App\Models\BusinessGoogleOperation;
use App\Models\GoogleAdsAccount;
use App\Models\GoogleAdsMutation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\GoogleAds\Mutations\Concerns\CreatesMutationFixtures;
use Tests\TestCase;

/**
 * Google Ads Module V1 contract §6 steps 1-2 — the service's own gate:
 * everything resolves inside the Business's account, and permission,
 * entitlement and connection are enforced with ZERO provider calls and zero
 * ledger rows when refused.
 */
class GoogleAdsMutationGuardsTest extends TestCase
{
    use CreatesMutationFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindFakeAds();
    }

    private function service(): GoogleAdsMutationService
    {
        return app(GoogleAdsMutationService::class);
    }

    private function assertNothingHappened(): void
    {
        $this->assertSame(0, $this->fakeAds->callCount());
        $this->assertSame(0, BusinessGoogleOperation::query()->whereIn('operation_type', GoogleAdsMutationOperations::typeValues())->count());
        $this->assertSame(0, GoogleAdsMutation::query()->count());
    }

    /** @return array<string, array{0: callable(GoogleAdsMutationService, array, array): mixed}> */
    public static function everyOperationOnForeignTargets(): array
    {
        return [
            'pause campaign' => [fn ($s, $mine, $theirs) => $s->pauseCampaign($mine['business'], $mine['actor'], $theirs['campaign']->uid)],
            'resume campaign' => [fn ($s, $mine, $theirs) => $s->resumeCampaign($mine['business'], $mine['actor'], $theirs['campaign']->uid)],
            'pause keyword' => [fn ($s, $mine, $theirs) => $s->pauseKeyword($mine['business'], $mine['actor'], $theirs['keyword']->uid)],
            'resume keyword' => [fn ($s, $mine, $theirs) => $s->resumeKeyword($mine['business'], $mine['actor'], $theirs['keyword']->uid)],
            'negative on a foreign campaign' => [fn ($s, $mine, $theirs) => $s->addNegativeKeyword($mine['business'], $mine['actor'], $theirs['campaign']->uid, 'x')],
            'negative preview on a foreign campaign' => [fn ($s, $mine, $theirs) => $s->previewNegativeKeyword($mine['business'], $mine['actor'], $theirs['campaign']->uid, 'x')],
            'negative with a foreign search term' => [fn ($s, $mine, $theirs) => $s->addNegativeKeyword(
                $mine['business'], $mine['actor'], $mine['campaign']->uid, 'x', GoogleAdsKeywordLevel::AdGroup, \App\Enums\GoogleAds\GoogleAdsMatchType::Exact, $theirs['term']->id,
            )],
        ];
    }

    #[DataProvider('everyOperationOnForeignTargets')]
    public function test_another_businesss_uid_fails_closed_as_not_found_with_no_call_and_no_ledger_row(callable $attempt): void
    {
        $mine = $this->mutationTenant(name: 'Mine');
        $mine['campaign'] = $this->campaignOf($mine['account']);
        $theirs = $this->mutationTenant(name: 'Theirs');
        $theirs['campaign'] = $this->campaignOf($theirs['account']);
        $theirs['keyword'] = $this->positiveKeywordOf($theirs['account']);
        $theirs['term'] = $this->searchTermOf($theirs['account']);

        try {
            $attempt($this->service(), $mine, $theirs);
            $this->fail('expected the foreign target to be refused');
        } catch (GoogleAdsMutationNotFoundException $e) {
            $this->assertSame(404, $e->httpStatus());
        }

        $this->assertNothingHappened();
    }

    public function test_a_request_supplied_external_id_is_never_trusted_as_a_target(): void
    {
        $t = $this->mutationTenant();
        $external = $this->campaignOf($t['account'])->external_campaign_id;
        $externalKeyword = $this->positiveKeywordOf($t['account'])->external_criterion_id;

        foreach ([
            fn () => $this->service()->pauseCampaign($t['business'], $t['actor'], (string) $external),
            fn () => $this->service()->pauseKeyword($t['business'], $t['actor'], (string) $externalKeyword),
            fn () => $this->service()->addNegativeKeyword($t['business'], $t['actor'], (string) $external, 'x'),
            fn () => $this->service()->pauseCampaign($t['business'], $t['actor'], "1000000001' OR 1=1 --"),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('expected a not-found refusal');
            } catch (GoogleAdsMutationNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertNothingHappened();
    }

    public function test_the_wrong_businesss_account_cannot_be_reached_with_this_businesss_context(): void
    {
        $mine = $this->mutationTenant(name: 'Mine');
        $theirs = $this->mutationTenant(name: 'Theirs');
        $theirCampaign = $this->campaignOf($theirs['account']);

        // Their uid, my business, my account: absent.
        $this->expectException(GoogleAdsMutationNotFoundException::class);

        try {
            $this->service()->pauseCampaign($mine['business'], $mine['actor'], $theirCampaign->uid);
        } finally {
            $this->assertNothingHappened();
            $this->assertSame('ENABLED', $theirCampaign->fresh()->status->value);
        }
    }

    public function test_a_business_with_no_selected_account_is_not_found(): void
    {
        $t = $this->mutationTenant();
        $campaign = $this->campaignOf($t['account']);
        GoogleAdsAccount::query()->whereKey($t['account']->id)->delete();

        try {
            $this->service()->pauseCampaign($t['business'], $t['actor'], $campaign->uid);
            $this->fail('expected a refusal');
        } catch (GoogleAdsMutationNotFoundException $e) {
            $this->assertSame('account_not_found', $e->reason);
        }

        $this->assertNothingHappened();
    }

    public function test_an_actor_without_manage_google_ads_is_forbidden(): void
    {
        $t = $this->mutationTenant(canManage: false);
        $campaign = $this->campaignOf($t['account']);

        foreach ([
            fn () => $this->service()->pauseCampaign($t['business'], $t['actor'], $campaign->uid),
            fn () => $this->service()->pauseKeyword($t['business'], $t['actor'], $this->positiveKeywordOf($t['account'])->uid),
            fn () => $this->service()->addNegativeKeyword($t['business'], $t['actor'], $campaign->uid, 'x'),
            fn () => $this->service()->previewNegativeKeyword($t['business'], $t['actor'], $campaign->uid, 'x'),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('expected a refusal');
            } catch (GoogleAdsMutationForbiddenException $e) {
                $this->assertSame(403, $e->httpStatus());
            }
        }

        $this->assertNothingHappened();
    }

    public function test_a_core_plan_business_is_not_entitled(): void
    {
        $t = $this->mutationTenant(WorkspacePlanTier::Core);
        $campaign = $this->campaignOf($t['account']);

        try {
            $this->service()->pauseCampaign($t['business'], $t['actor'], $campaign->uid);
            $this->fail('expected a refusal');
        } catch (GoogleAdsMutationNotEntitledException $e) {
            $this->assertSame(404, $e->httpStatus());
        }

        $this->assertNothingHappened();
    }

    public function test_an_agency_plan_business_is_entitled(): void
    {
        $t = $this->mutationTenant(WorkspacePlanTier::Agency);

        $outcome = $this->service()->pauseCampaign($t['business'], $t['actor'], $this->campaignOf($t['account'])->uid);

        $this->assertTrue($outcome->isSuccess());
    }

    public function test_a_business_whose_entitlement_cannot_be_resolved_fails_closed(): void
    {
        $t = $this->mutationTenant();
        $campaign = $this->campaignOf($t['account']);
        $t['business']->workspace_id = null;

        $this->expectException(GoogleAdsMutationNotEntitledException::class);

        try {
            $this->service()->pauseCampaign($t['business'], $t['actor'], $campaign->uid);
        } finally {
            $this->assertNothingHappened();
        }
    }

    /** @return array<string, array{0: string}> */
    public static function unusableConnections(): array
    {
        return ['revoked' => ['revoked'], 'disconnected' => ['disconnected'], 'pending' => ['pending'], 'token gone' => ['no_token']];
    }

    #[DataProvider('unusableConnections')]
    public function test_a_connection_that_is_not_active_is_refused_with_no_call(string $case): void
    {
        $t = $this->mutationTenant();
        $campaign = $this->campaignOf($t['account']);

        match ($case) {
            'revoked' => $t['connection']->forceFill(['state' => GoogleConnectionState::Revoked, 'refresh_token_encrypted' => null])->save(),
            'disconnected' => app(GoogleAdsConnectionManager::class)->disconnect($t['connection'], $t['actor']->id),
            'pending' => $t['connection']->forceFill(['state' => GoogleConnectionState::Pending])->save(),
            'no_token' => $t['connection']->forceFill(['refresh_token_encrypted' => null])->save(),
        };

        try {
            $this->service()->pauseCampaign($t['business'], $t['actor'], $campaign->uid);
            $this->fail('expected a refusal');
        } catch (GoogleAdsMutationConnectionException $e) {
            $this->assertSame(409, $e->httpStatus());
        } catch (GoogleAdsMutationNotFoundException $e) {
            // A disconnect also unselects the account, so it is refused as "no account" first.
            $this->assertSame('disconnected', $case);
        }

        $this->assertNothingHappened();
        $this->assertSame('ENABLED', $campaign->fresh()->status->value);
    }

    public function test_every_refusal_is_a_typed_exception_with_an_http_status(): void
    {
        foreach ([
            GoogleAdsMutationNotFoundException::class => 404,
            GoogleAdsMutationNotEntitledException::class => 404,
            GoogleAdsMutationForbiddenException::class => 403,
            GoogleAdsMutationConnectionException::class => 409,
        ] as $class => $status) {
            $exception = new $class();
            $this->assertInstanceOf(GoogleAdsMutationException::class, $exception);
            $this->assertSame($status, $exception->httpStatus());
        }
    }
}

<?php

namespace Tests\Unit\MetaAds;

use App\Enums\MetaAds\MetaAdsLevel;
use App\Enums\MetaAds\MetaAdsMutationTargetType;
use App\Enums\MetaAds\MetaAdsRequestedState;
use App\Enums\MetaAds\MetaAdsSyncRunState;
use App\Enums\MetaAds\MetaAdsSyncTrigger;
use App\Enums\MetaAds\MetaConnectionState;
use App\Enums\MetaAds\MetaOperationStatus;
use App\Enums\MetaAds\MetaOperationType;
use PHPUnit\Framework\TestCase;

/**
 * Meta Ads Module V1 — the enum values are PERSISTED, so their exact values
 * are pinned. A change here is a data migration.
 */
class MetaAdsEnumPinsTest extends TestCase
{
    /**
     * @param  class-string<\BackedEnum>  $enum
     * @return array<int, string>
     */
    private function values(string $enum): array
    {
        return array_map(static fn (\BackedEnum $c): string => (string) $c->value, $enum::cases());
    }

    public function test_connection_states_are_pinned(): void
    {
        $this->assertSame(['pending', 'active', 'expired', 'revoked', 'disconnected'], $this->values(MetaConnectionState::class));
    }

    public function test_connection_transitions_match_the_contract_exactly(): void
    {
        $expected = [
            'pending' => ['active', 'disconnected'],
            'active' => ['active', 'expired', 'revoked', 'disconnected'],
            'expired' => ['pending', 'disconnected'],
            'revoked' => ['pending', 'disconnected'],
            'disconnected' => ['pending'],
        ];

        foreach (MetaConnectionState::cases() as $from) {
            $allowed = array_map(static fn (MetaConnectionState $s): string => $s->value, $from->transitionsTo());
            $this->assertEqualsCanonicalizing($expected[$from->value], $allowed, $from->value);

            foreach (MetaConnectionState::cases() as $to) {
                $this->assertSame(
                    in_array($to->value, $expected[$from->value], true),
                    $from->canTransitionTo($to),
                    $from->value . ' -> ' . $to->value,
                );
            }
        }

        // Re-authorising while active completes in place; the dead states cannot skip pending.
        $this->assertTrue(MetaConnectionState::Active->canTransitionTo(MetaConnectionState::Active));
        $this->assertFalse(MetaConnectionState::Expired->canTransitionTo(MetaConnectionState::Active));
        $this->assertFalse(MetaConnectionState::Revoked->canTransitionTo(MetaConnectionState::Active));
        $this->assertFalse(MetaConnectionState::Disconnected->canTransitionTo(MetaConnectionState::Active));
        $this->assertFalse(MetaConnectionState::Pending->canTransitionTo(MetaConnectionState::Expired));
    }

    public function test_operation_types_are_pinned_and_fit_the_varchar_40_column(): void
    {
        $this->assertSame([
            'connect_initiated', 'connect_completed', 'connect_failed', 'disconnected', 'token_expired',
            'accounts_listed', 'account_selected', 'meta_ads_sync',
            'campaign_status_changed', 'ad_set_status_changed', 'ad_status_changed',
        ], $this->values(MetaOperationType::class));

        foreach ($this->values(MetaOperationType::class) as $value) {
            $this->assertLessThanOrEqual(40, strlen($value), $value);
        }
    }

    public function test_operation_statuses_are_pinned(): void
    {
        $this->assertSame(['pending', 'succeeded', 'failed', 'unknown', 'deferred'], $this->values(MetaOperationStatus::class));
    }

    public function test_levels_target_types_triggers_and_run_states_are_pinned(): void
    {
        $this->assertSame(['campaign', 'ad_set', 'ad'], $this->values(MetaAdsLevel::class));
        $this->assertSame(['campaign', 'ad_set', 'ad'], $this->values(MetaAdsMutationTargetType::class));
        $this->assertSame(['scheduled', 'manual', 'connect'], $this->values(MetaAdsSyncTrigger::class));
        $this->assertSame(['queued', 'running', 'succeeded', 'partial', 'failed', 'skipped'], $this->values(MetaAdsSyncRunState::class));
    }

    public function test_sync_run_terminal_states(): void
    {
        $this->assertFalse(MetaAdsSyncRunState::Queued->isTerminal());
        $this->assertFalse(MetaAdsSyncRunState::Running->isTerminal());

        foreach ([MetaAdsSyncRunState::Succeeded, MetaAdsSyncRunState::Partial, MetaAdsSyncRunState::Failed, MetaAdsSyncRunState::Skipped] as $state) {
            $this->assertTrue($state->isTerminal(), $state->value);
        }
    }

    public function test_requested_state_maps_to_the_provider_status_values(): void
    {
        $this->assertSame(['paused', 'active'], $this->values(MetaAdsRequestedState::class));
        $this->assertSame('PAUSED', MetaAdsRequestedState::Paused->providerValue());
        $this->assertSame('ACTIVE', MetaAdsRequestedState::Active->providerValue());
    }
}

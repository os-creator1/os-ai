<?php

namespace Tests\Unit\Usage;

use App\Enums\Usage\FundingAttemptPurpose;
use App\Enums\Usage\FundingAttemptState;
use App\Enums\Usage\PaymentInstrumentStatus;
use App\Enums\Usage\PaymentInstrumentType;
use App\Enums\Usage\PaymentProvider;
use App\Enums\Usage\ProviderCustomerStatus;
use App\Enums\Usage\ProviderEventState;
use App\Enums\Usage\TransitionSource;
use PHPUnit\Framework\TestCase;

/**
 * RFC-005 M3 contract §21/§23 item 69 — the exact case set and backing
 * value of every new M3 enum, mechanically proving no case was dropped,
 * renamed, or given an unexpected backing value.
 */
class PaymentProviderEnumsTest extends TestCase
{
    public function test_payment_provider_has_exactly_stripe(): void
    {
        $this->assertSame(['stripe'], array_column(PaymentProvider::cases(), 'value'));
    }

    public function test_payment_instrument_type_has_exactly_card(): void
    {
        $this->assertSame(['card'], array_column(PaymentInstrumentType::cases(), 'value'));
    }

    public function test_payment_instrument_status_has_exactly_active_and_detached(): void
    {
        $this->assertSame(['active', 'detached'], array_column(PaymentInstrumentStatus::cases(), 'value'));
    }

    public function test_provider_customer_status_has_exactly_active_and_detached(): void
    {
        $this->assertSame(['active', 'detached'], array_column(ProviderCustomerStatus::cases(), 'value'));
    }

    public function test_funding_attempt_purpose_has_exactly_three_cases(): void
    {
        $this->assertSame(
            ['manual_top_up', 'auto_recharge', 'addon_purchase'],
            array_column(FundingAttemptPurpose::cases(), 'value'),
        );
    }

    public function test_funding_attempt_state_has_exactly_nine_cases(): void
    {
        $this->assertSame(
            ['created', 'provider_pending', 'requires_action', 'processing', 'succeeded', 'failed', 'canceled', 'refunded', 'disputed'],
            array_column(FundingAttemptState::cases(), 'value'),
        );
    }

    public function test_transition_source_has_exactly_four_cases(): void
    {
        $this->assertSame(
            ['sync_response', 'webhook_event', 'admin_action', 'reconciliation_job'],
            array_column(TransitionSource::cases(), 'value'),
        );
    }

    public function test_provider_event_state_has_exactly_six_cases(): void
    {
        $this->assertSame(
            ['received', 'processing', 'processed', 'failed', 'ignored', 'disposed'],
            array_column(ProviderEventState::cases(), 'value'),
        );
    }
}

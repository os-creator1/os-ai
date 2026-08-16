<?php

namespace Tests\Unit\Usage;

use App\Enums\Usage\BillingStatusTransitionSource;
use App\Enums\Usage\PayerType;
use App\Enums\Usage\UsageLimitType;
use Tests\TestCase;

/**
 * RFC-005 M2 contract §11 — exact case sets for the three new M2 enums.
 */
class UsageBillingEnumsTest extends TestCase
{
    public function test_usage_limit_type_has_exactly_three_cases(): void
    {
        $this->assertSame(
            ['business_spend_cap', 'feature_limit', 'platform_safety_limit'],
            array_map(fn (UsageLimitType $case) => $case->value, UsageLimitType::cases()),
        );
    }

    public function test_payer_type_has_exactly_three_cases(): void
    {
        $this->assertSame(
            ['business', 'workspace', 'agency_rebill'],
            array_map(fn (PayerType $case) => $case->value, PayerType::cases()),
        );
    }

    public function test_billing_status_transition_source_has_exactly_two_cases(): void
    {
        $this->assertSame(
            ['dispute_webhook', 'admin_action'],
            array_map(fn (BillingStatusTransitionSource $case) => $case->value, BillingStatusTransitionSource::cases()),
        );
    }
}
